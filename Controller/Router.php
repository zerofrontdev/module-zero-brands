<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Brands\Controller;

use Magento\Framework\App\Action\Forward;
use Magento\Framework\App\ActionFactory;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\RouterInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;
use Zero\Brands\Model\Brands;
use Zero\Brands\Model\Config;

/**
 * /brands: the A–Z of brands; /brands/{url key}: a brand's page. Its links
 * (filters, pages, sorting) stay on /brands/{url key}: the request path is
 * kept as the rewrite alias, as Magento's own URL rewrites do.
 */
class Router implements RouterInterface
{
    public function __construct(
        private readonly ActionFactory $actionFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly Config $config,
        private readonly Brands $brands
    ) {
    }

    public function match(RequestInterface $request)
    {
        if (!$request instanceof HttpRequest || $request->getModuleName() === 'zero_brands') {
            return null;
        }
        $storeId = (int) $this->storeManager->getStore()->getId();
        if (!$this->config->isEnabled($storeId)) {
            return null;
        }
        $path = trim((string) $request->getPathInfo(), '/');
        $route = $this->config->route($storeId);
        if ($path === $route) {
            return $this->forward($request, $path, 'index', 'index');
        }
        if (!str_starts_with($path, $route . '/')) {
            return null;
        }
        $key = rawurldecode(substr($path, strlen($route) + 1));
        if ($key === '' || str_contains($key, '/')) {
            return null;
        }
        try {
            // One light lookup: a 404 address never builds the whole brand list.
            $optionId = $this->brands->optionIdByUrlKey($key, $storeId);
        } catch (\Throwable) {
            return null;
        }

        return $optionId !== null ? $this->forward($request, $path, 'brand', 'view', $optionId) : null;
    }

    private function forward(HttpRequest $request, string $path, string $controller, string $action, ?int $id = null): ActionInterface
    {
        $request->setModuleName('zero_brands')->setControllerName($controller)->setActionName($action);
        if ($id !== null) {
            $request->setParam('brand_id', $id);
        }
        $request->setAlias(UrlInterface::REWRITE_REQUEST_PATH_ALIAS, $path);

        return $this->actionFactory->create(Forward::class);
    }
}
