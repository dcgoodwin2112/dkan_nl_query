<?php

namespace Drupal\Tests\dkan_nl_query\Unit\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\dkan_nl_query\Llm\LlmProviderFactory;
use Drupal\dkan_nl_query\Llm\LlmProviderInterface;
use Drupal\dkan_nl_query\Service\NlQueryService;
use Drupal\dkan_nl_query\Service\SchemaContextBuilder;
use Drupal\dkan_nl_query\Service\ToolExecutor;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \Drupal\dkan_nl_query\Service\NlQueryService
 */
class NlQueryServiceTest extends TestCase {

  protected function createService(
    ?SchemaContextBuilder $contextBuilder = NULL,
    ?ToolExecutor $toolExecutor = NULL,
    ?ConfigFactoryInterface $configFactory = NULL,
    ?LlmProviderFactory $providerFactory = NULL,
    ?LoggerChannelFactoryInterface $loggerFactory = NULL,
  ): NlQueryService {
    if (!$configFactory) {
      $config = $this->createMock(ImmutableConfig::class);
      $config->method('get')->willReturnCallback(fn($k) => match ($k) {
        'model' => 'claude-haiku-4-5',
        'max_tokens' => 4096,
        'max_iterations' => 10,
        default => NULL,
      });
      $configFactory = $this->createMock(ConfigFactoryInterface::class);
      $configFactory->method('get')->willReturn($config);
    }

    if (!$loggerFactory) {
      $logger = $this->createMock(LoggerChannelInterface::class);
      $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
      $loggerFactory->method('get')->willReturn($logger);
    }

    return new NlQueryService(
      $contextBuilder ?? $this->createMock(SchemaContextBuilder::class),
      $toolExecutor ?? $this->createMock(ToolExecutor::class),
      $configFactory,
      $providerFactory ?? $this->createMock(LlmProviderFactory::class),
      $loggerFactory,
    );
  }

  protected function collectEmits(): array {
    // Use an object so the reference survives array destructuring.
    $collector = new \stdClass();
    $collector->events = [];
    $emit = function (string $type, mixed $data) use ($collector) {
      $collector->events[] = ['type' => $type, 'data' => $data];
    };
    return [$emit, $collector];
  }

  /**
   * @covers ::query
   */
  public function testContextErrorEmitsError(): void {
    $context = $this->createMock(SchemaContextBuilder::class);
    $context->method('buildContext')
      ->willReturn(['error' => 'Dataset not found']);

    $service = $this->createService(contextBuilder: $context);
    [$emit, $collector] = $this->collectEmits();

    $result = $service->query('bad-uuid', 'What is this?', $emit);

    $this->assertEquals('', $result['answer']);
    $this->assertNotEmpty($collector->events);
    $errorEvents = array_filter($collector->events, fn($e) => $e['type'] === 'error');
    $this->assertNotEmpty($errorEvents);
  }

  /**
   * @covers ::query
   */
  public function testNoResourcesEmitsError(): void {
    $context = $this->createMock(SchemaContextBuilder::class);
    $context->method('buildContext')
      ->willReturn(['title' => 'Test', 'resources' => []]);

    $service = $this->createService(contextBuilder: $context);
    [$emit, $collector] = $this->collectEmits();

    $result = $service->query('ds-uuid', 'What?', $emit);

    $errorEvents = array_filter($collector->events, fn($e) => $e['type'] === 'error');
    $this->assertNotEmpty($errorEvents);
    $this->assertEquals('', $result['answer']);
  }

  /**
   * @covers ::query
   */
  public function testCatalogModeNoDatasets(): void {
    $context = $this->createMock(SchemaContextBuilder::class);
    $context->method('buildCatalogContext')
      ->willReturn(['datasets' => []]);

    $service = $this->createService(contextBuilder: $context);
    [$emit, $collector] = $this->collectEmits();

    $result = $service->query(NULL, 'List datasets', $emit);

    $errorEvents = array_filter($collector->events, fn($e) => $e['type'] === 'error');
    $this->assertNotEmpty($errorEvents);
  }

