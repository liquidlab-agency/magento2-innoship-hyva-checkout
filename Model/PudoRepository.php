<?php
/**
 * Copyright © - LiquidLab Agency - All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Liquidlab\InnoShipHyva\Model;

use Liquidlab\InnoShipHyva\Api\Data\PudoInterface;
use Liquidlab\InnoShipHyva\Api\PudoRepositoryInterface;
use Liquidlab\InnoShipHyva\Model\ResourceModel\Pudo\CollectionFactory;
use Magento\Framework\Exception\NoSuchEntityException;

class PudoRepository implements PudoRepositoryInterface
{
    public function __construct(
        private readonly CollectionFactory $collectionFactory
    ) {
    }

    public function getByPudoId(int $pudoId): PudoInterface
    {
        if ($pudoId <= 0) {
            throw new NoSuchEntityException(__('Invalid PUDO ID.'));
        }

        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('pudo_id', $pudoId)->setPageSize(1);

        $item = $collection->getFirstItem();
        if (!$item->getId()) {
            throw new NoSuchEntityException(__('PUDO with ID %1 not found.', $pudoId));
        }

        return $item;
    }

    public function getCounties(): array
    {
        $collection = $this->collectionFactory->create();
        $select = $collection->getSelect()
            ->reset(\Magento\Framework\DB\Select::COLUMNS)
            ->columns(['countyName'])
            ->distinct(true)
            ->where('isActive = ?', 1)
            ->where('countyName IS NOT NULL')
            ->where('countyName != ?', '')
            ->order('countyName ASC');

        return $collection->getConnection()->fetchCol($select);
    }

    public function getCitiesByCounty(string $county): array
    {
        if ($county === '') {
            return [];
        }

        $collection = $this->collectionFactory->create();
        $select = $collection->getSelect()
            ->reset(\Magento\Framework\DB\Select::COLUMNS)
            ->columns(['localityName'])
            ->distinct(true)
            ->where('isActive = ?', 1)
            ->where('countyName = ?', $county)
            ->where('localityName IS NOT NULL')
            ->where('localityName != ?', '')
            ->order('localityName ASC');

        return $collection->getConnection()->fetchCol($select);
    }

    public function getActivePudoPoints(?string $county = null, ?string $city = null): array
    {
        $collection = $this->collectionFactory->create();
        $collection
            ->addFieldToFilter('isActive', 1)
            ->addFieldToFilter('latitude', ['neq' => 0]);

        if ($county !== null && $county !== '') {
            $collection->addFieldToFilter('countyName', $county);
        }
        if ($city !== null && $city !== '') {
            $collection->addFieldToFilter('localityName', $city);
        }

        return array_values($collection->getItems());
    }
}
