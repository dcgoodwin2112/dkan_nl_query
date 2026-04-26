<?php

namespace Drupal\Tests\dkan_nl_query\Unit\Service;

use Drupal\dkan_query_tools\Tool\DatastoreTools;
use Drupal\dkan_query_tools\Tool\MetastoreTools;
use Drupal\dkan_query_tools\Tool\SearchTools;
use Drupal\dkan_nl_query\Service\ToolExecutor;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \Drupal\dkan_nl_query\Service\ToolExecutor
 */
class ToolExecutorTest extends TestCase {

  /**
   * Create a ToolExecutor with optional mock overrides.
   */
  protected function createExecutor(
    ?DatastoreTools $datastoreTools = NULL,
    ?MetastoreTools $metastoreTools = NULL,
    ?SearchTools $searchTools = NULL,
  ): ToolExecutor {
    return new ToolExecutor(
      $datastoreTools ?? $this->createMock(DatastoreTools::class),
      $metastoreTools ?? $this->createMock(MetastoreTools::class),
      $searchTools ?? $this->createMock(SearchTools::class),
    );
  }

  /**
   * @covers ::execute
   */
  public function testQueryDatastoreMinimalArgs(): void {
    $datastore = $this->createMock(DatastoreTools::class);
    $datastore->method('getImportStatus')
      ->willReturn(['status' => 'done']);
    $datastore->expects($this->once())
      ->method('queryDatastore')
      ->with(
        resourceId: 'abc__123',
        columns: NULL,
        conditions: NULL,
        sortField: NULL,
        sortDirection: 'asc',
        limit: 100,
        offset: 0,
        expressions: NULL,
        groupings: NULL,
      )
      ->willReturn(['results' => [], 'count' => 0]);

    $executor = $this->createExecutor(datastoreTools: $datastore);
    $result = $executor->execute('query_datastore', ['resource_id' => 'abc__123']);

    $this->assertEquals(['results' => [], 'count' => 0], $result);
  }

  /**
   * @covers ::execute
   */
  public function testQueryDatastoreAllArgs(): void {
    $datastore = $this->createMock(DatastoreTools::class);
    $datastore->method('getImportStatus')
      ->willReturn(['status' => 'done']);
    $datastore->expects($this->once())
      ->method('queryDatastore')
      ->with(
        resourceId: 'abc__123',
        columns: 'name,age',
        conditions: '[{"property":"state","value":"CA"}]',
        sortField: 'name',
        sortDirection: 'desc',
        limit: 50,
        offset: 10,
        expressions: '[{"operator":"count","operands":["name"],"alias":"total"}]',
        groupings: 'state',
      )
      ->willReturn(['results' => [['name' => 'test']], 'count' => 1]);

    $executor = $this->createExecutor(datastoreTools: $datastore);
    $result = $executor->execute('query_datastore', [
      'resource_id' => 'abc__123',
      'columns' => 'name,age',
      'conditions' => '[{"property":"state","value":"CA"}]',
      'sort_field' => 'name',
      'sort_direction' => 'desc',
      'limit' => 50,
      'offset' => 10,
      'expressions' => '[{"operator":"count","operands":["name"],"alias":"total"}]',
      'groupings' => 'state',
    ]);

    $this->assertEquals(1, $result['count']);
  }

  /**
   * @covers ::execute
   */
  public function testQueryDatastoreJoin(): void {
    $datastore = $this->createMock(DatastoreTools::class);
    $datastore->method('getImportStatus')
      ->willReturn(['status' => 'done']);
    $datastore->expects($this->once())
      ->method('queryDatastoreJoin')
      ->willReturn(['results' => [], 'count' => 0]);

    $executor = $this->createExecutor(datastoreTools: $datastore);
    $result = $executor->execute('query_datastore_join', [
      'resource_id' => 'abc__123',
      'join_resource_id' => 'def__456',
      'join_on' => 'state=state',
    ]);

    $this->assertEquals(['results' => [], 'count' => 0], $result);
  }

