<?php
/**
 * Copyright © - LiquidLab Agency - All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Liquidlab\InnoShipHyva\Model\ResourceModel\Pudo;

use Liquidlab\InnoShipHyva\Model\Pudo as PudoModel;
use Liquidlab\InnoShipHyva\Model\ResourceModel\Pudo as PudoResource;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected $_idFieldName = 'id';

    protected function _construct()
    {
        $this->_init(PudoModel::class, PudoResource::class);
    }
}
