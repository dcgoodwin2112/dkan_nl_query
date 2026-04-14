<?php

namespace Drupal\dkan_nl_query\Service;

use Drupal\dkan_mcp\Tools\DatastoreTools;
use Drupal\dkan_mcp\Tools\MetastoreTools;
use Drupal\dkan_mcp\Tools\SearchTools;

/**
 * Executes LLM tool_use calls against dkan_mcp services.
 */
class ToolExecutor {

  public function __construct(
    protected DatastoreTools $datastoreTools,
    protected MetastoreTools $metastoreTools,
    protected SearchTools $searchTools,
  ) {}

  /**
   * Execute a tool call and return the result.
   */
  public function execute(string $toolName, array $arguments): array {
    return match ($toolName) {
      'query_datastore' => $this->datastoreTools->queryDatastore(
        resourceId: $arguments['resource_id'],
        columns: $arguments['columns'] ?? NULL,
        conditions: $arguments['conditions'] ?? NULL,
        sortField: $arguments['sort_field'] ?? NULL,
        sortDirection: $arguments['sort_direction'] ?? 'asc',
        limit: $arguments['limit'] ?? 100,
        offset: $arguments['offset'] ?? 0,
        expressions: $arguments['expressions'] ?? NULL,
        groupings: $arguments['groupings'] ?? NULL,
      ),
      'query_datastore_join' => $this->datastoreTools->queryDatastoreJoin(
        resourceId: $arguments['resource_id'],
        joinResourceId: $arguments['join_resource_id'],
        joinOn: $arguments['join_on'],
        columns: $arguments['columns'] ?? NULL,
        conditions: $arguments['conditions'] ?? NULL,
        sortField: $arguments['sort_field'] ?? NULL,
        sortDirection: $arguments['sort_direction'] ?? 'asc',
        limit: $arguments['limit'] ?? 100,
        offset: $arguments['offset'] ?? 0,
        expressions: $arguments['expressions'] ?? NULL,
        groupings: $arguments['groupings'] ?? NULL,
      ),
      'get_datastore_schema' => $this->datastoreTools->getDatastoreSchema(
        resourceId: $arguments['resource_id'],
      ),
      'get_datastore_stats' => $this->datastoreTools->getDatastoreStats(
        resourceId: $arguments['resource_id'],
        columns: $arguments['columns'] ?? NULL,
      ),
      'get_import_status' => $this->datastoreTools->getImportStatus(
        resourceId: $arguments['resource_id'],
      ),
      'search_columns' => $this->datastoreTools->searchColumns(
        searchTerm: $arguments['search_term'],
        searchIn: $arguments['search_in'] ?? 'name',
        limit: $arguments['limit'] ?? 100,
      ),
      'search_datasets' => $this->searchTools->searchDatasets(
        keyword: $arguments['keyword'],
        page: $arguments['page'] ?? 1,
        pageSize: $arguments['page_size'] ?? 10,
      ),
      'list_datasets' => $this->metastoreTools->listDatasets(
        offset: $arguments['offset'] ?? 0,
        limit: $arguments['limit'] ?? 25,
      ),
      'list_distributions' => $this->metastoreTools->listDistributions(
        datasetId: $arguments['dataset_id'],
      ),
      default => ['error' => "Unknown tool: $toolName"],
    };
  }

  /**
   * Get tool definitions for query mode (single dataset).
   */
  public function getQueryToolDefinitions(): array {
    return array_merge($this->getDatastoreTools(), $this->getUtilityTools());
  }

  /**
   * Get tool definitions for discovery mode (cross-dataset).
   */
  public function getDiscoveryToolDefinitions(): array {
    return array_merge(
      $this->getDiscoveryTools(),
      $this->getDatastoreTools(),
      $this->getUtilityTools()
    );
  }

