<?php
/**
 * Copyright © - LiquidLab Agency - All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Liquidlab\InnoShipHyva\Test\Unit\Magewire;

use Liquidlab\InnoShipHyva\Api\PudoRepositoryInterface;
use Liquidlab\InnoShipHyva\Magewire\PudoPicker;
use Liquidlab\InnoShipHyva\Model\PudoPointsProvider;
use Liquidlab\InnoShipHyva\Model\RegionResolver;
use Magento\Checkout\Model\Session as SessionCheckout;
use Magento\Framework\DataObject;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\AddressExtensionFactory;
use Magento\Quote\Model\Quote;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The picker sends the Alpine component a small search context, never the
 * pins: those come from the points endpoint.
 */
class PudoPickerContextTest extends TestCase
{
    private const SESSION_KEY = 'innoship_selected_pudo_point';
    private const BUCHAREST_REGION_ID = 42;

    private SessionCheckout&MockObject $sessionCheckout;
    private PudoRepositoryInterface&MockObject $pudoRepository;
    private PudoPicker $picker;

    /** @var array<string, mixed> */
    private array $session = [];

    protected function setUp(): void
    {
        // SessionManager declares getData(); setData() reaches the session through __call().
        $this->sessionCheckout = $this->createMock(SessionCheckout::class);
        $this->sessionCheckout->method('getData')->willReturnCallback(fn ($key) => $this->session[$key] ?? null);
        $this->sessionCheckout->method('__call')->willReturnCallback(function (string $method, array $args) {
            if ($method === 'setData') {
                $this->session[$args[0]] = $args[1];
            }
            return $this->sessionCheckout;
        });

        $this->pudoRepository = $this->createMock(PudoRepositoryInterface::class);
        $this->pudoRepository->expects($this->never())->method('getActivePudoPoints');

        $this->picker = new PudoPicker(
            $this->createMock(CartRepositoryInterface::class),
            $this->sessionCheckout,
            $this->createMock(LoggerInterface::class),
            $this->pudoRepository,
            $this->createMock(PudoPointsProvider::class),
            $this->createMock(RegionResolver::class),
            $this->createMock(AddressExtensionFactory::class)
        );
    }

    public function testContextCarriesTheSelectionAndTheRomanianShippingRegion(): void
    {
        $this->givenShippingAddress('RO', self::BUCHAREST_REGION_ID);
        $this->picker->selectedCounty = 'Bucuresti';
        $this->picker->selectedCity = 'Sector 1';

        $this->assertSame(
            ['selectedCounty' => 'Bucuresti', 'selectedCity' => 'Sector 1', 'regionId' => self::BUCHAREST_REGION_ID],
            $this->picker->getPickerContext()
        );
    }

    public function testContextHasNoRegionOutsideRomania(): void
    {
        $this->givenShippingAddress('HU', 5);

        $this->assertSame(0, $this->picker->getPickerContext()['regionId']);
    }

    public function testContextRestoresTheSelectionFromTheSession(): void
    {
        $this->givenShippingAddress('RO', 0);
        $this->session[self::SESSION_KEY] = ['selected_county' => 'Cluj', 'selected_city' => 'Cluj-Napoca'];
        $this->picker->selectedCounty = '';
        $this->picker->selectedCity = '';

        $context = $this->picker->getPickerContext();

        $this->assertSame('Cluj', $context['selectedCounty']);
        $this->assertSame('Cluj-Napoca', $context['selectedCity']);
    }

    public function testCountyChangeReturnsTheCountyAndSendsTheContextWithoutPins(): void
    {
        $this->givenShippingAddress('RO', self::BUCHAREST_REGION_ID);
        // Magewire assigns the new value, runs the hook, then assigns what the hook returns.
        $this->picker->selectedCounty = 'Cluj';
        $this->picker->selectedCity = 'Bucuresti';

        $this->assertSame('Cluj', $this->picker->updatedSelectedCounty());
        $this->assertSame('', $this->picker->selectedCity);
        $this->assertSame(['selected_county' => 'Cluj', 'selected_city' => ''], $this->session[self::SESSION_KEY]);
        $this->assertSame(
            [[
                'event' => 'innoship-pudo-data-updated',
                'data' => ['data' => [
                    'selectedCounty' => 'Cluj',
                    'selectedCity' => '',
                    'regionId' => self::BUCHAREST_REGION_ID,
                ]],
            ]],
            $this->picker->getBrowserEvents()
        );
    }

    public function testCityChangeReturnsTheCityAndSendsOneContextEvent(): void
    {
        $this->givenShippingAddress('RO', self::BUCHAREST_REGION_ID);
        $this->session[self::SESSION_KEY] = ['selected_county' => 'Cluj', 'selected_city' => ''];
        $this->picker->selectedCounty = 'Cluj';
        $this->picker->selectedCity = 'Cluj-Napoca';

        $this->assertSame('Cluj-Napoca', $this->picker->updatedSelectedCity());
        $this->assertSame('Cluj-Napoca', $this->session[self::SESSION_KEY]['selected_city']);

        $events = $this->picker->getBrowserEvents();
        $this->assertSame(['innoship-pudo-data-updated'], array_column($events, 'event'));
        $this->assertSame(
            ['selectedCounty' => 'Cluj', 'selectedCity' => 'Cluj-Napoca', 'regionId' => self::BUCHAREST_REGION_ID],
            $events[0]['data']['data']
        );
    }

    public function testOpeningTheMapSendsTheCurrentContext(): void
    {
        $this->givenShippingAddress('RO', self::BUCHAREST_REGION_ID);

        $this->picker->setModalOpen(true);

        $this->assertTrue($this->picker->showModal);
        $this->assertSame(
            ['selectedCounty' => '', 'selectedCity' => '', 'regionId' => self::BUCHAREST_REGION_ID],
            $this->picker->getBrowserEvents()[0]['data']['data']
        );
    }

    public function testClosingTheMapSendsNothing(): void
    {
        $this->picker->showModal = true;

        $this->picker->setModalOpen(false);

        $this->assertFalse($this->picker->showModal);
        $this->assertSame([], $this->picker->getBrowserEvents());
    }

    private function givenShippingAddress(string $countryId, int $regionId): void
    {
        $address = new DataObject(['country_id' => $countryId, 'region_id' => $regionId]);
        $quote = $this->createMock(Quote::class);
        $quote->method('getShippingAddress')->willReturn($address);
        $this->sessionCheckout->method('getQuote')->willReturn($quote);
    }
}
