<?php
/**
 * Copyright © - LiquidLab Agency - All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Liquidlab\InnoShipHyva\Model;

use Liquidlab\InnoShipHyva\Api\Data\PudoInterface;
use Liquidlab\InnoShipHyva\Api\PudoRepositoryInterface;
use Magento\Framework\App\Cache\Type\Collection as CollectionCache;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\SerializerInterface;

/**
 * Pickup points for the checkout map, served as JSON by the
 * innoshiphyva/pudo/points endpoint (Controller\Pudo\Points).
 *
 * The picker used to print these points into the checkout page and into every
 * Magewire update of its component: close to 2 MB around Bucharest. The
 * Alpine component now fetches them when the map opens.
 *
 * Results are cached for CACHE_LIFETIME seconds, since the region search reads
 * every active point. They carry the collections cache tag, so flushing the
 * Magento cache clears them.
 */
class PudoPointsProvider
{
    public const SEARCH_RADIUS_KM = 50;
    public const CACHE_LIFETIME = 3600;

    private const CACHE_KEY_PREFIX = 'liquidlab_innoship_pudo_points_';

    public function __construct(
        private readonly PudoRepositoryInterface $pudoRepository,
        private readonly RegionCoordinatesProvider $regionCoordinatesProvider,
        private readonly CacheInterface $cache,
        private readonly SerializerInterface $serializer
    ) {
    }

    /**
     * The active points in one city.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getByCity(string $county, string $city): array
    {
        return $this->cached(
            'city_' . sha1($county . '|' . $city),
            fn (): array => $this->serializePoints($this->pudoRepository->getActivePudoPoints($county, $city))
        );
    }

    /**
     * The active points within SEARCH_RADIUS_KM of a Romanian region's centre,
     * nearest first, and that centre as `customerLocation`. Both are empty for a
     * region without known coordinates.
     *
     * @return array{
     *     customerLocation: array{lat: float, lng: float, region_id: int}|null,
     *     pins: array<int, array<string, mixed>>
     * }
     */
    public function getNearRegion(int $regionId): array
    {
        $coordinates = $this->regionCoordinatesProvider->getByRegionId($regionId);
        if (!$coordinates) {
            return ['customerLocation' => null, 'pins' => []];
        }

        $lat = (float)$coordinates['lat'];
        $lng = (float)$coordinates['lng'];

        return [
            'customerLocation' => ['lat' => $lat, 'lng' => $lng, 'region_id' => $regionId],
            'pins' => $this->cached(
                'region_' . $regionId,
                fn (): array => $this->filterByDistance(
                    $this->serializePoints($this->pudoRepository->getActivePudoPoints()),
                    $lat,
                    $lng
                )
            ),
        ];
    }

    /**
     * The point's payment types as the JSON object the map popup and the session read.
     *
     * "Cash, Card" becomes {"Cash":true,"Card":true}.
     */
    public function getPaymentTypesJson(?string $paymentType): string
    {
        if (empty($paymentType)) {
            return (string)json_encode([]);
        }

        $result = [];
        foreach (array_map('trim', explode(',', $paymentType)) as $type) {
            if (in_array($type, ['Cash', 'Card', 'Online'], true)) {
                $result[$type] = true;
            }
        }

        return (string)json_encode($result);
    }

    /**
     * @param callable(): array $load
     */
    private function cached(string $key, callable $load): array
    {
        $cacheKey = self::CACHE_KEY_PREFIX . $key;
        $cached = $this->cache->load($cacheKey);
        if (is_string($cached) && $cached !== '') {
            try {
                $data = $this->serializer->unserialize($cached);
            } catch (\InvalidArgumentException $e) {
                $data = null;
            }
            if (is_array($data)) {
                return $data;
            }
        }

        $data = $load();
        $this->cache->save(
            (string)$this->serializer->serialize($data),
            $cacheKey,
            [CollectionCache::CACHE_TAG],
            self::CACHE_LIFETIME
        );

        return $data;
    }

    /**
     * @param PudoInterface[] $points
     * @return array<int, array<string, mixed>>
     */
    private function serializePoints(array $points): array
    {
        return array_map(fn (PudoInterface $pudo): array => $this->serializePudo($pudo), array_values($points));
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
            'postal_code' => (string)$pudo->getPostalCode(),
            'latitude' => $pudo->getLatitude(),
            'longitude' => $pudo->getLongitude(),
            'type' => $pudo->getFixedLocationTypeId(),
            'accepted_payment_type' => $this->getPaymentTypesJson($pudo->getSupportedPaymentType()),
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

    /**
     * @param array<int, array<string, mixed>> $points
     * @return array<int, array<string, mixed>>
     */
    private function filterByDistance(array $points, float $lat, float $lng): array
    {
        $nearby = [];
        foreach ($points as $point) {
            $distance = $this->calculateDistance($lat, $lng, (float)$point['latitude'], (float)$point['longitude']);
            if ($distance <= self::SEARCH_RADIUS_KM) {
                $point['distance_km'] = round($distance, 2);
                $nearby[] = $point;
            }
        }

        usort($nearby, fn (array $a, array $b): int => $a['distance_km'] <=> $b['distance_km']);

        return $nearby;
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
}
