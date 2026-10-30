<?php
/**
 * Copyright © - LiquidLab Agency - All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Liquidlab\InnoShipHyva\Plugin\HyvaCheckout;

use Hyva\Checkout\Magewire\Checkout\AddressView\ShippingDetails\AddressForm;
use Magewirephp\Magewire\Model\Action\FireEvent;

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
 *
 * The same roundtrip must not autosave. Hyvä's `dehydrate()` saves the form
 * whenever the browser's `address` differs from the form, and on these events
 * the browser still holds the address from before the event (for a logged-in
 * customer, the saved address). Saving it wrote that address back over the
 * locker, so the order went out to the customer's saved address.
 *
 * Instead of saving, the roundtrip sends back the address `boot()` loaded from
 * the quote. It must not call `boot()` a second time: the form is shared for the
 * request, so a second `init()` makes every form modifier register its listeners
 * again, and each one logs a FormException ("A form modifier with name ... already
 * exists").
 */
class RefreshAddressFormOnPudoSelected
{
    public const PUDO_EVENTS = ['innoship-pudo-selected', 'innoship-pudo-cleared'];

    /**
     * The address `boot()` loaded from the quote, before hydration replaced it
     * with the browser's copy.
     */
    private ?array $bootedAddress = null;

    /**
     * @param string[] $listeners
     * @return string[]
     */
    public function afterGetListeners(AddressForm $subject, array $listeners): array
    {
        foreach (self::PUDO_EVENTS as $event) {
            $listeners[$event] = '$refresh';
        }
        return $listeners;
    }

    /**
     * Magewire runs `boot()` before hydration, which overwrites `address` with
     * the browser's copy unless the event is the request's only update.
     */
    public function afterBoot(AddressForm $subject, $result): void
    {
        $this->bootedAddress = $subject->address;
    }

    /**
     * On a PUDO event roundtrip, send back the address loaded from the quote
     * instead of letting Hyvä autosave the browser's stale address over it.
     */
    public function aroundDehydrate(AddressForm $subject, callable $proceed): void
    {
        if (!self::isPudoEventRequest($subject->getRequest('updates'))) {
            $proceed();
            return;
        }

        if ($this->bootedAddress !== null) {
            $subject->address = $this->bootedAddress;
        }
    }

    /**
     * @param mixed $updates the Magewire request updates
     */
    public static function isPudoEventRequest($updates): bool
    {
        if (!is_array($updates)) {
            return false;
        }

        foreach ($updates as $update) {
            if (($update['type'] ?? null) === FireEvent::ACTION
                && in_array($update['payload']['event'] ?? null, self::PUDO_EVENTS, true)
            ) {
                return true;
            }
        }

        return false;
    }
}
