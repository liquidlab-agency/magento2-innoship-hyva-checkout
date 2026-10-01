<?php
/**
 * Copyright © - LiquidLab Agency - All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Liquidlab\InnoShipHyva\Test\Unit\Model;

use Liquidlab\InnoShipHyva\Api\Data\PudoInterface;
use Liquidlab\InnoShipHyva\Api\PudoRepositoryInterface;
use Liquidlab\InnoShipHyva\Model\PudoPointsProvider;
use Liquidlab\InnoShipHyva\Model\RegionCoordinatesProvider;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The points the checkout map loads from innoshiphyva/pudo/points. They used to
 * be printed into the page and into every Magewire update of the picker.
 */
class PudoPointsProviderTest extends TestCase
{
    private const BUCHAREST = ['lat' => 44.4268, 'lng' => 26.1025];

    private PudoRepositoryInterface&MockObject $pudoRepository;
    private RegionCoordinatesProvider&MockObject $regionCoordinates;
    private PudoPointsProvider $provider;

    /** @var array<string, string> */
    private array $cacheStore = [];

    /** @var array<int, array{tags: array, lifetime: mixed}> */
    private array $cacheSaves = [];

    protected function setUp(): void
    {
        $this->pudoRepository = $this->createMock(PudoRepositoryInterface::class);
        $this->regionCoordinates = $this->createMock(RegionCoordinatesProvider::class);

        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturnCallback(fn (string $id) => $this->cacheStore[$id] ?? false);
        $cache->method('save')->willReturnCallback(
            function (string $data, string $id, array $tags = [], $lifetime = null): bool {
                $this->cacheStore[$id] = $data;
                $this->cacheSaves[] = ['tags' => $tags, 'lifetime' => $lifetime];
                return true;
            }
        );

        $this->provider = new PudoPointsProvider($this->pudoRepository, $this->regionCoordinates, $cache, new Json());
    }

    public function testGetByCityReturnsTheCityPointsInThePopupShape(): void
    {
        $this->pudoRepository->expects($this->once())
            ->method('getActivePudoPoints')
            ->with('Cluj', 'Cluj-Napoca')
            ->willReturn([$this->pudo(101, 46.7712, 23.6236, 'Cash, Card')]);

        $pins = $this->provider->getByCity('Cluj', 'Cluj-Napoca');

        $this->assertCount(1, $pins);
        $this->assertSame(101, $pins[0]['pudo_id']);
        $this->assertSame('Locker 101', $pins[0]['name']);
        $this->assertSame('{"Cash":true,"Card":true}', $pins[0]['accepted_payment_type']);
        $this->assertSame('08:00', $pins[0]['mo_start']);
        $this->assertNull($pins[0]['su_start']);
        $this->assertArrayNotHasKey('distance_km', $pins[0]);
    }

    public function testResultsAreCachedForAnHourUnderTheCollectionsTag(): void
    {
        $this->pudoRepository->expects($this->once())
            ->method('getActivePudoPoints')
            ->willReturn([$this->pudo(101, 46.7712, 23.6236)]);

        $first = $this->provider->getByCity('Cluj', 'Cluj-Napoca');
        $second = $this->provider->getByCity('Cluj', 'Cluj-Napoca');

        $this->assertSame($first, $second);
        $this->assertSame([['tags' => ['COLLECTION_DATA'], 'lifetime' => 3600]], $this->cacheSaves);
    }

    public function testACorruptCacheEntryIsRebuilt(): void
    {
        $this->cacheStore['liquidlab_innoship_pudo_points_city_' . sha1('Cluj|Cluj-Napoca')] = '{not json';
        $this->pudoRepository->expects($this->once())
            ->method('getActivePudoPoints')
            ->willReturn([$this->pudo(101, 46.7712, 23.6236)]);

        $this->assertSame([101], array_column($this->provider->getByCity('Cluj', 'Cluj-Napoca'), 'pudo_id'));
    }

    public function testGetNearRegionKeepsThePointsWithinFiftyKilometresNearestFirst(): void
    {
        $this->regionCoordinates->method('getByRegionId')->with(42)->willReturn(self::BUCHAREST);
        $this->pudoRepository->expects($this->once())
            ->method('getActivePudoPoints')
            ->with()
            ->willReturn([
                $this->pudo(1, 44.6000, 26.1025),   // about 19 km north
                $this->pudo(2, 44.4300, 26.1100),   // under 1 km
                $this->pudo(3, 46.7712, 23.6236),   // Cluj-Napoca, over 300 km
            ]);

        $result = $this->provider->getNearRegion(42);

        $this->assertSame(['lat' => 44.4268, 'lng' => 26.1025, 'region_id' => 42], $result['customerLocation']);
        $this->assertSame([2, 1], array_column($result['pins'], 'pudo_id'));
        $this->assertLessThan(1.0, $result['pins'][0]['distance_km']);
        $this->assertEqualsWithDelta(19.3, $result['pins'][1]['distance_km'], 0.5);

        // Served from the cache the second time: the repository is read once.
        $this->assertSame($result, $this->provider->getNearRegion(42));
    }

    public function testGetNearRegionIsEmptyForARegionWithoutCoordinates(): void
    {
        $this->regionCoordinates->method('getByRegionId')->willReturn(null);
        $this->pudoRepository->expects($this->never())->method('getActivePudoPoints');

        $this->assertSame(['customerLocation' => null, 'pins' => []], $this->provider->getNearRegion(999));
    }

    #[DataProvider('paymentTypesProvider')]
    public function testGetPaymentTypesJson(?string $types, string $expected): void
    {
        $this->assertSame($expected, $this->provider->getPaymentTypesJson($types));
    }

    public static function paymentTypesProvider(): array
    {
        return [
            'none' => [null, '[]'],
            'empty' => ['', '[]'],
            'card only' => ['Card', '{"Card":true}'],
            'all, spaced' => ['Cash, Card , Online', '{"Cash":true,"Card":true,"Online":true}'],
            'unknown types ignored' => ['Voucher,Cash', '{"Cash":true}'],
            'only unknown types' => ['Voucher', '[]'],
        ];
    }

    private function pudo(int $id, float $lat, float $lng, ?string $paymentTypes = 'Card'): PudoInterface&MockObject
    {
        $hours = ['start' => '08:00', 'end' => '20:00'];
        $closed = ['start' => null, 'end' => null];

        $pudo = $this->createMock(PudoInterface::class);
        $pudo->method('getPudoId')->willReturn($id);
        $pudo->method('getCourierId')->willReturn(1);
        $pudo->method('getName')->willReturn('Locker ' . $id);
        $pudo->method('getAddressText')->willReturn('Strada Test ' . $id);
        $pudo->method('getLocalityName')->willReturn('Test');
        $pudo->method('getPostalCode')->willReturn('400000');
        $pudo->method('getLatitude')->willReturn($lat);
        $pudo->method('getLongitude')->willReturn($lng);
        $pudo->method('getFixedLocationTypeId')->willReturn(1);
        $pudo->method('getSupportedPaymentType')->willReturn($paymentTypes);
        $pudo->method('getPhone')->willReturn(null);
        $pudo->method('getOpenHours')->willReturn([
            'mo' => $hours, 'tu' => $hours, 'we' => $hours, 'th' => $hours, 'fr' => $hours,
            'sa' => $closed, 'su' => $closed,
        ]);

        return $pudo;
    }
}
