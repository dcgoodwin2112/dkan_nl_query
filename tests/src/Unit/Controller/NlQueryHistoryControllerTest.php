<?php

namespace Drupal\Tests\dkan_nl_query\Unit\Controller;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\dkan_nl_query\Controller\NlQueryHistoryController;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \Drupal\dkan_nl_query\Controller\NlQueryHistoryController
 */
class NlQueryHistoryControllerTest extends TestCase {

  protected function createController(
    ?EntityTypeManagerInterface $entityTypeManager = NULL,
    ?AccountProxyInterface $currentUser = NULL,
  ): NlQueryHistoryController {
    return new NlQueryHistoryController(
      $entityTypeManager ?? $this->createMock(EntityTypeManagerInterface::class),
      $currentUser ?? $this->mockUser(42),
    );
  }

  protected function mockUser(int $uid): AccountProxyInterface {
    $user = $this->createMock(AccountProxyInterface::class);
    $user->method('id')->willReturn($uid);
    return $user;
  }

  protected function mockEntity(array $values, array $targetIds = []): object {
    $entity = $this->createMock(\Drupal\Core\Entity\FieldableEntityInterface::class);
    $entity->method('id')->willReturn($values['id'] ?? '1');
    $entity->method('get')->willReturnCallback(function ($field) use ($values, $targetIds) {
      $item = new \stdClass();
      $item->value = $values[$field] ?? NULL;
      $item->target_id = $targetIds[$field] ?? ($values[$field] ?? NULL);
      return $item;
    });
    $entity->method('delete')->willReturn(NULL);
    return $entity;
  }

