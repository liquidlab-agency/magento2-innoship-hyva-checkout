<?php

/**
 * Copyright © - LiquidLab Agency - All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Liquidlab\InnoShipHyva\Test\Unit\Plugin\Quote;

use Liquidlab\InnoShipHyva\Plugin\Quote\ClearStalePudoOnShippingMethodSet;
use Magento\Checkout\Model\Session as SessionCheckout;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\ShippingMethodManagementInterface;
use Magento\Quote\Model\Quote;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The global choke-point cleanup: whenever the shipping method is set to a
 * non-locker carrier through ShippingMethodManagementInterface::set(), any stale
 * locker pudo (on the address and/or in the checkout session) must be dropped so
 * a courier-labelled quote can never carry a locker pickup point. Covers the
 * paths that bypass Hyvä's Magewire MethodList::updatedMethod — the auto-select-
 * first-shipping flip, REST, GraphQL, admin.
 *
 * The shipping address is a real DataObject so the blanking and the
 * innoship_pudo_id/courier_id clearing exercise the actual magic setters/getters;
 * the quote is a Quote mock so its setData()/getShippingAddress() satisfy the
 * repository save.
 */
class ClearStalePudoOnShippingMethodSetTest extends TestCase
{
    private const LOCKER_CARRIER = 'innoshipcargusgo';
    private const COURIER_CARRIER = 'innoship';
    private const SESSION_KEY = 'innoship_selected_pudo_point';
    private const CART_ID = 44197;

    private CartRepositoryInterface&MockObject $quoteRepository;
    private SessionCheckout&MockObject $sessionCheckout;
    private ShippingMethodManagementInterface&MockObject $subject;
    private ClearStalePudoOnShippingMethodSet $plugin;

    protected function setUp(): void
    {
        $this->quoteRepository = $this->createMock(CartRepositoryInterface::class);
        $this->sessionCheckout = $this->createMock(SessionCheckout::class);
        $this->subject = $this->createMock(ShippingMethodManagementInterface::class);
        $this->plugin = new ClearStalePudoOnShippingMethodSet(
            $this->quoteRepository,
            $this->sessionCheckout,
            $this->createMock(LoggerInterface::class)
        );
    }

    public function testKeepsPudoWhenNewCarrierIsLocker(): void
    {
        // Setting the locker carrier itself — must not even load the quote.
        $this->quoteRepository->expects($this->never())->method('getActive');
        $this->quoteRepository->expects($this->never())->method('save');

        $result = $this->plugin->afterSet(
            $this->subject,
            true,
            self::CART_ID,
            self::LOCKER_CARRIER,
            'innoshipcargusgo_1'
        );

        $this->assertTrue($result);
    }

    public function testClearsAddressPudoWhenSwitchingToCourier(): void
    {
        $address = new DataObject([
            'innoship_pudo_id' => 679650,
            'innoship_courier_id' => 3,
            'city' => 'Buhusi',
            'company' => 'FANbox Republicii 13 BC',
        ]);
        $this->primeQuote($address, null);

        $this->quoteRepository->expects($this->once())->method('save');

        $result = $this->plugin->afterSet($this->subject, true, self::CART_ID, self::COURIER_CARRIER, '1');

        $this->assertTrue($result);
        $this->assertNull($address->getData('innoship_pudo_id'));
        $this->assertNull($address->getData('innoship_courier_id'));
        $this->assertSame('', $address->getData('city'));
        $this->assertSame('', $address->getData('company'));
    }

    public function testClearsWhenOnlySessionHasPudo(): void
    {
        $address = new DataObject(['innoship_pudo_id' => null]);
        $this->primeQuote($address, ['pudo_id' => '679650']);

        // A session-only pudo (address stamp already gone) still forces a
        // cleanup save so the null stamps are persisted onto the quote.
        $this->quoteRepository->expects($this->once())->method('save');

        $result = $this->plugin->afterSet($this->subject, true, self::CART_ID, self::COURIER_CARRIER, '1');

        $this->assertTrue($result);
    }

    public function testDoesNothingWhenNoPudoAnywhere(): void
    {
        $address = new DataObject(['innoship_pudo_id' => null]);
        $this->primeQuote($address, null);

        $this->quoteRepository->expects($this->never())->method('save');

        $result = $this->plugin->afterSet($this->subject, true, self::CART_ID, self::COURIER_CARRIER, '1');

        $this->assertTrue($result);
    }

    public function testNoOpWhenCartNotFound(): void
    {
        $this->quoteRepository->method('getActive')
            ->willThrowException(new NoSuchEntityException(__('No such entity.')));
        $this->quoteRepository->expects($this->never())->method('save');

        $result = $this->plugin->afterSet($this->subject, true, self::CART_ID, self::COURIER_CARRIER, '1');

        $this->assertTrue($result);
    }

    /**
     * @param array<string, string>|null $sessionPudo
     */
    private function primeQuote(DataObject $address, ?array $sessionPudo): void
    {
        $quote = $this->createMock(Quote::class);
        $quote->method('getShippingAddress')->willReturn($address);

        $this->quoteRepository->method('getActive')->with(self::CART_ID)->willReturn($quote);
        $this->sessionCheckout->method('getData')->with(self::SESSION_KEY)->willReturn($sessionPudo);
    }
}
