<?php
/**
 * Copyright © - LiquidLab Agency - All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Liquidlab\InnoShipHyva\Plugin\HyvaCheckout;

use Hyva\Checkout\Magewire\Checkout\AddressView\BillingDetails;
use Magento\Checkout\Model\Session as SessionCheckout;

/**
 * Locks the "billing same as shipping" checkbox to OFF whenever a PUDO is
 * selected, because we cannot invoice a customer to a pickup-point address.
 *
 * Three hooks on Hyvä's `BillingDetails` Magewire component:
 *
 *   1. afterBoot()  → force `$billingAsShipping = false` if a PUDO is in
 *      session. Runs on every Magewire roundtrip; defensive against any
 *      flow that flipped the underlying flag.
 *
 *   2. beforeUpdatedBillingAsShipping() → if the customer tried to flip
 *      the checkbox while a PUDO is selected, coerce the value back to
 *      false BEFORE the upstream method runs. Belt-and-suspenders for #1;
 *      stops a stray request from changing the quote.
 *
 *   3. afterGetListeners() → subscribe BillingDetails to `innoship-pudo-selected`
 *      and `innoship-pudo-cleared` (Magewire's `$refresh` handler), so the
 *      component re-runs boot() the moment the PUDO state changes.
 *
 * The visible "disabled" attribute on the checkbox is handled separately
 * by the template override at view/frontend/templates/checkout/address-view/billing-details.phtml.
 */
class LockBillingAsShippingForPudo
{
    private const INNOSHIP_PUDO_SESSION_KEY = 'innoship_selected_pudo_point';

    public function __construct(
        private readonly SessionCheckout $sessionCheckout
    ) {
    }

    public function afterBoot(BillingDetails $subject, $result): void
    {
        if ($this->isPudoSelected()) {
            $subject->billingAsShipping = false;
        }
    }

    public function beforeUpdatedBillingAsShipping(BillingDetails $subject, bool $value): array
    {
        if ($this->isPudoSelected()) {
            $value = false;
        }
        return [$value];
    }

    /**
     * @param string[] $listeners
     * @return string[]
     */
    public function afterGetListeners(BillingDetails $subject, array $listeners): array
    {
        $listeners['innoship-pudo-selected'] = '$refresh';
        $listeners['innoship-pudo-cleared'] = '$refresh';
        return $listeners;
    }

    private function isPudoSelected(): bool
    {
        $pudoData = $this->sessionCheckout->getData(self::INNOSHIP_PUDO_SESSION_KEY);
        return is_array($pudoData) && !empty($pudoData['pudo_id']);
    }
}
