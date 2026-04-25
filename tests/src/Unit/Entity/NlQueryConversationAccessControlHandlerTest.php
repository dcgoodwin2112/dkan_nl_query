<?php

namespace Drupal\Tests\dkan_nl_query\Unit\Entity;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\dkan_nl_query\Entity\NlQueryConversationAccessControlHandler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Testable subclass that exposes protected methods.
 */
class TestableAccessHandler extends NlQueryConversationAccessControlHandler {

  public function __construct() {
    // Skip parent constructor (requires EntityTypeInterface).
  }

  public function testCheckAccess(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult {
    return $this->checkAccess($entity, $operation, $account);
  }

  public function testCheckCreateAccess(AccountInterface $account, array $context = []): AccessResult {
    return $this->checkCreateAccess($account, $context);
  }

}

/**
 * @coversDefaultClass \Drupal\dkan_nl_query\Entity\NlQueryConversationAccessControlHandler
 */
class NlQueryConversationAccessControlHandlerTest extends TestCase {

  protected TestableAccessHandler $handler;

  protected function setUp(): void {
    // Set up a minimal Drupal container for AccessResult's cache context calls.
    $cacheContextsManager = $this->createMock(CacheContextsManager::class);
    $cacheContextsManager->method('assertValidTokens')->willReturn(TRUE);

    $container = new ContainerBuilder();
    $container->set('cache_contexts_manager', $cacheContextsManager);
    \Drupal::setContainer($container);

    $this->handler = new TestableAccessHandler();
  }

  protected function mockAccount(int $uid, array $permissions = []): AccountInterface {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn($uid);
    $account->method('hasPermission')
      ->willReturnCallback(fn($perm) => in_array($perm, $permissions, TRUE));
    return $account;
  }

  protected function mockEntity(int $ownerUid): EntityInterface {
    $entity = $this->createMock(\Drupal\Core\Entity\FieldableEntityInterface::class);
    $uidField = new \stdClass();
    $uidField->target_id = $ownerUid;
    $entity->method('get')
      ->with('uid')
      ->willReturn($uidField);
    return $entity;
  }

  /**
   * @covers ::checkAccess
   */
  public function testAdminCanAccessAnyConversation(): void {
    $account = $this->mockAccount(1, ['administer nl query conversations']);
    $entity = $this->mockEntity(99);

    $result = $this->handler->testCheckAccess($entity, 'view', $account);

    $this->assertTrue($result->isAllowed());
  }

  /**
   * @covers ::checkAccess
   */
  public function testOwnerWithPermissionCanView(): void {
    $account = $this->mockAccount(42, ['manage own nl query conversations']);
    $entity = $this->mockEntity(42);

    $result = $this->handler->testCheckAccess($entity, 'view', $account);

    $this->assertTrue($result->isAllowed());
  }

  /**
   * @covers ::checkAccess
   */
  public function testOwnerWithPermissionCanUpdate(): void {
    $account = $this->mockAccount(42, ['manage own nl query conversations']);
    $entity = $this->mockEntity(42);

    $result = $this->handler->testCheckAccess($entity, 'update', $account);

    $this->assertTrue($result->isAllowed());
  }

  /**
   * @covers ::checkAccess
   */
  public function testOwnerWithPermissionCanDelete(): void {
    $account = $this->mockAccount(42, ['manage own nl query conversations']);
    $entity = $this->mockEntity(42);

    $result = $this->handler->testCheckAccess($entity, 'delete', $account);

    $this->assertTrue($result->isAllowed());
  }

  /**
   * @covers ::checkAccess
   */
  public function testNonOwnerWithPermissionIsDenied(): void {
    $account = $this->mockAccount(42, ['manage own nl query conversations']);
    $entity = $this->mockEntity(99);

    $result = $this->handler->testCheckAccess($entity, 'view', $account);

    $this->assertFalse($result->isAllowed());
  }

  /**
   * @covers ::checkAccess
   */
  public function testOwnerWithoutPermissionIsDenied(): void {
    $account = $this->mockAccount(42, []);
    $entity = $this->mockEntity(42);

    $result = $this->handler->testCheckAccess($entity, 'view', $account);

    $this->assertFalse($result->isAllowed());
  }

  /**
   * @covers ::checkAccess
   */
  public function testUnknownOperationReturnsNeutral(): void {
    $account = $this->mockAccount(42, ['manage own nl query conversations']);
    $entity = $this->mockEntity(42);

    $result = $this->handler->testCheckAccess($entity, 'unknown_op', $account);

    $this->assertTrue($result->isNeutral());
  }

  /**
   * @covers ::checkCreateAccess
   */
  public function testCreateAccessWithPermission(): void {
    $account = $this->mockAccount(42, ['manage own nl query conversations']);

    $result = $this->handler->testCheckCreateAccess($account);

    $this->assertTrue($result->isAllowed());
  }

  /**
   * @covers ::checkCreateAccess
   */
  public function testCreateAccessWithoutPermission(): void {
    $account = $this->mockAccount(42, []);

    $result = $this->handler->testCheckCreateAccess($account);

    $this->assertFalse($result->isAllowed());
  }

}
