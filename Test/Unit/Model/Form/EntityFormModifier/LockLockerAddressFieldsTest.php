<?php

/**
 * Copyright © - LiquidLab Agency - All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Liquidlab\InnoShipHyva\Test\Unit\Model\Form\EntityFormModifier;

use Hyva\Checkout\Model\Form\EntityField\AbstractEntityField;
use Hyva\Checkout\Model\Form\EntityFormElementInterface;
use Hyva\Checkout\Model\Form\EntityFormInterface;
use Liquidlab\InnoShipHyva\Model\Config\LockerAddressConfig;
use Liquidlab\InnoShipHyva\Model\Form\EntityFormModifier\LockLockerAddressFields;
use Magento\Checkout\Model\Session as SessionCheckout;
use Magento\Framework\DataObject;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The shipping form locks the fields a locker filled in, and only while the QUOTE
 * says the destination is a locker (stamped pudo + locker method), never from the
 * session or the PudoPoint component (EQT-19).
 *
 * Quote and address are real DataObjects; fields are mocks that record disable()
 * and the comment.
 */
class LockLockerAddressFieldsTest extends TestCase
{
    private const LOCKER_METHOD = 'innoshipcargusgo_innoshipcargusgo_1';
    private const COURIER_METHOD = 'innoship_innoship_1';

    private SessionCheckout&MockObject $sessionCheckout;
    private LockerAddressConfig&MockObject $config;

    /** @var array<string, AbstractEntityField&MockObject> */
    private array $fields = [];

    /** @var array<string, int> */
    private array $disabled = [];

    /** @var array<string, string> */
    private array $comments = [];

    protected function setUp(): void
    {
        $this->sessionCheckout = $this->createMock(SessionCheckout::class);
        $this->config = $this->createMock(LockerAddressConfig::class);
        $this->config->method('isAddressLocked')->willReturn(true);
    }

    public function testLocksEveryLockerFieldAndCommentsOnStreet(): void
    {
        $this->givenQuote(self::LOCKER_METHOD, 679650, $this->lockerAddress());

        $this->build();

        $this->assertSame(
            ['street', 'city', 'postcode', 'company', 'country_id', 'region'],
            array_keys($this->disabled)
        );
        $this->assertSame(['street' => LockLockerAddressFields::COMMENT], $this->comments);
        $this->assertArrayNotHasKey('firstname', $this->disabled);
        $this->assertArrayNotHasKey('telephone', $this->disabled);
    }

    public function testLocksStreetLines(): void
    {
        $this->givenQuote(self::LOCKER_METHOD, 679650, $this->lockerAddress());
        $line = $this->makeField('street.1', 'text');
        $this->fields['street']->method('getRelatives')->willReturn(['street.1' => $line]);

        $this->build();

        $this->assertArrayHasKey('street.1', $this->disabled);
    }

    public function testLeavesFieldsTheLockerLeftEmptyEditable(): void
    {
        // RegionResolver found no county: the customer must still be able to pick one.
        $address = $this->lockerAddress();
        $address['region'] = '';
        $address['region_id'] = null;
        $this->givenQuote(self::LOCKER_METHOD, 679650, $address);

        $this->build();

        $this->assertArrayNotHasKey('region', $this->disabled);
        $this->assertArrayHasKey('city', $this->disabled);
    }

    public function testCommentSkipsSelectFields(): void
    {
        $this->givenQuote(self::LOCKER_METHOD, 679650, $this->lockerAddress());
        unset($this->fields['street']);

        $this->build();

        $this->assertSame(['city' => LockLockerAddressFields::COMMENT], $this->comments);
    }

    public function testDoesNothingWithoutAPudo(): void
    {
        $this->givenQuote(self::LOCKER_METHOD, null, $this->lockerAddress());

        $this->build();

        $this->assertSame([], $this->disabled);
        $this->assertSame([], $this->comments);
    }

    public function testDoesNothingForACourierMethodWithAStalePudo(): void
    {
        $this->givenQuote(self::COURIER_METHOD, 679650, $this->lockerAddress());

        $this->build();

        $this->assertSame([], $this->disabled);
    }

    public function testDoesNothingWhenTurnedOff(): void
    {
        $this->config = $this->createMock(LockerAddressConfig::class);
        $this->config->method('isAddressLocked')->willReturn(false);
        $this->givenQuote(self::LOCKER_METHOD, 679650, $this->lockerAddress());

        $this->build();

        $this->assertSame([], $this->disabled);
    }

    public function testRegistersOnFormBuild(): void
    {
        $form = $this->createMock(EntityFormInterface::class);
        $form->expects($this->once())
            ->method('registerModificationListener')
            ->with('innoshipHyvaLockLockerAddress', 'form:build', $this->isType('callable'))
            ->willReturnSelf();

        $this->makeModifier()->apply($form);
    }

    private function build(): void
    {
        $form = $this->createMock(EntityFormInterface::class);
        $form->method('getField')->willReturnCallback(fn (string $name) => $this->fields[$name] ?? null);

        $this->makeModifier()->lockLockerAddress($form);
    }

    private function makeModifier(): LockLockerAddressFields
    {
        return new LockLockerAddressFields(
            $this->sessionCheckout,
            $this->config,
            $this->createMock(LoggerInterface::class)
        );
    }

    /**
     * @param array<string, mixed> $addressData
     */
    private function givenQuote(string $method, ?int $pudoId, array $addressData): void
    {
        $address = new DataObject($addressData + [
            'shipping_method' => $method,
            'innoship_pudo_id' => $pudoId,
        ]);
        $quote = new DataObject(['store_id' => 1, 'shipping_address' => $address]);
        $this->sessionCheckout->method('getQuote')->willReturn($quote);

        foreach ([
            'firstname' => 'text',
            'street' => 'text',
            'country_id' => 'select',
            'region' => 'select',
            'postcode' => 'text',
            'city' => 'text',
            'telephone' => 'text',
            'company' => 'text',
        ] as $name => $input) {
            $this->fields[$name] = $this->makeField($name, $input);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function lockerAddress(): array
    {
        return [
            'firstname' => 'Ana',
            'telephone' => '0700000000',
            'company' => 'easybox Novopolis Residence',
            'street' => "Bd. I.C. Bratianu Nr. 284\n",
            'city' => 'Constanta',
            'postcode' => '900316',
            'country_id' => 'RO',
            'region' => 'Constanța',
            'region_id' => 292,
        ];
    }

    private function makeField(string $name, string $input): AbstractEntityField&MockObject
    {
        $field = $this->createMock(AbstractEntityField::class);
        $field->method('getFrontendInput')->willReturn($input);
        $field->method('disable')->willReturnCallback(function () use ($name, $field) {
            $this->disabled[$name] = ($this->disabled[$name] ?? 0) + 1;
            return $field;
        });
        $field->method('setData')->willReturnCallback(function ($key, $value = null) use ($name, $field) {
            if ($key === EntityFormElementInterface::COMMENT) {
                $this->comments[$name] = $value;
            }
            return $field;
        });

        return $field;
    }
}
