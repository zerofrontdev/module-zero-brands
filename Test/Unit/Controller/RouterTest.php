<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Brands\Test\Unit\Controller;

use Magento\Framework\App\Action\Forward;
use Magento\Framework\App\ActionFactory;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Zero\Brands\Controller\Router;
use Zero\Brands\Model\Brands;
use Zero\Brands\Model\Config;

/**
 * /brands/{url key}: one light lookup of the key; the whole brand list (with
 * its product counts) is never built to answer an address.
 */
#[AllowMockObjectsWithoutExpectations]
class RouterTest extends TestCase
{
    private Brands&MockObject $brands;

    protected function setUp(): void
    {
        $this->brands = $this->createMock(Brands::class);
        $this->brands->expects($this->never())->method('all');
        $this->brands->expects($this->never())->method('byUrlKey');
    }

    public function testAnUnknownKeyFallsThrough(): void
    {
        $this->brands->expects($this->once())->method('optionIdByUrlKey')->with('zz-unknown', 1)->willReturn(null);

        $this->assertNull($this->route('/brands/zz-unknown'));
    }

    public function testAKnownKeyForwardsToTheBrand(): void
    {
        $this->brands->expects($this->once())->method('optionIdByUrlKey')->with('acebeam', 1)->willReturn(5);

        $this->assertSame(
            ['module' => 'zero_brands', 'controller' => 'brand', 'action' => 'view', 'brand_id' => 5, 'alias' => 'brands/acebeam'],
            $this->route('/brands/acebeam')
        );
    }

    public function testAnEncodedKeyIsLookedUpDecoded(): void
    {
        $this->brands->expects($this->once())->method('optionIdByUrlKey')->with('l\'oréal', 1)->willReturn(9);

        $this->assertSame(9, $this->route('/brands/l%27or%C3%A9al')['brand_id']);
    }

    public function testAFailingLookupFallsThrough(): void
    {
        $this->brands->method('optionIdByUrlKey')->willThrowException(new \RuntimeException('no brand attribute'));

        $this->assertNull($this->route('/brands/acebeam'));
    }

    public function testOtherAddressesAreNotLookedUp(): void
    {
        $this->brands->expects($this->never())->method('optionIdByUrlKey');

        $this->assertSame(['module' => 'zero_brands', 'controller' => 'index', 'action' => 'index', 'alias' => 'brands'], $this->route('/brands'));
        $this->assertNull($this->route('/brands/a/b'));
        $this->assertNull($this->route('/brandsx/acebeam'));
        $this->assertNull($this->route('/brands/acebeam', false));
    }

    /**
     * @return array<string, string|int>|null
     */
    private function route(string $path, bool $enabled = true): ?array
    {
        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturn($enabled);
        $config->method('route')->willReturn('brands');
        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn(1);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);
        $actionFactory = $this->createStub(ActionFactory::class);
        $actionFactory->method('create')->willReturn($this->createStub(Forward::class));

        $values = [];
        $request = $this->createPartialMock(
            HttpRequest::class,
            ['getPathInfo', 'getModuleName', 'setModuleName', 'setControllerName', 'setActionName', 'setParam', 'setAlias']
        );
        $request->method('getPathInfo')->willReturn($path);
        $request->method('getModuleName')->willReturn(null);
        foreach (['setModuleName' => 'module', 'setControllerName' => 'controller', 'setActionName' => 'action'] as $method => $key) {
            $request->method($method)->willReturnCallback(function ($value) use ($request, &$values, $key) {
                $values[$key] = $value;
                return $request;
            });
        }
        $request->method('setParam')->willReturnCallback(function ($name, $value) use ($request, &$values) {
            $values[$name] = $value;
            return $request;
        });
        $request->method('setAlias')->willReturnCallback(function ($name, $value) use ($request, &$values) {
            $values['alias'] = $value;
            return $request;
        });

        $result = (new Router($actionFactory, $storeManager, $config, $this->brands))->match($request);

        return $result ? $values : null;
    }
}
