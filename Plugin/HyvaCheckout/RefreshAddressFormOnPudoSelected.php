<?php
/**
 * Copyright © - LiquidLab Agency - All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Liquidlab\InnoShipHyva\Plugin\HyvaCheckout;

use Hyva\Checkout\Magewire\Checkout\AddressView\ShippingDetails\AddressForm;

/**
 * Makes Hyvä's shipping-address form re-hydrate from the quote whenever a
 * PUDO is selected, so the customer immediately sees the PUDO's company,
 * street, city and postcode in the visible form inputs instead of having
 * to refresh the page.
 *
 * The server-side overwrite of the quote shipping address already happens
 * in {@see \Liquidlab\InnoShipHyva\Magewire\PudoPicker::updateShippingAddressWithPudo()};
 * `PudoPicker::selectPudoPoint()` then emits `innoship-pudo-selected`.
 *
 * Hyvä's form only listens for `edit`/`create` events
 * ({@see \Hyva\Checkout\Magewire\Checkout\AddressView\AbstractMagewireAddressForm}
 * `$listeners` at line 59), so without this plugin the inputs sit with
 * whatever the customer last typed. By mapping our event to Magewire's
 * built-in `$refresh` handler, the component re-runs `boot()` and
 * `$address` is re-imported from the now-updated quote
 * (`AbstractMagewireAddressForm::boot()` line 90).
 */
class RefreshAddressFormOnPudoSelected
{
    /**
     * @param string[] $listeners
     * @return string[]
     */
    public function afterGetListeners(AddressForm $subject, array $listeners): array
    {
        $listeners['innoship-pudo-selected'] = '$refresh';
        return $listeners;
    }
}