  /**
   * @covers ::execute
   */
  public function testGetDatastoreSchema(): void {
    $datastore = $this->createMock(DatastoreTools::class);
    $datastore->method('getImportStatus')
      ->willReturn(['status' => 'done']);
    $datastore->expects($this->once())
      ->method('getDatastoreSchema')
      ->with(resourceId: 'abc__123')
      ->willReturn(['columns' => [['name' => 'id', 'type' => 'int']]]);

    $executor = $this->createExecutor(datastoreTools: $datastore);
    $result = $executor->execute('get_datastore_schema', ['resource_id' => 'abc__123']);

    $this->assertArrayHasKey('columns', $result);
  }

  /**
   * @covers ::execute
   */
  public function testGetDatastoreStats(): void {
    $datastore = $this->createMock(DatastoreTools::class);
    $datastore->method('getImportStatus')
      ->willReturn(['status' => 'done']);
    $datastore->expects($this->once())
      ->method('getDatastoreStats')
      ->with(resourceId: 'abc__123', columns: 'name')
      ->willReturn(['total_rows' => 100]);

    $executor = $this->createExecutor(datastoreTools: $datastore);
    $result = $executor->execute('get_datastore_stats', [
      'resource_id' => 'abc__123',
      'columns' => 'name',
    ]);

    $this->assertEquals(100, $result['total_rows']);
  }

  /**
   * @covers ::execute
   */
  public function testGetImportStatus(): void {
    $datastore = $this->createMock(DatastoreTools::class);
    $datastore->method('getImportStatus')
      ->willReturn(['status' => 'done']);

    $executor = $this->createExecutor(datastoreTools: $datastore);
    $result = $executor->execute('get_import_status', ['resource_id' => 'abc__123']);

    $this->assertEquals('done', $result['status']);
  }

  /**
   * @covers ::execute
   */
  public function testSearchColumns(): void {
    $datastore = $this->createMock(DatastoreTools::class);
    $datastore->expects($this->once())
      ->method('searchColumns')
      ->with(searchTerm: 'state', searchIn: 'both', limit: 50)
      ->willReturn(['matches' => []]);

    $executor = $this->createExecutor(datastoreTools: $datastore);
    $result = $executor->execute('search_columns', [
      'search_term' => 'state',
      'search_in' => 'both',
      'limit' => 50,
    ]);

    $this->assertArrayHasKey('matches', $result);
  }

  /**
   * @covers ::execute
   */
  public function testSearchDatasets(): void {
    $search = $this->createMock(SearchTools::class);
    $search->expects($this->once())
      ->method('searchDatasets')
      ->with(keyword: 'sharks', page: 1, pageSize: 10)
      ->willReturn(['results' => []]);

    $executor = $this->createExecutor(searchTools: $search);
    $result = $executor->execute('search_datasets', ['keyword' => 'sharks']);

    $this->assertArrayHasKey('results', $result);
  }

  /**
   * @covers ::execute
   */
  public function testListDatasets(): void {
    $metastore = $this->createMock(MetastoreTools::class);
    $metastore->expects($this->once())
      ->method('listDatasets')
      ->with(offset: 5, limit: 10)
      ->willReturn(['datasets' => [], 'total' => 0]);

    $executor = $this->createExecutor(metastoreTools: $metastore);
    $result = $executor->execute('list_datasets', ['offset' => 5, 'limit' => 10]);

    $this->assertEquals(0, $result['total']);
  }

  /**
   * @covers ::execute
   */
  public function testListDistributions(): void {
    $metastore = $this->createMock(MetastoreTools::class);
    $metastore->expects($this->once())
      ->method('listDistributions')
      ->with(datasetId: 'uuid-123')
      ->willReturn(['distributions' => []]);

    $executor = $this->createExecutor(metastoreTools: $metastore);
    $result = $executor->execute('list_distributions', ['dataset_id' => 'uuid-123']);

    $this->assertArrayHasKey('distributions', $result);
  }

  /**
   * @covers ::execute
   */
  public function testCreateChartReturnsChartRendered(): void {
    $executor = $this->createExecutor();
    $result = $executor->execute('create_chart', ['spec' => ['mark' => 'bar']]);

    $this->assertEquals(['status' => 'chart_rendered'], $result);
  }

