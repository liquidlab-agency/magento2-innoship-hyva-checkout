<?php
/**
 * Copyright © - LiquidLab Agency - All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Liquidlab\InnoShipHyva\ViewModel;

use Magento\Checkout\Model\Session as SessionCheckout;
use Magento\Framework\View\Element\Block\ArgumentInterface;

/**
 * Lightweight ViewModel that lets phtml templates ask "is the customer
 * currently shipping to a PUDO?" without injecting `Magento\Checkout\Model\Session`
 * into the template directly.
 */
class PudoSelectionState implements ArgumentInterface
{
    private const INNOSHIP_PUDO_SESSION_KEY = 'innoship_selected_pudo_point';

    public function __construct(
        private readonly SessionCheckout $sessionCheckout
    ) {
    }

    public function isPudoSelected(): bool
    {
        $pudoData = $this->sessionCheckout->getData(self::INNOSHIP_PUDO_SESSION_KEY);
        return is_array($pudoData) && !empty($pudoData['pudo_id']);
    }
}
