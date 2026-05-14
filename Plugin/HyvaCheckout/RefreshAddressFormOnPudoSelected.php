<?php
/**
 * Copyright © - LiquidLab Agency - All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Liquidlab\InnoShipHyva\Plugin\HyvaCheckout;

use Hyva\Checkout\Magewire\Checkout\AddressView\ShippingDetails\AddressForm;

/**
 * Makes Hyvä's shipping-address form re-hydrate from the quote on two events:
 *
 *   • `innoship-pudo-selected` — emitted by `PudoPicker::selectPudoPoint()` after
 *     the PUDO address is stamped onto the quote. The form re-runs `boot()` and
 *     the customer immediately sees the PUDO's company/street/city/postcode.
 *
 *   • `innoship-pudo-cleared` — emitted by `PudoPoint::clearPudoPoint()` after
 *     the PUDO stamp is removed and the address fields are blanked (when the
 *     address still matched the locker). The form re-runs `boot()` and the
 *     customer sees empty inputs, prompting them to enter a real delivery address.
 *
 * Hyvä's form only listens for `edit`/`create` events
 * ({@see \Hyva\Checkout\Magewire\Checkout\AddressView\AbstractMagewireAddressForm}
 * `$listeners`), so without this plugin the inputs sit stale until a page reload.
 * By mapping our events to Magewire's built-in `$refresh` handler the component
 * re-imports `$address` from the now-updated quote on each event.
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
        $listeners['innoship-pudo-cleared']  = '$refresh';
        return $listeners;
    }
}
