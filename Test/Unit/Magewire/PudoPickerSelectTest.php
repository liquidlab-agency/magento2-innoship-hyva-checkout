<?php

/**
 * Copyright © - LiquidLab Agency - All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Liquidlab\InnoShipHyva\Test\Unit\Magewire;

use Liquidlab\InnoShipHyva\Api\Data\PudoInterface;
use Liquidlab\InnoShipHyva\Api\PudoRepositoryInterface;
use Liquidlab\InnoShipHyva\Magewire\PudoPicker;
use Liquidlab\InnoShipHyva\Model\PudoPointsProvider;
use Liquidlab\InnoShipHyva\Model\RegionResolver;
use Magento\Checkout\Model\Session as SessionCheckout;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\AddressExtensionFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * PudoPicker::selectPudoPoint() with a point the map still shows but Innoship
 * has deactivated or removed since the points endpoint cached it.
 */
class PudoPickerSelectTest extends TestCase
{
    private const PUDO_ID = '679650';
    private const MESSAGE = 'This pickup point is no longer available. Please select another one.';

    private SessionCheckout&MockObject $sessionCheckout;
    private CartRepositoryInterface&MockObject $quoteRepository;
    private PudoRepositoryInterface&MockObject $pudoRepository;
    private PudoPicker $picker;

    protected function setUp(): void
    {
        $this->sessionCheckout = $this->createMock(SessionCheckout::class);
        $this->quoteRepository = $this->createMock(CartRepositoryInterface::class);
        $this->pudoRepository = $this->createMock(PudoRepositoryInterface::class);

        $this->picker = new PudoPicker(
            $this->quoteRepository,
            $this->sessionCheckout,
            $this->createMock(LoggerInterface::class),
            $this->pudoRepository,
            $this->createMock(PudoPointsProvider::class),
            $this->createMock(RegionResolver::class),
            $this->createMock(AddressExtensionFactory::class)
        );
    }

    public function testAnInactivePointIsTurnedDown(): void
    {
        $pudo = $this->createMock(PudoInterface::class);
        $pudo->method('isActive')->willReturn(false);
        $this->pudoRepository->method('getByPudoId')->with(679650)->willReturn($pudo);

        $this->expectNothingSaved();
        $this->picker->selectPudoPoint(self::PUDO_ID);

        $this->assertTurnedDown();
    }

    public function testAMissingPointIsTurnedDown(): void
    {
        $this->pudoRepository->method('getByPudoId')
            ->willThrowException(new NoSuchEntityException(__('PUDO with ID %1 not found.', self::PUDO_ID)));

        $this->expectNothingSaved();
        $this->picker->selectPudoPoint(self::PUDO_ID);

        $this->assertTurnedDown();
    }

    private function expectNothingSaved(): void
    {
        // Neither the session selection nor the quote's shipping address changes.
        $this->sessionCheckout->expects($this->never())->method($this->anything());
        $this->quoteRepository->expects($this->never())->method('save');
    }

    private function assertTurnedDown(): void
    {
        $messages = $this->picker->getFlashMessages();
        $this->assertCount(1, $messages);
        $this->assertSame('error', $messages[0]->getType());
        $this->assertSame(self::MESSAGE, $messages[0]->getMessage()->render());

        // The Alpine component drops the point from the map.
        $this->assertSame(
            [['event' => 'innoship-pudo-unavailable', 'data' => ['pudoId' => self::PUDO_ID]]],
            $this->picker->getBrowserEvents()
        );
    }
}
