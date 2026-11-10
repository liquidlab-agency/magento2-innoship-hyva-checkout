<?php

/**
 * Copyright © - LiquidLab Agency - All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Liquidlab\InnoShipHyva\Model\Config;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Whether the shipping address is read-only while a locker is the destination.
 *
 *   Stores → Configuration → Sales → Shipping Methods
 *     → InnoShip Locker → "Lock the Shipping Address to the Pickup Point"
 *
 * On by default (etc/config.xml).
 */
class LockerAddressConfig
{
    public const XML_PATH_LOCK_ADDRESS = 'carriers/innoshipcargusgo/lock_locker_address';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function isAddressLocked(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_LOCK_ADDRESS,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }
}
