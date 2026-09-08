<?php

/**
 * Copyright © - LiquidLab Agency - All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Liquidlab\InnoShipHyva\Plugin\HyvaCheckout;

use Liquidlab\InnoShipHyva\Model\Config\PaymentRestrictionConfig;
use Magento\Checkout\Model\Session as SessionCheckout;
use Psr\Log\LoggerInterface;

/**
 * Stops Hyvä's "auto-select first available shipping method" from silently
 * clobbering a locker the customer has already chosen.
 *
 * Hyva\Checkout\Observer\Frontend\HyvaCheckoutHyvaCheckoutInitAfter::execute()
 * runs on every hyva_checkout_init_after — which the payment MethodList dispatches
 * on EVERY payment-method change (magento2-hyva-checkout Magewire/Checkout/Payment/
 * MethodList::updatedMethod) — and, when
 * enable_first_shipping_method_available=1, calls the private
 * setFirstAvailableShippingMethod(). That helper takes the FIRST non-error rate
 * from estimateByExtendedAddress() and does not preserve the current choice, so a
 * customer on the InnoShip locker who merely switches payment gets flipped to the
 * first courier rate. Because the flip goes straight through
 * ShippingMethodManagementInterface::set(), the locker's innoship_pudo_id is left
 * stamped on a courier-labelled quote — the EQT-19 shape.
 *
 * setFirstAvailableShippingMethod() is private and cannot be intercepted, but its
 * single gate — SystemConfigExperimental::enableFirstAvailableShippingMethod() — is
 * public and (verified) read nowhere else. Returning false from it for this one
 * roundtrip skips the auto-select entirely, so the locker (and its pudo) survive.
 * We suppress ONLY while a locker method is currently selected; every other cart
 * keeps Hyvä's default auto-select behaviour. Preventing the flip here means the
 * stale-pudo state is never created on this path, so the ClearStalePudoOnShipping-
 * MethodSet backstop never has to race it.
 *
 * Plugged on the canonical Developer\SystemConfigExperimental; plugin inheritance
 * carries it to the deprecated ...HyvaThemes\SystemConfigExperimental alias the
 * observer actually instantiates.
 */
class SuppressShippingAutoSelectForLocker
{
    public function __construct(
        private readonly SessionCheckout $sessionCheckout,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * $subject is Hyvä's SystemConfigExperimental, typed loosely as object to avoid a
     * hard dependency on that namespaced config class; only the boolean it returned
     * is re-evaluated here.
     */
    public function afterEnableFirstAvailableShippingMethod(object $subject, bool $result): bool
    {
        if ($result === false) {
            // Merchant already has auto-select-first-shipping off — nothing to do.
            return $result;
        }

        try {
            $quote = $this->sessionCheckout->getQuote();
            $shippingMethod = (string)$quote->getShippingAddress()->getShippingMethod();

            if (strpos($shippingMethod, PaymentRestrictionConfig::LOCKER_CARRIER_CODE) !== false) {
                // A locker is explicitly selected — do not let Hyvä auto-flip it to
                // the first courier rate (and strand the pickup point) on this
                // roundtrip. The customer can still switch away manually, which goes
                // through MethodList::updatedMethod + ClearPudoOnShippingMethodChange.
                return false;
            }
        } catch (\Exception $e) {
            // Could not resolve the quote (e.g. no active checkout session) — leave
            // Hyvä's behaviour untouched.
            $this->logger->warning(
                'InnoShipHyva: SuppressShippingAutoSelectForLocker could not read quote: ' . $e->getMessage()
            );
        }

        return $result;
    }
}
