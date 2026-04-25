<?php

namespace Drupal\dkan_nl_query\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\dkan_nl_query\Service\NlQueryService;
use Drupal\dkan_nl_query\Service\SchemaContextBuilder;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * SSE streaming endpoint for natural language queries.
 */
class NlQueryController {

  public function __construct(
    protected NlQueryService $nlQueryService,
    protected SchemaContextBuilder $schemaContextBuilder,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AccountProxyInterface $currentUser,
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Handle a natural language query via SSE.
   */
  public function query(?string $dataset_id, Request $request): StreamedResponse|JsonResponse {
    $question = $request->request->get('question', '');
    if (empty(trim($question))) {
      return new JsonResponse(['error' => 'Question is required.'], 400);
    }

    // Decode conversation history if provided.
    $historyJson = $request->request->get('history', '[]');
    $history = json_decode($historyJson, TRUE) ?? [];

    // Optional model override from frontend selector.
    $model = $request->request->get('model') ?: NULL;

    // Conversation ID for follow-ups.
    $conversationId = $request->request->get('conversation_id') ?: NULL;

    // Normalize empty dataset_id to null for cross-dataset mode.
    $datasetId = !empty($dataset_id) ? $dataset_id : NULL;

    // Capture references for the closure.
    $entityTypeManager = $this->entityTypeManager;
    $currentUser = $this->currentUser;
    $config = $this->configFactory->get('dkan_nl_query.settings');
    $shouldSave = $currentUser->isAuthenticated() && ($config->get('save_chat_history') ?? TRUE);

    return new StreamedResponse(function () use ($datasetId, $question, $history, $model, $conversationId, $entityTypeManager, $currentUser, $shouldSave) {
      // Allow long-running agentic loops (multiple Claude API calls).
      set_time_limit(300);

      // Prevent buffering.
      while (ob_get_level()) {
        ob_end_clean();
      }

      $emit = function (string $type, mixed $data) {
        echo "event: {$type}\n";
        echo "data: " . json_encode($data) . "\n\n";
        flush();
      };

      try {
        $result = $this->nlQueryService->query($datasetId, $question, $emit, $history, $model);
      }
      catch (\Throwable $e) {
        $emit('error', ['message' => 'Server error: ' . $e->getMessage()]);
        echo "event: done\ndata: {}\n\n";
        flush();
        return;
      }

      // Save conversation if user is authenticated and feature is enabled.
      if ($shouldSave && !empty(trim($result['answer'] ?? ''))) {
        try {
          $convStorage = $entityTypeManager->getStorage('nl_query_conversation');
          $msgStorage = $entityTypeManager->getStorage('nl_query_message');

          // Load or create conversation.
          if ($conversationId) {
            $conversation = $convStorage->load($conversationId);
            // Verify ownership.
            if ($conversation && (int) $conversation->get('uid')->target_id !== (int) $currentUser->id()) {
              $conversation = NULL;
            }
          }
          else {
            $conversation = NULL;
          }

          if (!$conversation) {
            $title = mb_substr(trim($question), 0, 255);
            $conversation = $convStorage->create([
              'uid' => $currentUser->id(),
              'title' => $title,
              'dataset_id' => $datasetId ?: '',
              'pinned' => FALSE,
            ]);
            $conversation->save();
          }
          else {
            // Touch the changed timestamp.
            $conversation->save();
          }

          // Determine next weight.
          $maxWeight = 0;
          $existingIds = $msgStorage->getQuery()
            ->accessCheck(FALSE)
            ->condition('conversation_id', $conversation->id())
            ->sort('weight', 'DESC')
            ->range(0, 1)
            ->execute();
          if ($existingIds) {
            $last = $msgStorage->load(reset($existingIds));
            $maxWeight = (int) $last->get('weight')->value;
          }

          // Save user message.
          $msgStorage->create([
            'conversation_id' => $conversation->id(),
            'role' => 'user',
            'content' => $question,
            'weight' => $maxWeight + 1,
          ])->save();

          // Save assistant message with collected data.
          $msgStorage->create([
            'conversation_id' => $conversation->id(),
            'role' => 'assistant',
            'content' => $result['answer'],
            'chart_spec' => $result['chart_spec'] ? json_encode($result['chart_spec']) : NULL,
            'table_data' => $result['table_data'] ? json_encode($result['table_data']) : NULL,
            'tool_calls' => !empty($result['tool_calls']) ? json_encode($result['tool_calls']) : NULL,
            'weight' => $maxWeight + 2,
          ])->save();

          // Emit conversation ID so frontend can track follow-ups.
          $emit('conversation', [
            'id' => (int) $conversation->id(),
            'title' => $conversation->get('title')->value,
          ]);
        }
        catch (\Throwable $e) {
          // Don't fail the response if saving fails.
          \Drupal::logger('dkan_nl_query')->warning('Failed to save conversation: @message', [
            '@message' => $e->getMessage(),
          ]);
        }
      }

      // Generate follow-up suggestions if enabled.
      $nlConfig = $this->configFactory->get('dkan_nl_query.settings');
      if (($nlConfig->get('show_follow_up_suggestions') ?? TRUE) && !empty(trim($result['answer'] ?? ''))) {
        try {
          $suggestions = $this->nlQueryService->generateSuggestions(
            $question,
            $result['answer'],
            $result['tool_calls'] ?? [],
          );
          if ($suggestions) {
            $emit('suggestions', ['items' => $suggestions]);
          }
        }
        catch (\Throwable $e) {
          \Drupal::logger('dkan_nl_query')->warning('Suggestions failed: @message', [
            '@message' => $e->getMessage(),
          ]);
        }
      }

      echo "event: done\ndata: {}\n\n";
      flush();
    }, 200, [
      'Content-Type' => 'text/event-stream',
      'Cache-Control' => 'no-cache',
      'Connection' => 'keep-alive',
      'X-Accel-Buffering' => 'no',
    ]);
  }

  /**
   * Return list of datasets for the selector dropdown.
   */
  public function listDatasets(): JsonResponse {
    $catalog = $this->schemaContextBuilder->buildCatalogContext();
    return new JsonResponse($catalog['datasets'] ?? []);
  }

}
