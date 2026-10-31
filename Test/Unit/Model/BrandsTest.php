<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Brands\Test\Unit\Model;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;
use Magento\Eav\Model\ResourceModel\Entity\Attribute as AttributeResource;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\EntityManager\EntityMetadataInterface;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Framework\Filter\TranslitUrl;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Zero\Brands\Model\Brands;
use Zero\Brands\Model\Config;

/**
 * The brand list is built once per request and store; an address and a
 * product list's brand values are read without it.
 */
#[AllowMockObjectsWithoutExpectations]
class BrandsTest extends TestCase
{
    private AdapterInterface&MockObject $connection;
    /** @var array<int, array<int, array{0: string, 1: mixed}>> each select's calls, in order */
    private array $selects = [];

    protected function setUp(): void
    {
        $this->connection = $this->createMock(AdapterInterface::class);
        $this->connection->method('select')->willReturnCallback(fn () => $this->select());
        $this->connection->method('quoteInto')->willReturnCallback(static fn ($text, $value) => str_replace('?', (string) $value, $text));
        $this->connection->method('isTableExists')->willReturn(true);
    }

    public function testTheListIsBuiltOncePerStoreAndWebsite(): void
    {
        $this->connection->expects($this->exactly(3))->method('fetchAll')->willReturn([$this->optionRow(5, 'Acebeam')]);
        $this->connection->expects($this->exactly(3))->method('fetchPairs')->willReturn([5 => '4']);
        $brands = $this->brands();

        $first = $brands->all(1, 1);
        $this->assertSame($first, $brands->all(1, 1));
        $this->assertSame(4, $first[5]['products']);
        $brands->all(1);
        $brands->all(1);
        $brands->all(2, 1);
        $brands->all(2, 1);
    }

    public function testSavingOrDeletingABrandForgetsTheList(): void
    {
        $this->connection->expects($this->exactly(3))->method('fetchAll')->willReturn([$this->optionRow(5, 'Acebeam')]);
        $this->connection->method('fetchPairs')->willReturn([]);
        $this->connection->method('fetchOne')->willReturn(false);
        $brands = $this->brands();

        $brands->all(1);
        $brands->save(5, ['name' => 'Acebeam']);
        $brands->all(1);
        $brands->delete(5);
        $brands->all(1);
    }

    public function testAnOwnAddressIsFoundWithoutCountingProducts(): void
    {
        $this->connection->expects($this->never())->method('fetchPairs');
        $this->connection->expects($this->never())->method('fetchAll');
        $this->connection->expects($this->once())->method('fetchOne')->willReturn('12');

        $this->assertSame(12, $this->brands()->optionIdByUrlKey('acebeam-lights', 1));
        $calls = $this->selects[0];
        $this->assertContains(['where', ['o.attribute_id = ?', 83]], $calls);
        $this->assertContains(['where', ['b.url_key = ?', 'acebeam-lights']], $calls);
        $this->assertContains(['limit', [1]], $calls);
    }

    public function testANameAddressIsFoundAmongTheBrandsWithoutAnOwnOne(): void
    {
        $this->connection->expects($this->never())->method('fetchPairs');
        $this->connection->method('fetchOne')->willReturn(false);
        $this->connection->expects($this->exactly(2))->method('fetchAll')->willReturn([
            ['option_id' => '5', 'admin_name' => 'Acebeam', 'store_name' => null],
            ['option_id' => '7', 'admin_name' => 'Fenix', 'store_name' => 'Fenix Light'],
        ]);
        $brands = $this->brands();

        $this->assertSame(7, $brands->optionIdByUrlKey('fenix-light', 2), 'the store view\'s name wins');
        $this->assertNull($brands->optionIdByUrlKey('zz-unknown', 2));
        $this->assertContains(['where', ["b.url_key IS NULL OR b.url_key = ''", null]], $this->selects[1]);
    }

    public function testRawValuesAreReadInOneQueryAndTheStoreViewWins(): void
    {
        $this->connection->expects($this->once())->method('fetchAll')->willReturn([
            ['entity_id' => '1', 'store_id' => '0', 'value' => '12'],
            ['entity_id' => '2', 'store_id' => '0', 'value' => '12,7'],
            ['entity_id' => '3', 'store_id' => '0', 'value' => ''],
            ['entity_id' => '1', 'store_id' => '2', 'value' => '7'],
        ]);

        $values = $this->brands()->valuesOf([1, 2, 3, 2, 0], 2);

        $this->assertSame([1 => '7', 2 => '12,7'], $values);
        $calls = $this->selects[0];
        $this->assertContains(['join', [['v' => 'catalog_product_entity_int'], 'v.row_id = e.row_id', ['store_id', 'value']]], $calls);
        $this->assertContains(['where', ['e.entity_id IN (?)', [1, 2, 3]]], $calls);
        $this->assertContains(['where', ['v.attribute_id = ?', 83]], $calls);
        $this->assertContains(['where', ['v.store_id IN (?)', [0, 2]]], $calls);
        $this->assertContains(['order', ['v.store_id ASC']], $calls, 'the store view\'s row comes last and wins');
    }

    public function testNoProductsReadsNothing(): void
    {
        $this->connection->expects($this->never())->method('fetchAll');

        $this->assertSame([], $this->brands()->valuesOf([], 1));
    }

    private function brands(): Brands
    {
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->connection);
        $resource->method('getTableName')->willReturnArgument(0);
        $attribute = $this->createStub(AbstractAttribute::class);
        $attribute->method('getId')->willReturn(83);
        $attribute->method('getFrontendInput')->willReturn('select');
        $attribute->method('getBackendTable')->willReturn('catalog_product_entity_int');
        $eavConfig = $this->createStub(EavConfig::class);
        $eavConfig->method('getAttribute')->willReturn($attribute);
        $config = $this->createStub(Config::class);
        $config->method('attributeCode')->willReturn('manufacturer');
        $translit = $this->createStub(TranslitUrl::class);
        $translit->method('filter')->willReturnCallback(
            static fn ($text) => strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', (string) $text))
        );
        $metadata = $this->createStub(EntityMetadataInterface::class);
        $metadata->method('getLinkField')->willReturn('row_id');
        $metadataPool = $this->createStub(MetadataPool::class);
        $metadataPool->method('getMetadata')->willReturnCallback(
            fn ($type) => $type === ProductInterface::class ? $metadata : $this->fail('metadata of ' . $type)
        );

        return new Brands($resource, $eavConfig, $this->createStub(AttributeResource::class), $config, $translit, $metadataPool);
    }

    private function select(): Select
    {
        $index = count($this->selects);
        $this->selects[$index] = [];
        $select = $this->createStub(Select::class);
        foreach (['from', 'join', 'joinLeft', 'where', 'order', 'limit', 'group'] as $method) {
            $select->method($method)->willReturnCallback(function (...$args) use ($select, $method, $index) {
                // The double passes the declared defaults too: drop the trailing ones.
                while (count($args) > ($method === 'where' ? 2 : 1) && end($args) === null) {
                    array_pop($args);
                }
                $this->selects[$index][] = [$method, $method === 'where' ? [$args[0], $args[1] ?? null] : $args];
                return $select;
            });
        }

        return $select;
    }

    /**
     * @return array<string, mixed>
     */
    private function optionRow(int $id, string $name): array
    {
        return [
            'option_id' => (string) $id, 'sort_order' => '0', 'admin_name' => $name, 'store_name' => null,
            'url_key' => null, 'logo' => null, 'description' => null, 'meta_title' => null,
            'meta_description' => null, 'is_featured' => '0', 'brand_sort' => '0',
        ];
    }
}
