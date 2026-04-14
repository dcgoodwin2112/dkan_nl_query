<?php

namespace Drupal\dkan_nl_query\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\dkan_mcp\Tools\DatastoreTools;
use Drupal\dkan_mcp\Tools\MetastoreTools;

/**
 * Builds schema context for Claude's system prompt.
 */
class SchemaContextBuilder {

  public function __construct(
    protected MetastoreTools $metastoreTools,
    protected DatastoreTools $datastoreTools,
    protected CacheBackendInterface $cache,
  ) {}

  /**
   * Build schema context for a dataset.
   *
   * @return array
   *   Context array with title, description, and resources.
   */
  public function buildContext(string $datasetId): array {
    $cacheKey = "dkan_nl_query:context:$datasetId";
    $cached = $this->cache->get($cacheKey);
    if ($cached) {
      return $cached->data;
    }

    $datasetResult = $this->metastoreTools->getDataset($datasetId);
    if (isset($datasetResult['error'])) {
      return $datasetResult;
    }
    $dataset = $datasetResult['dataset'] ?? $datasetResult;

    $distributions = $this->metastoreTools->listDistributions($datasetId);
    if (isset($distributions['error'])) {
      return $distributions;
    }

    $resources = [];
    foreach ($distributions['distributions'] ?? [] as $dist) {
      $resourceId = $dist['resource_id'] ?? NULL;
      if (!$resourceId) {
        continue;
      }

      // Skip resources that aren't imported.
      $importStatus = $this->datastoreTools->getImportStatus($resourceId);
      if (($importStatus['status'] ?? '') !== 'done') {
        continue;
      }

      $schema = $this->datastoreTools->getDatastoreSchema($resourceId);
      if (isset($schema['error'])) {
        continue;
      }

      $stats = $this->datastoreTools->getDatastoreStats($resourceId);

      // Fetch sample values for each column (3 distinct values).
      $sampleValues = $this->fetchSampleValues($resourceId, $schema['columns'] ?? []);

      $columns = [];
      foreach ($schema['columns'] ?? [] as $col) {
        $colInfo = [
          'name' => $col['name'],
          'type' => $col['type'] ?? 'text',
          'description' => $col['description'] ?? $col['name'],
        ];

        if (!isset($stats['error'])) {
          foreach ($stats['columns'] ?? [] as $statCol) {
            if ($statCol['name'] === $col['name']) {
              $colInfo['distinct_count'] = $statCol['distinct_count'] ?? NULL;
              $colInfo['null_count'] = $statCol['null_count'] ?? NULL;
              $colInfo['min'] = $statCol['min'] ?? NULL;
              $colInfo['max'] = $statCol['max'] ?? NULL;
              break;
            }
          }
        }

        if (isset($sampleValues[$col['name']])) {
          $colInfo['sample_values'] = $sampleValues[$col['name']];
        }

        $columns[] = $colInfo;
      }

      $resources[] = [
        'resource_id' => $resourceId,
        'title' => $dist['title'] ?? $dist['media_type'] ?? 'Unknown',
        'columns' => $columns,
        'total_rows' => $stats['total_rows'] ?? NULL,
      ];
    }

    $context = [
      'title' => $dataset['title'] ?? 'Unknown',
      'description' => $dataset['description'] ?? '',
      'keywords' => $dataset['keyword'] ?? [],
      'themes' => $dataset['theme'] ?? [],
      'resources' => $resources,
    ];

    $this->cache->set($cacheKey, $context, time() + 3600);

    return $context;
  }

  /**
   * Fetch 3 sample distinct values per column.
   *
   * @return array
   *   Keyed by column name, values are arrays of sample strings.
   */
  protected function fetchSampleValues(string $resourceId, array $columns): array {
    $samples = [];
    // Query first 5 rows to extract sample values.
    $result = $this->datastoreTools->queryDatastore(
      resourceId: $resourceId,
      limit: 5,
    );
    if (isset($result['error']) || empty($result['results'])) {
      return $samples;
    }

    foreach ($columns as $col) {
      $name = $col['name'];
      $seen = [];
      foreach ($result['results'] as $row) {
        $val = $row[$name] ?? '';
        if ($val !== '' && !in_array($val, $seen, TRUE)) {
          $seen[] = $val;
          if (count($seen) >= 3) {
            break;
          }
        }
      }
      if ($seen) {
        $samples[$name] = $seen;
      }
    }

    return $samples;
  }

  /**
   * Build catalog context listing all available datasets.
   *
   * @return array
   *   Context array with datasets list.
   */
  public function buildCatalogContext(): array {
    $cacheKey = 'dkan_nl_query:catalog';
    $cached = $this->cache->get($cacheKey);
    if ($cached) {
      return $cached->data;
    }

    $result = $this->metastoreTools->listDatasets(0, 50);
    $datasets = [];
    foreach ($result['datasets'] ?? [] as $ds) {
      // Fetch full metadata for keywords/themes.
      $full = $this->metastoreTools->getDataset($ds['identifier']);
      $meta = $full['dataset'] ?? $full;

      $datasets[] = [
        'identifier' => $ds['identifier'],
        'title' => $ds['title'] ?? 'Untitled',
        'description' => isset($ds['description']) ? mb_substr($ds['description'], 0, 200) : '',
        'distributions' => $ds['distributions'] ?? 0,
        'keywords' => $meta['keyword'] ?? [],
        'themes' => $meta['theme'] ?? [],
      ];
    }

    $context = [
      'datasets' => $datasets,
      'total' => $result['total'] ?? count($datasets),
    ];

    $this->cache->set($cacheKey, $context, time() + 3600);

    return $context;
  }

