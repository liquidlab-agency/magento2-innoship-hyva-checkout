<?php

/**
 * Copyright © - LiquidLab Agency - All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Liquidlab\InnoShipHyva\Model\Form\EntityFormModifier;

use Hyva\Checkout\Model\Form\EntityFormElementInterface;
use Hyva\Checkout\Model\Form\EntityFormInterface;
use Hyva\Checkout\Model\Form\EntityFormModifierInterface;
use Liquidlab\InnoShipHyva\Model\Config\LockerAddressConfig;
use Liquidlab\InnoShipHyva\Model\Config\PaymentRestrictionConfig;
use Magento\Checkout\Model\Session as SessionCheckout;
use Magento\Framework\DataObject;
use Psr\Log\LoggerInterface;

/**
 * Makes the shipping address read-only while a locker is the destination.
 *
 * `PudoPicker::updateShippingAddressWithPudo()` stamps the locker's street, city,
 * postcode, county, country and name onto the quote shipping address. Left
 * editable, the customer can change it, and the parcel still goes to the locker
 * (InnoShip routes by innoship_pudo_id) while the order shows another address.
 *
 * On every `form:build` the fields that hold a locker value get the disabled
 * state; Hyvä's WithBaseAttributesModifier (sortOrder 900) turns that into the
 * `disabled` attribute, and both Hyvä validators skip disabled fields. The form
 * re-renders on `innoship-pudo-selected` / `innoship-pudo-cleared`
 * ({@see \Liquidlab\InnoShipHyva\Plugin\HyvaCheckout\RefreshAddressFormOnPudoSelected}),
 * so the lock follows the selection without any browser script.
 *
 * Keyed on the QUOTE (stamped pudo + locker method), never the session or the
 * PudoPoint component (EQT-19). A field the locker left empty stays editable, so
 * the customer can still fill it (for example a county RegionResolver could not
 * match). CheckoutSubmitBefore writes the locker address back at order submit, so
 * this lock is the UI half; the order never relies on it.
 */
class LockLockerAddressFields implements EntityFormModifierInterface
{
    public const COMMENT = 'This is the pickup point\'s address. To change it, choose another pickup point.';

    /**
     * @param string[] $lockedFields field names, in the order the comment looks for a text field
     */
    public function __construct(
        private readonly SessionCheckout $sessionCheckout,
        private readonly LockerAddressConfig $lockerAddressConfig,
        private readonly LoggerInterface $logger,
        private readonly array $lockedFields = [
            'street',
            'city',
            'postcode',
            'company',
            'country_id',
            'region',
            'region_id',
        ]
    ) {
    }

    public function apply(EntityFormInterface $form): EntityFormInterface
    {
        $form->registerModificationListener(
            'innoshipHyvaLockLockerAddress',
            'form:build',
            [$this, 'lockLockerAddress']
        );

        return $form;
    }

    public function lockLockerAddress(EntityFormInterface $form): EntityFormInterface
    {
        $address = $this->getLockerAddress();
        if ($address === null) {
            return $form;
        }

        $hasComment = false;

        foreach ($this->lockedFields as $name) {
            $field = $form->getField($name);
            if ($field === null || !$this->hasValue($address, $name)) {
                continue;
            }

            $this->disable($field);

            // Hyvä's select template renders no comment, so it goes on the first text field.
            if (!$hasComment && $field->getFrontendInput() !== 'select') {
                $field->setData(EntityFormElementInterface::COMMENT, self::COMMENT);
                $hasComment = true;
            }
        }

        return $form;
    }

    /**
     * The quote shipping address, when it is a locker address and the lock is on.
     */
    private function getLockerAddress(): ?DataObject
    {
        try {
            $quote = $this->sessionCheckout->getQuote();
            if (!$this->lockerAddressConfig->isAddressLocked((int)$quote->getStoreId())) {
                return null;
            }

            $address = $quote->getShippingAddress();
            $isLockerMethod = str_contains(
                (string)$address->getShippingMethod(),
                PaymentRestrictionConfig::LOCKER_CARRIER_CODE
            );

            return $isLockerMethod && (int)$address->getInnoshipPudoId() > 0 ? $address : null;
        } catch (\Exception $e) {
            $this->logger->error('InnoShipHyva: could not read the quote to lock the locker address: ' . $e->getMessage());
            return null;
        }
    }

    private function hasValue(DataObject $address, string $name): bool
    {
        if ($name === 'street') {
            $street = $address->getData('street');
            $lines = is_array($street) ? $street : explode("\n", (string)$street);

            return array_filter($lines, static fn ($line): bool => trim((string)$line) !== '') !== [];
        }

        // Hyvä's region field holds the region id when the country has a region list.
        if ($name === 'region') {
            return (int)$address->getData('region_id') > 0 || (string)$address->getData('region') !== '';
        }

        return (string)$address->getData($name) !== '';
    }

    private function disable(EntityFormElementInterface $element): void
    {
        $element->disable();

        // Street lines are relatives of the street field and carry their own state.
        foreach ($element->getRelatives() as $relative) {
            $this->disable($relative);
        }
    }
}
