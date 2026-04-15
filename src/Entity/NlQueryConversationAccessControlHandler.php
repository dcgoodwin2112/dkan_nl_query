<?php

namespace Drupal\dkan_nl_query\Entity;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Access control for NL Query Conversation entities.
 */
class NlQueryConversationAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResult {
    if ($account->hasPermission('administer nl query conversations')) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    $isOwner = $entity->get('uid')->target_id == $account->id();

    return match ($operation) {
      'view', 'update', 'delete' => AccessResult::allowedIf(
        $isOwner && $account->hasPermission('manage own nl query conversations')
      )->cachePerPermissions()->cachePerUser(),
      default => AccessResult::neutral(),
    };
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL): AccessResult {
    return AccessResult::allowedIfHasPermission($account, 'manage own nl query conversations');
  }

}
