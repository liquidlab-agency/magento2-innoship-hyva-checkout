<?php
/**
 * Copyright © - LiquidLab Agency - All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Liquidlab\InnoShipHyva\Plugin\HyvaCheckout;

use Hyva\Checkout\Magewire\Checkout\Payment\MethodList;

/**
 * Makes Hyvä's payment-method Magewire component refresh whenever the
 * shipping method changes, so per-carrier payment restrictions (see
 * {@see \Liquidlab\InnoShipHyva\Plugin\PaymentMethodRestriction}) take
 * effect immediately — not only after a full page reload.
 *
 * Hyvä's `Hyva\Checkout\Magewire\Checkout\Shipping\MethodList` already
 * emits `shipping_method_selected` after saving the new method, but the
 * payment-method component only listens for `*_address_saved` and coupon
 * events. We append our own listener via a plugin on `getListeners()`
 * (Magewire's `Listener` hydrator reads listeners through that getter,
 * so a plugin is the safest way to inject one without subclassing).
 */
class RefreshPaymentMethodsOnShippingChange
{
    /**
     * @param string[] $listeners
     * @return string[]
     */
    public function afterGetListeners(MethodList $subject, array $listeners): array
    {
        $listeners['shipping_method_selected'] = 'refresh';
        return $listeners;
    }
}
