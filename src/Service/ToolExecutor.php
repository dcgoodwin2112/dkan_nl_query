<?php

namespace Drupal\dkan_nl_query\Service;

use Drupal\dkan_query_tools\Tool\DatastoreTools;
use Drupal\dkan_query_tools\Tool\MetastoreTools;
use Drupal\dkan_query_tools\Tool\SearchTools;

/**
 * Executes LLM tool_use calls against dkan_query_tools services.
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
    // Resolve resource_id from direct ID or dataset title.
    if (isset($arguments['resource_id'])) {
      $resolved = $this->resolveResourceId($arguments['resource_id']);
      if ($resolved === NULL) {
        return ['error' => "Could not resolve resource: {$arguments['resource_id']}"];
      }
      $arguments['resource_id'] = $resolved;
    }

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
      'find_dataset_resources' => $this->findDatasetResources(
        $arguments['title'] ?? '',
      ),
      // create_chart is handled by NlQueryService (emits SSE event).
      'create_chart' => ['status' => 'chart_rendered'],
      default => ['error' => "Unknown tool: $toolName"],
    };
  }

  /**
   * Resolve a resource_id from either a direct ID or dataset title.
   *
   * Accepts identifier__version format directly, or a dataset title
   * which is resolved to the first imported resource_id. If a direct
   * resource_id fails validation, falls back to fuzzy matching against
   * all known resources (handles LLM hex string corruption).
   */
  public function resolveResourceId(string $input): ?string {
    // If it looks like a resource_id, validate it exists.
    if (str_contains($input, '__')) {
      $status = $this->datastoreTools->getImportStatus($input);
      if (($status['status'] ?? '') === 'done') {
        return $input;
      }

      // ID is corrupted — find the closest match by version suffix.
      $version = explode('__', $input)[1] ?? '';
      if ($version) {
        $match = $this->findResourceByVersion($version);
        if ($match) {
          return $match;
        }
      }

      // Try matching by identifier prefix (first 6 chars).
      $prefix = substr(explode('__', $input)[0], 0, 6);
      $match = $this->findResourceByPrefix($prefix);
      if ($match) {
        return $match;
      }
    }

    // Try as dataset title lookup.
    $result = $this->findDatasetResources($input);
    if (!isset($result['error'])) {
      foreach ($result['distributions'] ?? [] as $dist) {
        if (!empty($dist['resource_id'])) {
          return $dist['resource_id'];
        }
      }
    }

    return NULL;
  }

  /**
   * Find a resource_id by matching its version suffix.
   */
  protected function findResourceByVersion(string $version): ?string {
    $datasets = $this->metastoreTools->listDatasets(0, 50);
    foreach ($datasets['datasets'] ?? [] as $ds) {
      $dists = $this->metastoreTools->listDistributions($ds['identifier']);
      foreach ($dists['distributions'] ?? [] as $dist) {
        $rid = $dist['resource_id'] ?? '';
        if ($rid && str_ends_with($rid, "__$version")) {
          $status = $this->datastoreTools->getImportStatus($rid);
          if (($status['status'] ?? '') === 'done') {
            return $rid;
          }
        }
      }
    }
    return NULL;
  }

  /**
   * Find a resource_id by matching its identifier prefix.
   */
  protected function findResourceByPrefix(string $prefix): ?string {
    $datasets = $this->metastoreTools->listDatasets(0, 50);
    foreach ($datasets['datasets'] ?? [] as $ds) {
      $dists = $this->metastoreTools->listDistributions($ds['identifier']);
      foreach ($dists['distributions'] ?? [] as $dist) {
        $rid = $dist['resource_id'] ?? '';
        if ($rid && str_starts_with($rid, $prefix)) {
          $status = $this->datastoreTools->getImportStatus($rid);
          if (($status['status'] ?? '') === 'done') {
            return $rid;
          }
        }
      }
    }
    return NULL;
  }

  /**
   * Find the distribution UUID for a given resource_id.
   */
  public function resolveDistributionUuid(string $resourceId): ?string {
    $datasets = $this->metastoreTools->listDatasets(0, 50);
    foreach ($datasets['datasets'] ?? [] as $ds) {
      $dists = $this->metastoreTools->listDistributions($ds['identifier']);
      foreach ($dists['distributions'] ?? [] as $dist) {
        if (($dist['resource_id'] ?? '') === $resourceId && !empty($dist['identifier'])) {
          return $dist['identifier'];
        }
      }
    }
    return NULL;
  }

  /**
   * Find a dataset by title and return its resources.
   */
  protected function findDatasetResources(string $title): array {
    $title = strtolower(trim($title));
    if ($title === '') {
      return ['error' => 'Title search term is required.'];
    }

    $datasets = $this->metastoreTools->listDatasets(0, 50);
    foreach ($datasets['datasets'] ?? [] as $ds) {
      if (str_contains(strtolower($ds['title'] ?? ''), $title)) {
        $dists = $this->metastoreTools->listDistributions($ds['identifier']);
        return [
          'dataset_id' => $ds['identifier'],
          'title' => $ds['title'],
          'distributions' => $dists['distributions'] ?? [],
        ];
      }
    }

    return ['error' => "No dataset found matching: $title"];
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
              'description' => 'Resource ID (identifier__version format) OR a dataset title for automatic lookup. Examples: "abc123__1773329007" or "Shark Tagging".',
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
              'description' => 'Resource ID (identifier__version format) OR a dataset title for automatic lookup.',
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
              'description' => 'Resource ID (identifier__version format) OR a dataset title for automatic lookup.',
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
              'description' => 'Resource ID (identifier__version format) OR a dataset title for automatic lookup.',
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
        'name' => 'create_chart',
        'description' => 'Render an interactive chart from query results. Pass a Vega-Lite v5 specification with data.values containing the results. Use after query_datastore when visualization would help. Good for: comparisons (bar), trends (line), distributions (histogram), proportions (arc), correlations (point).',
        'input_schema' => [
          'type' => 'object',
          'properties' => [
            'spec' => [
              'type' => 'object',
              'description' => 'Vega-Lite v5 spec. Example: {"$schema":"https://vega.github.io/schema/vega-lite/v5.json","data":{"values":[{"x":"A","y":10}]},"mark":"bar","encoding":{"x":{"field":"x","type":"nominal"},"y":{"field":"y","type":"quantitative"}}}',
            ],
          ],
          'required' => ['spec'],
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
        'description' => 'Get distributions (data files) for a dataset by UUID. Returns resource_id needed for datastore tools.',
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
      [
        'name' => 'find_dataset_resources',
        'description' => 'Find a dataset by title and get its resource_ids. Use this instead of list_distributions when you know the dataset title — avoids needing to type the UUID. Returns dataset_id, title, and distributions with resource_ids.',
        'input_schema' => [
          'type' => 'object',
          'properties' => [
            'title' => [
              'type' => 'string',
              'description' => 'Dataset title or partial title to search for (case-insensitive)',
            ],
          ],
          'required' => ['title'],
        ],
      ],
    ];
  }

}
