<?php

namespace Drupal\Tests\dkan_nl_query\Unit\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\dkan_query_tools\Tool\DatastoreTools;
use Drupal\dkan_query_tools\Tool\MetastoreTools;
use Drupal\dkan_nl_query\Service\SchemaContextBuilder;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \Drupal\dkan_nl_query\Service\SchemaContextBuilder
 */
class SchemaContextBuilderTest extends TestCase {

  protected function createBuilder(
    ?MetastoreTools $metastore = NULL,
    ?DatastoreTools $datastore = NULL,
    ?CacheBackendInterface $cache = NULL,
  ): SchemaContextBuilder {
    return new SchemaContextBuilder(
      $metastore ?? $this->createMock(MetastoreTools::class),
      $datastore ?? $this->createMock(DatastoreTools::class),
      $cache ?? $this->createNoHitCache(),
    );
  }

  protected function createNoHitCache(): CacheBackendInterface {
    $cache = $this->createMock(CacheBackendInterface::class);
    $cache->method('get')->willReturn(FALSE);
    return $cache;
  }

  /**
   * @covers ::buildContext
   */
  public function testBuildContextReturnsCachedData(): void {
    $cached = new \stdClass();
    $cached->data = ['title' => 'Cached Dataset', 'resources' => []];

    $cache = $this->createMock(CacheBackendInterface::class);
    $cache->method('get')->willReturn($cached);

    $metastore = $this->createMock(MetastoreTools::class);
    $metastore->expects($this->never())->method('getDataset');

    $builder = $this->createBuilder(metastore: $metastore, cache: $cache);
    $result = $builder->buildContext('ds-uuid');

    $this->assertEquals('Cached Dataset', $result['title']);
  }

  /**
   * @covers ::buildContext
   */
  public function testBuildContextPropagatesDatasetError(): void {
    $metastore = $this->createMock(MetastoreTools::class);
    $metastore->method('getDataset')
      ->willReturn(['error' => 'Not found']);

    $builder = $this->createBuilder(metastore: $metastore);
    $result = $builder->buildContext('bad-uuid');

    $this->assertArrayHasKey('error', $result);
    $this->assertEquals('Not found', $result['error']);
  }

  /**
   * @covers ::buildContext
   */
  public function testBuildContextPropagatesDistributionError(): void {
    $metastore = $this->createMock(MetastoreTools::class);
    $metastore->method('getDataset')
      ->willReturn(['dataset' => ['title' => 'Test']]);
    $metastore->method('listDistributions')
      ->willReturn(['error' => 'Failed to list']);

    $builder = $this->createBuilder(metastore: $metastore);
    $result = $builder->buildContext('ds-uuid');

    $this->assertArrayHasKey('error', $result);
  }

  /**
   * @covers ::buildContext
   */
  public function testBuildContextSkipsUnimportedResources(): void {
    $metastore = $this->createMock(MetastoreTools::class);
    $metastore->method('getDataset')
      ->willReturn(['dataset' => ['title' => 'Test']]);
    $metastore->method('listDistributions')
      ->willReturn(['distributions' => [
        ['resource_id' => 'abc__123', 'title' => 'CSV'],
      ]]);

    $datastore = $this->createMock(DatastoreTools::class);
    $datastore->method('getImportStatus')
      ->willReturn(['status' => 'waiting']);
    // Schema should never be called for non-imported resources.
    $datastore->expects($this->never())->method('getDatastoreSchema');

    $builder = $this->createBuilder(metastore: $metastore, datastore: $datastore);
    $result = $builder->buildContext('ds-uuid');

    $this->assertEmpty($result['resources']);
  }

  /**
   * @covers ::buildContext
   */
  public function testBuildContextSkipsResourcesWithSchemaError(): void {
    $metastore = $this->createMock(MetastoreTools::class);
    $metastore->method('getDataset')
      ->willReturn(['dataset' => ['title' => 'Test']]);
    $metastore->method('listDistributions')
      ->willReturn(['distributions' => [
        ['resource_id' => 'abc__123', 'title' => 'CSV'],
      ]]);

    $datastore = $this->createMock(DatastoreTools::class);
    $datastore->method('getImportStatus')
      ->willReturn(['status' => 'done']);
    $datastore->method('getDatastoreSchema')
      ->willReturn(['error' => 'Table not found']);

    $builder = $this->createBuilder(metastore: $metastore, datastore: $datastore);
    $result = $builder->buildContext('ds-uuid');

    $this->assertEmpty($result['resources']);
  }

