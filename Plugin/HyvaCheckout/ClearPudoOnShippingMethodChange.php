<?php

/**
 * Copyright © - LiquidLab Agency - All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Liquidlab\InnoShipHyva\Plugin\HyvaCheckout;

use Hyva\Checkout\Magewire\Checkout\Shipping\MethodList;
use Liquidlab\InnoShipHyva\Model\Config\PaymentRestrictionConfig;
use Magento\Checkout\Model\Session as SessionCheckout;
use Magento\Quote\Api\CartRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Clears a stale locker pickup point when the customer switches to a non-locker
 * shipping method.
 *
 * PudoPoint is rendered only inside the locker method's child block, so switching
 * locker → courier removes it from the DOM before its own listeners can react; the
 * locker address and innoship_pudo_id would otherwise persist on the quote, and
 * InnoShip's AWB (which routes by innoship_pudo_id) would still send the parcel to
 * the now-unwanted EasyBox. This after plugin on Hyvä's MethodList::updatedMethod()
 * runs server-side in the same roundtrip that saves the new method: when the new
 * method is not the locker carrier it drops the session PUDO, blanks the locker
 * address fields, clears the innoship_pudo_id / innoship_courier_id stamps from the
 * address and quote, and emits innoship-pudo-cleared so BillingDetails unlocks.
 *
 * Equitana has no Liquidlab_PlataEasybox, so this lives in the compat module. It is
 * a UX/consistency safeguard; the hard guarantee that a locker delivery stays
 * prepaid is Observer\ValidatePudoOnQuoteSubmit, which runs regardless.
 */
class ClearPudoOnShippingMethodChange
{
    private const INNOSHIP_PUDO_SESSION_KEY = 'innoship_selected_pudo_point';

    public function __construct(
        private readonly SessionCheckout $sessionCheckout,
        private readonly CartRepositoryInterface $quoteRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    public function afterUpdatedMethod(MethodList $subject, string $result, string $value): string
    {
        if (strpos($value, PaymentRestrictionConfig::LOCKER_CARRIER_CODE) !== false) {
            // Still a locker method — keep the pickup point.
            return $result;
        }

        try {
            $quote = $this->sessionCheckout->getQuote();
            $shippingAddress = $quote->getShippingAddress();

            $sessionPudo = $this->sessionCheckout->getData(self::INNOSHIP_PUDO_SESSION_KEY);
            $hasSessionPudo = is_array($sessionPudo) && !empty($sessionPudo['pudo_id']);
            $hasAddressPudo = (int)$shippingAddress->getInnoshipPudoId() > 0;

            if (!$hasSessionPudo && !$hasAddressPudo) {
                // No locker selected — nothing to clean up.
                return $result;
            }

            $this->sessionCheckout->unsetData(self::INNOSHIP_PUDO_SESSION_KEY);

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

            // BillingDetails re-enables "billing same as shipping" on its next boot
            // because LockBillingAsShippingForPudo subscribes to this event.
            $subject->emit('innoship-pudo-cleared');
        } catch (\Exception $e) {
            $this->logger->error(
                'InnoShipHyva: Failed to clear PUDO on shipping method change: ' . $e->getMessage()
            );
        }

        return $result;
    }
}
