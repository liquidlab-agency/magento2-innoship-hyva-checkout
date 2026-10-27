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
    private RegionResolver&MockObject $regionResolver;
    private PudoPicker $picker;

    protected function setUp(): void
    {
        $this->sessionCheckout = $this->createMock(SessionCheckout::class);
        $this->pudoRepository = $this->createMock(PudoRepositoryInterface::class);
        $this->regionResolver = $this->createMock(RegionResolver::class);

        $this->picker = new PudoPicker(
            $this->createMock(CartRepositoryInterface::class),
            $this->sessionCheckout,
            $this->createMock(LoggerInterface::class),
            $this->pudoRepository,
            $this->createMock(PudoPointsProvider::class),
            $this->regionResolver,
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

    public function testDoesNotReStampAnInactivePudo(): void
    {
        // Innoship deactivated the locker after it was picked. Leave the quote as
        // it is: ValidatePudoOnQuoteSubmit then asks for another point.
        $this->stubQuote(self::LOCKER_METHOD, null);
        $pudo = $this->createMock(PudoInterface::class);
        $pudo->method('isActive')->willReturn(false);
        // The address write starts by reading the point's county and street.
        $pudo->expects($this->never())->method('getCountyName');
        $pudo->expects($this->never())->method('getAddressText');
        $this->pudoRepository->expects($this->once())
            ->method('getByPudoId')
            ->with(679650)
            ->willReturn($pudo);

        $this->invokeReconcile(['pudo_id' => self::PUDO_ID]);
    }

    public function testNoReFetchWhenPudoAlreadyMatches(): void
    {
        // Locker selected and the stamp already matches — idempotent early return.
        $this->stubQuote(self::LOCKER_METHOD, self::PUDO_ID);

        $this->pudoRepository->expects($this->never())->method('getByPudoId');

        $this->invokeReconcile(['pudo_id' => self::PUDO_ID]);
    }

    public function testStampingALockerUnlinksTheSavedCustomerAddress(): void
    {
        // A logged-in customer had saved address 4187 selected. The locker must not
        // stay linked to it: Hyvä's address list would keep that card selected and
        // re-import it, and save_in_address_book would copy the locker into the
        // customer's address book when the order is placed.
        $address = $this->stubQuote(self::LOCKER_METHOD, null);
        $address->addData([
            'customer_address_id' => 4187,
            'save_in_address_book' => 1,
            'extension_attributes' => new DataObject(),
        ]);
        $this->regionResolver->method('resolveByName')
            ->willReturn(['region' => 'Bucuresti', 'region_id' => 1]);

        // getCountryCode() is a magic getter on the Pudo model, not on the interface.
        $pudo = $this->getMockBuilder(PudoInterface::class)
            ->onlyMethods(get_class_methods(PudoInterface::class))
            ->addMethods(['getCountryCode'])
            ->getMock();
        $pudo->method('getCountryCode')->willReturn('RO');
        $pudo->method('getName')->willReturn('easybox Barsanesti');
        $pudo->method('getAddressText')->willReturn('Str. Barsanesti 2B');
        $pudo->method('getLocalityName')->willReturn('Sector 6');
        $pudo->method('getPostalCode')->willReturn('061308');
        $pudo->method('getPudoId')->willReturn(149801);

        $method = new ReflectionMethod(PudoPicker::class, 'updateShippingAddressWithPudo');
        $method->setAccessible(true);
        $method->invoke($this->picker, $pudo);

        $this->assertNull($address->getCustomerAddressId());
        $this->assertSame(0, $address->getSaveInAddressBook());
        $this->assertSame(['Str. Barsanesti 2B'], $address->getStreet());
        $this->assertSame('149801', $address->getInnoshipPudoId());
    }

    private function stubQuote(string $shippingMethod, ?string $pudoId): DataObject
    {
        $address = new DataObject([
            'shipping_method' => $shippingMethod,
            'innoship_pudo_id' => $pudoId,
        ]);
        $quote = $this->createMock(Quote::class);
        $quote->method('getShippingAddress')->willReturn($address);
        $this->sessionCheckout->method('getQuote')->willReturn($quote);

        return $address;
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