  /**
   * @covers ::buildContext
   */
  public function testBuildContextMergesStatsWithSchema(): void {
    $metastore = $this->createMock(MetastoreTools::class);
    $metastore->method('getDataset')
      ->willReturn(['dataset' => ['title' => 'Test Dataset']]);
    $metastore->method('listDistributions')
      ->willReturn(['distributions' => [
        ['resource_id' => 'abc__123', 'title' => 'CSV'],
      ]]);

    $datastore = $this->createMock(DatastoreTools::class);
    $datastore->method('getImportStatus')
      ->willReturn(['status' => 'done']);
    $datastore->method('getDatastoreSchema')
      ->willReturn(['columns' => [
        ['name' => 'state', 'type' => 'text', 'description' => 'US State'],
      ]]);
    $datastore->method('getDatastoreStats')
      ->willReturn([
        'total_rows' => 500,
        'columns' => [
          ['name' => 'state', 'distinct_count' => 50, 'min' => 'AL', 'max' => 'WY'],
        ],
      ]);
    $datastore->method('queryDatastore')
      ->willReturn([
        'results' => [
          ['state' => 'California'],
          ['state' => 'Texas'],
          ['state' => 'Florida'],
        ],
      ]);

    $cache = $this->createMock(CacheBackendInterface::class);
    $cache->method('get')->willReturn(FALSE);
    $cache->expects($this->once())->method('set');

    $builder = $this->createBuilder(
      metastore: $metastore,
      datastore: $datastore,
      cache: $cache,
    );
    $result = $builder->buildContext('ds-uuid');

    $this->assertEquals('Test Dataset', $result['title']);
    $this->assertCount(1, $result['resources']);

    $resource = $result['resources'][0];
    $this->assertEquals('abc__123', $resource['resource_id']);
    $this->assertEquals(500, $resource['total_rows']);

    $col = $resource['columns'][0];
    $this->assertEquals('state', $col['name']);
    $this->assertEquals(50, $col['distinct_count']);
    $this->assertEquals('AL', $col['min']);
    $this->assertEquals('WY', $col['max']);
    $this->assertEquals(['California', 'Texas', 'Florida'], $col['sample_values']);
  }

  /**
   * @covers ::buildContext
   */
  public function testBuildContextSampleValuesDeduplication(): void {
    $metastore = $this->createMock(MetastoreTools::class);
    $metastore->method('getDataset')
      ->willReturn(['dataset' => ['title' => 'Test']]);
    $metastore->method('listDistributions')
      ->willReturn(['distributions' => [
        ['resource_id' => 'abc__123', 'title' => 'CSV'],
      ]]);

    $datastore = $this->createMock(DatastoreTools::class);
    $datastore->method('getImportStatus')
      ->willReturn(['status' => 'done']);
    $datastore->method('getDatastoreSchema')
      ->willReturn(['columns' => [
        ['name' => 'val', 'type' => 'text'],
      ]]);
    $datastore->method('getDatastoreStats')
      ->willReturn(['total_rows' => 5, 'columns' => []]);
    $datastore->method('queryDatastore')
      ->willReturn([
        'results' => [
          ['val' => 'A'],
          ['val' => 'A'],
          ['val' => ''],
          ['val' => 'B'],
          ['val' => 'C'],
        ],
      ]);

    $builder = $this->createBuilder(metastore: $metastore, datastore: $datastore);
    $result = $builder->buildContext('ds-uuid');

    $col = $result['resources'][0]['columns'][0];
    // Should have 3 distinct non-empty values: A, B, C.
    $this->assertEquals(['A', 'B', 'C'], $col['sample_values']);
  }

  /**
   * @covers ::buildContext
   */
  public function testBuildContextSkipsDistributionsWithoutResourceId(): void {
    $metastore = $this->createMock(MetastoreTools::class);
    $metastore->method('getDataset')
      ->willReturn(['dataset' => ['title' => 'Test']]);
    $metastore->method('listDistributions')
      ->willReturn(['distributions' => [
        ['title' => 'CSV without resource_id'],
      ]]);

    $datastore = $this->createMock(DatastoreTools::class);
    $datastore->expects($this->never())->method('getImportStatus');

    $builder = $this->createBuilder(metastore: $metastore, datastore: $datastore);
    $result = $builder->buildContext('ds-uuid');

    $this->assertEmpty($result['resources']);
  }

  /**
   * @covers ::buildCatalogContext
   */
  public function testBuildCatalogContextReturnsCached(): void {
    $cached = new \stdClass();
    $cached->data = ['datasets' => [['title' => 'Cached']], 'total' => 1];

    $cache = $this->createMock(CacheBackendInterface::class);
    $cache->method('get')->willReturn($cached);

    $metastore = $this->createMock(MetastoreTools::class);
    $metastore->expects($this->never())->method('listDatasets');

    $builder = $this->createBuilder(metastore: $metastore, cache: $cache);
    $result = $builder->buildCatalogContext();

    $this->assertEquals(1, $result['total']);
  }

