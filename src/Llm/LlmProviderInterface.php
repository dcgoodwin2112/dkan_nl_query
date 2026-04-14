<?php

namespace Drupal\dkan_nl_query\Llm;

/**
 * Interface for LLM provider implementations.
 */
interface LlmProviderInterface {

  /**
   * Stream a message with tool support.
   *
   * @param string $systemPrompt
   *   System prompt for the model.
   * @param array $messages
   *   Conversation messages in provider-neutral format.
   * @param array $tools
   *   Tool definitions in Anthropic format (canonical).
   * @param string $model
   *   Model identifier.
   * @param int $maxTokens
   *   Maximum tokens for the response.
   * @param callable $emit
   *   SSE emit callback: $emit(string $type, mixed $data).
   *
   * @return array
   *   Array with 'stop_reason' ('tool_use' or 'end_turn'), 'content' (blocks),
   *   and 'tool_uses' (tool call details).
   */
  public function stream(
    string $systemPrompt,
    array $messages,
    array $tools,
    string $model,
    int $maxTokens,
    callable $emit,
  ): array;

}
