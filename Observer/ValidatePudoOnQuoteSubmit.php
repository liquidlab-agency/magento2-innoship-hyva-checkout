<?php

/**
 * Copyright © - LiquidLab Agency - All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Liquidlab\InnoShipHyva\Observer;

use Liquidlab\InnoShipHyva\Model\Config\PaymentRestrictionConfig;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Model\Quote;

/**
 * Hard server-side backstop for InnoShip locker deliveries, enforced on
 * sales_model_service_quote_submit_before — the single choke point every
 * order-creation path funnels through (Hyvä/Luma checkout, admin order create,
 * REST /carts/{id}/order, GraphQL placeOrder).
 *
 * A quote is a "locker delivery" when EITHER the shipping method is the locker
 * carrier OR a pickup point is stamped on the shipping address. The pudo is the
 * real signal: InnoShip's AWB routes the parcel by innoship_pudo_id regardless of
 * the shipping_method label, so a stamped pudo on a courier-labelled order still
 * ships to the locker.
 *
 * Two invariants are enforced, closing every bypass of the client-side Magewire
 * gate and the payment-list filter (stale $this->pudoId, spoofed
 * cascading-step-data, address edited after picking, or a payment/shipping reload
 * that drifts the payment list back to cash-on-delivery while a locker is still
 * stamped):
 *   1. A locker delivery must have a pickup point (innoship_pudo_id).
 *   2. A locker delivery must be paid with a method the merchant allows for the
 *      locker carrier (lockers are prepaid-only — cash-on-delivery to a card-only
 *      EasyBox is rejected).
 *
 * Runs AFTER checkout_submit_before, so CheckoutSubmitBefore's session re-stamp
 * gets its chance first; this only throws when the quote is genuinely invalid.
 */
class ValidatePudoOnQuoteSubmit implements ObserverInterface
{
    public function __construct(
        private readonly PaymentRestrictionConfig $paymentRestrictionConfig
    ) {
    }

    public function execute(Observer $observer): void
    {
        /** @var Quote|null $quote */
        $quote = $observer->getData('quote');
        if ($quote === null) {
            return;
        }

        $shippingAddress = $quote->getShippingAddress();
        $shippingMethod = (string)$shippingAddress->getShippingMethod();
        $pudoId = (int)$shippingAddress->getInnoshipPudoId();

        $isLockerMethod = strpos($shippingMethod, PaymentRestrictionConfig::LOCKER_CARRIER_CODE) !== false;
        if (!$isLockerMethod && $pudoId < 1) {
            // Not a locker delivery in any sense — nothing to enforce.
            return;
        }

        // Invariant 1: a locker delivery must have a pickup point.
        if ($pudoId < 1) {
            throw new LocalizedException(
                __('Please select a pickup point (locker) for your delivery before placing the order.')
            );
        }

        // Invariant 2: a locker delivery must use an allowed (prepaid) payment method.
        $allowed = $this->paymentRestrictionConfig->getAllowedPaymentMethods(
            PaymentRestrictionConfig::LOCKER_CARRIER_CODE,
            (int)$quote->getStoreId()
        );
        if ($allowed === []) {
            // Merchant configured no payment restriction for the locker carrier.
            return;
        }

        $paymentMethod = (string)$quote->getPayment()->getMethod();
        if (!in_array($paymentMethod, $allowed, true)) {
            throw new LocalizedException(
                __('Deliveries to a pickup point (locker) must be paid online. Please choose an online payment method.')
            );
        }
    }
}