  /**
   * @covers ::query
   */
  public function testSingleTurnNoToolUse(): void {
    $context = $this->createMock(SchemaContextBuilder::class);
    $context->method('buildContext')
      ->willReturn([
        'title' => 'Test',
        'resources' => [['resource_id' => 'abc__123', 'columns' => []]],
      ]);
    $context->method('buildSystemPrompt')->willReturn('System prompt');

    $toolExecutor = $this->createMock(ToolExecutor::class);
    $toolExecutor->method('getQueryToolDefinitions')->willReturn([]);

    $provider = $this->createMock(LlmProviderInterface::class);
    $provider->expects($this->once())
      ->method('stream')
      ->willReturnCallback(function ($sys, $msgs, $tools, $model, $max, $emit) {
        $emit('token', ['text' => 'The answer']);
        return ['stop_reason' => 'end_turn', 'content' => [], 'tool_uses' => []];
      });

    $providerFactory = $this->createMock(LlmProviderFactory::class);
    $providerFactory->method('createForModel')->willReturn($provider);

    $service = $this->createService(
      contextBuilder: $context,
      toolExecutor: $toolExecutor,
      providerFactory: $providerFactory,
    );
    [$emit, $collector] = $this->collectEmits();

    $result = $service->query('ds-uuid', 'What is this?', $emit);

    $this->assertEquals('The answer', $result['answer']);
    $this->assertNull($result['chart_spec']);
    $this->assertNull($result['table_data']);
  }

  /**
   * @covers ::query
   */
  public function testToolUseLoopExecutesTool(): void {
    $context = $this->createMock(SchemaContextBuilder::class);
    $context->method('buildContext')
      ->willReturn([
        'title' => 'Test',
        'resources' => [['resource_id' => 'abc__123', 'columns' => []]],
      ]);
    $context->method('buildSystemPrompt')->willReturn('System');

    $toolExecutor = $this->createMock(ToolExecutor::class);
    $toolExecutor->method('getQueryToolDefinitions')->willReturn([]);
    $toolExecutor->expects($this->once())
      ->method('execute')
      ->with('query_datastore', ['resource_id' => 'abc__123'])
      ->willReturn([
        'results' => [['val' => 1]],
        'result_count' => 1,
        'total_rows' => 500,
        'limit' => 100,
        'offset' => 0,
      ]);

    $callCount = 0;
    $provider = $this->createMock(LlmProviderInterface::class);
    $provider->method('stream')
      ->willReturnCallback(function ($sys, $msgs, $tools, $model, $max, $emit) use (&$callCount) {
        $callCount++;
        if ($callCount === 1) {
          return [
            'stop_reason' => 'tool_use',
            'content' => [
              ['type' => 'tool_use', 'id' => 'tool1', 'name' => 'query_datastore', 'input' => ['resource_id' => 'abc__123']],
            ],
            'tool_uses' => [
              ['id' => 'tool1', 'name' => 'query_datastore', 'input' => ['resource_id' => 'abc__123']],
            ],
          ];
        }
        $emit('token', ['text' => 'Here are results']);
        return ['stop_reason' => 'end_turn', 'content' => [], 'tool_uses' => []];
      });

    $providerFactory = $this->createMock(LlmProviderFactory::class);
    $providerFactory->method('createForModel')->willReturn($provider);

    $service = $this->createService(
      contextBuilder: $context,
      toolExecutor: $toolExecutor,
      providerFactory: $providerFactory,
    );
    [$emit, $collector] = $this->collectEmits();

    $result = $service->query('ds-uuid', 'Show data', $emit);

    $this->assertEquals('Here are results', $result['answer']);
    $this->assertNotNull($result['table_data']);
    $this->assertNotEmpty($result['tool_calls']);
    $this->assertEquals('query_datastore', $result['tool_calls'][0]['name']);
    // Result summary should contain query metadata.
    $summary = $result['tool_calls'][0]['result_summary'];
    $this->assertEquals(1, $summary['result_count']);
    $this->assertEquals(500, $summary['total_rows']);
  }

