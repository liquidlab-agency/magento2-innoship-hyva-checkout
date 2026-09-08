<?php

/**
 * Copyright © - LiquidLab Agency - All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Liquidlab\InnoShipHyva\Test\Unit\Plugin\HyvaCheckout;

use Exception;
use Liquidlab\InnoShipHyva\Plugin\HyvaCheckout\PreserveChosenShippingMethod;
use Magento\Checkout\Model\Session as SessionCheckout;
use Magento\Framework\DataObject;
use Magento\Quote\Model\Quote;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use stdClass;

/**
 * Hyvä's auto-select-first-shipping (gated by enableFirstAvailableShippingMethod)
 * must be suppressed for the current roundtrip whenever a shipping method is already
 * selected — locker OR courier — so a mere payment-method change cannot silently flip
 * the customer's choice to the first (cheapest) rate. Both directions matter: flipping
 * a locker to the first courier strands the pudo, and flipping a courier to the first
 * locker restricts payment to the locker's prepaid allowlist and drops cash-on-delivery.
 * Only a method-less quote still gets Hyvä's auto-select convenience.
 *
 * The config subject is irrelevant to the decision (we only re-evaluate the
 * boolean it returned against the live quote), so a bare stdClass stands in for
 * Hyvä's namespaced SystemConfigExperimental.
 */
class PreserveChosenShippingMethodTest extends TestCase
{
    private const LOCKER_METHOD = 'innoshipcargusgo_innoshipcargusgo_1';
    private const COURIER_METHOD = 'innoship_1';

    private SessionCheckout&MockObject $sessionCheckout;
    private PreserveChosenShippingMethod $plugin;

    protected function setUp(): void
    {
        $this->sessionCheckout = $this->createMock(SessionCheckout::class);
        $this->plugin = new PreserveChosenShippingMethod(
            $this->sessionCheckout,
            $this->createMock(LoggerInterface::class)
        );
    }

    public function testLeavesDisabledResultUntouchedWithoutTouchingQuote(): void
    {
        // Merchant already has the feature off — do not even read the quote.
        $this->sessionCheckout->expects($this->never())->method('getQuote');

        $this->assertFalse(
            $this->plugin->afterEnableFirstAvailableShippingMethod(new stdClass(), false)
        );
    }

    public function testSuppressesWhenLockerSelected(): void
    {
        $this->stubQuoteMethod(self::LOCKER_METHOD);

        $this->assertFalse(
            $this->plugin->afterEnableFirstAvailableShippingMethod(new stdClass(), true)
        );
    }

    public function testSuppressesWhenCourierSelected(): void
    {
        // A courier the customer chose must survive too: otherwise any payment
        // change auto-flips it to the first (cheapest) locker rate, which then
        // restricts payment to the locker allowlist and drops cash-on-delivery.
        $this->stubQuoteMethod(self::COURIER_METHOD);

        $this->assertFalse(
            $this->plugin->afterEnableFirstAvailableShippingMethod(new stdClass(), true)
        );
    }

    public function testKeepsEnabledForEmptyMethod(): void
    {
        $this->stubQuoteMethod('');

        $this->assertTrue(
            $this->plugin->afterEnableFirstAvailableShippingMethod(new stdClass(), true)
        );
    }

    public function testKeepsEnabledWhenQuoteUnavailable(): void
    {
        $this->sessionCheckout->method('getQuote')
            ->willThrowException(new Exception('no active checkout session'));

        $this->assertTrue(
            $this->plugin->afterEnableFirstAvailableShippingMethod(new stdClass(), true)
        );
    }

    private function stubQuoteMethod(string $shippingMethod): void
    {
        $address = new DataObject(['shipping_method' => $shippingMethod]);
        $quote = $this->createMock(Quote::class);
        $quote->method('getShippingAddress')->willReturn($address);
        $this->sessionCheckout->method('getQuote')->willReturn($quote);
    }
}
