<?php
/**
 * Copyright © - LiquidLab Agency - All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Liquidlab\InnoShipHyva\Plugin\HyvaCheckout;

use Hyva\Checkout\Magewire\Checkout\AddressView\ShippingDetails\AddressList;

/**
 * Re-renders Hyvä's saved-address list (logged-in customers with saved
 * addresses) when a PUDO is selected or cleared.
 *
 * `PudoPicker` unlinks the quote shipping address from the saved address
 * (customer_address_id = null), and `AddressList::boot()` derives the selected
 * card from that id. Without a refresh the old saved card stays selected after
 * a locker is picked, although the quote now ships to the locker.
 */
class RefreshAddressListOnPudoSelected
{
    /**
     * @param string[] $listeners
     * @return string[]
     */
    public function afterGetListeners(AddressList $subject, array $listeners): array
    {
        foreach (RefreshAddressFormOnPudoSelected::PUDO_EVENTS as $event) {
            $listeners[$event] = '$refresh';
        }
        return $listeners;
    }
}
