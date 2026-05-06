<?php
/**
 * Copyright © - LiquidLab Agency - All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Liquidlab\InnoShipHyva\Model;

use Liquidlab\InnoShipHyva\Api\Data\PudoInterface;
use Liquidlab\InnoShipHyva\Model\ResourceModel\Pudo as PudoResource;
use Magento\Framework\Model\AbstractModel;

class Pudo extends AbstractModel implements PudoInterface
{
    protected function _construct()
    {
        $this->_init(PudoResource::class);
    }

    public function getPudoId(): int
    {
        return (int)$this->getData(self::PUDO_ID);
    }

    public function getCourierId(): int
    {
        return (int)$this->getData(self::COURIER_ID);
    }

    public function getName(): string
    {
        return (string)$this->getData(self::NAME);
    }

    public function getCountyName(): string
    {
        return (string)$this->getData(self::COUNTY_NAME);
    }

    public function getLocalityName(): string
    {
        return (string)$this->getData(self::LOCALITY_NAME);
    }

    public function getAddressText(): ?string
    {
        $value = $this->getData(self::ADDRESS_TEXT);
        return $value !== null ? (string)$value : null;
    }

    public function getPostalCode(): ?string
    {
        $value = $this->getData(self::POSTAL_CODE);
        return $value !== null ? (string)$value : null;
    }

    public function getLatitude(): float
    {
        return (float)$this->getData(self::LATITUDE);
    }

    public function getLongitude(): float
    {
        return (float)$this->getData(self::LONGITUDE);
    }

    public function getFixedLocationTypeId(): int
    {
        return (int)$this->getData(self::FIXED_LOCATION_TYPE_ID);
    }

    public function getPhone(): ?string
    {
        $value = $this->getData(self::PHONE);
        return $value !== null ? (string)$value : null;
    }

    public function getSupportedPaymentType(): ?string
    {
        $value = $this->getData(self::SUPPORTED_PAYMENT_TYPE);
        return $value !== null ? (string)$value : null;
    }

    public function getOpenHours(): array
    {
        return [
            'mo' => ['start' => $this->getData('open_hours_mo_start'), 'end' => $this->getData('open_hours_mo_end')],
            'tu' => ['start' => $this->getData('open_hours_tu_start'), 'end' => $this->getData('open_hours_tu_end')],
            'we' => ['start' => $this->getData('open_hours_we_start'), 'end' => $this->getData('open_hours_we_end')],
            'th' => ['start' => $this->getData('open_hours_th_start'), 'end' => $this->getData('open_hours_th_end')],
            'fr' => ['start' => $this->getData('open_hours_fr_start'), 'end' => $this->getData('open_hours_fr_end')],
            'sa' => ['start' => $this->getData('open_hours_sa_start'), 'end' => $this->getData('open_hours_sa_end')],
            'su' => ['start' => $this->getData('open_hours_su_start'), 'end' => $this->getData('open_hours_su_end')],
        ];
    }
}
