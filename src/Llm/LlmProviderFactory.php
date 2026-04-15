<?php

namespace Drupal\dkan_nl_query\Llm;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Creates LLM provider instances based on configuration.
 */
class LlmProviderFactory {

  /**
   * Model ID prefixes to provider mapping.
   */
  private const MODEL_PROVIDERS = [
    'claude-' => 'anthropic',
    'gpt-' => 'openai',
    'o1-' => 'openai',
    'o3-' => 'openai',
    'o4-' => 'openai',
  ];

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Create a provider for the given model.
   *
   * Infers the provider from the model ID prefix. Falls back to the
   * configured default provider.
   */
  public function createForModel(?string $model = NULL): LlmProviderInterface {
    $config = $this->configFactory->get('dkan_nl_query.settings');

    // Infer provider from model name.
    $provider = NULL;
    if ($model) {
      foreach (self::MODEL_PROVIDERS as $prefix => $providerName) {
        if (str_starts_with($model, $prefix)) {
          $provider = $providerName;
          break;
        }
      }
    }
    $provider = $provider ?: 'anthropic';

    return match ($provider) {
      'openai' => $this->createOpenAi($config->get('openai_api_key') ?: ''),
      default => $this->createAnthropic($config->get('anthropic_api_key') ?: $config->get('api_key') ?: ''),
    };
  }

  /**
   * Create an Anthropic provider.
   */
  protected function createAnthropic(string $apiKey): AnthropicProvider {
    return new AnthropicProvider($apiKey);
  }

  /**
   * Create an OpenAI provider.
   */
  protected function createOpenAi(string $apiKey): OpenAiProvider {
    return new OpenAiProvider($apiKey);
  }

}