  /**
   * @covers ::buildCatalogContext
   */
  public function testBuildCatalogContextBuildsDatasetList(): void {
    $metastore = $this->createMock(MetastoreTools::class);
    $metastore->method('listDatasets')
      ->willReturn([
        'datasets' => [
          ['identifier' => 'ds1', 'title' => 'Sharks', 'description' => 'Shark data', 'distributions' => 2],
        ],
        'total' => 1,
      ]);
    $metastore->method('getDataset')
      ->willReturn(['dataset' => ['keyword' => ['marine'], 'theme' => ['science']]]);
    $metastore->method('listDistributions')
      ->willReturn(['distributions' => [
        ['resource_id' => 'abc__123'],
      ]]);

    $datastore = $this->createMock(DatastoreTools::class);
    $datastore->method('getImportStatus')
      ->willReturn(['status' => 'done', 'num_of_rows' => 1000]);

    $cache = $this->createMock(CacheBackendInterface::class);
    $cache->method('get')->willReturn(FALSE);
    $cache->expects($this->once())->method('set');

    $builder = $this->createBuilder(
      metastore: $metastore,
      datastore: $datastore,
      cache: $cache,
    );
    $result = $builder->buildCatalogContext();

    $this->assertEquals(1, $result['total']);
    $ds = $result['datasets'][0];
    $this->assertEquals('ds1', $ds['identifier']);
    $this->assertEquals('Sharks', $ds['title']);
    $this->assertEquals(1, $ds['imported_resources']);
    $this->assertEquals(1000, $ds['total_rows']);
    $this->assertEquals(['marine'], $ds['keywords']);
  }

  /**
   * @covers ::buildCatalogContext
   */
  public function testBuildCatalogContextTruncatesDescription(): void {
    $longDesc = str_repeat('x', 300);
    $metastore = $this->createMock(MetastoreTools::class);
    $metastore->method('listDatasets')
      ->willReturn([
        'datasets' => [
          ['identifier' => 'ds1', 'title' => 'T', 'description' => $longDesc],
        ],
        'total' => 1,
      ]);
    $metastore->method('getDataset')->willReturn(['dataset' => []]);
    $metastore->method('listDistributions')
      ->willReturn(['distributions' => []]);

    $builder = $this->createBuilder(metastore: $metastore);
    $result = $builder->buildCatalogContext();

    $this->assertEquals(200, mb_strlen($result['datasets'][0]['description']));
  }

  /**
   * @covers ::buildSystemPrompt
   */
  public function testBuildSystemPromptContainsDatasetInfo(): void {
    $builder = $this->createBuilder();
    $context = [
      'title' => 'Shark Tagging',
      'description' => 'Data about sharks',
      'keywords' => ['marine'],
      'themes' => [],
      'resources' => [
        [
          'resource_id' => 'abc__123',
          'total_rows' => 500,
          'columns' => [
            [
              'name' => 'species',
              'type' => 'text',
              'description' => 'Shark species',
              'distinct_count' => 12,
              'min' => 'Blue',
              'max' => 'White',
              'sample_values' => ['Blue', 'Hammerhead'],
            ],
          ],
        ],
      ],
    ];

    $prompt = $builder->buildSystemPrompt($context);

    $this->assertStringContainsString('Shark Tagging', $prompt);
    $this->assertStringContainsString('Data about sharks', $prompt);
    $this->assertStringContainsString('abc__123', $prompt);
    $this->assertStringContainsString('species', $prompt);
    $this->assertStringContainsString('12 distinct', $prompt);
    $this->assertStringContainsString('Blue', $prompt);
    $this->assertStringContainsString('marine', $prompt);
  }

  /**
   * @covers ::buildCatalogSystemPrompt
   */
  public function testBuildCatalogSystemPromptListsDatasets(): void {
    $builder = $this->createBuilder();
    $catalog = [
      'total' => 2,
      'datasets' => [
        [
          'identifier' => 'ds1',
          'title' => 'Sharks',
          'description' => 'Marine data',
          'imported_resources' => 1,
          'total_rows' => 1000,
          'keywords' => ['marine'],
          'themes' => [],
        ],
        [
          'identifier' => 'ds2',
          'title' => 'Empty',
          'description' => '',
          'imported_resources' => 0,
          'total_rows' => 0,
          'keywords' => [],
          'themes' => [],
        ],
      ],
    ];

    $prompt = $builder->buildCatalogSystemPrompt($catalog);

    $this->assertStringContainsString('2 total', $prompt);
    $this->assertStringContainsString('Sharks', $prompt);
    $this->assertStringContainsString('ds1', $prompt);
    $this->assertStringContainsString('1,000 rows', $prompt);
    $this->assertStringContainsString('not imported', $prompt);
    $this->assertStringContainsString('find_dataset_resources', $prompt);
  }

}
