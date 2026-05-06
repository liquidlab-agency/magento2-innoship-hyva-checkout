<?php
/**
 * Copyright © - LiquidLab Agency - All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Liquidlab\InnoShipHyva\Api;

use Liquidlab\InnoShipHyva\Api\Data\PudoInterface;
use Magento\Framework\Exception\NoSuchEntityException;

interface PudoRepositoryInterface
{
    /**
     * @param int $pudoId
     * @return PudoInterface
     * @throws NoSuchEntityException
     */
    public function getByPudoId(int $pudoId): PudoInterface;

    /**
     * Return distinct active county names (sorted asc).
     *
     * @return string[]
     */
    public function getCounties(): array;

    /**
     * Return distinct active city names for the given county (sorted asc).
     *
     * @param string $county
     * @return string[]
     */
    public function getCitiesByCounty(string $county): array;

    /**
     * Fetch active PUDO points filtered by optional county/city.
     *
     * @param string|null $county
     * @param string|null $city
     * @return PudoInterface[]
     */
    public function getActivePudoPoints(?string $county = null, ?string $city = null): array;
}