  /**
   * @covers ::query
   */
  public function testChartToolEmitsChartEvent(): void {
    $context = $this->createMock(SchemaContextBuilder::class);
    $context->method('buildContext')
      ->willReturn([
        'title' => 'Test',
        'resources' => [['resource_id' => 'abc__123', 'columns' => []]],
      ]);
    $context->method('buildSystemPrompt')->willReturn('System');

    $toolExecutor = $this->createMock(ToolExecutor::class);
    $toolExecutor->method('getQueryToolDefinitions')->willReturn([]);

    $callCount = 0;
    $provider = $this->createMock(LlmProviderInterface::class);
    $provider->method('stream')
      ->willReturnCallback(function ($sys, $msgs, $tools, $model, $max, $emit) use (&$callCount) {
        $callCount++;
        if ($callCount === 1) {
          return [
            'stop_reason' => 'tool_use',
            'content' => [],
            'tool_uses' => [
              [
                'id' => 'chart1',
                'name' => 'create_chart',
                'input' => [
                  'spec' => [
                    'width' => 'container',
                    'mark' => 'bar',
                    'data' => ['values' => [['x' => 1]]],
                  ],
                ],
              ],
            ],
          ];
        }
        $emit('token', ['text' => 'Chart done']);
        return ['stop_reason' => 'end_turn', 'content' => [], 'tool_uses' => []];
      });

    $providerFactory = $this->createMock(LlmProviderFactory::class);
    $providerFactory->method('createForModel')->willReturn($provider);

    $service = $this->createService(
      contextBuilder: $context,
      toolExecutor: $toolExecutor,
      providerFactory: $providerFactory,
    );
    [$emit, $collector] = $this->collectEmits();

    $result = $service->query('ds-uuid', 'Make a chart', $emit);

    $this->assertNotNull($result['chart_spec']);
    // Container width should be normalized to 600.
    $this->assertEquals(600, $result['chart_spec']['width']);
    $this->assertEquals(400, $result['chart_spec']['height']);

    $chartEvents = array_filter($collector->events, fn($e) => $e['type'] === 'chart');
    $this->assertNotEmpty($chartEvents);
  }

  /**
   * @covers ::query
   */
  public function testLlmExceptionEmitsError(): void {
    $context = $this->createMock(SchemaContextBuilder::class);
    $context->method('buildContext')
      ->willReturn([
        'title' => 'Test',
        'resources' => [['resource_id' => 'abc__123', 'columns' => []]],
      ]);
    $context->method('buildSystemPrompt')->willReturn('System');

    $toolExecutor = $this->createMock(ToolExecutor::class);
    $toolExecutor->method('getQueryToolDefinitions')->willReturn([]);

    $provider = $this->createMock(LlmProviderInterface::class);
    $provider->method('stream')
      ->willThrowException(new \RuntimeException('API down'));

    $providerFactory = $this->createMock(LlmProviderFactory::class);
    $providerFactory->method('createForModel')->willReturn($provider);

    $service = $this->createService(
      contextBuilder: $context,
      toolExecutor: $toolExecutor,
      providerFactory: $providerFactory,
    );
    [$emit, $collector] = $this->collectEmits();

    $result = $service->query('ds-uuid', 'Query', $emit);

    $this->assertEquals('', $result['answer']);
    $errorEvents = array_filter($collector->events, fn($e) => $e['type'] === 'error');
    $this->assertNotEmpty($errorEvents);
  }

  /**
   * @covers ::query
   */
  public function testMaxIterationsSafety(): void {
    // Configure max_iterations = 2.
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(fn($k) => match ($k) {
      'model' => 'claude-haiku-4-5',
      'max_tokens' => 4096,
      'max_iterations' => 2,
      default => NULL,
    });
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($config);

    $context = $this->createMock(SchemaContextBuilder::class);
    $context->method('buildContext')
      ->willReturn([
        'title' => 'Test',
        'resources' => [['resource_id' => 'abc__123', 'columns' => []]],
      ]);
    $context->method('buildSystemPrompt')->willReturn('System');

    $toolExecutor = $this->createMock(ToolExecutor::class);
    $toolExecutor->method('getQueryToolDefinitions')->willReturn([]);
    $toolExecutor->method('execute')->willReturn(['results' => []]);

    // Provider always returns tool_use.
    $provider = $this->createMock(LlmProviderInterface::class);
    $provider->expects($this->exactly(2))
      ->method('stream')
      ->willReturn([
        'stop_reason' => 'tool_use',
        'content' => [],
        'tool_uses' => [
          ['id' => 't1', 'name' => 'get_datastore_schema', 'input' => ['resource_id' => 'abc__123']],
        ],
      ]);

    $providerFactory = $this->createMock(LlmProviderFactory::class);
    $providerFactory->method('createForModel')->willReturn($provider);

    $service = $this->createService(
      contextBuilder: $context,
      toolExecutor: $toolExecutor,
      configFactory: $configFactory,
      providerFactory: $providerFactory,
    );
    [$emit, $collector] = $this->collectEmits();

    $result = $service->query('ds-uuid', 'Loop forever', $emit);

    // Should stop after 2 iterations despite tool_use.
    $this->assertNotNull($result);
  }

