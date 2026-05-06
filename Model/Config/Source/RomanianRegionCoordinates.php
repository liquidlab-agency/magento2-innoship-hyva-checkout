<?php
/**
 * Copyright © - LiquidLab Agency - All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Liquidlab\InnoShipHyva\Model\Config\Source;

class RomanianRegionCoordinates
{
    /**
     * Map of Romanian region ISO 3166-2 codes to approximate centroid coordinates.
     */
    private const COORDINATES = [
        'AB' => ['lat' => 46.0667, 'lng' => 23.5833],
        'AR' => ['lat' => 46.1866, 'lng' => 21.3123],
        'AG' => ['lat' => 44.8565, 'lng' => 24.8692],
        'BC' => ['lat' => 46.5670, 'lng' => 26.9146],
        'BH' => ['lat' => 47.0465, 'lng' => 21.9189],
        'BN' => ['lat' => 47.1333, 'lng' => 24.5000],
        'BT' => ['lat' => 47.7414, 'lng' => 26.6617],
        'BV' => ['lat' => 45.6427, 'lng' => 25.5887],
        'BR' => ['lat' => 45.2692, 'lng' => 27.9574],
        'B'  => ['lat' => 44.4268, 'lng' => 26.1025],
        'BZ' => ['lat' => 45.1500, 'lng' => 26.8333],
        'CL' => ['lat' => 44.2000, 'lng' => 27.3333],
        'CS' => ['lat' => 45.3000, 'lng' => 21.8833],
        'CJ' => ['lat' => 46.7712, 'lng' => 23.6236],
        'CT' => ['lat' => 44.1598, 'lng' => 28.6348],
        'CV' => ['lat' => 45.8667, 'lng' => 25.7833],
        'DB' => ['lat' => 44.9167, 'lng' => 25.4500],
        'DJ' => ['lat' => 44.3302, 'lng' => 23.7949],
        'GL' => ['lat' => 45.4353, 'lng' => 28.0080],
        'GR' => ['lat' => 43.9000, 'lng' => 25.9667],
        'GJ' => ['lat' => 45.0333, 'lng' => 23.2833],
        'HR' => ['lat' => 46.3667, 'lng' => 25.8000],
        'HD' => ['lat' => 45.7500, 'lng' => 22.9000],
        'IL' => ['lat' => 44.1667, 'lng' => 27.9500],
        'IS' => ['lat' => 47.1585, 'lng' => 27.6014],
        'IF' => ['lat' => 44.4500, 'lng' => 26.1000],
        'MM' => ['lat' => 47.6587, 'lng' => 23.5681],
        'MH' => ['lat' => 44.6333, 'lng' => 22.6667],
        'MS' => ['lat' => 46.5427, 'lng' => 24.5574],
        'NT' => ['lat' => 46.9266, 'lng' => 26.3699],
        'OT' => ['lat' => 44.4333, 'lng' => 24.3667],
        'PH' => ['lat' => 44.9414, 'lng' => 26.0297],
        'SM' => ['lat' => 47.7903, 'lng' => 22.8756],
        'SJ' => ['lat' => 47.2000, 'lng' => 23.0667],
        'SB' => ['lat' => 45.7983, 'lng' => 24.1256],
        'SV' => ['lat' => 47.6514, 'lng' => 26.2551],
        'TR' => ['lat' => 43.9833, 'lng' => 25.3333],
        'TM' => ['lat' => 45.7489, 'lng' => 21.2087],
        'TL' => ['lat' => 45.1667, 'lng' => 28.8000],
        'VL' => ['lat' => 45.1167, 'lng' => 24.3667],
        'VS' => ['lat' => 46.6333, 'lng' => 27.7333],
        'VN' => ['lat' => 45.1000, 'lng' => 26.6333],
    ];

    /**
     * @return array<string, array{lat: float, lng: float}>
     */
    public function getAll(): array
    {
        return self::COORDINATES;
    }

    /**
     * @return array{lat: float, lng: float}|null
     */
    public function getByCode(string $regionCode): ?array
    {
        return self::COORDINATES[$regionCode] ?? null;
    }
}
