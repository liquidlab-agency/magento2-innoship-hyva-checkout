<?php
/**
 * Copyright © - LiquidLab Agency - All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Liquidlab\InnoShipHyva\Api\Data;

interface PudoInterface
{
    public const PUDO_ID = 'pudo_id';
    public const NAME = 'name';
    public const FIXED_LOCATION_TYPE_ID = 'fixedLocationTypeId';
    public const COURIER_ID = 'courierId';
    public const LOCALITY_NAME = 'localityName';
    public const COUNTY_NAME = 'countyName';
    public const COUNTRY_CODE = 'countryCode';
    public const ADDRESS_TEXT = 'addressText';
    public const POSTAL_CODE = 'postalCode';
    public const LONGITUDE = 'longitude';
    public const LATITUDE = 'latitude';
    public const IS_ACTIVE = 'isActive';
    public const PHONE = 'phone';
    public const SUPPORTED_PAYMENT_TYPE = 'supportedPaymentType';

    public function getPudoId(): int;

    public function getCourierId(): int;

    public function getName(): string;

    public function getCountyName(): string;

    public function getLocalityName(): string;

    public function getAddressText(): ?string;

    public function getPostalCode(): ?string;

    public function getLatitude(): float;

    public function getLongitude(): float;

    public function getFixedLocationTypeId(): int;

    public function getPhone(): ?string;

    public function getSupportedPaymentType(): ?string;

    public function getOpenHours(): array;

    public function getData($key = '', $index = null);
}