  /**
   * @covers ::query
   */
  public function testModelOverride(): void {
    $context = $this->createMock(SchemaContextBuilder::class);
    $context->method('buildContext')
      ->willReturn([
        'title' => 'Test',
        'resources' => [['resource_id' => 'abc__123', 'columns' => []]],
      ]);
    $context->method('buildSystemPrompt')->willReturn('System');

    $toolExecutor = $this->createMock(ToolExecutor::class);
    $toolExecutor->method('getQueryToolDefinitions')->willReturn([]);

    $provider = $this->createMock(LlmProviderInterface::class);
    $provider->method('stream')
      ->willReturn(['stop_reason' => 'end_turn', 'content' => [], 'tool_uses' => []]);

    $providerFactory = $this->createMock(LlmProviderFactory::class);
    $providerFactory->expects($this->once())
      ->method('createForModel')
      ->with('gpt-4o')
      ->willReturn($provider);

    $service = $this->createService(
      contextBuilder: $context,
      toolExecutor: $toolExecutor,
      providerFactory: $providerFactory,
    );
    [$emit, $collector] = $this->collectEmits();

    $service->query('ds-uuid', 'Hi', $emit, [], 'gpt-4o');
  }

  /**
   * @covers ::query
   */
  public function testCatalogModeUsesDiscoveryTools(): void {
    $context = $this->createMock(SchemaContextBuilder::class);
    $context->method('buildCatalogContext')
      ->willReturn(['datasets' => [['identifier' => 'ds1', 'title' => 'Test']]]);
    $context->method('buildCatalogSystemPrompt')->willReturn('Catalog prompt');

    $toolExecutor = $this->createMock(ToolExecutor::class);
    $toolExecutor->expects($this->once())
      ->method('getDiscoveryToolDefinitions')
      ->willReturn([]);

    $provider = $this->createMock(LlmProviderInterface::class);
    $provider->method('stream')
      ->willReturn(['stop_reason' => 'end_turn', 'content' => [], 'tool_uses' => []]);

    $providerFactory = $this->createMock(LlmProviderFactory::class);
    $providerFactory->method('createForModel')->willReturn($provider);

    $service = $this->createService(
      contextBuilder: $context,
      toolExecutor: $toolExecutor,
      providerFactory: $providerFactory,
    );
    [$emit, $collector] = $this->collectEmits();

    $service->query(NULL, 'List datasets', $emit);
  }

  /**
   * @covers ::query
   */
  public function testHistoryIncludedInMessages(): void {
    $context = $this->createMock(SchemaContextBuilder::class);
    $context->method('buildContext')
      ->willReturn([
        'title' => 'Test',
        'resources' => [['resource_id' => 'abc__123', 'columns' => []]],
      ]);
    $context->method('buildSystemPrompt')->willReturn('System');

    $toolExecutor = $this->createMock(ToolExecutor::class);
    $toolExecutor->method('getQueryToolDefinitions')->willReturn([]);

    $provider = $this->createMock(LlmProviderInterface::class);
    $provider->expects($this->once())
      ->method('stream')
      ->with(
        $this->anything(),
        $this->callback(function ($messages) {
          // History messages + current question.
          return count($messages) === 3
            && $messages[0]['role'] === 'user'
            && $messages[0]['content'] === 'Previous question'
            && $messages[1]['role'] === 'assistant'
            && $messages[2]['content'] === 'Follow up';
        }),
        $this->anything(),
        $this->anything(),
        $this->anything(),
        $this->anything(),
      )
      ->willReturn(['stop_reason' => 'end_turn', 'content' => [], 'tool_uses' => []]);

    $providerFactory = $this->createMock(LlmProviderFactory::class);
    $providerFactory->method('createForModel')->willReturn($provider);

    $service = $this->createService(
      contextBuilder: $context,
      toolExecutor: $toolExecutor,
      providerFactory: $providerFactory,
    );
    [$emit, $collector] = $this->collectEmits();

    $history = [
      ['role' => 'user', 'content' => 'Previous question'],
      ['role' => 'assistant', 'content' => 'Previous answer'],
    ];
    $service->query('ds-uuid', 'Follow up', $emit, $history);
  }

