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
   *
   * @return array
   *   Collected response data: {answer, chart_spec, table_data, tool_calls}.
   */
  public function query(?string $datasetId, string $question, callable $emit, array $history = [], ?string $model = NULL): array {
    $config = $this->configFactory->get('dkan_nl_query.settings');

    // Build context and tools based on mode.
    $emptyResult = ['answer' => '', 'chart_spec' => NULL, 'table_data' => NULL, 'tool_calls' => []];

    if ($datasetId) {
      $context = $this->schemaContextBuilder->buildContext($datasetId);
      if (isset($context['error'])) {
        $emit('error', ['message' => $context['error']]);
        return $emptyResult;
      }
      if (empty($context['resources'])) {
        $emit('error', ['message' => 'No queryable resources found for this dataset.']);
        return $emptyResult;
      }
      $systemPrompt = $this->schemaContextBuilder->buildSystemPrompt($context);
      $tools = $this->toolExecutor->getQueryToolDefinitions();
    }
    else {
      $catalog = $this->schemaContextBuilder->buildCatalogContext();
      if (empty($catalog['datasets'])) {
        $emit('error', ['message' => 'No datasets available on this site.']);
        return $emptyResult;
      }
      $systemPrompt = $this->schemaContextBuilder->buildCatalogSystemPrompt($catalog);
      $tools = $this->toolExecutor->getDiscoveryToolDefinitions();
    }

    $model = $model ?: $config->get('model') ?: 'claude-haiku-4-5';
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

    // Accumulators for persistence.
    $collectedAnswer = '';
    $collectedChartSpec = NULL;
    $collectedTableData = NULL;
    $collectedToolCalls = [];

    // Wrap emit to capture streamed answer text.
    $originalEmit = $emit;
    $emit = function (string $type, mixed $data) use ($originalEmit, &$collectedAnswer) {
      if ($type === 'token' && isset($data['text'])) {
        $collectedAnswer .= $data['text'];
      }
      $originalEmit($type, $data);
    };

    // Agentic loop: keep calling the LLM until it stops using tools.
    // Cross-dataset + chart can need 7+ rounds: search → distributions →
    // schema → stats → query → create_chart → summary.
    $maxIterations = $config->get('max_iterations') ?: 10;
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
        return $emptyResult;
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

        // Chart tool: emit spec to frontend, return success to LLM.
        if ($toolUse['name'] === 'create_chart') {
          // Normalize chart spec: fix container sizing and ensure pixel dimensions.
          $spec = $toolUse['input']['spec'] ?? [];
          if (empty($spec['width']) || $spec['width'] === 'container') {
            $spec['width'] = 600;
          }
          if (empty($spec['height']) || $spec['height'] === 'container') {
            $spec['height'] = 400;
          }
          $this->loggerFactory->get('dkan_nl_query')->notice('Chart spec: @spec', [
            '@spec' => json_encode($spec),
          ]);
          $emit('chart', ['spec' => $spec]);
          $collectedChartSpec = $spec;
          $collectedToolCalls[] = [
            'name' => 'create_chart',
            'input' => ['spec' => '(Vega-Lite spec)'],
            'duration_ms' => 0,
            'iteration' => $i + 1,
          ];
          $emit('tool_call', end($collectedToolCalls));
          $toolResults[] = [
            'type' => 'tool_result',
            'tool_use_id' => $toolUse['id'],
            'content' => '{"status":"chart_rendered"}',
            'is_error' => FALSE,
          ];
          continue;
        }

        $this->loggerFactory->get('dkan_nl_query')->notice('Tool call: @name with @args', [
          '@name' => $toolUse['name'],
          '@args' => json_encode($toolUse['input']),
        ]);

        $startTime = hrtime(TRUE);
        $result = $this->toolExecutor->execute($toolUse['name'], $toolUse['input']);
        $durationMs = (int) ((hrtime(TRUE) - $startTime) / 1e6);

        $collectedToolCalls[] = [
          'name' => $toolUse['name'],
          'input' => $toolUse['input'],
          'duration_ms' => $durationMs,
          'iteration' => $i + 1,
          'is_error' => isset($result['error']),
        ];
        $emit('tool_call', end($collectedToolCalls));

        $queryTools = ['query_datastore', 'query_datastore_join'];
        if (in_array($toolUse['name'], $queryTools, TRUE) && !isset($result['error'])) {
          $collectedTableData = $result;
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

    return [
      'answer' => $collectedAnswer,
      'chart_spec' => $collectedChartSpec,
      'table_data' => $collectedTableData,
      'tool_calls' => $collectedToolCalls,
    ];
  }

}
