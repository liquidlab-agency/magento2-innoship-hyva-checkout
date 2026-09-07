<?php

/**
 * Copyright © - LiquidLab Agency - All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Liquidlab\InnoShipHyva\Test\Unit\Plugin;

use Liquidlab\InnoShipHyva\Model\Config\PaymentRestrictionConfig;
use Liquidlab\InnoShipHyva\Plugin\PaymentMethodRestriction;
use Magento\Payment\Model\MethodInterface;
use Magento\Payment\Model\MethodList;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Coverage for the payment-method allowlist filter, in particular the fix that
 * keys the restriction on the stamped pudo (a locker delivery) rather than only
 * the shipping_method label. Without it, a locker whose method was flipped to the
 * courier (order 1000012201) let cash-on-delivery back into the payment list.
 */
class PaymentMethodRestrictionTest extends TestCase
{
    private const LOCKER_METHOD = 'innoshipcargusgo_innoshipcargusgo_1';
    private const COURIER_METHOD = 'innoship_innoship_1';

    private PaymentRestrictionConfig&MockObject $config;
    private MethodList&MockObject $subject;
    private PaymentMethodRestriction $plugin;

    protected function setUp(): void
    {
        $this->config = $this->createMock(PaymentRestrictionConfig::class);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(1);
        $storeManager->method('getStore')->willReturn($store);

        $this->subject = $this->createMock(MethodList::class);
        $this->plugin = new PaymentMethodRestriction($this->config, $storeManager, $this->createMock(LoggerInterface::class));
    }

    public function testLockerMethodKeepsOnlyAllowedPayment(): void
    {
        $this->config->method('isRestrictionSupported')->willReturn(true);
        $this->config->method('getAllowedPaymentMethods')->willReturn(['eppay']);

        $result = $this->plugin->afterGetAvailableMethods(
            $this->subject,
            [$this->method('eppay'), $this->method('cashondelivery')],
            $this->quote(self::LOCKER_METHOD, null)
        );

        $this->assertSame(['eppay'], $this->codes($result));
    }

    /**
     * The 1000012201 fix: courier-labelled method but a locker pudo still stamped
     * → the locker allowlist must apply, so cash-on-delivery is filtered out.
     */
    public function testCourierMethodWithStampedPudoStillEnforcesLockerAllowlist(): void
    {
        // Restriction is only "supported" for the locker carrier code; the plugin
        // must substitute it because a pudo is stamped, not because the label matches.
        $this->config->method('isRestrictionSupported')
            ->willReturnCallback(fn(string $c) => $c === PaymentRestrictionConfig::LOCKER_CARRIER_CODE);
        $this->config->method('getAllowedPaymentMethods')->willReturn(['eppay']);

        $result = $this->plugin->afterGetAvailableMethods(
            $this->subject,
            [$this->method('eppay'), $this->method('cashondelivery')],
            $this->quote(self::COURIER_METHOD, 679650)
        );

        $this->assertSame(['eppay'], $this->codes($result));
    }

    public function testCourierWithoutPudoPassesAllMethodsThrough(): void
    {
        $this->config->method('isRestrictionSupported')
            ->willReturnCallback(fn(string $c) => $c === PaymentRestrictionConfig::LOCKER_CARRIER_CODE);

        $result = $this->plugin->afterGetAvailableMethods(
            $this->subject,
            [$this->method('eppay'), $this->method('cashondelivery')],
            $this->quote(self::COURIER_METHOD, null)
        );

        $this->assertSame(['eppay', 'cashondelivery'], $this->codes($result));
    }

    public function testEmptyAllowlistPassesAllMethodsThrough(): void
    {
        $this->config->method('isRestrictionSupported')->willReturn(true);
        $this->config->method('getAllowedPaymentMethods')->willReturn([]);

        $result = $this->plugin->afterGetAvailableMethods(
            $this->subject,
            [$this->method('eppay'), $this->method('cashondelivery')],
            $this->quote(self::LOCKER_METHOD, 679650)
        );

        $this->assertSame(['eppay', 'cashondelivery'], $this->codes($result));
    }

    private function method(string $code): MethodInterface&MockObject
    {
        $m = $this->createMock(MethodInterface::class);
        $m->method('getCode')->willReturn($code);

        return $m;
    }

    /**
     * @param MethodInterface[] $methods
     * @return string[]
     */
    private function codes(array $methods): array
    {
        return array_map(static fn(MethodInterface $m) => $m->getCode(), $methods);
    }

    private function quote(string $shippingMethod, ?int $pudoId): Quote
    {
        // getShippingMethod() is a declared method on Quote\Address; getInnoshipPudoId()
        // is a magic getData() getter, so it must be added, not stubbed (J.55c).
        $address = $this->getMockBuilder(Address::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getShippingMethod'])
            ->addMethods(['getInnoshipPudoId'])
            ->getMock();
        $address->method('getShippingMethod')->willReturn($shippingMethod);
        $address->method('getInnoshipPudoId')->willReturn($pudoId);

        $quote = $this->createMock(Quote::class);
        $quote->method('getShippingAddress')->willReturn($address);

        return $quote;
    }
}
