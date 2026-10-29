<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Brands\Controller\Adminhtml\Brand;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\Exception\LocalizedException;
use Zero\Brands\Model\Brands;
use Zero\Brands\Model\Logo;

/**
 * Saves a brand (its name, page and logo), or deletes it (delete=1).
 */
class Save extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Zero_Brands::brands';

    public function __construct(
        Context $context,
        private readonly Brands $brands,
        private readonly Logo $logo,
        private readonly TypeListInterface $cacheTypeList
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $request = $this->getRequest();
        $id = (int) $request->getParam('id') ?: null;
        try {
            if ($id && $request->getParam('delete')) {
                $this->brands->delete($id);
                $this->messageManager->addSuccessMessage(__('The brand is deleted; its products have no brand now.'));
                $this->cleanCaches();

                return $this->resultRedirectFactory->create()->setPath('*/*/index');
            }
            $data = (array) $request->getPostValue();
            $upload = $request->getFiles('logo');
            if (is_array($upload) && ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $data['logo'] = $this->logo->save($upload);
            } elseif (!empty($data['logo_remove'])) {
                $data['logo'] = null;
            }
            $id = $this->brands->save($id, $data);
            $this->messageManager->addSuccessMessage(__('The brand is saved.'));
            $this->cleanCaches();
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        }

        return $this->resultRedirectFactory->create()->setPath('*/*/edit', $id ? ['id' => $id] : []);
    }

    private function cleanCaches(): void
    {
        // graphql_query_resolver_result: a headless storefront's cached brand answers (Model\Resolver).
        foreach (['full_page', 'block_html', 'eav', 'graphql_query_resolver_result'] as $type) {
            $this->cacheTypeList->cleanType($type);
        }
    }
}
