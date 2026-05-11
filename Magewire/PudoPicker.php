<?php
/**
 * Copyright © - LiquidLab Agency - All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Liquidlab\InnoShipHyva\Magewire;

use Liquidlab\InnoShipHyva\Api\Data\PudoInterface;
use Liquidlab\InnoShipHyva\Api\PudoRepositoryInterface;
use Liquidlab\InnoShipHyva\Model\RegionCoordinatesProvider;
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
    private const SEARCH_RADIUS_KM = 50;

    public ?string $selectedCounty = '';
    public ?string $selectedCity = '';
    public bool $showModal = false;

    public function __construct(
        private readonly CartRepositoryInterface $quoteRepository,
        private readonly SessionCheckout $sessionCheckout,
        private readonly LoggerInterface $logger,
        private readonly PudoRepositoryInterface $pudoRepository,
        private readonly RegionCoordinatesProvider $regionCoordinatesProvider,
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
                $this->convertPaymentInfoToJson($pudo->getSupportedPaymentType())
            ),
            'selected_county' => $this->selectedCounty,
            'selected_city' => $this->selectedCity,
        ];
    }

    /**
     * Build the data payload consumed by the Alpine component.
     *
     * Computed on demand instead of being kept in the Magewire public state,
     * so we don't ship counties/cities/pins back and forth on every roundtrip.
     */
    public function getInnoShipData(): array
    {
        try {
            $this->resolveSearchState();
            $customerLocation = $this->getCustomerLocation();
            $pins = [];

            if (!empty($this->selectedCounty) && !empty($this->selectedCity)) {
                $pins = $this->fetchPudoPoints($this->selectedCounty, $this->selectedCity);
            } elseif ($customerLocation) {
                $pins = $this->filterPudoPointsByDistance(
                    $this->fetchPudoPoints(),
                    $customerLocation,
                    self::SEARCH_RADIUS_KM
                );
            }

            return [
                'pins' => $pins,
                'customerLocation' => $customerLocation,
                'counties' => $this->getCounties(),
                'cities' => $this->getCities(),
                'selectedCounty' => $this->selectedCounty,
                'selectedCity' => $this->selectedCity,
            ];
        } catch (\Exception $e) {
            $this->logger->error('InnoShipHyva: Failed to get InnoShip data: ' . $e->getMessage());
            return ['pins' => [], 'counties' => [], 'cities' => []];
        }
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

    public function updatedSelectedCounty(): void
    {
        $this->selectedCity = '';

        $pudoData = $this->sessionCheckout->getData(self::INNOSHIP_PUDO_SESSION_KEY) ?: [];
        $pudoData['selected_county'] = $this->selectedCounty;
        $pudoData['selected_city'] = '';
        $this->sessionCheckout->setData(self::INNOSHIP_PUDO_SESSION_KEY, $pudoData);

        $this->dispatchBrowserEvent('innoship-pudo-data-updated', ['data' => $this->getInnoShipData()]);
    }

    public function updatedSelectedCity(): void
    {
        $pudoData = $this->sessionCheckout->getData(self::INNOSHIP_PUDO_SESSION_KEY) ?: [];
        $pudoData['selected_city'] = $this->selectedCity;
        $this->sessionCheckout->setData(self::INNOSHIP_PUDO_SESSION_KEY, $pudoData);

        $data = $this->getInnoShipData();
        $this->dispatchBrowserEvent('innoship-pudo-points-updated', ['pins' => $data['pins']]);
        $this->dispatchBrowserEvent('innoship-pudo-data-updated', ['data' => $data]);
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
     * @return array<int, array<string, mixed>>
     */
    private function fetchPudoPoints(?string $county = null, ?string $city = null): array
    {
        $points = $this->pudoRepository->getActivePudoPoints($county, $city);

        return array_map(fn(PudoInterface $pudo) => $this->serializePudo($pudo), $points);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializePudo(PudoInterface $pudo): array
    {
        $hours = $pudo->getOpenHours();

        return [
            'pudo_id' => $pudo->getPudoId(),
            'courier_id' => $pudo->getCourierId(),
            'name' => $pudo->getName(),
            'address' => $pudo->getAddressText(),
            'city' => $pudo->getLocalityName(),
            'latitude' => $pudo->getLatitude(),
            'longitude' => $pudo->getLongitude(),
            'type' => $pudo->getFixedLocationTypeId(),
            'accepted_payment_type' => $this->convertPaymentInfoToJson($pudo->getSupportedPaymentType()),
            'phone_number' => $pudo->getPhone() ?? '',
            'mo_start' => $hours['mo']['start'],
            'mo_end' => $hours['mo']['end'],
            'tu_start' => $hours['tu']['start'],
            'tu_end' => $hours['tu']['end'],
            'we_start' => $hours['we']['start'],
            'we_end' => $hours['we']['end'],
            'th_start' => $hours['th']['start'],
            'th_end' => $hours['th']['end'],
            'fr_start' => $hours['fr']['start'],
            'fr_end' => $hours['fr']['end'],
            'sa_start' => $hours['sa']['start'],
            'sa_end' => $hours['sa']['end'],
            'su_start' => $hours['su']['start'],
            'su_end' => $hours['su']['end'],
        ];
    }

    private function convertPaymentInfoToJson(?string $paymentType): string
    {
        if (empty($paymentType)) {
            return json_encode([]);
        }

        $types = array_map('trim', explode(',', $paymentType));
        $result = [];
        foreach ($types as $type) {
            if ($type === 'Cash') $result['Cash'] = true;
            if ($type === 'Card') $result['Card'] = true;
            if ($type === 'Online') $result['Online'] = true;
        }

        return (string)json_encode($result);
    }

    private function getCustomerLocation(): ?array
    {
        try {
            $quote = $this->sessionCheckout->getQuote();
            $shippingAddress = $quote->getShippingAddress();

            if (!$shippingAddress || $shippingAddress->getCountryId() !== 'RO') {
                return null;
            }

            $regionId = (int)$shippingAddress->getRegionId();
            if ($regionId <= 0) {
                return null;
            }

            $coordinates = $this->regionCoordinatesProvider->getByRegionId($regionId);
            if (!$coordinates) {
                return null;
            }

            return [
                'lat' => $coordinates['lat'],
                'lng' => $coordinates['lng'],
                'region' => $shippingAddress->getRegion(),
                'region_id' => $regionId,
            ];
        } catch (\Exception $e) {
            return null;
        }
    }

    private function filterPudoPointsByDistance(array $pudoPoints, array $customerLocation, float $radiusKm): array
    {
        $customerLat = (float)$customerLocation['lat'];
        $customerLng = (float)$customerLocation['lng'];
        $filteredPoints = [];

        foreach ($pudoPoints as $point) {
            $distance = $this->calculateDistance(
                $customerLat,
                $customerLng,
                (float)$point['latitude'],
                (float)$point['longitude']
            );
            if ($distance <= $radiusKm) {
                $point['distance_km'] = round($distance, 2);
                $filteredPoints[] = $point;
            }
        }

        usort($filteredPoints, fn($a, $b) => $a['distance_km'] <=> $b['distance_km']);
        return $filteredPoints;
    }

    private function calculateDistance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) * sin($dLat / 2)
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) * sin($dLng / 2);
        return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
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
