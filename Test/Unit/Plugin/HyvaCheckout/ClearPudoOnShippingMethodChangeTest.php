<?php

/**
 * Copyright © - LiquidLab Agency - All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Liquidlab\InnoShipHyva\Test\Unit\Plugin\HyvaCheckout;

use Hyva\Checkout\Magewire\Checkout\Shipping\MethodList;
use Liquidlab\InnoShipHyva\Plugin\HyvaCheckout\ClearPudoOnShippingMethodChange;
use Magento\Checkout\Model\Session as SessionCheckout;
use Magento\Framework\DataObject;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The shipping address is a real DataObject so the locker-address blanking and the
 * innoship_pudo_id/courier_id clearing exercise the actual magic setters/getters;
 * the quote is a Quote mock so it satisfies CartRepositoryInterface::save().
 */
class ClearPudoOnShippingMethodChangeTest extends TestCase
{
    private const LOCKER_METHOD = 'innoshipcargusgo_innoshipcargusgo_1';
    private const COURIER_METHOD = 'innoship_innoship_1';
    private const SESSION_KEY = 'innoship_selected_pudo_point';

    private SessionCheckout&MockObject $sessionCheckout;
    private CartRepositoryInterface&MockObject $quoteRepository;
    private MethodList&MockObject $subject;
    private ClearPudoOnShippingMethodChange $plugin;

    protected function setUp(): void
    {
        $this->sessionCheckout = $this->createMock(SessionCheckout::class);
        $this->quoteRepository = $this->createMock(CartRepositoryInterface::class);
        $this->subject = $this->createMock(MethodList::class);
        $this->plugin = new ClearPudoOnShippingMethodChange(
            $this->sessionCheckout,
            $this->quoteRepository,
            $this->createMock(LoggerInterface::class)
        );
    }

    public function testKeepsPudoWhenNewMethodIsLocker(): void
    {
        $this->quoteRepository->expects($this->never())->method('save');
        $this->subject->expects($this->never())->method('emit');

        $this->assertSame('r', $this->plugin->afterUpdatedMethod($this->subject, 'r', self::LOCKER_METHOD));
    }

    public function testClearsPudoWhenSwitchingToCourierWithStampedPudo(): void
    {
        $address = new DataObject(['innoship_pudo_id' => 679650, 'city' => 'Buhusi']);
        $this->primeSession($address, null);

        $this->quoteRepository->expects($this->once())->method('save');
        $this->subject->expects($this->once())->method('emit')->with('innoship-pudo-cleared');

        $this->plugin->afterUpdatedMethod($this->subject, 'r', self::COURIER_METHOD);

        $this->assertNull($address->getData('innoship_pudo_id'));
        $this->assertNull($address->getData('innoship_courier_id'));
        $this->assertSame('', $address->getData('city'));
    }

    public function testClearsWhenOnlySessionHasPudo(): void
    {
        $address = new DataObject(['innoship_pudo_id' => null]);
        $this->primeSession($address, ['pudo_id' => '679650']);

        $this->quoteRepository->expects($this->once())->method('save');
        $this->subject->expects($this->once())->method('emit')->with('innoship-pudo-cleared');

        $this->plugin->afterUpdatedMethod($this->subject, 'r', self::COURIER_METHOD);
    }

    public function testDoesNothingWhenNoPudoPresent(): void
    {
        $address = new DataObject(['innoship_pudo_id' => null]);
        $this->primeSession($address, null);

        $this->quoteRepository->expects($this->never())->method('save');
        $this->subject->expects($this->never())->method('emit');

        $this->assertSame('r', $this->plugin->afterUpdatedMethod($this->subject, 'r', self::COURIER_METHOD));
    }

    /**
     * @param array<string, string>|null $sessionPudo
     */
    private function primeSession(DataObject $address, ?array $sessionPudo): void
    {
        $quote = $this->createMock(Quote::class);
        $quote->method('getShippingAddress')->willReturn($address);

        $this->sessionCheckout->method('getQuote')->willReturn($quote);
        $this->sessionCheckout->method('getData')->with(self::SESSION_KEY)->willReturn($sessionPudo);
    }
}
