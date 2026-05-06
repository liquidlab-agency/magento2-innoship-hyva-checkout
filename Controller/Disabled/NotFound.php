<?php
/**
 * Copyright © - LiquidLab Agency - All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Liquidlab\InnoShipHyva\Controller\Disabled;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Exception\NotFoundException;

/**
 * Stub used by `etc/frontend/di.xml` preferences to disable the unused
 * `InnoShip_InnoShip` frontend controllers in a Hyvä-only checkout. Each
 * preference points the original controller class at this no-op, so any
 * surviving `/innoshipf/*` requests get a clean 404.
 *
 * Implements both Http*ActionInterfaces (so POST endpoints don't 405
 * before reaching execute) and CsrfAwareActionInterface (so the dispatcher
 * doesn't reject POSTs without a valid form key — we don't care, we 404).
 */
class NotFound extends Action implements
    HttpGetActionInterface,
    HttpPostActionInterface,
    CsrfAwareActionInterface
{
    /**
     * @throws NotFoundException
     */
    public function execute()
    {
        throw new NotFoundException(__('Endpoint disabled.'));
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
