<?php

namespace Drupal\dkan_nl_query\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\dkan_nl_query\Llm\LlmProviderFactory;

/**
 * Agentic loop: NL question → LLM provider → tool calls → answer.
 */
class NlQueryService {

  public function __construct(
    protected SchemaContextBuilder $schemaContextBuilder,
    protected ToolExecutor $toolExecutor,
    protected ConfigFactoryInterface $configFactory,
    protected LlmProviderFactory $providerFactory,
    protected LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Query a dataset with natural language, streaming results.
   *
   * @param string|null $datasetId
   *   Dataset UUID, or null for cross-dataset mode.
   * @param string $question
   *   Natural language question.
   * @param callable $emit
   *   Callback to emit SSE events. Called as $emit(string $type, mixed $data).
   * @param array $history
   *   Prior conversation turns as [{role, content}, ...].
   * @param string|null $model
   *   Model override. Uses admin-configured default when null.
   */
  public function query(?string $datasetId, string $question, callable $emit, array $history = [], ?string $model = NULL): void {
    $config = $this->configFactory->get('dkan_nl_query.settings');

    // Build context and tools based on mode.
    if ($datasetId) {
      $context = $this->schemaContextBuilder->buildContext($datasetId);
      if (isset($context['error'])) {
        $emit('error', ['message' => $context['error']]);
        return;
      }
      if (empty($context['resources'])) {
        $emit('error', ['message' => 'No queryable resources found for this dataset.']);
        return;
      }
      $systemPrompt = $this->schemaContextBuilder->buildSystemPrompt($context);
      $tools = $this->toolExecutor->getQueryToolDefinitions();
    }
    else {
      $catalog = $this->schemaContextBuilder->buildCatalogContext();
      if (empty($catalog['datasets'])) {
        $emit('error', ['message' => 'No datasets available on this site.']);
        return;
      }
      $systemPrompt = $this->schemaContextBuilder->buildCatalogSystemPrompt($catalog);
      $tools = $this->toolExecutor->getDiscoveryToolDefinitions();
    }

    $model = $model ?: $config->get('model') ?: 'claude-sonnet-4-20250514';
    $maxTokens = $config->get('max_tokens') ?: 4096;

    // Create the provider based on the model.
    $provider = $this->providerFactory->createForModel($model);

    // Build messages with conversation history.
    $messages = [];
    foreach ($history as $turn) {
      if (isset($turn['role']) && isset($turn['content'])) {
        $messages[] = [
          'role' => $turn['role'],
          'content' => $turn['content'],
        ];
      }
    }
    $messages[] = ['role' => 'user', 'content' => $question];

    // Agentic loop: keep calling the LLM until it stops using tools.
    $maxIterations = 5;
    for ($i = 0; $i < $maxIterations; $i++) {
      $emit('status', ['message' => $i === 0 ? 'Thinking...' : 'Analyzing results...']);

      try {
        $response = $provider->stream(
          $systemPrompt, $messages, $tools, $model, $maxTokens, $emit
        );
      }
      catch (\Throwable $e) {
        $this->loggerFactory->get('dkan_nl_query')->warning('LLM API error: @message', ['@message' => $e->getMessage()]);
        $emit('error', ['message' => 'API error: ' . $e->getMessage()]);
        return;
      }

      // If no tool use, we're done.
      if ($response['stop_reason'] !== 'tool_use') {
        break;
      }

      // Execute tool calls and continue.
      $messages[] = ['role' => 'assistant', 'content' => $response['content']];

      $toolResults = [];
      foreach ($response['tool_uses'] as $toolUse) {
        $emit('status', ['message' => "Querying data: {$toolUse['name']}..."]);

        $result = $this->toolExecutor->execute($toolUse['name'], $toolUse['input']);

        $queryTools = ['query_datastore', 'query_datastore_join'];
        if (in_array($toolUse['name'], $queryTools, TRUE) && !isset($result['error'])) {
          $emit('data', $result);
        }

        $toolResults[] = [
          'type' => 'tool_result',
          'tool_use_id' => $toolUse['id'],
          'content' => json_encode($result),
          'is_error' => isset($result['error']),
        ];
      }

      $messages[] = ['role' => 'user', 'content' => $toolResults];
    }
  }

}
