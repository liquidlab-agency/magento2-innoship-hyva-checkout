<?php

/**
 * Copyright © - LiquidLab Agency - All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Liquidlab\InnoShipHyva\Test\Unit\Observer;

use Liquidlab\InnoShipHyva\Model\Config\PaymentRestrictionConfig;
use Liquidlab\InnoShipHyva\Observer\ValidatePudoOnQuoteSubmit;
use Magento\Framework\DataObject;
use Magento\Framework\Event\Observer;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Hard server-side backstop coverage. Two invariants, both enforced on
 * sales_model_service_quote_submit_before:
 *   1. a locker delivery must have a pickup point (innoship_pudo_id);
 *   2. a locker delivery must be paid with an allowed (prepaid) method.
 *
 * "Locker delivery" = shipping method is the locker carrier OR a pudo is stamped.
 * The pudo is decisive because InnoShip's AWB routes by innoship_pudo_id — a
 * courier-labelled order that still carries a pudo (the EQT-19 order 1000012201
 * shape: shipping innoship_1 + pudo 679650 + cash-on-delivery) ships to a card-only
 * EasyBox and must be rejected.
 *
 * Quote/address/payment are real DataObjects so the magic getters the observer
 * relies on are exercised with zero constructor dependencies.
 */
class ValidatePudoOnQuoteSubmitTest extends TestCase
{
    private const LOCKER_METHOD = 'innoshipcargusgo_innoshipcargusgo_1';
    private const COURIER_METHOD = 'innoship_innoship_1';

    private PaymentRestrictionConfig&MockObject $paymentRestrictionConfig;
    private ValidatePudoOnQuoteSubmit $observer;

    protected function setUp(): void
    {
        $this->paymentRestrictionConfig = $this->createMock(PaymentRestrictionConfig::class);
        // Default: locker carrier allows only online payment (matches equitana config).
        $this->paymentRestrictionConfig
            ->method('getAllowedPaymentMethods')
            ->willReturn(['eppay']);

        $this->observer = new ValidatePudoOnQuoteSubmit($this->paymentRestrictionConfig);
    }

    public function testThrowsWhenLockerMethodHasNoPudo(): void
    {
        $event = $this->makeEvent($this->makeQuote(self::LOCKER_METHOD, null, 'eppay'));

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Please select a pickup point (locker)');

        $this->observer->execute($event);
    }

    public function testThrowsWhenLockerMethodHasPudoButCashOnDelivery(): void
    {
        $event = $this->makeEvent($this->makeQuote(self::LOCKER_METHOD, 679650, 'cashondelivery'));

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('must be paid online');

        $this->observer->execute($event);
    }

    /**
     * The EQT-19 order 1000012201 shape: the shipping method was flipped to the
     * courier (innoship_1) but a locker pudo (679650) stayed stamped and payment is
     * cash-on-delivery. The AWB would still ship to the card-only EasyBox — reject.
     */
    public function testThrowsWhenCourierMethodStillCarriesPudoWithCashOnDelivery(): void
    {
        $event = $this->makeEvent($this->makeQuote(self::COURIER_METHOD, 679650, 'cashondelivery'));

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('must be paid online');

        $this->observer->execute($event);
    }

    public function testDoesNotThrowWhenLockerHasPudoAndOnlinePayment(): void
    {
        $this->expectNotToPerformAssertions();

        $event = $this->makeEvent($this->makeQuote(self::LOCKER_METHOD, 679650, 'eppay'));
        $this->observer->execute($event);
    }

    public function testDoesNotThrowForCourierWithoutPudo(): void
    {
        $this->expectNotToPerformAssertions();

        $event = $this->makeEvent($this->makeQuote(self::COURIER_METHOD, null, 'cashondelivery'));
        $this->observer->execute($event);
    }

    public function testDoesNotThrowForNonInnoShipMethodWithoutPudo(): void
    {
        $this->expectNotToPerformAssertions();

        $event = $this->makeEvent($this->makeQuote('flatrate_flatrate', null, 'cashondelivery'));
        $this->observer->execute($event);
    }

    public function testNoOpWhenQuoteMissing(): void
    {
        $this->expectNotToPerformAssertions();

        $this->observer->execute(new Observer());
    }

    private function makeQuote(string $shippingMethod, ?int $pudoId, string $paymentMethod): DataObject
    {
        $address = new DataObject([
            'shipping_method'  => $shippingMethod,
            'innoship_pudo_id' => $pudoId,
        ]);

        return new DataObject([
            'store_id'         => 1,
            'shipping_address' => $address,
            'payment'          => new DataObject(['method' => $paymentMethod]),
        ]);
    }

    private function makeEvent(DataObject $quote): Observer
    {
        $observer = new Observer();
        $observer->setData('quote', $quote);

        return $observer;
    }
}
