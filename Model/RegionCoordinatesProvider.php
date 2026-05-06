<?php
/**
 * Copyright © - LiquidLab Agency - All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Liquidlab\InnoShipHyva\Model;

use Liquidlab\InnoShipHyva\Model\Config\Source\RomanianRegionCoordinates;
use Magento\Directory\Model\ResourceModel\Region\CollectionFactory as RegionCollectionFactory;

class RegionCoordinatesProvider
{
    /** @var array<int, array{lat: float, lng: float}>|null */
    private ?array $cache = null;

    public function __construct(
        private readonly RegionCollectionFactory $regionCollectionFactory,
        private readonly RomanianRegionCoordinates $romanianCoordinates
    ) {
    }

    /**
     * @return array{lat: float, lng: float}|null
     */
    public function getByRegionId(int $regionId): ?array
    {
        $map = $this->loadMap();
        return $map[$regionId] ?? null;
    }

    /**
     * @return array<int, array{lat: float, lng: float}>
     */
    private function loadMap(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $this->cache = [];
        $collection = $this->regionCollectionFactory->create();
        $collection->addCountryFilter('RO');

        foreach ($collection as $region) {
            $coordinates = $this->romanianCoordinates->getByCode((string)$region->getCode());
            if ($coordinates) {
                $this->cache[(int)$region->getRegionId()] = $coordinates;
            }
        }

        return $this->cache;
    }
}
