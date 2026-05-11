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
     * Lowercase + strip diacritics for forgiving lookup.
     */
    private function normalize(string $value): string
    {
        $stripped = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if ($stripped === false) {
            $stripped = $value;
        }
        return strtolower(trim($stripped));
    }
}
