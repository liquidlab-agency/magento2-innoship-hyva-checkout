<?php
/**
 * Copyright © - LiquidLab Agency - All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Liquidlab\InnoShipHyva\Observer;

use Magento\Checkout\Model\Session as SessionCheckout;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\AddressExtensionFactory;
use Magento\Quote\Model\Quote;
use Psr\Log\LoggerInterface;

class CheckoutSubmitBefore implements ObserverInterface
{
    public function __construct(
        private readonly SessionCheckout $sessionCheckout,
        private readonly LoggerInterface $logger,
        private readonly AddressExtensionFactory $addressExtensionFactory,
        private readonly CartRepositoryInterface $cartRepository
    ) {
    }

    /**
     * Ensure PUDO address is properly set in quote before order submission begins
     * This runs at checkout_submit_before event, which is EARLIER than sales_model_service_quote_submit_before
     * This prevents any address restoration that might happen during the order submission process
     * 
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        try {
            /** @var Quote $quote */
            $quote = $observer->getData('quote');

            if (!$quote) {
                return;
            }

            $shippingAddress = $quote->getShippingAddress();
            if (!$shippingAddress) {
                return;
            }

            // Check if this is an InnoShip shipping method
            $shippingMethod = $shippingAddress->getShippingMethod();
            if (!$this->isInnoShipShippingMethod($shippingMethod)) {
                return;
            }

            // Check if PUDO data exists in quote or session
            $pudoData = $this->getPudoDataFromQuote($quote);
            if (!$pudoData) {
                $this->logger->debug('InnoShipHyva CheckoutSubmitBefore: No PUDO data found for InnoShip shipping method', [
                    'quote_id' => $quote->getId(),
                    'shipping_method' => $shippingMethod
                ]);
                return;
            }

            // Update shipping address with PUDO data BEFORE order submission begins
            $this->updateShippingAddressWithPudoData($shippingAddress, $pudoData, $quote);

            // Explicitly save the quote to ensure data is in the database 
            // when InnoShip's observer reloads it from QuoteFactory
            $this->cartRepository->save($quote);

        } catch (\Exception $e) {
            $this->logger->error('InnoShipHyva CheckoutSubmitBefore: Failed to update shipping address with PUDO data: ' . $e->getMessage(), [
                'exception' => $e
            ]);
        }
    }

    /**
     * Check if the shipping method is an InnoShip method that requires PUDO selection
     * 
     * @param string|null $shippingMethod
     * @return bool
     */
    private function isInnoShipShippingMethod(?string $shippingMethod): bool
    {
        if (!$shippingMethod) {
            return false;
        }

        // Check for InnoShip shipping methods
        $innoShipShippingMethods = [
            'innoshipcargusgo_innoshipcargusgo_1',
            'innoshipcargusgo'
        ];

        foreach ($innoShipShippingMethods as $method) {
            if (strpos($shippingMethod, $method) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get PUDO data from quote and session
     * This checks multiple sources: quote custom data, session data, and shipping address
     * 
     * @param Quote $quote
     * @return array|null
     */
    private function getPudoDataFromQuote(Quote $quote): ?array
    {
        // First, try to get PUDO data from quote custom data (set by InnoShipHyva component)
        $pudoSelectionData = $quote->getData('innoship_selected_pudo_point');
        if ($pudoSelectionData) {
            $pudoData = json_decode((string)$pudoSelectionData, true);
            if (is_array($pudoData) && !empty($pudoData['pudo_id'])) {
                $this->logger->debug('InnoShipHyva CheckoutSubmitBefore: Found PUDO data in quote custom data');
                return $pudoData;
            }
        }

        // Check for newer PUDO selection format
        $pudoSelection = $quote->getData('innoship_pudo_selection');
        if ($pudoSelection) {
            $pudoData = json_decode((string)$pudoSelection, true);
            if (is_array($pudoData) && !empty($pudoData['pudo_id'])) {
                $this->logger->debug('InnoShipHyva CheckoutSubmitBefore: Found PUDO data in quote pudo selection');
                return $pudoData;
            }
        }

        // Fallback: try to get from session data
        $sessionPudoData = $this->sessionCheckout->getData('innoship_selected_pudo_point');
        if (is_array($sessionPudoData) && !empty($sessionPudoData['pudo_id'])) {
            $this->logger->debug('InnoShipHyva CheckoutSubmitBefore: Found PUDO data in session');
            return $sessionPudoData;
        }

        // Check if shipping address already has PUDO ID set
        $shippingAddress = $quote->getShippingAddress();
        if ($shippingAddress && $shippingAddress->getInnoshipPudoId()) {
            return [
                'pudo_id' => $shippingAddress->getInnoshipPudoId(),
                'name' => $shippingAddress->getCompany() ?: __('PUDO Point')->render(),
                'address' => implode(', ', $shippingAddress->getStreet() ?: []),
                'city' => $shippingAddress->getCity(),
                'postal_code' => $shippingAddress->getPostcode(),
                'courier_id' => $shippingAddress->getInnoshipCourierId(),
                'shipping_price' => $shippingAddress->getInnoshipShippingPrice()
            ];
        }

        return null;
    }

    /**
     * Update shipping address with PUDO data
     * This ensures the PUDO address is properly set before order submission begins
     * 
     * @param \Magento\Quote\Api\Data\AddressInterface $shippingAddress
     * @param array $pudoData
     * @param Quote $quote
     * @return void
     */
    private function updateShippingAddressWithPudoData($shippingAddress, array $pudoData, Quote $quote): void
    {
        // Parse PUDO address if it's a full address string
        $addressParts = $this->parsePudoAddress($pudoData['address'] ?? '');

        // Update shipping address with PUDO details
        $shippingAddress->setCompany($pudoData['name'] ?? __('PUDO Point')->render());
        
        // Set street address
        if (!empty($addressParts['street'])) {
            $shippingAddress->setStreet([$addressParts['street']]);
        } elseif (!empty($pudoData['address'])) {
            $shippingAddress->setStreet([$pudoData['address']]);
        }

        // Set city
        if (!empty($addressParts['city'])) {
            $shippingAddress->setCity($addressParts['city']);
        } elseif (!empty($pudoData['city'])) {
            $shippingAddress->setCity($pudoData['city']);
        }

        // Set postal code
        if (!empty($addressParts['postal_code'])) {
            $shippingAddress->setPostcode($addressParts['postal_code']);
        } elseif (!empty($pudoData['postal_code'])) {
            $shippingAddress->setPostcode($pudoData['postal_code']);
        }

        // Set PUDO ID for InnoShip
        if (!empty($pudoData['pudo_id'])) {
            $shippingAddress->setInnoshipPudoId($pudoData['pudo_id']);
            $quote->setInnoshipPudoId($pudoData['pudo_id']);
        }
        
        // Also set courier ID if available
        if (!empty($pudoData['courier_id'])) {
            $shippingAddress->setInnoshipCourierId($pudoData['courier_id']);
            $quote->setInnoshipCourierId($pudoData['courier_id']);
        }
        
        // Also set shipping price if available
        if (!empty($pudoData['shipping_price'])) {
            $shippingAddress->setInnoshipShippingPrice($pudoData['shipping_price']);
        }

        // Set extension attributes for better compatibility with Magento mechanisms like Copy service
        $extensionAttributes = $shippingAddress->getExtensionAttributes() ?: $this->addressExtensionFactory->create();
        if (!empty($pudoData['pudo_id']) && method_exists($extensionAttributes, 'setInnoshipPudoId')) {
            $extensionAttributes->setInnoshipPudoId((int)$pudoData['pudo_id']);
        }
        if (!empty($pudoData['courier_id']) && method_exists($extensionAttributes, 'setInnoshipCourierId')) {
            $extensionAttributes->setInnoshipCourierId((int)$pudoData['courier_id']);
        }
        if (!empty($pudoData['shipping_price']) && method_exists($extensionAttributes, 'setInnoshipShippingPrice')) {
            $extensionAttributes->setInnoshipShippingPrice((float)$pudoData['shipping_price']);
        }
        $shippingAddress->setExtensionAttributes($extensionAttributes);
    }

    /**
     * Parse PUDO address string into components
     * Expected format: "CITY, Street Name, Nr. X, Postal Code"
     * 
     * @param string $address
     * @return array
     */
    private function parsePudoAddress(string $address): array
    {
        $result = [
            'city' => '',
            'street' => '',
            'postal_code' => ''
        ];

        if (empty($address)) {
            return $result;
        }

        try {
            // Split by comma and clean up parts
            $parts = array_map('trim', explode(',', $address));
            
            if (count($parts) >= 1) {
                $result['city'] = $parts[0];
            }
            
            if (count($parts) >= 2) {
                // Combine street parts (everything except first and last if postal code is present)
                $streetParts = array_slice($parts, 1);
                
                // Check if last part looks like a postal code (digits)
                $lastPart = end($streetParts);
                if (preg_match('/\b\d{6}\b/', $lastPart)) {
                    $result['postal_code'] = trim(preg_replace('/.*?(\d{6}).*/', '$1', $lastPart));
                    array_pop($streetParts); // Remove postal code from street parts
                }
                
                $result['street'] = implode(', ', $streetParts);
            }
            
        } catch (\Exception $e) {
            $this->logger->warning('InnoShipHyva CheckoutSubmitBefore: Failed to parse PUDO address: ' . $e->getMessage());
            $result['street'] = $address; // Fallback to full address
        }

        return $result;
    }
}