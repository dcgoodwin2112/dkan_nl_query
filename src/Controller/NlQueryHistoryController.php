<?php

namespace Drupal\dkan_nl_query\Controller;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * API endpoints for conversation history CRUD.
 */
class NlQueryHistoryController {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AccountProxyInterface $currentUser,
  ) {}

  /**
   * List the current user's conversations.
   */
  public function list(): JsonResponse {
    $storage = $this->entityTypeManager->getStorage('nl_query_conversation');

    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('uid', $this->currentUser->id())
      ->sort('pinned', 'DESC')
      ->sort('changed', 'DESC')
      ->range(0, 50)
      ->execute();

    $conversations = [];
    foreach ($storage->loadMultiple($ids) as $entity) {
      $conversations[] = [
        'id' => (int) $entity->id(),
        'title' => $entity->get('title')->value,
        'dataset_id' => $entity->get('dataset_id')->value,
        'pinned' => (bool) $entity->get('pinned')->value,
        'created' => (int) $entity->get('created')->value,
        'changed' => (int) $entity->get('changed')->value,
      ];
    }

    return new JsonResponse($conversations);
  }

  /**
   * Load a conversation with all its messages.
   */
  public function load(string $id): JsonResponse {
    $conversation = $this->entityTypeManager
      ->getStorage('nl_query_conversation')
      ->load($id);

    if (!$conversation) {
      return new JsonResponse(['error' => 'Conversation not found.'], 404);
    }

    if ((int) $conversation->get('uid')->target_id !== (int) $this->currentUser->id()) {
      return new JsonResponse(['error' => 'Access denied.'], 403);
    }

    $messageStorage = $this->entityTypeManager->getStorage('nl_query_message');
    $messageIds = $messageStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('conversation_id', $id)
      ->sort('weight', 'ASC')
      ->sort('id', 'ASC')
      ->execute();

    $messages = [];
    foreach ($messageStorage->loadMultiple($messageIds) as $msg) {
      $message = [
        'role' => $msg->get('role')->value,
        'content' => $msg->get('content')->value,
      ];

      $chartSpec = $msg->get('chart_spec')->value;
      if ($chartSpec) {
        $message['chart_spec'] = json_decode($chartSpec, TRUE);
      }

      $tableData = $msg->get('table_data')->value;
      if ($tableData) {
        $message['table_data'] = json_decode($tableData, TRUE);
      }

      $toolCalls = $msg->get('tool_calls')->value;
      if ($toolCalls) {
        $message['tool_calls'] = json_decode($toolCalls, TRUE);
      }

      $messages[] = $message;
    }

    return new JsonResponse([
      'id' => (int) $conversation->id(),
      'title' => $conversation->get('title')->value,
      'dataset_id' => $conversation->get('dataset_id')->value,
      'pinned' => (bool) $conversation->get('pinned')->value,
      'messages' => $messages,
    ]);
  }

  /**
   * Delete a conversation and its messages.
   */
  public function delete(string $id): JsonResponse {
    $storage = $this->entityTypeManager->getStorage('nl_query_conversation');
    $conversation = $storage->load($id);

    if (!$conversation) {
      return new JsonResponse(['error' => 'Conversation not found.'], 404);
    }

    if ((int) $conversation->get('uid')->target_id !== (int) $this->currentUser->id()) {
      return new JsonResponse(['error' => 'Access denied.'], 403);
    }

    // Delete all messages first.
    $messageStorage = $this->entityTypeManager->getStorage('nl_query_message');
    $messageIds = $messageStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('conversation_id', $id)
      ->execute();
    if ($messageIds) {
      $messageStorage->delete($messageStorage->loadMultiple($messageIds));
    }

    $conversation->delete();

    return new JsonResponse(['status' => 'deleted']);
  }

  /**
   * Toggle pinned status on a conversation.
   */
  public function togglePin(string $id): JsonResponse {
    $conversation = $this->entityTypeManager
      ->getStorage('nl_query_conversation')
      ->load($id);

    if (!$conversation) {
      return new JsonResponse(['error' => 'Conversation not found.'], 404);
    }

    if ((int) $conversation->get('uid')->target_id !== (int) $this->currentUser->id()) {
      return new JsonResponse(['error' => 'Access denied.'], 403);
    }

    $pinned = !((bool) $conversation->get('pinned')->value);
    $conversation->set('pinned', $pinned);
    $conversation->save();

    return new JsonResponse(['pinned' => $pinned]);
  }

}