  /**
   * @covers ::query
   */
  public function testDataEventEmittedForQueryDatastore(): void {
    $context = $this->createMock(SchemaContextBuilder::class);
    $context->method('buildContext')
      ->willReturn([
        'title' => 'Test',
        'resources' => [['resource_id' => 'abc__123', 'columns' => []]],
      ]);
    $context->method('buildSystemPrompt')->willReturn('System');

    $toolExecutor = $this->createMock(ToolExecutor::class);
    $toolExecutor->method('getQueryToolDefinitions')->willReturn([]);
    $toolExecutor->method('execute')
      ->willReturn(['results' => [['col' => 'val']], 'count' => 1]);

    $callCount = 0;
    $provider = $this->createMock(LlmProviderInterface::class);
    $provider->method('stream')
      ->willReturnCallback(function () use (&$callCount) {
        $callCount++;
        if ($callCount === 1) {
          return [
            'stop_reason' => 'tool_use',
            'content' => [],
            'tool_uses' => [
              ['id' => 't1', 'name' => 'query_datastore', 'input' => ['resource_id' => 'abc__123']],
            ],
          ];
        }
        return ['stop_reason' => 'end_turn', 'content' => [], 'tool_uses' => []];
      });

    $providerFactory = $this->createMock(LlmProviderFactory::class);
    $providerFactory->method('createForModel')->willReturn($provider);

    $service = $this->createService(
      contextBuilder: $context,
      toolExecutor: $toolExecutor,
      providerFactory: $providerFactory,
    );
    [$emit, $collector] = $this->collectEmits();

    $result = $service->query('ds-uuid', 'Query', $emit);

    $dataEvents = array_filter($collector->events, fn($e) => $e['type'] === 'data');
    $this->assertNotEmpty($dataEvents);
    $this->assertNotNull($result['table_data']);
  }

  /**
   * Helper: run a single tool call and return its result_summary.
   */
  protected function runToolAndGetSummary(string $toolName, array $toolInput, array $toolResult): array {
    $context = $this->createMock(SchemaContextBuilder::class);
    $context->method('buildContext')
      ->willReturn([
        'title' => 'Test',
        'resources' => [['resource_id' => 'abc__123', 'columns' => []]],
      ]);
    $context->method('buildSystemPrompt')->willReturn('System');

    $toolExecutor = $this->createMock(ToolExecutor::class);
    $toolExecutor->method('getQueryToolDefinitions')->willReturn([]);
    $toolExecutor->method('execute')->willReturn($toolResult);

    $callCount = 0;
    $provider = $this->createMock(LlmProviderInterface::class);
    $provider->method('stream')
      ->willReturnCallback(function () use (&$callCount, $toolName, $toolInput) {
        $callCount++;
        if ($callCount === 1) {
          return [
            'stop_reason' => 'tool_use',
            'content' => [],
            'tool_uses' => [
              ['id' => 't1', 'name' => $toolName, 'input' => $toolInput],
            ],
          ];
        }
        return ['stop_reason' => 'end_turn', 'content' => [], 'tool_uses' => []];
      });

    $providerFactory = $this->createMock(LlmProviderFactory::class);
    $providerFactory->method('createForModel')->willReturn($provider);

    $service = $this->createService(
      contextBuilder: $context,
      toolExecutor: $toolExecutor,
      providerFactory: $providerFactory,
    );
    [$emit, $collector] = $this->collectEmits();

    $result = $service->query('ds-uuid', 'Test', $emit);

    $this->assertNotEmpty($result['tool_calls']);
    return $result['tool_calls'][0]['result_summary'];
  }

  /**
   * @covers ::buildResultSummary
   */
  public function testResultSummaryForSchema(): void {
    $summary = $this->runToolAndGetSummary(
      'get_datastore_schema',
      ['resource_id' => 'abc__123'],
      [
        'resource_id' => 'abc__123',
        'columns' => [
          ['name' => 'state', 'type' => 'text'],
          ['name' => 'rate', 'type' => 'number'],
        ],
      ],
    );

    $this->assertEquals(2, $summary['column_count']);
    $this->assertEquals(['state', 'rate'], $summary['columns']);
  }

  /**
   * @covers ::buildResultSummary
   */
  public function testResultSummaryForStats(): void {
    $summary = $this->runToolAndGetSummary(
      'get_datastore_stats',
      ['resource_id' => 'abc__123'],
      [
        'resource_id' => 'abc__123',
        'total_rows' => 1500,
        'columns' => [
          ['name' => 'state', 'distinct_count' => 50],
          ['name' => 'rate', 'distinct_count' => 100],
        ],
      ],
    );

    $this->assertEquals(1500, $summary['total_rows']);
    $this->assertEquals(2, $summary['column_count']);
  }

