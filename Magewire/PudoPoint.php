<?php
/**
 * Liquidlab InnoShipHyva - Hyva Checkout compatibility for InnoShip
 */

declare(strict_types=1);

namespace Liquidlab\InnoShipHyva\Magewire;

use Hyva\Checkout\Model\Magewire\Component\EvaluationInterface;
use Hyva\Checkout\Model\Magewire\Component\EvaluationResultFactory;
use Hyva\Checkout\Model\Magewire\Component\EvaluationResultInterface;
use Magento\Checkout\Model\Session as SessionCheckout;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Api\CartRepositoryInterface;
use Magewirephp\Magewire\Component;
use Psr\Log\LoggerInterface;

class PudoPoint extends Component implements EvaluationInterface
{
    private const INNOSHIP_PUDO_SESSION_KEY = 'innoship_selected_pudo_point';
    
    public string $pudoId = '';
    public string $pudoName = '';
    public string $pudoAddress = '';
    public string $pudoCity = '';
    public string $pudoLatitude = '';
    public string $pudoLongitude = '';
    public string $pudoType = '';
    public string $pudoCourierId = '';
    public string $paymentInfo = '';

    protected $listeners = [
        'innoship-pudo-selected' => 'onPudoSelected'
    ];

    public function __construct(
        private readonly CartRepositoryInterface $quoteRepository,
        private readonly SessionCheckout $sessionCheckout,
        private readonly LoggerInterface $logger
    ) {
    }

    public function evaluateCompletion(EvaluationResultFactory $resultFactory): EvaluationResultInterface
    {
        try {
            $quote = $this->sessionCheckout->getQuote();
            $shippingMethod = $quote->getShippingAddress()->getShippingMethod();
            
            if (!$this->isInnoShipShippingMethod($shippingMethod)) {
                return $resultFactory->createSuccess();
            }

            if (empty($this->pudoId)) {
                return $resultFactory->createErrorMessageEvent((string)__('Please select a pickup point location.'))
                    ->withCustomEvent('shipping:method:error');
            }

            return $resultFactory->createSuccess();
            
        } catch (\Exception $e) {
            $this->logger->error('InnoShipHyva PudoPoint evaluation error: ' . $e->getMessage());
            return $resultFactory->createErrorMessageEvent((string)__('An error occurred while processing the pickup point selection.'))
                ->withCustomEvent('shipping:method:error');
        }
    }

    public function mount(): void
    {
        $this->loadPudoData();
    }

    public function onPudoSelected(): void
    {
        $this->loadPudoData();
    }

    private function loadPudoData(): void
    {
        try {
            $pudoData = $this->sessionCheckout->getData(self::INNOSHIP_PUDO_SESSION_KEY);
            if (is_array($pudoData)) {
                $this->pudoId = $pudoData['pudo_id'] ?? '';
                $this->pudoName = $pudoData['name'] ?? '';
                $this->pudoAddress = $pudoData['address'] ?? '';
                $this->pudoCity = $pudoData['city'] ?? '';
                $this->pudoLatitude = $pudoData['latitude'] ?? '';
                $this->pudoLongitude = $pudoData['longitude'] ?? '';
                $this->pudoType = $pudoData['type'] ?? '';
                $this->pudoCourierId = (string)($pudoData['courier_id'] ?? '');
                $this->paymentInfo = $pudoData['payment_info'] ?? '';
            }
        } catch (\Exception $e) {
            $this->logger->error('InnoShipHyva PudoPoint data loading error: ' . $e->getMessage());
        }
    }

    public function clearPudoPoint(): void
    {
        $this->pudoId = '';
        $this->pudoName = '';
        $this->pudoAddress = '';
        $this->pudoCity = '';
        $this->pudoLatitude = '';
        $this->pudoLongitude = '';
        $this->pudoType = '';
        $this->pudoCourierId = '';
        $this->paymentInfo = '';

        $this->sessionCheckout->unsetData(self::INNOSHIP_PUDO_SESSION_KEY);
        $this->restoreOriginalShippingAddress();
    }

    public function hasSelectedPudo(): bool
    {
        return !empty($this->pudoId);
    }

    private function isInnoShipShippingMethod(?string $shippingMethod): bool
    {
        return $shippingMethod && strpos($shippingMethod, 'innoshipcargusgo') !== false;
    }

    private function restoreOriginalShippingAddress(): void
    {
        try {
            $originalAddressData = $this->sessionCheckout->getData('original_shipping_address');
            if (!$originalAddressData) return;

            $quote = $this->sessionCheckout->getQuote();
            $shippingAddress = $quote->getShippingAddress();
            if (!$shippingAddress) return;

            $shippingAddress->setCompany($originalAddressData['company'] ?? '');
            $shippingAddress->setStreet($originalAddressData['street'] ?? []);
            $shippingAddress->setCity($originalAddressData['city'] ?? '');
            $shippingAddress->setPostcode($originalAddressData['postcode'] ?? '');
            $shippingAddress->setCountryId($originalAddressData['country_id'] ?? '');
            $shippingAddress->setRegionId($originalAddressData['region_id'] ?? null);
            $shippingAddress->setRegion($originalAddressData['region'] ?? '');
            $shippingAddress->setInnoshipPudoId(null);
            
            $this->quoteRepository->save($quote);
            $this->sessionCheckout->unsetData('original_shipping_address');
        } catch (\Exception $e) {
            $this->logger->error('InnoShipHyva: Failed to restore original shipping address: ' . $e->getMessage());
        }
    }
}