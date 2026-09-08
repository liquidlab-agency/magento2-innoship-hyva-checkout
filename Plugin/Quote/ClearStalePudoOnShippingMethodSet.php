<?php

/**
 * Copyright © - LiquidLab Agency - All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Liquidlab\InnoShipHyva\Plugin\Quote;

use Liquidlab\InnoShipHyva\Model\Config\PaymentRestrictionConfig;
use Magento\Checkout\Model\Session as SessionCheckout;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\ShippingMethodManagementInterface;
use Magento\Quote\Model\Quote;
use Psr\Log\LoggerInterface;

/**
 * Choke-point backstop: clears a stale locker pickup point whenever the shipping
 * method is set to a non-locker carrier through the low-level quote service.
 *
 * ClearPudoOnShippingMethodChange only covers the storefront path where the
 * customer clicks a shipping-method radio (Hyvä's Magewire
 * MethodList::updatedMethod). It does NOT cover the many callers that reach
 * Magento\Quote\Api\ShippingMethodManagementInterface::set() directly and never
 * touch that Magewire method:
 *
 *   - Hyvä's own auto-select-first-shipping
 *     (Observer\Frontend\HyvaCheckoutHyvaCheckoutInitAfter::setFirstAvailableShippingMethod),
 *     which fires on every hyva_checkout_init_after — including every payment-method
 *     change — and silently flips Locker → Curier while leaving innoship_pudo_id
 *     stamped. That stale pudo makes PaymentMethodRestriction hide cash-on-delivery
 *     on the (now courier) order and routes the AWB back to the EasyBox.
 *   - REST  /carts/{id}/selected-shipping-method and /carts/{id}/shipping-information
 *   - GraphQL setShippingMethodsOnCart
 *   - admin order create/edit, and any custom programmatic caller
 *
 * set() itself only writes shipping_method and re-saves the quote; it knows nothing
 * about innoship_pudo_id. Registered globally (etc/di.xml) so every one of those
 * paths is covered. On the storefront auto-select path this is normally pre-empted
 * by SuppressShippingAutoSelectForLocker (which stops the flip from happening at
 * all); this plugin is the layer below it for every non-storefront caller.
 *
 * Mirrors ClearPudoOnShippingMethodChange's cleanup so behaviour is identical
 * whichever layer fires. Idempotent: no pudo stamped and no session pudo => no-op.
 */
class ClearStalePudoOnShippingMethodSet
{
    private const INNOSHIP_PUDO_SESSION_KEY = 'innoship_selected_pudo_point';

    public function __construct(
        private readonly CartRepositoryInterface $quoteRepository,
        private readonly SessionCheckout $sessionCheckout,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param ShippingMethodManagementInterface $subject
     * @param bool $result
     * @param int|string $cartId
     * @param string $carrierCode
     * @param string $methodCode
     */
    public function afterSet(
        ShippingMethodManagementInterface $subject,
        $result,
        $cartId,
        $carrierCode,
        $methodCode
    ): bool {
        if ((string)$carrierCode === PaymentRestrictionConfig::LOCKER_CARRIER_CODE) {
            // Setting (or keeping) the locker carrier — the pickup point stays.
            return (bool)$result;
        }

        try {
            // Load by cart id rather than the checkout session so REST/GraphQL/admin
            // callers clean the correct quote. Within one request this is the same
            // instance set() just saved, so the new (non-locker) method is visible.
            /** @var Quote $quote */
            $quote = $this->quoteRepository->getActive((int)$cartId);
            $shippingAddress = $quote->getShippingAddress();
            $hasAddressPudo = (int)$shippingAddress->getInnoshipPudoId() > 0;

            $sessionPudo = $this->readSessionPudo();
            $hasSessionPudo = is_array($sessionPudo) && !empty($sessionPudo['pudo_id']);

            if (!$hasAddressPudo && !$hasSessionPudo) {
                // No locker in play — nothing to clean up.
                return (bool)$result;
            }

            if ($hasSessionPudo) {
                // Drop the UI-side selection so PudoPicker::mount() does not
                // reconcile the locker back onto this now-courier quote.
                $this->clearSessionPudo();
            }

            if ($hasAddressPudo) {
                // The stored shipping address is the locker's — blank it so the
                // customer enters their real courier delivery address.
                $shippingAddress->setCompany('');
                $shippingAddress->setStreet([]);
                $shippingAddress->setCity('');
                $shippingAddress->setPostcode('');
                $shippingAddress->setRegion('');
                $shippingAddress->setRegionId(0);
            }

            $shippingAddress->setData('innoship_pudo_id', null);
            $shippingAddress->setData('innoship_courier_id', null);
            $quote->setData('innoship_pudo_id', null);
            $quote->setData('innoship_courier_id', null);
            $this->quoteRepository->save($quote);
        } catch (NoSuchEntityException $e) {
            // Cart no longer active/found — nothing to clean.
            return (bool)$result;
        } catch (\Exception $e) {
            $this->logger->error(
                'InnoShipHyva: Failed to clear stale PUDO on ShippingMethodManagement::set: ' . $e->getMessage()
            );
        }

        return (bool)$result;
    }

    /**
     * Read the checkout-session pudo, tolerating areas where the checkout
     * session is not usable (admin, some webapi contexts).
     *
     * @return array<string, mixed>|null
     */
    private function readSessionPudo(): ?array
    {
        try {
            $data = $this->sessionCheckout->getData(self::INNOSHIP_PUDO_SESSION_KEY);

            return is_array($data) ? $data : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    private function clearSessionPudo(): void
    {
        try {
            $this->sessionCheckout->unsetData(self::INNOSHIP_PUDO_SESSION_KEY);
        } catch (\Exception $e) {
            // Non-fatal: the address-level clear below is the authoritative fix.
            $this->logger->warning(
                'InnoShipHyva: could not clear checkout-session pudo on method set: ' . $e->getMessage()
            );
        }
    }
}
