<?php
/**
 * Copyright © - LiquidLab Agency - All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Liquidlab\InnoShipHyva\Magewire;

use Liquidlab\InnoShipHyva\Api\Data\PudoInterface;
use Liquidlab\InnoShipHyva\Api\PudoRepositoryInterface;
use Liquidlab\InnoShipHyva\Model\Config\PaymentRestrictionConfig;
use Liquidlab\InnoShipHyva\Model\PudoPointsProvider;
use Liquidlab\InnoShipHyva\Model\RegionResolver;
use Magento\Checkout\Model\Session as SessionCheckout;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\AddressExtensionFactory;
use Magewirephp\Magewire\Component;
use Psr\Log\LoggerInterface;

class PudoPicker extends Component
{
    private const INNOSHIP_PUDO_SESSION_KEY = 'innoship_selected_pudo_point';

    public ?string $selectedCounty = '';
    public ?string $selectedCity = '';
    public bool $showModal = false;

    public function __construct(
        private readonly CartRepositoryInterface $quoteRepository,
        private readonly SessionCheckout $sessionCheckout,
        private readonly LoggerInterface $logger,
        private readonly PudoRepositoryInterface $pudoRepository,
        private readonly PudoPointsProvider $pudoPointsProvider,
        private readonly RegionResolver $regionResolver,
        private readonly AddressExtensionFactory $addressExtensionFactory
    ) {
    }

    public function mount(): void
    {
        try {
            $pudoData = $this->sessionCheckout->getData(self::INNOSHIP_PUDO_SESSION_KEY);
            if (is_array($pudoData)) {
                $this->selectedCounty = $pudoData['selected_county'] ?? '';
                $this->selectedCity = $pudoData['selected_city'] ?? '';

                $this->reconcileQuoteWithSessionPudo($pudoData);
            }
        } catch (\Exception $e) {
            $this->logger->error('InnoShipHyva PudoPicker mount error: ' . $e->getMessage());
        }
    }

    /**
     * If the session holds a selected PUDO but the quote shipping address has
     * lost it (fresh quote, address re-entered, etc.), re-apply the selection
     * so order submission carries the PUDO ID. Idempotent: returns early when
     * the IDs already match.
     */
    private function reconcileQuoteWithSessionPudo(array $pudoData): void
    {
        if (empty($pudoData['pudo_id'])) {
            return;
        }

        try {
            $quote = $this->sessionCheckout->getQuote();
            $shippingAddress = $quote->getShippingAddress();
            if (!$shippingAddress) {
                return;
            }

            // Never re-stamp the session's locker pudo onto a quote that is no
            // longer on a locker method. PudoPicker's block renders on every
            // checkout load (before.body.end), so mount() runs even after Hyvä's
            // auto-select flipped the method to a courier or the customer switched
            // away — reconciling here would recreate the stale-pudo-on-courier
            // state that hides cash-on-delivery and mis-routes the AWB to the
            // EasyBox. An empty method is left to reconcile (the genuine
            // "restore a lost selection on a fresh quote" case). Clearing the
            // courier quote's pudo is owned by ClearStalePudoOnShippingMethodSet /
            // ClearPudoOnShippingMethodChange.
            $shippingMethod = (string)$shippingAddress->getShippingMethod();
            if ($shippingMethod !== ''
                && strpos($shippingMethod, PaymentRestrictionConfig::LOCKER_CARRIER_CODE) === false
            ) {
                return;
            }

            if ((string)$shippingAddress->getInnoshipPudoId() === (string)$pudoData['pudo_id']) {
                return;
            }

            // Re-fetch the entity so reconciliation applies the same
            // structured fields the selection flow uses (street/city/
            // postcode/country/region) rather than trusting stale session
            // data that may pre-date a schema change.
            try {
                $pudo = $this->pudoRepository->getByPudoId((int)$pudoData['pudo_id']);
            } catch (NoSuchEntityException $e) {
                $this->logger->warning(
                    'InnoShipHyva: session references a PUDO that is no longer in the database: '
                    . $pudoData['pudo_id']
                );
                return;
            }

            if (!$pudo->isActive()) {
                $this->logger->warning(
                    'InnoShipHyva: session references a PUDO that Innoship has deactivated: ' . $pudoData['pudo_id']
                );
                return;
            }

            $this->updateShippingAddressWithPudo($pudo);
        } catch (\Exception $e) {
            $this->logger->warning(
                'InnoShipHyva: reconcileQuoteWithSessionPudo failed: ' . $e->getMessage()
            );
        }
    }

    public function selectPudoPoint(string $pudoId): void
    {
        try {
            $pudo = $this->pudoRepository->getByPudoId((int)$pudoId);
        } catch (NoSuchEntityException $e) {
            $pudo = null;
        }

        // The map caches its points, so it can still offer a locker that
        // Innoship has deactivated or removed since.
        if ($pudo === null || !$pudo->isActive()) {
            $this->logger->warning('InnoShipHyva: pickup point ' . $pudoId . ' is no longer available');
            $this->dispatchErrorMessage(
                (string)__('This pickup point is no longer available. Please select another one.')
            );
            $this->dispatchBrowserEvent('innoship-pudo-unavailable', ['pudoId' => $pudoId]);
            return;
        }

        try {
            $this->sessionCheckout->setData(
                self::INNOSHIP_PUDO_SESSION_KEY,
                $this->buildSessionPudoData($pudo)
            );

            $this->updateShippingAddressWithPudo($pudo);

            $this->logger->info(
                'InnoShipHyva: Successfully selected PUDO: ' . $pudo->getName() . ' (ID: ' . $pudoId . ')'
            );

            $this->emit('innoship-pudo-selected');
        } catch (\Exception $e) {
            $this->logger->error('InnoShipHyva: Failed to select PUDO: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Serializes the PUDO entity into the array we keep in the checkout
     * session. Note: the row is already structured — `address` is the
     * street only, `city` is the locality, etc. — so DOWNSTREAM CODE
     * MUST NOT try to parse `address` for city/postcode. Use the
     * dedicated keys instead.
     */
    private function buildSessionPudoData(PudoInterface $pudo): array
    {
        return [
            'pudo_id' => (string)$pudo->getPudoId(),
            'courier_id' => (string)$pudo->getCourierId(),
            'name' => $pudo->getName(),
            'address' => (string)$pudo->getAddressText(),
            'city' => $pudo->getLocalityName(),
            'postal_code' => (string)$pudo->getPostalCode(),
            'country_code' => (string)$pudo->getCountryCode(),
            'county_name' => (string)$pudo->getCountyName(),
            'latitude' => (string)$pudo->getLatitude(),
            'longitude' => (string)$pudo->getLongitude(),
            'type' => (string)$pudo->getFixedLocationTypeId(),
            'payment_info' => $this->formatPaymentInfo(
                $this->pudoPointsProvider->getPaymentTypesJson($pudo->getSupportedPaymentType())
            ),
            'selected_county' => $this->selectedCounty,
            'selected_city' => $this->selectedCity,
        ];
    }

    /**
     * What the Alpine component needs to fetch its pins from the points
     * endpoint: the chosen county and city, or else the quote's shipping region.
     *
     * The pins themselves stay out of this component. Printed into its template
     * they made the checkout HTML, and every Magewire update of the component,
     * about 2 MB larger.
     *
     * @return array{selectedCounty: string, selectedCity: string, regionId: int}
     */
    public function getPickerContext(): array
    {
        $this->resolveSearchState();

        return [
            'selectedCounty' => (string)$this->selectedCounty,
            'selectedCity' => (string)$this->selectedCity,
            'regionId' => $this->getShippingRegionId(),
        ];
    }

    public function getCounties(): array
    {
        try {
            return $this->pudoRepository->getCounties();
        } catch (\Exception $e) {
            $this->logger->error('InnoShipHyva: Failed to fetch counties: ' . $e->getMessage());
            return [];
        }
    }

    public function getCities(): array
    {
        $this->resolveSearchState();

        if (empty($this->selectedCounty)) {
            return [];
        }

        try {
            return $this->pudoRepository->getCitiesByCounty((string)$this->selectedCounty);
        } catch (\Exception $e) {
            $this->logger->error(
                'InnoShipHyva: Failed to fetch cities for county ' . $this->selectedCounty . ': ' . $e->getMessage()
            );
            return [];
        }
    }

    /**
     * Magewire assigns what an updated* hook returns to the property, so each
     * hook returns the value it was given; returning nothing reset the choice
     * to null.
     */
    public function updatedSelectedCounty(): ?string
    {
        $this->selectedCity = '';

        $pudoData = $this->sessionCheckout->getData(self::INNOSHIP_PUDO_SESSION_KEY) ?: [];
        $pudoData['selected_county'] = $this->selectedCounty;
        $pudoData['selected_city'] = '';
        $this->sessionCheckout->setData(self::INNOSHIP_PUDO_SESSION_KEY, $pudoData);

        $this->dispatchBrowserEvent('innoship-pudo-data-updated', ['data' => $this->getPickerContext()]);

        return $this->selectedCounty;
    }

    public function updatedSelectedCity(): ?string
    {
        $pudoData = $this->sessionCheckout->getData(self::INNOSHIP_PUDO_SESSION_KEY) ?: [];
        $pudoData['selected_city'] = $this->selectedCity;
        $this->sessionCheckout->setData(self::INNOSHIP_PUDO_SESSION_KEY, $pudoData);

        $this->dispatchBrowserEvent('innoship-pudo-data-updated', ['data' => $this->getPickerContext()]);

        return $this->selectedCity;
    }

    /**
     * Opens or closes the map. The Alpine component calls this instead of
     * $wire.set('showModal'), because Magewire's $set assigns the property
     * without calling updated* hooks. Opening sends the current context, so
     * the map follows a shipping region the customer entered after the page
     * loaded.
     */
    public function setModalOpen(bool $open): void
    {
        $this->showModal = $open;

        if ($open) {
            $this->dispatchBrowserEvent('innoship-pudo-data-updated', ['data' => $this->getPickerContext()]);
        }
    }

    private function resolveSearchState(): void
    {
        if (empty($this->selectedCounty)) {
            $pudoData = $this->sessionCheckout->getData(self::INNOSHIP_PUDO_SESSION_KEY);
            if (is_array($pudoData)) {
                if (!empty($pudoData['selected_county'])) {
                    $this->selectedCounty = $pudoData['selected_county'];
                }
                if (empty($this->selectedCity) && !empty($pudoData['selected_city'])) {
                    $this->selectedCity = $pudoData['selected_city'];
                }
            }
        }
    }

    /**
     * The quote's Romanian shipping region, which the map centres on until the
     * customer picks a county and city; 0 when there is none.
     */
    private function getShippingRegionId(): int
    {
        try {
            $shippingAddress = $this->sessionCheckout->getQuote()->getShippingAddress();
            if (!$shippingAddress || $shippingAddress->getCountryId() !== 'RO') {
                return 0;
            }

            return max(0, (int)$shippingAddress->getRegionId());
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Overwrite the quote shipping address with the PUDO's structured fields.
     *
     * The PUDO record already gives us a clean breakdown — `getAddressText()`
     * is the street, `getLocalityName()` is the city, etc. — so we map them
     * 1:1 instead of running any parser over a flat address string.
     *
     * We also set `country_id` and `region_id`/`region` (via
     * {@see RegionResolver}) so the form passes Magento address validation
     * even when the customer had not entered an address yet.
     */
    private function updateShippingAddressWithPudo(PudoInterface $pudo): void
    {
        try {
            $quote = $this->sessionCheckout->getQuote();
            $shippingAddress = $quote->getShippingAddress();
            if (!$shippingAddress) {
                return;
            }

            $countryCode = (string)$pudo->getCountryCode() !== ''
                ? (string)$pudo->getCountryCode()
                : 'RO';

            $regionInfo = $this->regionResolver->resolveByName(
                (string)$pudo->getCountyName(),
                $countryCode
            );

            $shippingAddress->setCompany($pudo->getName());
            $shippingAddress->setStreet([(string)$pudo->getAddressText()]);
            $shippingAddress->setCity($pudo->getLocalityName());
            $shippingAddress->setPostcode((string)$pudo->getPostalCode());
            $shippingAddress->setCountryId($countryCode);
            $shippingAddress->setRegion($regionInfo['region']);
            if ($regionInfo['region_id'] !== null) {
                $shippingAddress->setRegionId($regionInfo['region_id']);
            }

            // Invoices cannot be issued to a locker address.
            // If the billing address was a mirror of shipping (now a locker),
            // clear its location fields so the customer is forced to enter
            // their own billing details, but preserve their identity.
            if ($shippingAddress->getSameAsBilling()) {
                if ($billingAddress = $quote->getBillingAddress()) {
                    $billingAddress->setCompany(null);
                    $billingAddress->setStreet([]);
                    $billingAddress->setCity(null);
                    $billingAddress->setPostcode(null);
                    $billingAddress->setRegion(null);
                    $billingAddress->setRegionId(null);
                }
            }

            // Force "billing same as shipping" OFF on the canonical quote flag,
            // so BillingDetails::boot() sees the right value on next
            // roundtrip (Plugin\HyvaCheckout\LockBillingAsShippingForPudo
            // is the defensive belt; this is the suspenders).
            $shippingAddress->setSameAsBilling(false);

            $pudoIdString = (string)$pudo->getPudoId();
            $courierIdString = (string)$pudo->getCourierId();

            $shippingAddress->setInnoshipPudoId($pudoIdString);
            $shippingAddress->setInnoshipCourierId($courierIdString);

            $quote->setInnoshipPudoId($pudoIdString);
            $quote->setInnoshipCourierId($courierIdString);

            $extensionAttributes = $shippingAddress->getExtensionAttributes() ?: $this->addressExtensionFactory->create();
            if (method_exists($extensionAttributes, 'setInnoshipPudoId')) {
                $extensionAttributes->setInnoshipPudoId($pudo->getPudoId());
            }
            if (method_exists($extensionAttributes, 'setInnoshipCourierId')) {
                $extensionAttributes->setInnoshipCourierId($pudo->getCourierId());
            }
            $shippingAddress->setExtensionAttributes($extensionAttributes);

            $this->quoteRepository->save($quote);
        } catch (\Exception $e) {
            $this->logger->error('InnoShipHyva: Failed to update shipping address: ' . $e->getMessage());
        }
    }

    private function formatPaymentInfo(?string $acceptedPaymentType): string
    {
        if (empty($acceptedPaymentType)) {
            return '';
        }
        try {
            $payments = json_decode($acceptedPaymentType, true);
            $info = [];
            if (!empty($payments['Cash'])) {
                $info[] = __('Cash')->render();
            }
            if (!empty($payments['Card'])) {
                $info[] = __('Card')->render();
            }
            if (!empty($payments['Online'])) {
                $info[] = __('Online')->render();
            }
            return !empty($info) ? __('Payment methods')->render() . ': ' . implode(', ', $info) : '';
        } catch (\Exception $e) {
            return '';
        }
    }
}
