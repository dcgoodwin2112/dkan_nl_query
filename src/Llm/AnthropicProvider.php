<?php

namespace Drupal\dkan_nl_query\Llm;

use Anthropic\Client;
use Anthropic\Messages\RawContentBlockDeltaEvent;
use Anthropic\Messages\RawContentBlockStartEvent;
use Anthropic\Messages\RawContentBlockStopEvent;
use Anthropic\Messages\RawMessageDeltaEvent;

/**
 * Anthropic Claude LLM provider.
 */
class AnthropicProvider implements LlmProviderInterface {

  /**
   * The Anthropic client.
   */
  protected Client $client;

  /**
   * Create an Anthropic provider.
   */
  public function __construct(string $apiKey) {
    $this->client = new Client(apiKey: $apiKey);
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
    $stream = $this->client->messages->createStream(
      maxTokens: $maxTokens,
      messages: $messages,
      model: $model,
      system: $systemPrompt,
      tools: $tools,
    );

    $contentBlocks = [];
    $toolUses = [];
    $currentBlock = NULL;
    $currentToolInput = '';
    $currentText = '';
    $stopReason = 'end_turn';

    foreach ($stream as $event) {
      if ($event instanceof RawContentBlockStartEvent) {
        $currentBlock = $event->contentBlock;
        if ($currentBlock->type === 'tool_use') {
          $currentToolInput = '';
        }
        elseif ($currentBlock->type === 'text') {
          $currentText = '';
        }
      }
      elseif ($event instanceof RawContentBlockDeltaEvent) {
        $delta = $event->delta;
        if ($delta->type === 'text_delta') {
          $currentText .= $delta->text;
          $emit('token', ['text' => $delta->text]);
        }
        elseif ($delta->type === 'input_json_delta') {
          $currentToolInput .= $delta->partialJSON;
        }
      }
      elseif ($event instanceof RawContentBlockStopEvent) {
        if ($currentBlock !== NULL) {
          if ($currentBlock->type === 'tool_use') {
            $input = json_decode($currentToolInput, TRUE) ?? [];
            $toolUses[] = [
              'id' => $currentBlock->id,
              'name' => $currentBlock->name,
              'input' => $input,
            ];
            $contentBlocks[] = [
              'type' => 'tool_use',
              'id' => $currentBlock->id,
              'name' => $currentBlock->name,
              'input' => $input,
            ];
          }
          elseif ($currentBlock->type === 'text' && $currentText !== '') {
            $contentBlocks[] = [
              'type' => 'text',
              'text' => $currentText,
            ];
          }
        }
        $currentBlock = NULL;
      }
      elseif ($event instanceof RawMessageDeltaEvent) {
        if ($event->delta->stopReason !== NULL) {
          $stopReason = $event->delta->stopReason;
        }
      }
    }

    return [
      'stop_reason' => $stopReason,
      'content' => $contentBlocks,
      'tool_uses' => $toolUses,
    ];
  }

}
