<?php
/**
 * Copyright © - LiquidLab Agency - All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Liquidlab\InnoShipHyva\ViewModel;

use Liquidlab\InnoShipHyva\Api\PudoRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\View\Element\Block\ArgumentInterface;

class PudoInfo implements ArgumentInterface
{
    public function __construct(
        private readonly PudoRepositoryInterface $pudoRepository
    ) {
    }

    public function getPudoData(int $pudoId): ?array
    {
        if ($pudoId <= 0) {
            return null;
        }

        try {
            $pudo = $this->pudoRepository->getByPudoId($pudoId);
        } catch (NoSuchEntityException) {
            return null;
        }

        return [
            'pudo_id' => $pudo->getPudoId(),
            'name' => $pudo->getName(),
            'addressText' => $pudo->getAddressText(),
            'localityName' => $pudo->getLocalityName(),
            'countyName' => $pudo->getCountyName(),
            'postalCode' => $pudo->getPostalCode(),
            'latitude' => $pudo->getLatitude(),
            'longitude' => $pudo->getLongitude(),
            'phone' => $pudo->getPhone(),
            'courierId' => $pudo->getCourierId(),
        ];
    }
}