  /**
   * @covers ::list
   */
  public function testListReturnsConversations(): void {
    $entity = $this->mockEntity([
      'id' => '1',
      'title' => 'Test Conversation',
      'dataset_id' => 'ds-uuid',
      'pinned' => '1',
      'created' => '1700000000',
      'changed' => '1700001000',
    ], ['uid' => 42]);

    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturn($query);
    $query->method('condition')->willReturn($query);
    $query->method('sort')->willReturn($query);
    $query->method('range')->willReturn($query);
    $query->method('execute')->willReturn([1]);

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('getQuery')->willReturn($query);
    $storage->method('loadMultiple')->willReturn([1 => $entity]);

    $etm = $this->createMock(EntityTypeManagerInterface::class);
    $etm->method('getStorage')->willReturn($storage);

    $controller = $this->createController(entityTypeManager: $etm);
    $response = $controller->list();

    $this->assertEquals(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertCount(1, $data);
    $this->assertEquals('Test Conversation', $data[0]['title']);
    $this->assertEquals(1, $data[0]['id']);
    $this->assertTrue($data[0]['pinned']);
  }

  /**
   * @covers ::list
   */
  public function testListReturnsEmptyArray(): void {
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturn($query);
    $query->method('condition')->willReturn($query);
    $query->method('sort')->willReturn($query);
    $query->method('range')->willReturn($query);
    $query->method('execute')->willReturn([]);

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('getQuery')->willReturn($query);
    $storage->method('loadMultiple')->willReturn([]);

    $etm = $this->createMock(EntityTypeManagerInterface::class);
    $etm->method('getStorage')->willReturn($storage);

    $controller = $this->createController(entityTypeManager: $etm);
    $response = $controller->list();

    $data = json_decode($response->getContent(), TRUE);
    $this->assertEmpty($data);
  }

  /**
   * @covers ::load
   */
  public function testLoadNotFound(): void {
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->willReturn(NULL);

    $etm = $this->createMock(EntityTypeManagerInterface::class);
    $etm->method('getStorage')->willReturn($storage);

    $controller = $this->createController(entityTypeManager: $etm);
    $response = $controller->load('999');

    $this->assertEquals(404, $response->getStatusCode());
  }

  /**
   * @covers ::load
   */
  public function testLoadAccessDenied(): void {
    $entity = $this->mockEntity(['id' => '1'], ['uid' => 99]);

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->willReturn($entity);

    $etm = $this->createMock(EntityTypeManagerInterface::class);
    $etm->method('getStorage')->willReturn($storage);

    $controller = $this->createController(
      entityTypeManager: $etm,
      currentUser: $this->mockUser(42),
    );
    $response = $controller->load('1');

    $this->assertEquals(403, $response->getStatusCode());
  }

  /**
   * @covers ::load
   */
  public function testLoadReturnsConversationWithMessages(): void {
    $conversation = $this->mockEntity([
      'id' => '1',
      'title' => 'Test Chat',
      'dataset_id' => 'ds-uuid',
      'pinned' => '0',
    ], ['uid' => 42]);

    $message = $this->mockEntity([
      'role' => 'assistant',
      'content' => 'Hello world',
      'chart_spec' => '{"mark":"bar"}',
      'table_data' => '{"results":[]}',
      'tool_calls' => '[{"name":"query_datastore"}]',
    ]);

    $convStorage = $this->createMock(EntityStorageInterface::class);
    $convStorage->method('load')->willReturn($conversation);

    $msgQuery = $this->createMock(QueryInterface::class);
    $msgQuery->method('accessCheck')->willReturn($msgQuery);
    $msgQuery->method('condition')->willReturn($msgQuery);
    $msgQuery->method('sort')->willReturn($msgQuery);
    $msgQuery->method('execute')->willReturn([10]);

    $msgStorage = $this->createMock(EntityStorageInterface::class);
    $msgStorage->method('getQuery')->willReturn($msgQuery);
    $msgStorage->method('loadMultiple')->willReturn([10 => $message]);

    $etm = $this->createMock(EntityTypeManagerInterface::class);
    $etm->method('getStorage')
      ->willReturnCallback(fn($type) => match ($type) {
        'nl_query_conversation' => $convStorage,
        'nl_query_message' => $msgStorage,
      });

    $controller = $this->createController(
      entityTypeManager: $etm,
      currentUser: $this->mockUser(42),
    );
    $response = $controller->load('1');

    $this->assertEquals(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertEquals('Test Chat', $data['title']);
    $this->assertCount(1, $data['messages']);
    $this->assertEquals('assistant', $data['messages'][0]['role']);
    $this->assertEquals(['mark' => 'bar'], $data['messages'][0]['chart_spec']);
    $this->assertArrayHasKey('tool_calls', $data['messages'][0]);
  }

  /**
   * @covers ::load
   */
  public function testLoadMessageWithoutOptionalFields(): void {
    $conversation = $this->mockEntity([
      'id' => '1',
      'title' => 'Test',
      'dataset_id' => '',
      'pinned' => '0',
    ], ['uid' => 42]);

    $message = $this->mockEntity([
      'role' => 'user',
      'content' => 'Hello',
      'chart_spec' => NULL,
      'table_data' => NULL,
      'tool_calls' => NULL,
    ]);

    $convStorage = $this->createMock(EntityStorageInterface::class);
    $convStorage->method('load')->willReturn($conversation);

    $msgQuery = $this->createMock(QueryInterface::class);
    $msgQuery->method('accessCheck')->willReturn($msgQuery);
    $msgQuery->method('condition')->willReturn($msgQuery);
    $msgQuery->method('sort')->willReturn($msgQuery);
    $msgQuery->method('execute')->willReturn([1]);

    $msgStorage = $this->createMock(EntityStorageInterface::class);
    $msgStorage->method('getQuery')->willReturn($msgQuery);
    $msgStorage->method('loadMultiple')->willReturn([1 => $message]);

    $etm = $this->createMock(EntityTypeManagerInterface::class);
    $etm->method('getStorage')
      ->willReturnCallback(fn($type) => match ($type) {
        'nl_query_conversation' => $convStorage,
        'nl_query_message' => $msgStorage,
      });

    $controller = $this->createController(
      entityTypeManager: $etm,
      currentUser: $this->mockUser(42),
    );
    $response = $controller->load('1');

    $data = json_decode($response->getContent(), TRUE);
    $msg = $data['messages'][0];
    $this->assertEquals('user', $msg['role']);
    $this->assertArrayNotHasKey('chart_spec', $msg);
    $this->assertArrayNotHasKey('table_data', $msg);
    $this->assertArrayNotHasKey('tool_calls', $msg);
  }

  /**
   * @covers ::delete
   */
  public function testDeleteNotFound(): void {
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->willReturn(NULL);

    $etm = $this->createMock(EntityTypeManagerInterface::class);
    $etm->method('getStorage')->willReturn($storage);

    $controller = $this->createController(entityTypeManager: $etm);
    $response = $controller->delete('999');

    $this->assertEquals(404, $response->getStatusCode());
  }

  /**
   * @covers ::delete
   */
  public function testDeleteAccessDenied(): void {
    $entity = $this->mockEntity(['id' => '1'], ['uid' => 99]);

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->willReturn($entity);

    $etm = $this->createMock(EntityTypeManagerInterface::class);
    $etm->method('getStorage')->willReturn($storage);

    $controller = $this->createController(
      entityTypeManager: $etm,
      currentUser: $this->mockUser(42),
    );
    $response = $controller->delete('1');

    $this->assertEquals(403, $response->getStatusCode());
  }

  /**
   * @covers ::delete
   */
  public function testDeleteSuccess(): void {
    $conversation = $this->mockEntity(['id' => '1'], ['uid' => 42]);

    $msgQuery = $this->createMock(QueryInterface::class);
    $msgQuery->method('accessCheck')->willReturn($msgQuery);
    $msgQuery->method('condition')->willReturn($msgQuery);
    $msgQuery->method('execute')->willReturn([]);

    $msgStorage = $this->createMock(EntityStorageInterface::class);
    $msgStorage->method('getQuery')->willReturn($msgQuery);
    $msgStorage->method('loadMultiple')->willReturn([]);

    $convStorage = $this->createMock(EntityStorageInterface::class);
    $convStorage->method('load')->willReturn($conversation);

    $etm = $this->createMock(EntityTypeManagerInterface::class);
    $etm->method('getStorage')
      ->willReturnCallback(fn($type) => match ($type) {
        'nl_query_conversation' => $convStorage,
        'nl_query_message' => $msgStorage,
      });

    $controller = $this->createController(
      entityTypeManager: $etm,
      currentUser: $this->mockUser(42),
    );
    $response = $controller->delete('1');

    $this->assertEquals(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertEquals('deleted', $data['status']);
  }

  /**
   * @covers ::togglePin
   */
  public function testTogglePinNotFound(): void {
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->willReturn(NULL);

    $etm = $this->createMock(EntityTypeManagerInterface::class);
    $etm->method('getStorage')->willReturn($storage);

    $controller = $this->createController(entityTypeManager: $etm);
    $response = $controller->togglePin('999');

    $this->assertEquals(404, $response->getStatusCode());
  }

  /**
   * @covers ::togglePin
   */
  public function testTogglePinAccessDenied(): void {
    $entity = $this->mockEntity(['id' => '1'], ['uid' => 99]);

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->willReturn($entity);

    $etm = $this->createMock(EntityTypeManagerInterface::class);
    $etm->method('getStorage')->willReturn($storage);

    $controller = $this->createController(
      entityTypeManager: $etm,
      currentUser: $this->mockUser(42),
    );
    $response = $controller->togglePin('1');

    $this->assertEquals(403, $response->getStatusCode());
  }

  /**
   * @covers ::togglePin
   */
  public function testTogglePinUnpinToPin(): void {
    $entity = $this->mockEntity([
      'id' => '1',
      'pinned' => '0',
    ], ['uid' => 42]);
    $entity->method('set')->willReturn(NULL);
    $entity->method('save')->willReturn(1);

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->willReturn($entity);

    $etm = $this->createMock(EntityTypeManagerInterface::class);
    $etm->method('getStorage')->willReturn($storage);

    $controller = $this->createController(
      entityTypeManager: $etm,
      currentUser: $this->mockUser(42),
    );
    $response = $controller->togglePin('1');

    $this->assertEquals(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertTrue($data['pinned']);
  }

  /**
   * @covers ::togglePin
   */
  public function testTogglePinPinToUnpin(): void {
    $entity = $this->mockEntity([
      'id' => '1',
      'pinned' => '1',
    ], ['uid' => 42]);
    $entity->method('set')->willReturn(NULL);
    $entity->method('save')->willReturn(1);

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->willReturn($entity);

    $etm = $this->createMock(EntityTypeManagerInterface::class);
    $etm->method('getStorage')->willReturn($storage);

    $controller = $this->createController(
      entityTypeManager: $etm,
      currentUser: $this->mockUser(42),
    );
    $response = $controller->togglePin('1');

    $data = json_decode($response->getContent(), TRUE);
    $this->assertFalse($data['pinned']);
  }

}
