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
 * Reads per-carrier payment-method allowlists from the existing upstream
 * `InnoShip_InnoShip` admin fields. We do **not** introduce a new admin
 * section — the merchant configures restrictions in the same place they
 * always have, under each carrier's shipping-method group:
 *
 *   Stores → Configuration → Sales → Shipping Methods
 *     → InnoShip Locker → "Allow Payment Methods"
 *
 * Upstream currently ships only the locker carrier's field
 * (`innoship_cargus_go_payment_restriction`); the full-courier carrier
 * has no equivalent setting and is therefore unrestricted on our side.
 * Adding a new carrier here is a one-line change in `self::CONFIG_PATHS`.
 *
 * The class also fixes the empty-config trap in upstream's reader:
 * `explode(',', '')` produces `['']`, which `!empty()` reports as truthy,
 * so an unconfigured field used to strip every payment method.
 */
class PaymentRestrictionConfig
{
    /**
     * Carrier code → store-config path of the allowlist multiselect.
     *
     * @var array<string, string>
     */
    private const CONFIG_PATHS = [
        'innoshipcargusgo' => 'carriers/innoshipcargusgo/innoship_cargus_go_payment_restriction',
    ];

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * Whether this carrier has a configurable payment-method allowlist.
     */
    public function isRestrictionSupported(string $carrierCode): bool
    {
        return isset(self::CONFIG_PATHS[$carrierCode]);
    }

    /**
     * Return the allowlist of payment-method codes for the given carrier.
     * An empty array means "no restriction configured — allow all".
     *
     * @return string[]
     */
    public function getAllowedPaymentMethods(string $carrierCode, ?int $storeId = null): array
    {
        if (!$this->isRestrictionSupported($carrierCode)) {
            return [];
        }

        $raw = (string)$this->scopeConfig->getValue(
            self::CONFIG_PATHS[$carrierCode],
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        if ($raw === '') {
            return [];
        }

        // array_filter without a callback drops empty strings, so an empty
        // admin multi-select returns [] (= "no restriction") rather than [''].
        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }
}