  /**
   * @covers ::buildResultSummary
   */
  public function testResultSummaryForImportStatus(): void {
    $summary = $this->runToolAndGetSummary(
      'get_import_status',
      ['resource_id' => 'abc__123'],
      [
        'resource_id' => 'abc__123',
        'status' => 'done',
        'num_of_rows' => 1000,
        'num_of_columns' => 8,
      ],
    );

    $this->assertEquals('done', $summary['status']);
    $this->assertEquals(1000, $summary['num_of_rows']);
    $this->assertEquals(8, $summary['num_of_columns']);
  }

  /**
   * @covers ::buildResultSummary
   */
  public function testResultSummaryForError(): void {
    $summary = $this->runToolAndGetSummary(
      'get_datastore_schema',
      ['resource_id' => 'bad__id'],
      ['error' => 'Table not found'],
    );

    $this->assertArrayHasKey('error', $summary);
    $this->assertEquals('Table not found', $summary['error']);
  }

  /**
   * @covers ::buildResultSummary
   */
  public function testResultSummaryForSearchColumns(): void {
    $summary = $this->runToolAndGetSummary(
      'search_columns',
      ['search_term' => 'state'],
      [
        'matches' => [['column_name' => 'state']],
        'total_matches' => 3,
        'resources_searched' => 8,
      ],
    );

    $this->assertEquals(3, $summary['total_matches']);
    $this->assertEquals(8, $summary['resources_searched']);
  }

  /**
   * @covers ::buildResultSummary
   */
  public function testResultSummaryForListDistributions(): void {
    $summary = $this->runToolAndGetSummary(
      'list_distributions',
      ['dataset_id' => 'ds-uuid'],
      [
        'distributions' => [
          ['resource_id' => 'abc__1'],
          ['resource_id' => 'def__2'],
        ],
      ],
    );

    $this->assertEquals(2, $summary['count']);
  }

  /**
   * @covers ::buildResultSummary
   */
  public function testResultSummaryForFindDatasetResources(): void {
    $summary = $this->runToolAndGetSummary(
      'find_dataset_resources',
      ['title' => 'shark'],
      [
        'dataset_id' => 'ds-uuid',
        'title' => 'Shark Tagging',
        'distributions' => [['resource_id' => 'abc__1']],
      ],
    );

    $this->assertEquals('ds-uuid', $summary['dataset_id']);
    $this->assertEquals('Shark Tagging', $summary['title']);
    $this->assertEquals(1, $summary['distribution_count']);
  }

  /**
   * @covers ::query
   */
  public function testChartToolCallHasResultSummary(): void {
    $context = $this->createMock(SchemaContextBuilder::class);
    $context->method('buildContext')
      ->willReturn([
        'title' => 'Test',
        'resources' => [['resource_id' => 'abc__123', 'columns' => []]],
      ]);
    $context->method('buildSystemPrompt')->willReturn('System');

    $toolExecutor = $this->createMock(ToolExecutor::class);
    $toolExecutor->method('getQueryToolDefinitions')->willReturn([]);

    $callCount = 0;
    $provider = $this->createMock(LlmProviderInterface::class);
    $provider->method('stream')
      ->willReturnCallback(function () use (&$callCount) {
        $callCount++;
        if ($callCount === 1) {
          return [
            'stop_reason' => 'tool_use',
            'content' => [],
            'tool_uses' => [
              ['id' => 'c1', 'name' => 'create_chart', 'input' => ['spec' => ['mark' => 'bar']]],
            ],
          ];
        }
        return ['stop_reason' => 'end_turn', 'content' => [], 'tool_uses' => []];
      });

    $providerFactory = $this->createMock(LlmProviderFactory::class);
    $providerFactory->method('createForModel')->willReturn($provider);

    $service = $this->createService(
      contextBuilder: $context,
      toolExecutor: $toolExecutor,
      providerFactory: $providerFactory,
    );
    [$emit, $collector] = $this->collectEmits();

    $result = $service->query('ds-uuid', 'Chart', $emit);

    $this->assertNotEmpty($result['tool_calls']);
    $summary = $result['tool_calls'][0]['result_summary'];
    $this->assertEquals('chart_rendered', $summary['status']);
  }

}
