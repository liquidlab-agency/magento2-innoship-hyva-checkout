<?php

/**
 * Copyright © - LiquidLab Agency - All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Liquidlab\InnoShipHyva\Test\Unit\Plugin\HyvaCheckout;

use Hyva\Checkout\Magewire\Checkout\AddressView\ShippingDetails\AddressForm;
use Hyva\Checkout\Magewire\Checkout\AddressView\ShippingDetails\AddressList;
use Liquidlab\InnoShipHyva\Plugin\HyvaCheckout\RefreshAddressFormOnPudoSelected;
use Liquidlab\InnoShipHyva\Plugin\HyvaCheckout\RefreshAddressListOnPudoSelected;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * On a PUDO event roundtrip Hyvä's AddressForm::dehydrate() would autosave the
 * browser's stale address (a logged-in customer's saved address) over the locker
 * that PudoPicker just stamped on the quote. The plugin must send back the address
 * boot() loaded from the quote instead, without booting the form a second time, and
 * leave every other roundtrip to Hyvä.
 */
class RefreshAddressFormOnPudoSelectedTest extends TestCase
{
    private AddressForm&MockObject $form;
    private RefreshAddressFormOnPudoSelected $plugin;

    protected function setUp(): void
    {
        $this->form = $this->createMock(AddressForm::class);
        $this->plugin = new RefreshAddressFormOnPudoSelected();
    }

    public function testFormRefreshesOnPudoEvents(): void
    {
        $listeners = $this->plugin->afterGetListeners($this->form, ['edit' => 'edit']);

        $this->assertSame('edit', $listeners['edit']);
        $this->assertSame('$refresh', $listeners['innoship-pudo-selected']);
        $this->assertSame('$refresh', $listeners['innoship-pudo-cleared']);
    }

    public function testAddressListRefreshesOnPudoEvents(): void
    {
        $listeners = (new RefreshAddressListOnPudoSelected())
            ->afterGetListeners($this->createMock(AddressList::class), []);

        $this->assertSame('$refresh', $listeners['innoship-pudo-selected']);
        $this->assertSame('$refresh', $listeners['innoship-pudo-cleared']);
    }

    /**
     * @dataProvider pudoEvents
     */
    public function testSkipsAutosaveAndSendsBackTheQuoteAddressOnPudoEvent(string $event): void
    {
        $this->form->method('getRequest')->with('updates')
            ->willReturn([['type' => 'fireEvent', 'payload' => ['event' => $event, 'params' => []]]]);

        $this->bootThenHydrate(['street' => ['Easybox Lotus'], 'city' => 'Oradea']);

        $this->plugin->aroundDehydrate($this->form, function (): void {
            $this->fail('Hyvä autosave must not run on a PUDO event roundtrip.');
        });

        $this->assertSame(['street' => ['Easybox Lotus'], 'city' => 'Oradea'], $this->form->address);
    }

    /**
     * Magewire already booted the component; booting it again re-runs the form's
     * init() and every form modifier throws "A form modifier with name ... already
     * exists".
     *
     * @dataProvider pudoEvents
     */
    public function testDoesNotBootTheFormAgainOnPudoEvent(string $event): void
    {
        $this->form->method('getRequest')->with('updates')->willReturn([
            ['type' => 'syncInput', 'payload' => ['name' => 'address.street.0', 'value' => 'Str. X 1']],
            ['type' => 'fireEvent', 'payload' => ['event' => $event, 'params' => []]],
        ]);
        $this->form->expects($this->never())->method('boot');

        $this->bootThenHydrate(['street' => ['Easybox Lotus']]);

        $this->plugin->aroundDehydrate($this->form, function (): void {
            $this->fail('Hyvä autosave must not run on a PUDO event roundtrip.');
        });

        $this->assertSame(['street' => ['Easybox Lotus']], $this->form->address);
    }

    public function testLeavesTheAddressAloneWhenBootWasNotSeen(): void
    {
        $this->form->method('getRequest')->with('updates')->willReturn([
            ['type' => 'fireEvent', 'payload' => ['event' => 'innoship-pudo-selected', 'params' => []]],
        ]);
        $this->form->address = ['city' => 'Oradea'];

        $this->plugin->aroundDehydrate($this->form, function (): void {
            $this->fail('Hyvä autosave must not run on a PUDO event roundtrip.');
        });

        $this->assertSame(['city' => 'Oradea'], $this->form->address);
    }

    /**
     * boot() loads the quote address, then hydration overwrites it with the
     * browser's copy (a logged-in customer's saved address).
     */
    private function bootThenHydrate(array $quoteAddress): void
    {
        $this->form->address = $quoteAddress;
        $this->plugin->afterBoot($this->form, null);
        $this->form->address = ['street' => ['Str. Salvata 9'], 'city' => 'Bucuresti'];
    }

    public static function pudoEvents(): array
    {
        return [['innoship-pudo-selected'], ['innoship-pudo-cleared']];
    }

    public function testLeavesOtherRoundtripsToHyva(): void
    {
        $this->form->method('getRequest')->with('updates')->willReturn([
            ['type' => 'syncInput', 'payload' => ['name' => 'address.street.0', 'value' => 'Str. X 1']],
            ['type' => 'fireEvent', 'payload' => ['event' => 'shipping_address_activated', 'params' => []]],
        ]);
        $this->form->expects($this->never())->method('boot');

        $called = false;
        $this->plugin->aroundDehydrate($this->form, function () use (&$called): void {
            $called = true;
        });

        $this->assertTrue($called);
    }

    public function testLeavesPrecedingRequestsToHyva(): void
    {
        $this->assertFalse(RefreshAddressFormOnPudoSelected::isPudoEventRequest(null));
        $this->assertFalse(RefreshAddressFormOnPudoSelected::isPudoEventRequest([]));
    }
}
