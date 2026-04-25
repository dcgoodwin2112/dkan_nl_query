<?php

namespace Drupal\Tests\dkan_nl_query\Unit\Llm;

use Drupal\dkan_nl_query\Llm\OpenAiProvider;
use PHPUnit\Framework\TestCase;

/**
 * Testable subclass that skips SDK client initialization.
 */
class TestableOpenAiProvider extends OpenAiProvider {

  public function __construct() {
    // Skip parent constructor to avoid OpenAI SDK initialization.
  }

  public function testConvertMessage(array $msg): array {
    return $this->convertMessage($msg);
  }

  public function testConvertToolDefinition(array $tool): array {
    return $this->convertToolDefinition($tool);
  }

}

/**
 * @coversDefaultClass \Drupal\dkan_nl_query\Llm\OpenAiProvider
 */
class OpenAiProviderTest extends TestCase {

  protected TestableOpenAiProvider $provider;

  protected function setUp(): void {
    $this->provider = new TestableOpenAiProvider();
  }

  /**
   * @covers ::convertMessage
   */
  public function testConvertMessageStringContent(): void {
    $result = $this->provider->testConvertMessage([
      'role' => 'user',
      'content' => 'Hello world',
    ]);

    $this->assertEquals('user', $result['role']);
    $this->assertEquals('Hello world', $result['content']);
  }

  /**
   * @covers ::convertMessage
   */
  public function testConvertMessageToolResultArray(): void {
    $result = $this->provider->testConvertMessage([
      'role' => 'user',
      'content' => [
        [
          'type' => 'tool_result',
          'tool_use_id' => 'tool_1',
          'content' => '{"results":[]}',
        ],
        [
          'type' => 'tool_result',
          'tool_use_id' => 'tool_2',
          'content' => '{"count":5}',
        ],
      ],
    ]);

    // Tool results expand into separate messages.
    $this->assertCount(2, $result);
    $this->assertEquals('tool', $result[0]['role']);
    $this->assertEquals('tool_1', $result[0]['tool_call_id']);
    $this->assertEquals('{"results":[]}', $result[0]['content']);
    $this->assertEquals('tool', $result[1]['role']);
    $this->assertEquals('tool_2', $result[1]['tool_call_id']);
  }

  /**
   * @covers ::convertMessage
   */
  public function testConvertMessageAssistantWithToolUse(): void {
    $result = $this->provider->testConvertMessage([
      'role' => 'assistant',
      'content' => [
        ['type' => 'text', 'text' => 'Let me query that.'],
        [
          'type' => 'tool_use',
          'id' => 'call_1',
          'name' => 'query_datastore',
          'input' => ['resource_id' => 'abc__123'],
        ],
      ],
    ]);

    $this->assertEquals('assistant', $result['role']);
    $this->assertEquals('Let me query that.', $result['content']);
    $this->assertCount(1, $result['tool_calls']);
    $this->assertEquals('call_1', $result['tool_calls'][0]['id']);
    $this->assertEquals('function', $result['tool_calls'][0]['type']);
    $this->assertEquals('query_datastore', $result['tool_calls'][0]['function']['name']);
    $this->assertEquals(
      '{"resource_id":"abc__123"}',
      $result['tool_calls'][0]['function']['arguments']
    );
  }

  /**
   * @covers ::convertMessage
   */
  public function testConvertMessageAssistantTextOnly(): void {
    $result = $this->provider->testConvertMessage([
      'role' => 'assistant',
      'content' => [
        ['type' => 'text', 'text' => 'Here are results.'],
      ],
    ]);

    $this->assertEquals('assistant', $result['role']);
    $this->assertEquals('Here are results.', $result['content']);
    $this->assertArrayNotHasKey('tool_calls', $result);
  }

  /**
   * @covers ::convertMessage
   */
  public function testConvertMessageNonStringNonArrayContent(): void {
    $result = $this->provider->testConvertMessage([
      'role' => 'user',
      'content' => 42,
    ]);

    $this->assertEquals('user', $result['role']);
    $this->assertEquals('42', $result['content']);
  }

  /**
   * @covers ::convertToolDefinition
   */
  public function testConvertToolDefinition(): void {
    $result = $this->provider->testConvertToolDefinition([
      'name' => 'query_datastore',
      'description' => 'Query a datastore resource.',
      'input_schema' => [
        'type' => 'object',
        'properties' => [
          'resource_id' => ['type' => 'string'],
          'limit' => ['type' => 'integer'],
        ],
        'required' => ['resource_id'],
      ],
    ]);

    $this->assertEquals('function', $result['type']);
    $this->assertEquals('query_datastore', $result['function']['name']);
    $this->assertEquals('Query a datastore resource.', $result['function']['description']);
    $this->assertEquals('object', $result['function']['parameters']['type']);
    $this->assertArrayHasKey('resource_id', $result['function']['parameters']['properties']);
  }

  /**
   * @covers ::convertToolDefinition
   */
  public function testConvertToolDefinitionMissingSchema(): void {
    $result = $this->provider->testConvertToolDefinition([
      'name' => 'create_chart',
      'description' => 'Render a chart.',
    ]);

    $this->assertEquals('function', $result['type']);
    $this->assertEquals('create_chart', $result['function']['name']);
    $this->assertEquals('object', $result['function']['parameters']['type']);
  }

  /**
   * @covers ::convertMessage
   */
  public function testConvertMessageMultipleToolUseBlocks(): void {
    $result = $this->provider->testConvertMessage([
      'role' => 'assistant',
      'content' => [
        [
          'type' => 'tool_use',
          'id' => 'call_1',
          'name' => 'get_datastore_schema',
          'input' => ['resource_id' => 'abc__123'],
        ],
        [
          'type' => 'tool_use',
          'id' => 'call_2',
          'name' => 'get_datastore_stats',
          'input' => ['resource_id' => 'abc__123'],
        ],
      ],
    ]);

    $this->assertCount(2, $result['tool_calls']);
    $this->assertEquals('call_1', $result['tool_calls'][0]['id']);
    $this->assertEquals('call_2', $result['tool_calls'][1]['id']);
    // Empty content when no text blocks.
    $this->assertEquals('', $result['content']);
  }

}
