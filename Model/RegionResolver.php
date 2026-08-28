<?php
/**
 * Copyright © - LiquidLab Agency - All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Liquidlab\InnoShipHyva\Model;

use Magento\Directory\Model\ResourceModel\Region\CollectionFactory as RegionCollectionFactory;

/**
 * Resolves a Magento `region_id` from a county/region NAME plus country code.
 *
 * Used when copying a PUDO point's `countyName` onto a quote shipping address:
 * the InnoShip dataset stores Romanian county names without diacritics
 * (e.g. "Bucuresti", "Brasov") while Magento's `directory_country_region`
 * table holds them WITH diacritics ("București", "Brașov"). A naive exact
 * match against `default_name` returns nothing for those.
 *
 * Strategy:
 *   1. exact match on `default_name`
 *   2. fall back to a diacritic-stripped, case-insensitive comparison
 *      across the country's regions (small, ~42 rows for RO — cheap)
 *
 * If nothing matches we return `region_id = null` and `region` is the raw
 * input name. Magento accepts a free-text region in that case (it'll be
 * stored in `region` text but `region_id` will be NULL).
 */
class RegionResolver
{
    /** Cache: "<countryCode>" => array<string, int>  (normalized name → region_id) */
    private array $cache = [];

    public function __construct(
        private readonly RegionCollectionFactory $regionCollectionFactory
    ) {
    }

    /**
     * @return array{region_id: int|null, region: string}
     */
    public function resolveByName(string $regionName, string $countryCode): array
    {
        $regionName = trim($regionName);
        if ($regionName === '' || $countryCode === '') {
            return ['region_id' => null, 'region' => $regionName];
        }

        $regionId = $this->lookup($regionName, $countryCode);
        return ['region_id' => $regionId, 'region' => $regionName];
    }

    private function lookup(string $regionName, string $countryCode): ?int
    {
        $map = $this->getNormalizedMap($countryCode);
        $key = $this->normalize($regionName);
        return $map[$key] ?? null;
    }

    /**
     * @return array<string, int>  normalized region name → region_id
     */
    private function getNormalizedMap(string $countryCode): array
    {
        if (isset($this->cache[$countryCode])) {
            return $this->cache[$countryCode];
        }

        $collection = $this->regionCollectionFactory->create();
        $collection->addCountryFilter($countryCode);

        $map = [];
        foreach ($collection as $region) {
            $id = (int)$region->getRegionId();
            // Index by every name shape we might be handed.
            foreach ([$region->getName(), $region->getDefaultName(), $region->getCode()] as $candidate) {
                if (is_string($candidate) && $candidate !== '') {
                    $map[$this->normalize($candidate)] = $id;
                }
            }
        }

        $this->cache[$countryCode] = $map;
        return $map;
    }

    /**
     * Fold a region/county name to a forgiving, locale-INDEPENDENT lookup key.
     *
     * Do NOT reintroduce iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', …) here.
     * That transliteration is governed by the process LC_CTYPE locale:
     *   - under a UTF-8 locale (e.g. staging's C.UTF-8) glibc folds
     *     "Constanța" → "constanta" and the match succeeds;
     *   - under the bare C / POSIX locale (common on production PHP-FPM) glibc
     *     cannot transliterate and emits the placeholder "constan?a", so the
     *     ASCII InnoShip name "Constanta" never matches and region_id resolves
     *     to NULL for EVERY county whose Magento name carries a diacritic
     *     (22 of 42 RO counties, incl. București and Constanța).
     * That locale split was the entire prod-vs-staging divergence: identical
     * bytes, different LC_CTYPE. See Test/Unit/Model/RegionResolverTest.php,
     * which pins LC_ALL=C to reproduce the production condition.
     *
     * Two locale-independent steps, applied to BOTH sides of the comparison:
     *   1. Diacritic fold — intl Normalizer NFD splits every accented letter
     *      into base + combining mark; strip the combining marks (\p{Mn}). This
     *      folds all Romanian forms — comma-below (ș U+0219, ț U+021B), legacy
     *      cedilla (ş U+015F, ţ U+0163) and ă/â/î — to plain ASCII, under any
     *      locale. ext-intl (hence \Normalizer) is a hard Magento requirement,
     *      so this is always available; we still guard defensively.
     *   2. Separator fold — collapse runs of whitespace/hyphen to a single
     *      space. The InnoShip feed and Magento's directory disagree on the
     *      separator for multi-word counties: InnoShip sends "Satu Mare" (space)
     *      where Magento stores "Satu-Mare" (hyphen), so without this the two
     *      never match and region_id is NULL for every Satu Mare locker — a
     *      separator bug orthogonal to (and older than) the diacritic one. This
     *      also future-proofs "Bistrița-Năsăud" / "Caraș-Severin".
     */
    private function normalize(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        // 1. Locale-independent diacritic fold.
        if (class_exists(\Normalizer::class)) {
            $decomposed = \Normalizer::normalize($value, \Normalizer::FORM_D);
            if ($decomposed !== false) {
                $value = preg_replace('/\p{Mn}+/u', '', $decomposed) ?? $value;
            }
        }

        // 2. Separator-insensitive: "Satu Mare" ≡ "Satu-Mare".
        $value = preg_replace('/[\s\-]+/u', ' ', $value) ?? $value;

        return mb_strtolower(trim($value), 'UTF-8');
    }
}
