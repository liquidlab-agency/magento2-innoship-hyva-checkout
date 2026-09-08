<?php

/**
 * Copyright © - LiquidLab Agency - All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Liquidlab\InnoShipHyva\Plugin\HyvaCheckout;

use Magento\Checkout\Model\Session as SessionCheckout;
use Psr\Log\LoggerInterface;

/**
 * Stops Hyvä's "auto-select first available shipping method" from silently
 * clobbering a shipping method the customer has already chosen.
 *
 * Hyva\Checkout\Observer\Frontend\HyvaCheckoutHyvaCheckoutInitAfter::execute()
 * runs on every hyva_checkout_init_after — which the payment MethodList dispatches
 * on EVERY payment-method change (magento2-hyva-checkout Magewire/Checkout/Payment/
 * MethodList::updatedMethod) — and, when enable_first_shipping_method_available=1,
 * calls the private setFirstAvailableShippingMethod(). That helper takes the FIRST
 * non-error rate from estimateByExtendedAddress() and writes it straight through
 * ShippingMethodManagementInterface::set() WITHOUT preserving the current choice.
 * The first rate on this store is the cheapest one (the InnoShip locker), so a mere
 * payment-method change silently rewrites the quote's shipping method. Two ways it
 * bites, both keyed on the same clobber:
 *   - a customer on the locker gets flipped to the first courier rate, stranding the
 *     locker's innoship_pudo_id on a courier-labelled quote (the EQT-19 shape);
 *   - a customer on the courier gets flipped to the first (cheapest) LOCKER rate,
 *     which then restricts payment to the locker's prepaid-only allowlist and drops
 *     cash-on-delivery (Ramburs) from the payment list.
 *
 * setFirstAvailableShippingMethod() is private and cannot be intercepted, but its
 * single gate — SystemConfigExperimental::enableFirstAvailableShippingMethod() — is
 * public and (verified) read nowhere else. Returning false from it for the current
 * roundtrip skips the auto-select entirely. We suppress it whenever a method is
 * ALREADY selected (locker OR courier): the feature exists to pick a method for a
 * method-LESS quote, not to override the customer's explicit choice, so a cart with
 * no method yet still gets Hyvä's default behaviour. The customer can always switch
 * method manually, which goes through MethodList::updatedMethod +
 * ClearPudoOnShippingMethodChange.
 *
 * Plugged on the canonical Developer\SystemConfigExperimental; plugin inheritance
 * carries it to the deprecated ...HyvaThemes\SystemConfigExperimental alias the
 * observer actually instantiates.
 */
class PreserveChosenShippingMethod
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

            if ($shippingMethod !== '') {
                // A shipping method is already explicitly selected — do not let Hyvä's
                // auto-select-first-shipping clobber it on this roundtrip. This preserves
                // BOTH a chosen locker (whose pudo would otherwise be stranded on a
                // courier quote) and a chosen courier (which would otherwise be flipped
                // to the first, cheapest locker rate, removing cash-on-delivery).
                return false;
            }
        } catch (\Exception $e) {
            // Could not resolve the quote (e.g. no active checkout session) — leave
            // Hyvä's behaviour untouched.
            $this->logger->warning(
                'InnoShipHyva: PreserveChosenShippingMethod could not read quote: ' . $e->getMessage()
            );
        }

        return $result;
    }
}