  /**
   * Build system prompt for cross-dataset discovery mode.
   */
  public function buildCatalogSystemPrompt(array $catalog): string {
    $prompt = "You are a data query assistant for a DKAN open data portal. ";
    $prompt .= "Help users find and query datasets using natural language.\n\n";

    $prompt .= "## Available Datasets ({$catalog['total']} total)\n\n";
    foreach ($catalog['datasets'] as $ds) {
      $prompt .= "- **{$ds['title']}** (ID: `{$ds['identifier']}`)";
      if ($ds['distributions']) {
        $prompt .= " — {$ds['distributions']} file(s)";
      }
      $tags = array_merge($ds['keywords'] ?? [], $ds['themes'] ?? []);
      if ($tags) {
        $prompt .= " [" . implode(', ', $tags) . "]";
      }
      if ($ds['description']) {
        $prompt .= "\n  {$ds['description']}";
      }
      $prompt .= "\n";
    }

    $prompt .= "\n## Workflow\n\n";
    $prompt .= "1. Use search_datasets or search_columns to find relevant data.\n";
    $prompt .= "2. Use list_distributions(dataset_id) to get the resource_id (identifier__version format).\n";
    $prompt .= "3. Use get_datastore_schema(resource_id) to discover columns.\n";
    $prompt .= "4. Optionally use get_datastore_stats to understand data distribution before querying.\n";
    $prompt .= "5. Use query_datastore(resource_id, ...) to answer the question.\n";
    $prompt .= "6. To compare data across datasets, use query_datastore_join with two resource_ids.\n";
    $prompt .= "7. Don't reproduce the full query results as a table — the UI shows an interactive data table automatically. You may use small markdown tables for summaries or comparisons.\n\n";

    $prompt .= "## Important Notes\n\n";
    $prompt .= "- Resource IDs use `identifier__version` format (e.g., `abc123__1773329007`). Get them from list_distributions.\n";
    $prompt .= "- All data is stored as text. String comparisons apply: \"9\" > \"10\" alphabetically. Use aggregate expressions (max, min) for numeric ordering.\n";
    $prompt .= "- Conditions must be a JSON string array, e.g.: `[{\"property\":\"state\",\"value\":\"CA\",\"operator\":\"=\"}]`\n";
    $prompt .= "- Maximum 500 rows per query. Use pagination (offset) for more.\n";
    $prompt .= "- Cannot filter on aggregated values (no HAVING clause).\n";

    return $prompt;
  }

  /**
   * Build a system prompt string from single-dataset context.
   */
  public function buildSystemPrompt(array $context): string {
    $prompt = "You are a data query assistant for a DKAN open data portal. ";
    $prompt .= "You help users query the dataset \"{$context['title']}\" using natural language.\n\n";

    if (!empty($context['description'])) {
      $prompt .= "## Dataset Description\n\n{$context['description']}\n\n";
    }

    $tags = array_merge($context['keywords'] ?? [], $context['themes'] ?? []);
    if ($tags) {
      $prompt .= "Tags: " . implode(', ', $tags) . "\n\n";
    }

    foreach ($context['resources'] as $resource) {
      $prompt .= "## Resource: {$resource['resource_id']}\n\n";
      if ($resource['total_rows']) {
        $prompt .= "Total rows: {$resource['total_rows']}\n\n";
      }
      $prompt .= "Columns:\n";
      foreach ($resource['columns'] as $col) {
        $line = "- **{$col['name']}** ({$col['type']})";
        if ($col['description'] !== $col['name']) {
          $line .= ": {$col['description']}";
        }
        $details = [];
        if (isset($col['distinct_count'])) {
          $details[] = "{$col['distinct_count']} distinct";
        }
        if (isset($col['min']) && isset($col['max'])) {
          $details[] = "range: {$col['min']}–{$col['max']}";
        }
        if (!empty($col['sample_values'])) {
          $details[] = 'e.g.: "' . implode('", "', $col['sample_values']) . '"';
        }
        if ($details) {
          $line .= " — " . implode(', ', $details);
        }
        $prompt .= "$line\n";
      }
      $prompt .= "\n";
    }

    $prompt .= "## Instructions\n\n";
    $prompt .= "1. Use get_datastore_stats to understand data distribution if needed.\n";
    $prompt .= "2. Use query_datastore to answer the user's question.\n";
    $prompt .= "3. Use search_columns to find columns by name across all resources.\n";
    $prompt .= "4. Choose appropriate columns, conditions, sorting, and aggregation.\n";
    $prompt .= "5. Keep results concise — use limit and specific columns.\n";
    $prompt .= "6. After receiving results, provide a clear natural language summary.\n";
    $prompt .= "7. If the question is ambiguous, make reasonable assumptions and state them.\n";
    $prompt .= "8. Don't reproduce the full query results as a table — the UI shows an interactive data table automatically. You may use small markdown tables for summaries or comparisons.\n\n";

    $prompt .= "## Important Notes\n\n";
    $prompt .= "- All data is stored as text. String comparisons apply: \"9\" > \"10\" alphabetically. Use aggregate expressions (max, min) for true numeric ordering.\n";
    $prompt .= "- Conditions must be a JSON string array, e.g.: `[{\"property\":\"state\",\"value\":\"CA\",\"operator\":\"=\"}]`\n";
    $prompt .= "- For IN: `[{\"property\":\"state\",\"value\":[\"CA\",\"TX\"],\"operator\":\"in\"}]`\n";
    $prompt .= "- Maximum 500 rows per query. Single-column sort only.\n";
    $prompt .= "- Cannot filter on aggregated values (no HAVING). Cannot mix aggregate and arithmetic expressions.\n";

    return $prompt;
  }

}
