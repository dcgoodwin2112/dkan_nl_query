<?php

/**
 * @file
 * Stubs for DKAN/Drupal classes referenced by dkan_query_tools Tool classes.
 *
 * These are needed so PHPUnit can load DatastoreTools, MetastoreTools,
 * and SearchTools for mocking without a full Drupal bootstrap.
 */

namespace Drupal\common\Storage {

  interface DatabaseTableInterface {

    public function getSchema(): array;

    public function getTableName(): string;

  }

}

namespace Drupal\common {

  class DatasetInfo {

    public function gather(string $uuid): array {
      return [];
    }

  }

}

namespace Drupal\datastore {

  use Drupal\common\Storage\DatabaseTableInterface;

  class DatastoreService {

    public function getStorage(string $identifier, $version = NULL): DatabaseTableInterface {
      throw new \RuntimeException('Not implemented');
    }

    public function summary($identifier) {
      return [];
    }

    public function import(string $identifier, bool $deferred = FALSE, $version = NULL) {
      return [];
    }

    public function drop(string $identifier, ?string $version = NULL): void {
    }

  }

}

namespace Drupal\datastore\Service {

  class DatastoreQuery {

    protected string $json;

    public function __construct(string $json, $rows_limit = NULL) {
      $this->json = $json;
    }

    public function __toString(): string {
      return $this->json;
    }

  }

  class Query {

    public function runQuery(DatastoreQuery $datastoreQuery) {
      return new \RootedData\RootedJsonData('{"results":[],"count":0,"schema":{}}');
    }

  }

}

namespace Drupal\metastore {

  use RootedData\RootedJsonData;

  class MetastoreService {

    public function getAll(string $schema_id, ?int $start = NULL, ?int $length = NULL, $unpublished = FALSE): array {
      return [];
    }

    public function get(string $schema_id, string $identifier, bool $published = TRUE): RootedJsonData {
      return new RootedJsonData('{}');
    }

    public function count(string $schema_id, bool $unpublished = FALSE): int {
      return 0;
    }

    public function getSchemas() {
      return [];
    }

    public function getCatalog() {
      return new \stdClass();
    }

    public function post(string $schema_id, RootedJsonData $data): string {
      return '';
    }

    public function publish(string $schema_id, string $identifier): bool {
      return TRUE;
    }

    public function put(string $schema_id, string $identifier, RootedJsonData $data): array {
      return ['identifier' => $identifier, 'new' => FALSE];
    }

    public function patch(string $schema_id, string $identifier, mixed $json_data): string {
      return $identifier;
    }

    public function delete(string $schema_id, string $identifier): string {
      return $identifier;
    }

    public function getSchema(string $schema_id) {
      return new \stdClass();
    }

  }

}

namespace RootedData {

  class RootedJsonData {

    protected string $json;

    public function __construct(string $json = '{}', $schema = '{}') {
      $this->json = $json;
    }

    public function __toString(): string {
      return $this->json;
    }

    public function set(string $path, $value): void {
      if ($path === '$') {
        $this->json = json_encode($value);
      }
    }

  }

}

namespace Drupal\Core\Database {

  abstract class Connection {

    public function select(string $table, ?string $alias = NULL, array $options = []) {
      throw new \RuntimeException('Not implemented');
    }

  }

}

namespace Drupal\Core\Database\Query {

  interface SelectInterface {}

}