  /**
   * @covers ::execute
   */
  public function testUnknownToolReturnsError(): void {
    $executor = $this->createExecutor();
    $result = $executor->execute('nonexistent_tool', []);

    $this->assertArrayHasKey('error', $result);
    $this->assertStringContainsString('nonexistent_tool', $result['error']);
  }

  /**
   * @covers ::execute
   */
  public function testUnresolvableResourceIdReturnsError(): void {
    $datastore = $this->createMock(DatastoreTools::class);
    $datastore->method('getImportStatus')
      ->willReturn(['status' => 'error']);

    $metastore = $this->createMock(MetastoreTools::class);
    $metastore->method('listDatasets')
      ->willReturn(['datasets' => []]);

    $executor = $this->createExecutor(
      datastoreTools: $datastore,
      metastoreTools: $metastore,
    );
    $result = $executor->execute('query_datastore', ['resource_id' => 'bad__id']);

    $this->assertArrayHasKey('error', $result);
    $this->assertStringContainsString('Could not resolve', $result['error']);
  }

  /**
   * @covers ::execute
   */
  public function testResolveResourceIdDirect(): void {
    $datastore = $this->createMock(DatastoreTools::class);
    $datastore->method('getImportStatus')
      ->willReturn(['status' => 'done']);
    $datastore->expects($this->once())
      ->method('queryDatastore')
      ->with($this->callback(fn($rid) => $rid === 'abc123__999'))
      ->willReturn(['results' => []]);

    $executor = $this->createExecutor(datastoreTools: $datastore);
    $executor->execute('query_datastore', ['resource_id' => 'abc123__999']);
  }

  /**
   * @covers ::execute
   */
  public function testResolveResourceIdByVersionSuffix(): void {
    $datastore = $this->createMock(DatastoreTools::class);
    $datastore->method('getImportStatus')
      ->willReturnCallback(function ($rid) {
        // Direct ID fails, but the real resource matches.
        if ($rid === 'corrupted__999') {
          return ['status' => 'error'];
        }
        return ['status' => 'done'];
      });
    $datastore->expects($this->once())
      ->method('queryDatastore')
      ->with($this->callback(fn($rid) => $rid === 'real_abc__999'))
      ->willReturn(['results' => []]);

    $metastore = $this->createMock(MetastoreTools::class);
    $metastore->method('listDatasets')
      ->willReturn(['datasets' => [['identifier' => 'ds1', 'title' => 'Test']]]);
    $metastore->method('listDistributions')
      ->willReturn(['distributions' => [['resource_id' => 'real_abc__999']]]);

    $executor = $this->createExecutor(
      datastoreTools: $datastore,
      metastoreTools: $metastore,
    );
    $executor->execute('query_datastore', ['resource_id' => 'corrupted__999']);
  }

  /**
   * @covers ::execute
   */
  public function testResolveResourceIdByPrefix(): void {
    $datastore = $this->createMock(DatastoreTools::class);
    $callCount = 0;
    $datastore->method('getImportStatus')
      ->willReturnCallback(function ($rid) use (&$callCount) {
        $callCount++;
        // Direct ID fails, version suffix match fails, prefix matches.
        if ($rid === 'abcdef__wrong' || $rid === 'other__888') {
          return ['status' => 'error'];
        }
        return ['status' => 'done'];
      });
    $datastore->expects($this->once())
      ->method('queryDatastore')
      ->with($this->callback(fn($rid) => $rid === 'abcdef12345__777'))
      ->willReturn(['results' => []]);

    $metastore = $this->createMock(MetastoreTools::class);
    $metastore->method('listDatasets')
      ->willReturn(['datasets' => [['identifier' => 'ds1', 'title' => 'Test']]]);
    $metastore->method('listDistributions')
      ->willReturn(['distributions' => [
        ['resource_id' => 'other__888'],
        ['resource_id' => 'abcdef12345__777'],
      ]]);

    $executor = $this->createExecutor(
      datastoreTools: $datastore,
      metastoreTools: $metastore,
    );
    $executor->execute('query_datastore', ['resource_id' => 'abcdef__wrong']);
  }

