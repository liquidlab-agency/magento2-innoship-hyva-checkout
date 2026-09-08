<?php

/**
 * Copyright © - LiquidLab Agency - All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Liquidlab\InnoShipHyva\Test\Unit\Magewire;

use Liquidlab\InnoShipHyva\Api\PudoRepositoryInterface;
use Liquidlab\InnoShipHyva\Magewire\PudoPicker;
use Liquidlab\InnoShipHyva\Model\RegionCoordinatesProvider;
use Liquidlab\InnoShipHyva\Model\RegionResolver;
use Magento\Checkout\Model\Session as SessionCheckout;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Api\Data\AddressExtensionFactory;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Fix F guard on PudoPicker::reconcileQuoteWithSessionPudo(). The picker's block
 * renders on every checkout page (before.body.end), so mount() → reconcile runs
 * regardless of the current shipping method. It must NOT re-stamp the session's
 * locker pudo back onto a quote that has moved to a courier method — that is the
 * stale-pudo-on-courier state that hides cash-on-delivery and mis-routes the AWB.
 *
 * The re-stamp path is entered only when it reaches pudoRepository::getByPudoId()
 * (to re-fetch the locker before writing it to the address), so each case asserts
 * whether that call happens. The private method is exercised via reflection so the
 * guard is tested in isolation, without a full Magewire mount.
 */
class PudoPickerReconcileTest extends TestCase
{
    private const LOCKER_METHOD = 'innoshipcargusgo_innoshipcargusgo_1';
    private const COURIER_METHOD = 'innoship_1';
    private const PUDO_ID = '679650';

    private SessionCheckout&MockObject $sessionCheckout;
    private PudoRepositoryInterface&MockObject $pudoRepository;
    private PudoPicker $picker;

    protected function setUp(): void
    {
        $this->sessionCheckout = $this->createMock(SessionCheckout::class);
        $this->pudoRepository = $this->createMock(PudoRepositoryInterface::class);

        $this->picker = new PudoPicker(
            $this->createMock(CartRepositoryInterface::class),
            $this->sessionCheckout,
            $this->createMock(LoggerInterface::class),
            $this->pudoRepository,
            $this->createMock(RegionCoordinatesProvider::class),
            $this->createMock(RegionResolver::class),
            $this->createMock(AddressExtensionFactory::class)
        );
    }

    public function testDoesNotReStampWhenMethodIsCourier(): void
    {
        $this->stubQuote(self::COURIER_METHOD, null);

        // The guard must short-circuit before the locker is re-fetched.
        $this->pudoRepository->expects($this->never())->method('getByPudoId');

        $this->invokeReconcile(['pudo_id' => self::PUDO_ID]);
    }

    public function testReStampsWhenMethodIsLocker(): void
    {
        $this->stubQuote(self::LOCKER_METHOD, null);

        // Guard passes; the mismatch (null vs 679650) drives a re-fetch. Throwing
        // here proves the path was entered while keeping the test focused on the
        // guard rather than the full address write.
        $this->pudoRepository->expects($this->once())
            ->method('getByPudoId')
            ->with(679650)
            ->willThrowException(new NoSuchEntityException(__('gone')));

        $this->invokeReconcile(['pudo_id' => self::PUDO_ID]);
    }

    public function testReStampsWhenMethodEmpty(): void
    {
        // Empty method = fresh quote; restoring a lost selection is the genuine
        // reason reconcile exists, so the guard must let it through.
        $this->stubQuote('', null);

        $this->pudoRepository->expects($this->once())
            ->method('getByPudoId')
            ->with(679650)
            ->willThrowException(new NoSuchEntityException(__('gone')));

        $this->invokeReconcile(['pudo_id' => self::PUDO_ID]);
    }

    public function testNoReFetchWhenPudoAlreadyMatches(): void
    {
        // Locker selected and the stamp already matches — idempotent early return.
        $this->stubQuote(self::LOCKER_METHOD, self::PUDO_ID);

        $this->pudoRepository->expects($this->never())->method('getByPudoId');

        $this->invokeReconcile(['pudo_id' => self::PUDO_ID]);
    }

    private function stubQuote(string $shippingMethod, ?string $pudoId): void
    {
        $address = new DataObject([
            'shipping_method' => $shippingMethod,
            'innoship_pudo_id' => $pudoId,
        ]);
        $quote = $this->createMock(Quote::class);
        $quote->method('getShippingAddress')->willReturn($address);
        $this->sessionCheckout->method('getQuote')->willReturn($quote);
    }

    /**
     * @param array<string, mixed> $pudoData
     */
    private function invokeReconcile(array $pudoData): void
    {
        $method = new ReflectionMethod(PudoPicker::class, 'reconcileQuoteWithSessionPudo');
        $method->setAccessible(true);
        $method->invoke($this->picker, $pudoData);
    }
}
