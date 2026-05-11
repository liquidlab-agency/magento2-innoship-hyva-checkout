<?php
/**
 * Copyright © - LiquidLab Agency - All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Liquidlab\InnoShipHyva\Plugin;

use Liquidlab\InnoShipHyva\Model\Config\PaymentRestrictionConfig;
use Magento\Payment\Model\MethodInterface;
use Magento\Payment\Model\MethodList;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Filters the available payment methods at checkout against the per-carrier
 * allowlist stored in the upstream `InnoShip_InnoShip` admin settings (see
 * {@see PaymentRestrictionConfig}).
 *
 * Replaces the upstream `InnoShip\InnoShip\Plugin\Model\PaymentMethodAvailable`
 * (disabled in our `etc/frontend/di.xml`), which:
 *   - hardcoded `=== "innoshipcargusgo_innoshipcargusgo_1"`, so any extension
 *     that normalised the shipping_method silently broke the filter;
 *   - stripped every payment method when the admin field was empty
 *     (`!empty([''])` trap).
 *
 * Our version:
 *   - extracts the carrier code with `strpos`, so it tolerates any suffix
 *     in the method string;
 *   - returns the unfiltered list when no allowlist is configured;
 *   - reuses the same admin field upstream already exposes, so the merchant
 *     does not have to reconfigure anything.
 */
class PaymentMethodRestriction
{
    public function __construct(
        private readonly PaymentRestrictionConfig $config,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param MethodInterface[] $availableMethods
     * @return MethodInterface[]
     */
    public function afterGetAvailableMethods(
        MethodList $subject,
        array $availableMethods,
        ?CartInterface $quote = null
    ): array {
        if ($quote === null) {
            return $availableMethods;
        }

        $shippingAddress = $quote->getShippingAddress();
        if (!$shippingAddress) {
            return $availableMethods;
        }

        $shippingMethod = (string)$shippingAddress->getShippingMethod();
        if ($shippingMethod === '') {
            return $availableMethods;
        }

        $carrierCode = $this->extractCarrierCode($shippingMethod);
        if (!$this->config->isRestrictionSupported($carrierCode)) {
            // Not an InnoShip carrier we filter on — pass through unchanged.
            return $availableMethods;
        }

        try {
            $storeId = (int)$this->storeManager->getStore()->getId();
        } catch (\Exception $e) {
            $this->logger->warning(
                'InnoShipHyva PaymentMethodRestriction: could not resolve store, skipping. '
                . $e->getMessage()
            );
            return $availableMethods;
        }

        $allowlist = $this->config->getAllowedPaymentMethods($carrierCode, $storeId);
        if ($allowlist === []) {
            // No restriction configured for this carrier — allow all.
            return $availableMethods;
        }

        return array_values(array_filter(
            $availableMethods,
            static fn(MethodInterface $method) => in_array($method->getCode(), $allowlist, true)
        ));
    }

    /**
     * Extract the carrier code from a shipping_method string.
     *
     * Magento stores shipping_method as `<carrierCode>_<methodCode>` where
     * the carrier code is the segment before the FIRST underscore. Examples:
     *   `flatrate_flatrate`                        → `flatrate`
     *   `innoship_innoship_1`                      → `innoship`
     *   `innoshipcargusgo_innoshipcargusgo_1`      → `innoshipcargusgo`
     */
    private function extractCarrierCode(string $shippingMethod): string
    {
        $separatorPos = strpos($shippingMethod, '_');
        return $separatorPos === false ? $shippingMethod : substr($shippingMethod, 0, $separatorPos);
    }
}
