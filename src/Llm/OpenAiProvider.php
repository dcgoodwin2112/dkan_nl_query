<?php

namespace Drupal\dkan_nl_query\Llm;

use OpenAI\Contracts\ClientContract;

/**
 * OpenAI LLM provider.
 */
class OpenAiProvider implements LlmProviderInterface {

  /**
   * The OpenAI client.
   */
  protected ClientContract $client;

  /**
   * Create an OpenAI provider.
   */
  public function __construct(string $apiKey) {
    $this->client = \OpenAI::client($apiKey);
  }

  /**
   * {@inheritdoc}
   */
  public function stream(
    string $systemPrompt,
    array $messages,
    array $tools,
    string $model,
    int $maxTokens,
    callable $emit,
  ): array {
    // Convert messages: prepend system message.
    $openAiMessages = [
      ['role' => 'system', 'content' => $systemPrompt],
    ];
    foreach ($messages as $msg) {
      $converted = $this->convertMessage($msg);
      // Tool results expand into multiple messages in OpenAI format.
      if (is_array($converted) && isset($converted[0])) {
        foreach ($converted as $m) {
          $openAiMessages[] = $m;
        }
      }
      else {
        $openAiMessages[] = $converted;
      }
    }

    // Convert tool definitions from Anthropic format to OpenAI format.
    $openAiTools = array_map([$this, 'convertToolDefinition'], $tools);

    $params = [
      'model' => $model,
      'messages' => $openAiMessages,
      'max_tokens' => $maxTokens,
      'stream' => TRUE,
    ];
    if ($openAiTools) {
      $params['tools'] = $openAiTools;
    }

    try {
      $stream = $this->client->chat()->createStreamed($params);
    }
    catch (\Throwable $e) {
      $emit('error', ['message' => 'OpenAI API error: ' . $e->getMessage()]);
      return ['stop_reason' => 'end_turn', 'content' => [], 'tool_uses' => []];
    }

    $contentBlocks = [];
    $toolUses = [];
    $currentText = '';
    // Tool call state: OpenAI streams tool calls incrementally.
    $pendingToolCalls = [];
    $stopReason = 'end_turn';

    foreach ($stream as $response) {
      if (empty($response->choices)) {
        continue;
      }

      $choice = $response->choices[0];
      $delta = $choice->delta;

      // Text content.
      if ($delta->content !== NULL) {
        $currentText .= $delta->content;
        $emit('token', ['text' => $delta->content]);
      }

      // Tool calls arrive incrementally.
      foreach ($delta->toolCalls as $toolCall) {
        $index = $toolCall->index ?? 0;
        if ($toolCall->id !== NULL) {
          // New tool call starting.
          $pendingToolCalls[$index] = [
            'id' => $toolCall->id,
            'name' => $toolCall->function->name ?? '',
            'arguments' => '',
          ];
        }
        if (isset($pendingToolCalls[$index]) && $toolCall->function->arguments) {
          $pendingToolCalls[$index]['arguments'] .= $toolCall->function->arguments;
        }
      }

      // Finish reason.
      if ($choice->finishReason !== NULL) {
        $stopReason = match ($choice->finishReason) {
          'tool_calls' => 'tool_use',
          'stop' => 'end_turn',
          default => $choice->finishReason,
        };
      }
    }

    // Finalize text block.
    if ($currentText !== '') {
      $contentBlocks[] = [
        'type' => 'text',
        'text' => $currentText,
      ];
    }

    // Finalize tool calls.
    foreach ($pendingToolCalls as $tc) {
      $input = json_decode($tc['arguments'], TRUE) ?? [];
      $toolUses[] = [
        'id' => $tc['id'],
        'name' => $tc['name'],
        'input' => $input,
      ];
      // Content blocks in Anthropic format for the agentic loop.
      $contentBlocks[] = [
        'type' => 'tool_use',
        'id' => $tc['id'],
        'name' => $tc['name'],
        'input' => $input,
      ];
    }

    return [
      'stop_reason' => $stopReason,
      'content' => $contentBlocks,
      'tool_uses' => $toolUses,
    ];
  }

  /**
   * Convert a message from Anthropic format to OpenAI format.
   */
  protected function convertMessage(array $msg): array {
    $role = $msg['role'];
    $content = $msg['content'];

    // String content passes through.
    if (is_string($content)) {
      return ['role' => $role, 'content' => $content];
    }

    // Array content: handle tool_use blocks (assistant) and tool_result (user).
    if (is_array($content)) {
      // Check if this is a tool_result array.
      $firstBlock = $content[0] ?? [];
      if (($firstBlock['type'] ?? '') === 'tool_result') {
        // OpenAI sends each tool result as a separate message with role: tool.
        $results = [];
        foreach ($content as $block) {
          $results[] = [
            'role' => 'tool',
            'tool_call_id' => $block['tool_use_id'],
            'content' => $block['content'] ?? '',
          ];
        }
        return $results;
      }

      // Assistant message with mixed text + tool_use blocks.
      $textParts = [];
      $toolCalls = [];
      foreach ($content as $block) {
        if (($block['type'] ?? '') === 'text') {
          $textParts[] = $block['text'] ?? '';
        }
        elseif (($block['type'] ?? '') === 'tool_use') {
          $toolCalls[] = [
            'id' => $block['id'],
            'type' => 'function',
            'function' => [
              'name' => $block['name'],
              'arguments' => json_encode($block['input']),
            ],
          ];
        }
      }

      $msg = [
        'role' => 'assistant',
        'content' => implode("\n", $textParts) ?: '',
      ];
      if ($toolCalls) {
        $msg['tool_calls'] = $toolCalls;
      }
      return $msg;
    }

    return ['role' => $role, 'content' => (string) $content];
  }

  /**
   * Convert a tool definition from Anthropic format to OpenAI format.
   */
  protected function convertToolDefinition(array $tool): array {
    return [
      'type' => 'function',
      'function' => [
        'name' => $tool['name'],
        'description' => $tool['description'] ?? '',
        'parameters' => $tool['input_schema'] ?? ['type' => 'object', 'properties' => new \stdClass()],
      ],
    ];
  }

}
