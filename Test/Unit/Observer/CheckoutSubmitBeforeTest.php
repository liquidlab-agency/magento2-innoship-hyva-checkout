<?php

/**
 * Copyright © - LiquidLab Agency - All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Liquidlab\InnoShipHyva\Test\Unit\Observer;

use Liquidlab\InnoShipHyva\Model\RegionResolver;
use Liquidlab\InnoShipHyva\Observer\CheckoutSubmitBefore;
use Magento\Checkout\Model\Session as SessionCheckout;
use Magento\Framework\Event\Observer;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\AddressExtensionFactory;
use Magento\Quote\Api\Data\AddressExtensionInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * At order submit the locker address is written back over the shipping address,
 * so whatever reached the quote from the form (the locked fields included) can't
 * change where a locker order says it goes. Country and county are part of it.
 */
class CheckoutSubmitBeforeTest extends TestCase
{
    private const LOCKER_METHOD = 'innoshipcargusgo_innoshipcargusgo_1';

    private SessionCheckout&MockObject $sessionCheckout;
    private RegionResolver&MockObject $regionResolver;
    private CheckoutSubmitBefore $observer;

    protected function setUp(): void
    {
        $this->sessionCheckout = $this->createMock(SessionCheckout::class);
        $this->regionResolver = $this->createMock(RegionResolver::class);
        $extensionFactory = $this->createMock(AddressExtensionFactory::class);
        $extensionFactory->method('create')->willReturn($this->createMock(AddressExtensionInterface::class));

        $this->observer = new CheckoutSubmitBefore(
            $this->sessionCheckout,
            $this->createMock(LoggerInterface::class),
            $extensionFactory,
            $this->createMock(CartRepositoryInterface::class),
            $this->regionResolver
        );
    }

    public function testWritesTheLockerAddressBackOverAnEditedOne(): void
    {
        $this->givenSessionPudo();
        $this->regionResolver->method('resolveByName')
            ->with('Constanța', 'RO')
            ->willReturn(['region' => 'Constanța', 'region_id' => 292]);
        $address = $this->makeAddress([
            'shipping_method' => self::LOCKER_METHOD,
            'company' => 'Acme',
            'street' => 'Str. Edited 1',
            'city' => 'Cluj-Napoca',
            'postcode' => '400001',
            'country_id' => 'HU',
            'region' => 'Cluj',
            'region_id' => 290,
        ]);

        $this->observer->execute($this->makeEvent($address));

        $this->assertSame('easybox Novopolis Residence', $address->getData('company'));
        $this->assertSame('Bd. I.C. Bratianu Nr. 284', $address->getData('street'));
        $this->assertSame('Constanta', $address->getData('city'));
        $this->assertSame('900316', $address->getData('postcode'));
        $this->assertSame('RO', $address->getData('country_id'));
        $this->assertSame('Constanța', $address->getData('region'));
        $this->assertSame(292, $address->getData('region_id'));
    }

    public function testKeepsTheRegionIdWhenTheCountyIsNotFound(): void
    {
        $this->givenSessionPudo();
        $this->regionResolver->method('resolveByName')
            ->willReturn(['region' => 'Constanța', 'region_id' => null]);
        $address = $this->makeAddress(['shipping_method' => self::LOCKER_METHOD, 'region_id' => 292]);

        $this->observer->execute($this->makeEvent($address));

        $this->assertSame('Constanța', $address->getData('region'));
        $this->assertSame(292, $address->getData('region_id'));
    }

    public function testLeavesACourierAddressAlone(): void
    {
        $this->givenSessionPudo();
        $this->regionResolver->expects($this->never())->method('resolveByName');
        $address = $this->makeAddress([
            'shipping_method' => 'innoship_innoship_1',
            'city' => 'Cluj-Napoca',
            'country_id' => 'RO',
        ]);

        $this->observer->execute($this->makeEvent($address));

        $this->assertSame('Cluj-Napoca', $address->getData('city'));
    }

    private function givenSessionPudo(): void
    {
        $this->sessionCheckout->method('getData')
            ->with('innoship_selected_pudo_point')
            ->willReturn([
                'pudo_id' => '679650',
                'courier_id' => '1',
                'name' => 'easybox Novopolis Residence',
                'address' => 'Bd. I.C. Bratianu Nr. 284',
                'city' => 'Constanta',
                'postal_code' => '900316',
                'country_code' => 'RO',
                'county_name' => 'Constanța',
            ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function makeAddress(array $data): Address&MockObject
    {
        $address = $this->getMockBuilder(Address::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getExtensionAttributes', 'setExtensionAttributes'])
            ->getMock();
        $address->setData($data);

        return $address;
    }

    private function makeEvent(Address $address): Observer
    {
        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getShippingAddress', 'getId'])
            ->getMock();
        $quote->method('getShippingAddress')->willReturn($address);
        $quote->method('getId')->willReturn(1);

        return new Observer(['quote' => $quote]);
    }
}
