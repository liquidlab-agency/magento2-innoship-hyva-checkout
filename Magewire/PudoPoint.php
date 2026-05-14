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
        // Capture the pudo_id BEFORE clearing the session — we need it to
        // fingerprint the current shipping address and decide whether to blank it.
        $pudoData = $this->sessionCheckout->getData(self::INNOSHIP_PUDO_SESSION_KEY);
        $clearedPudoId = is_array($pudoData) ? (string)($pudoData['pudo_id'] ?? '') : '';

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

        if ($clearedPudoId !== '') {
            $this->clearShippingAddressIfPudo($clearedPudoId);
        }

        // Notify other Magewire components (e.g. BillingDetails) that the
        // PUDO selection was cleared, so they can unlock UI elements that
        // depended on the PUDO being selected (the "billing same as
        // shipping" checkbox in particular).
        $this->emit('innoship-pudo-cleared');
    }

    public function hasSelectedPudo(): bool
    {
        return !empty($this->pudoId);
    }

    private function isInnoShipShippingMethod(?string $shippingMethod): bool
    {
        return $shippingMethod && strpos($shippingMethod, 'innoshipcargusgo') !== false;
    }

    /**
     * Blanks the shipping address location fields when the address on the quote
     * still belongs to the PUDO that was just deselected.
     *
     * The comparison uses `innoship_pudo_id` on the quote address — the integer
     * column stamped by `PudoPicker::updateShippingAddressWithPudo()`. If the
     * customer manually edited the address form AFTER picking the PUDO, Hyvä's
     * address-save flow would have written new street/city/postcode values and
     * cleared the pudo_id in the process; in that case we leave their data alone.
     *
     * The PUDO stamps (pudo_id / courier_id) are always removed from both the
     * address and the quote, regardless of the address comparison result —
     * the selection is being explicitly cancelled.
     */
    private function clearShippingAddressIfPudo(string $pudoId): void
    {
        try {
            $quote = $this->sessionCheckout->getQuote();
            $shippingAddress = $quote->getShippingAddress();
            if (!$shippingAddress) {
                return;
            }

            // innoship_pudo_id is an INT column — cast both sides to string for a
            // safe comparison (avoids int/string type juggling on the DB value).
            $addressPudoId = (string)$shippingAddress->getInnoshipPudoId();
            $addressMatchesPudo = $addressPudoId !== '' && $addressPudoId === $pudoId;

            if ($addressMatchesPudo) {
                // The address is still the locker address — blank the location
                // fields so the shipping form shows empty inputs and the customer
                // is prompted to enter their real delivery address.
                $shippingAddress->setCompany('');
                $shippingAddress->setStreet([]);
                $shippingAddress->setCity('');
                $shippingAddress->setPostcode('');
                $shippingAddress->setRegion('');
                $shippingAddress->setRegionId(null);
            }

            // Always remove the PUDO stamps regardless of address match.
            $shippingAddress->setInnoshipPudoId(null);
            $shippingAddress->setInnoshipCourierId(null);
            $quote->setInnoshipPudoId(null);
            $quote->setInnoshipCourierId(null);

            $this->quoteRepository->save($quote);
        } catch (\Exception $e) {
            $this->logger->error(
                'InnoShipHyva: Failed to clear shipping address after PUDO deselection: ' . $e->getMessage()
            );
        }
    }
}