  /**
   * Datastore query tools.
   */
  protected function getDatastoreTools(): array {
    return [
      [
        'name' => 'query_datastore',
        'description' => 'Query a datastore resource with filters, sorting, pagination, and aggregation. Conditions must be a JSON string array. Example: [{"property":"state","value":"California","operator":"="}]. For OR logic: [{"groupOperator":"or","conditions":[...]}]. All data is stored as text — comparisons are string-based ("9" > "10"). Use aggregate expressions (max, min) for true numeric ordering.',
        'input_schema' => [
          'type' => 'object',
          'properties' => [
            'resource_id' => [
              'type' => 'string',
              'description' => 'Resource ID in identifier__version format (get from list_distributions)',
            ],
            'columns' => [
              'type' => 'string',
              'description' => 'Comma-separated column names to return (omit for all)',
            ],
            'conditions' => [
              'type' => 'string',
              'description' => 'JSON array of conditions. Operators: =, <>, <, <=, >, >=, like, contains, starts with, in, not in, between. Example: [{"property":"state","value":"California","operator":"="}]. For IN: [{"property":"state","value":["CA","TX"],"operator":"in"}]. For between: [{"property":"age","value":[18,65],"operator":"between"}].',
            ],
            'sort_field' => [
              'type' => 'string',
              'description' => 'Column to sort by',
            ],
            'sort_direction' => [
              'type' => 'string',
              'enum' => ['asc', 'desc'],
              'description' => 'Sort direction',
            ],
            'limit' => [
              'type' => 'integer',
              'description' => 'Max rows to return (1-500, default 100)',
            ],
            'offset' => [
              'type' => 'integer',
              'description' => 'Rows to skip (default 0)',
            ],
            'expressions' => [
              'type' => 'string',
              'description' => 'JSON array of aggregate expressions. Example: [{"operator":"sum","operands":["revenue"],"alias":"total"}]. Operators: sum, count, avg, max, min. Must use with groupings. Cannot mix aggregate and arithmetic operators.',
            ],
            'groupings' => [
              'type' => 'string',
              'description' => 'Comma-separated columns to GROUP BY. Required with aggregate expressions. All non-aggregated columns must be listed.',
            ],
          ],
          'required' => ['resource_id'],
        ],
      ],
      [
        'name' => 'query_datastore_join',
        'description' => 'Join and query two datastore resources. Primary resource aliased as "t", joined as "j". Qualify columns with alias: "t.state,j.rate".',
        'input_schema' => [
          'type' => 'object',
          'properties' => [
            'resource_id' => [
              'type' => 'string',
              'description' => 'Primary resource ID (identifier__version format)',
            ],
            'join_resource_id' => [
              'type' => 'string',
              'description' => 'Resource ID to join with',
            ],
            'join_on' => [
              'type' => 'string',
              'description' => 'Join condition. Simple: "state=state_abbreviation" (primary_col=join_col).',
            ],
            'columns' => [
              'type' => 'string',
              'description' => 'Comma-separated columns with alias prefix: "t.state,j.rate"',
            ],
            'conditions' => [
              'type' => 'string',
              'description' => 'JSON array of conditions. Add "resource":"j" to filter joined table.',
            ],
            'sort_field' => [
              'type' => 'string',
              'description' => 'Column to sort by (with optional alias prefix)',
            ],
            'sort_direction' => [
              'type' => 'string',
              'enum' => ['asc', 'desc'],
            ],
            'limit' => [
              'type' => 'integer',
              'description' => 'Max rows (1-500, default 100)',
            ],
            'offset' => [
              'type' => 'integer',
            ],
            'expressions' => [
              'type' => 'string',
              'description' => 'JSON array of aggregate expressions (same as query_datastore)',
            ],
            'groupings' => [
              'type' => 'string',
              'description' => 'Comma-separated GROUP BY columns with alias prefix',
            ],
          ],
          'required' => ['resource_id', 'join_resource_id', 'join_on'],
        ],
      ],
      [
        'name' => 'get_datastore_schema',
        'description' => 'Get column names, types, and descriptions for a resource. Use before querying to discover available fields.',
        'input_schema' => [
          'type' => 'object',
          'properties' => [
            'resource_id' => [
              'type' => 'string',
              'description' => 'Resource ID in identifier__version format',
            ],
          ],
          'required' => ['resource_id'],
        ],
      ],
      [
        'name' => 'get_datastore_stats',
        'description' => 'Get per-column statistics: null count, distinct count, min, max, and total row count. Use to understand data quality and distribution before querying.',
        'input_schema' => [
          'type' => 'object',
          'properties' => [
            'resource_id' => [
              'type' => 'string',
              'description' => 'Resource ID in identifier__version format',
            ],
            'columns' => [
              'type' => 'string',
              'description' => 'Comma-separated column names to analyze (omit for all)',
            ],
          ],
          'required' => ['resource_id'],
        ],
      ],
    ];
  }

  /**
   * Utility tools available in both modes.
   */
  protected function getUtilityTools(): array {
    return [
      [
        'name' => 'search_columns',
        'description' => 'Search column names and descriptions across ALL imported datastore resources. Use to find which datasets contain specific types of data (e.g., "state", "price", "smoking").',
        'input_schema' => [
          'type' => 'object',
          'properties' => [
            'search_term' => [
              'type' => 'string',
              'description' => 'Column name or description substring to search (case-insensitive)',
            ],
            'search_in' => [
              'type' => 'string',
              'enum' => ['name', 'description', 'both'],
              'description' => 'Where to search (default: name)',
            ],
            'limit' => [
              'type' => 'integer',
              'description' => 'Max matches to return (default 100)',
            ],
          ],
          'required' => ['search_term'],
        ],
      ],
      [
        'name' => 'get_import_status',
        'description' => 'Check if a resource has been imported and is ready to query. Status: "done", "pending", or "not_imported".',
        'input_schema' => [
          'type' => 'object',
          'properties' => [
            'resource_id' => [
              'type' => 'string',
              'description' => 'Resource ID in identifier__version format',
            ],
          ],
          'required' => ['resource_id'],
        ],
      ],
    ];
  }

  /**
   * Dataset discovery tools.
   */
  protected function getDiscoveryTools(): array {
    return [
      [
        'name' => 'search_datasets',
        'description' => 'Search datasets by keyword. Returns matching datasets with title, identifier, description, and distribution count.',
        'input_schema' => [
          'type' => 'object',
          'properties' => [
            'keyword' => [
              'type' => 'string',
              'description' => 'Search term',
            ],
            'page' => [
              'type' => 'integer',
              'description' => 'Page number (1-based, default 1)',
            ],
            'page_size' => [
              'type' => 'integer',
              'description' => 'Results per page (default 10)',
            ],
          ],
          'required' => ['keyword'],
        ],
      ],
      [
        'name' => 'list_datasets',
        'description' => 'List all datasets with pagination. Returns title, identifier, description, distribution count.',
        'input_schema' => [
          'type' => 'object',
          'properties' => [
            'offset' => [
              'type' => 'integer',
              'description' => 'Number of datasets to skip (default 0)',
            ],
            'limit' => [
              'type' => 'integer',
              'description' => 'Max datasets to return (1-100, default 25)',
            ],
          ],
        ],
      ],
      [
        'name' => 'list_distributions',
        'description' => 'Get distributions (data files) for a dataset. Returns resource_id (identifier__version format) needed for all datastore tools.',
        'input_schema' => [
          'type' => 'object',
          'properties' => [
            'dataset_id' => [
              'type' => 'string',
              'description' => 'Dataset UUID',
            ],
          ],
          'required' => ['dataset_id'],
        ],
      ],
    ];
  }

}
