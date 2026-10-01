<?php
/**
 * Copyright © - LiquidLab Agency - All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Liquidlab\InnoShipHyva\Controller\Pudo;

use Liquidlab\InnoShipHyva\Model\PudoPointsProvider;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Psr\Log\LoggerInterface;

/**
 * GET innoshiphyva/pudo/points — the pickup points the checkout map shows.
 *
 *   ?county=…&city=…  the active points in that city
 *   ?region_id=…      the active points near that Romanian region, and its
 *                     centre as `customerLocation`
 *
 * The answer depends on the query alone, never on the session, so browsers and
 * Varnish may keep it for PudoPointsProvider::CACHE_LIFETIME seconds.
 */
class Points implements HttpGetActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $resultJsonFactory,
        private readonly PudoPointsProvider $pudoPointsProvider,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): Json
    {
        $result = $this->resultJsonFactory->create();
        $county = $this->getStringParam('county');
        $city = $this->getStringParam('city');
        $regionId = (int)$this->getStringParam('region_id');

        try {
            if ($county !== '' && $city !== '') {
                $data = ['pins' => $this->pudoPointsProvider->getByCity($county, $city)];
            } elseif ($regionId > 0) {
                $data = $this->pudoPointsProvider->getNearRegion($regionId);
            } else {
                $data = ['pins' => []];
            }
        } catch (\Exception $e) {
            $this->logger->error('InnoShipHyva: Failed to load PUDO points: ' . $e->getMessage());

            return $result->setHttpResponseCode(500)
                ->setHeader('Cache-Control', 'no-store', true)
                ->setData(['pins' => []]);
        }

        $maxAge = PudoPointsProvider::CACHE_LIFETIME;

        return $result->setHeader('Cache-Control', 'public, max-age=' . $maxAge . ', s-maxage=' . $maxAge, true)
            ->setHeader('Pragma', 'cache', true)
            ->setHeader('Expires', gmdate('D, d M Y H:i:s', time() + $maxAge) . ' GMT', true)
            ->setData($data);
    }

    private function getStringParam(string $name): string
    {
        $value = $this->request->getParam($name);

        return is_string($value) ? trim($value) : '';
    }
}
