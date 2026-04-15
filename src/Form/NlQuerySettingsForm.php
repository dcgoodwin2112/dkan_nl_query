<?php

namespace Drupal\dkan_nl_query\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Settings form for DKAN Natural Language Query.
 */
class NlQuerySettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['dkan_nl_query.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'dkan_nl_query_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('dkan_nl_query.settings');

    // --- Provider & API keys ---
    $form['provider_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('API Keys'),
      '#open' => TRUE,
      '#tree' => FALSE,
    ];

    $form['provider_settings']['anthropic_api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Anthropic API Key'),
      '#maxlength' => 256,
      '#default_value' => $config->get('anthropic_api_key') ?: $config->get('api_key'),
      '#description' => $this->t('API key for Claude models. Get one at console.anthropic.com.'),
    ];

    $form['provider_settings']['openai_api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('OpenAI API Key'),
      '#maxlength' => 256,
      '#default_value' => $config->get('openai_api_key'),
      '#description' => $this->t('API key for GPT models. Get one at platform.openai.com.'),
    ];

    // --- Model & generation ---
    $form['model_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('Model & Generation'),
      '#open' => TRUE,
      '#tree' => FALSE,
    ];

    $form['model_settings']['model'] = [
      '#type' => 'select',
      '#title' => $this->t('Default Model'),
      '#options' => [
        $this->t('Anthropic')->__toString() => [
          'claude-opus-4-6' => $this->t('Claude Opus 4.6'),
          'claude-sonnet-4-6' => $this->t('Claude Sonnet 4.6'),
          'claude-haiku-4-5' => $this->t('Claude Haiku 4.5'),
        ],
        $this->t('OpenAI')->__toString() => [
          'gpt-5.4' => $this->t('GPT-5.4'),
          'gpt-5.4-mini-2026-03-17' => $this->t('GPT-5.4 Mini'),
          'gpt-5.4-nano-2026-03-17' => $this->t('GPT-5.4 Nano'),
        ],
      ],
      '#default_value' => $config->get('model') ?: 'claude-haiku-4-5',
      '#description' => $this->t('Default model for new queries.'),
    ];

    $form['model_settings']['max_tokens'] = [
      '#type' => 'number',
      '#title' => $this->t('Max Tokens'),
      '#default_value' => $config->get('max_tokens') ?: 4096,
      '#min' => 256,
      '#max' => 8192,
      '#description' => $this->t('Maximum tokens per response.'),
    ];

    $form['model_settings']['max_iterations'] = [
      '#type' => 'number',
      '#title' => $this->t('Max Agentic Iterations'),
      '#default_value' => $config->get('max_iterations') ?: 10,
      '#min' => 1,
      '#max' => 20,
      '#description' => $this->t('Maximum tool-use loop iterations per query. Higher values allow more complex multi-step reasoning but increase latency and cost.'),
    ];

    // --- Widget display ---
    $form['widget_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('Widget Display'),
      '#open' => TRUE,
      '#tree' => FALSE,
    ];

    $form['widget_settings']['show_model_selector'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show model selector'),
      '#default_value' => $config->get('show_model_selector') ?? TRUE,
      '#description' => $this->t('Allow users to choose a model in the widget. When disabled, the default model is always used.'),
    ];

    $form['widget_settings']['show_examples'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show example questions'),
      '#default_value' => $config->get('show_examples') ?? TRUE,
      '#description' => $this->t('Display example question buttons below the input.'),
    ];

    $form['widget_settings']['show_debug_panel'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show tool calls debug panel'),
      '#default_value' => $config->get('show_debug_panel') ?? FALSE,
      '#description' => $this->t('Display a collapsible panel showing raw tool calls, arguments, and API equivalents. Useful for developers and data scientists.'),
    ];

    $form['widget_settings']['save_chat_history'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Save chat history'),
      '#default_value' => $config->get('save_chat_history') ?? TRUE,
      '#description' => $this->t('Automatically save conversations for authenticated users. Users can recall, pin, and delete saved chats.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('dkan_nl_query.settings')
      ->set('anthropic_api_key', $form_state->getValue('anthropic_api_key'))
      ->set('openai_api_key', $form_state->getValue('openai_api_key'))
      ->set('model', $form_state->getValue('model'))
      ->set('max_tokens', (int) $form_state->getValue('max_tokens'))
      ->set('max_iterations', (int) $form_state->getValue('max_iterations'))
      ->set('show_model_selector', (bool) $form_state->getValue('show_model_selector'))
      ->set('show_examples', (bool) $form_state->getValue('show_examples'))
      ->set('show_debug_panel', (bool) $form_state->getValue('show_debug_panel'))
      ->set('save_chat_history', (bool) $form_state->getValue('save_chat_history'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
