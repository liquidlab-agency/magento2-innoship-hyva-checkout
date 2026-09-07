<?php

/**
 * Copyright © - LiquidLab Agency - All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Liquidlab\InnoShipHyva\Test\Unit\Magewire;

use Hyva\Checkout\Model\Magewire\Component\Evaluation\ErrorEventMessage;
use Hyva\Checkout\Model\Magewire\Component\Evaluation\Success;
use Hyva\Checkout\Model\Magewire\Component\EvaluationResultFactory;
use Liquidlab\InnoShipHyva\Magewire\PudoPoint;
use Magento\Checkout\Model\Session as SessionCheckout;
use Magento\Framework\DataObject;
use Magento\Quote\Api\CartRepositoryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Regression coverage for the locker pickup-point validation gate
 * (PudoPoint::evaluateCompletion).
 *
 * The gate MUST validate against the quote's innoship_pudo_id — the single source
 * of truth — and NOT the public Magewire property $this->pudoId, which is hydrated
 * from the client snapshot and can be stale: pick a locker, then edit the shipping
 * address, and Hyvä's address-save clears innoship_pudo_id on the quote while the
 * component keeps its pudoId. The pre-fix `empty($this->pudoId)` check let such a
 * desynced order through with no pickup point (parcel shipped to the plain
 * address). The server-side backstop is covered separately in
 * ValidatePudoOnQuoteSubmitTest.
 */
class PudoPointTest extends TestCase
{
    private const LOCKER_METHOD = 'innoshipcargusgo_innoshipcargusgo_1';

    private SessionCheckout&MockObject $sessionCheckout;
    private EvaluationResultFactory&MockObject $resultFactory;
    private Success&MockObject $successResult;
    private ErrorEventMessage&MockObject $errorResult;
    private PudoPoint $pudoPoint;

    protected function setUp(): void
    {
        $this->sessionCheckout = $this->createMock(SessionCheckout::class);
        $quoteRepository = $this->createMock(CartRepositoryInterface::class);
        $logger = $this->createMock(LoggerInterface::class);

        $this->successResult = $this->createMock(Success::class);
        $this->errorResult = $this->createMock(ErrorEventMessage::class);
        $this->errorResult->method('withCustomEvent')->willReturnSelf();

        $this->resultFactory = $this->createMock(EvaluationResultFactory::class);
        $this->resultFactory->method('createSuccess')->willReturn($this->successResult);
        $this->resultFactory->method('createErrorMessageEvent')->willReturn($this->errorResult);

        $this->pudoPoint = new PudoPoint($quoteRepository, $this->sessionCheckout, $logger);
    }

    public function testReturnsErrorWhenLockerMethodHasNoPudoOnQuote(): void
    {
        $this->stubQuote(self::LOCKER_METHOD, null);

        $this->assertSame(
            $this->errorResult,
            $this->pudoPoint->evaluateCompletion($this->resultFactory)
        );
    }

    public function testReturnsErrorWhenLockerMethodHasZeroPudoOnQuote(): void
    {
        $this->stubQuote(self::LOCKER_METHOD, 0);

        $this->assertSame(
            $this->errorResult,
            $this->pudoPoint->evaluateCompletion($this->resultFactory)
        );
    }

    public function testReturnsSuccessWhenLockerMethodHasPudoOnQuote(): void
    {
        $this->stubQuote(self::LOCKER_METHOD, 2465879);

        $this->assertSame(
            $this->successResult,
            $this->pudoPoint->evaluateCompletion($this->resultFactory)
        );
    }

    public function testReturnsSuccessForNonLockerMethodEvenWithoutPudo(): void
    {
        $this->stubQuote('flatrate_flatrate', null);

        $this->assertSame(
            $this->successResult,
            $this->pudoPoint->evaluateCompletion($this->resultFactory)
        );
    }

    /**
     * The core regression: a stale client-carried $this->pudoId must NOT satisfy
     * the gate when the quote itself carries no locker. Pre-fix
     * (`empty($this->pudoId)`) this returned Success and let the bad order through;
     * post-fix (reads the quote) it correctly returns the error.
     */
    public function testStalePudoIdPropertyDoesNotBypassGateWhenQuoteHasNoPudo(): void
    {
        $this->stubQuote(self::LOCKER_METHOD, null);
        $this->pudoPoint->pudoId = '999999'; // stale client value from an earlier pick

        $this->assertSame(
            $this->errorResult,
            $this->pudoPoint->evaluateCompletion($this->resultFactory)
        );
    }

    private function stubQuote(string $shippingMethod, ?int $pudoId): void
    {
        $address = new DataObject([
            'shipping_method'  => $shippingMethod,
            'innoship_pudo_id' => $pudoId,
        ]);
        $quote = new DataObject(['shipping_address' => $address]);
        $this->sessionCheckout->method('getQuote')->willReturn($quote);
    }
}
