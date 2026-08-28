<?php
/**
 * Copyright © - LiquidLab Agency - All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Liquidlab\InnoShipHyva\Test\Unit\Model;

use Liquidlab\InnoShipHyva\Model\RegionResolver;
use Magento\Directory\Model\ResourceModel\Region\Collection;
use Magento\Directory\Model\ResourceModel\Region\CollectionFactory;
use Magento\Framework\DataObject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for the diacritic / locale bug.
 *
 * InnoShip stores Romanian county names WITHOUT diacritics ("Constanta",
 * "Bucuresti"); Magento's directory_country_region stores them WITH diacritics
 * ("Constanţa", "Bucureşti"). The resolver must reconcile the two.
 *
 * The whole suite pins LC_ALL=C in setUp() to reproduce PRODUCTION PHP-FPM,
 * whose bare C/POSIX locale is what broke the previous iconv('ASCII//TRANSLIT')
 * implementation (it emitted "constan?a" and matched nothing). Under this locale
 * every diacritic assertion below FAILS against the old code and PASSES against
 * the intl-Normalizer implementation — that is exactly the guarantee we want.
 */
class RegionResolverTest extends TestCase
{
    private ?string $originalLocale = null;
    private RegionResolver $resolver;

    protected function setUp(): void
    {
        // Reproduce the production runtime: a non-UTF-8 LC_CTYPE.
        $this->originalLocale = setlocale(LC_ALL, '0') ?: null;
        setlocale(LC_ALL, 'C');

        $regions = [
            // id, default_name (diacritic, as in the DB), code
            $this->makeRegion(287, "Bucure\u{015F}ti", 'B'),   // ş U+015F cedilla
            $this->makeRegion(292, "Constan\u{0163}a", 'CT'),  // ţ U+0163 cedilla
            $this->makeRegion(303, 'Ilfov', 'IF'),             // no diacritic (control)
            $this->makeRegion(300, 'Cluj', 'CJ'),              // no diacritic (control)
            $this->makeRegion(281, "Arge\u{015F}", 'AG'),      // ş
            $this->makeRegion(283, "Bac\u{0103}u", 'BC'),      // ă U+0103
            $this->makeRegion(310, "Timi\u{015F}", 'TM'),      // ş
            // Multi-word counties — Magento stores a HYPHEN separator.
            $this->makeRegion(308, 'Satu-Mare', 'SM'),                       // ASCII, hyphen
            $this->makeRegion(285, "Bistri\u{0163}a-N\u{0103}s\u{0103}ud", 'BN'), // ţ + ă, hyphen
        ];

        $collection = $this->createMock(Collection::class);
        $collection->method('addCountryFilter')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator($regions));

        $collectionFactory = $this->createMock(CollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);

        $this->resolver = new RegionResolver($collectionFactory);
    }

    protected function tearDown(): void
    {
        if ($this->originalLocale !== null) {
            setlocale(LC_ALL, $this->originalLocale);
        }
    }

    /**
     * The core bug: ASCII InnoShip county name must resolve to the diacritic
     * Magento region_id, even under the C/POSIX locale.
     */
    #[DataProvider('diacriticCountyProvider')]
    public function testResolvesAsciiCountyNameToDiacriticRegionId(
        string $innoShipCountyName,
        int $expectedRegionId
    ): void {
        $result = $this->resolver->resolveByName($innoShipCountyName, 'RO');

        $this->assertSame(
            $expectedRegionId,
            $result['region_id'],
            sprintf('"%s" should resolve to region_id %d', $innoShipCountyName, $expectedRegionId)
        );
        // The human-readable region text is always the raw input name.
        $this->assertSame($innoShipCountyName, $result['region']);
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function diacriticCountyProvider(): array
    {
        return [
            // The two reported failures.
            'Constanta (ascii)'      => ['Constanta', 292],
            'Bucuresti (ascii)'      => ['Bucuresti', 287],
            // Case-insensitivity.
            'CONSTANTA (upper)'      => ['CONSTANTA', 292],
            'bucuresti (lower)'      => ['bucuresti', 287],
            // Already-diacritic input (comma-below) must fold too.
            'Constanța (comma ț)'    => ["Constan\u{021B}a", 292],
            'București (comma ș)'    => ["Bucure\u{0219}ti", 287],
            // Already-diacritic input (cedilla) must fold too.
            'Constanţa (cedilla ţ)'  => ["Constan\u{0163}a", 292],
            // ă / other diacritics.
            'Bacau -> Bacău'         => ['Bacau', 283],
            'Arges -> Argeş'         => ['Arges', 281],
            'Timis -> Timiş'         => ['Timis', 310],
            // Match by ISO code.
            'CT (code)'              => ['CT', 292],
            // Separator mismatch: InnoShip "Satu Mare" (space) vs Magento
            // "Satu-Mare" (hyphen). Pre-existing, locale-independent failure.
            'Satu Mare (space)->hyphen' => ['Satu Mare', 308],
            'Satu-Mare (hyphen both)'   => ['Satu-Mare', 308],
            // Compound county: diacritics AND hyphen at once.
            'Bistrita-Nasaud (ascii)'   => ['Bistrita-Nasaud', 285],
            // Diacritic-free counties always worked; assert no regression.
            'Ilfov (control)'        => ['Ilfov', 303],
            'Cluj (control)'         => ['Cluj', 300],
        ];
    }

    public function testUnknownCountyReturnsNullRegionIdAndRawText(): void
    {
        $result = $this->resolver->resolveByName('Voluntari', 'RO');

        // A locality name that is not a county resolves to no region_id;
        // Magento then stores it as free-text region.
        $this->assertNull($result['region_id']);
        $this->assertSame('Voluntari', $result['region']);
    }

    public function testEmptyInputsReturnNullRegionId(): void
    {
        $this->assertNull($this->resolver->resolveByName('', 'RO')['region_id']);
        $this->assertNull($this->resolver->resolveByName('Constanta', '')['region_id']);
    }

    public function testWhitespaceIsTrimmedBeforeMatching(): void
    {
        $this->assertSame(292, $this->resolver->resolveByName('  Constanta  ', 'RO')['region_id']);
    }

    /**
     * Region rows are DataObjects, not mocks: the resolver reads getRegionId()/
     * getName()/getDefaultName()/getCode(), which on the real Magento Region
     * model are magic DataObject getters (getData('region_id') …) — not declared
     * methods, so they can't be stubbed. A real DataObject exercises the exact
     * same magic-getter path with zero constructor dependencies.
     */
    private function makeRegion(int $id, string $defaultName, string $code): DataObject
    {
        return new DataObject([
            'region_id'    => $id,
            'name'         => $defaultName,
            'default_name' => $defaultName,
            'code'         => $code,
        ]);
    }
}