  /**
   * @covers ::execute
   */
  public function testResolveResourceIdByTitleLookup(): void {
    $datastore = $this->createMock(DatastoreTools::class);
    $datastore->method('getImportStatus')
      ->willReturn(['status' => 'done']);
    $datastore->expects($this->once())
      ->method('queryDatastore')
      ->with($this->callback(fn($rid) => $rid === 'found__123'))
      ->willReturn(['results' => []]);

    $metastore = $this->createMock(MetastoreTools::class);
    $metastore->method('listDatasets')
      ->willReturn(['datasets' => [
        ['identifier' => 'ds1', 'title' => 'Shark Tagging Data'],
      ]]);
    $metastore->method('listDistributions')
      ->willReturn(['distributions' => [['resource_id' => 'found__123']]]);

    $executor = $this->createExecutor(
      datastoreTools: $datastore,
      metastoreTools: $metastore,
    );
    // Title-based lookup: no __ in input.
    $executor->execute('query_datastore', ['resource_id' => 'shark']);
  }

  /**
   * @covers ::execute
   */
  public function testFindDatasetResourcesEmptyTitle(): void {
    $executor = $this->createExecutor();
    $result = $executor->execute('find_dataset_resources', ['title' => '']);

    $this->assertArrayHasKey('error', $result);
    $this->assertStringContainsString('required', $result['error']);
  }

  /**
   * @covers ::execute
   */
  public function testFindDatasetResourcesMatch(): void {
    $metastore = $this->createMock(MetastoreTools::class);
    $metastore->method('listDatasets')
      ->willReturn(['datasets' => [
        ['identifier' => 'ds1', 'title' => 'Shark Tagging Data'],
      ]]);
    $metastore->method('listDistributions')
      ->willReturn(['distributions' => [['resource_id' => 'abc__123']]]);

    $executor = $this->createExecutor(metastoreTools: $metastore);
    $result = $executor->execute('find_dataset_resources', ['title' => 'SHARK']);

    $this->assertEquals('ds1', $result['dataset_id']);
    $this->assertEquals('Shark Tagging Data', $result['title']);
    $this->assertCount(1, $result['distributions']);
  }

  /**
   * @covers ::execute
   */
  public function testFindDatasetResourcesNoMatch(): void {
    $metastore = $this->createMock(MetastoreTools::class);
    $metastore->method('listDatasets')
      ->willReturn(['datasets' => [
        ['identifier' => 'ds1', 'title' => 'Something Else'],
      ]]);

    $executor = $this->createExecutor(metastoreTools: $metastore);
    $result = $executor->execute('find_dataset_resources', ['title' => 'nonexistent']);

    $this->assertArrayHasKey('error', $result);
    $this->assertStringContainsString('No dataset found', $result['error']);
  }

  /**
   * @covers ::getQueryToolDefinitions
   */
  public function testGetQueryToolDefinitions(): void {
    $executor = $this->createExecutor();
    $tools = $executor->getQueryToolDefinitions();

    $names = array_column($tools, 'name');
    $this->assertContains('query_datastore', $names);
    $this->assertContains('query_datastore_join', $names);
    $this->assertContains('get_datastore_schema', $names);
    $this->assertContains('get_datastore_stats', $names);
    $this->assertContains('search_columns', $names);
    $this->assertContains('create_chart', $names);
    // Discovery tools should NOT be in query mode.
    $this->assertNotContains('search_datasets', $names);
    $this->assertNotContains('list_datasets', $names);
  }

  /**
   * @covers ::getDiscoveryToolDefinitions
   */
  public function testGetDiscoveryToolDefinitions(): void {
    $executor = $this->createExecutor();
    $tools = $executor->getDiscoveryToolDefinitions();

    $names = array_column($tools, 'name');
    // Should include both discovery and datastore tools.
    $this->assertContains('search_datasets', $names);
    $this->assertContains('list_datasets', $names);
    $this->assertContains('list_distributions', $names);
    $this->assertContains('find_dataset_resources', $names);
    $this->assertContains('query_datastore', $names);
    $this->assertContains('create_chart', $names);
  }

}
