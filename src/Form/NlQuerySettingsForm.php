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

    $form['provider'] = [
      '#type' => 'radios',
      '#title' => $this->t('Default Provider'),
      '#options' => [
        'anthropic' => $this->t('Anthropic (Claude)'),
        'openai' => $this->t('OpenAI (GPT)'),
      ],
      '#default_value' => $config->get('provider') ?: 'anthropic',
      '#description' => $this->t('Default LLM provider. Users can override by selecting a different model in the widget.'),
    ];

    $form['anthropic_api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Anthropic API Key'),
      '#maxlength' => 256,
      '#default_value' => $config->get('anthropic_api_key') ?: $config->get('api_key'),
      '#description' => $this->t('API key for Claude models. Get one at console.anthropic.com.'),
    ];

    $form['openai_api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('OpenAI API Key'),
      '#maxlength' => 256,
      '#default_value' => $config->get('openai_api_key'),
      '#description' => $this->t('API key for GPT models. Get one at platform.openai.com.'),
    ];

    $form['model'] = [
      '#type' => 'select',
      '#title' => $this->t('Default Model'),
      '#options' => [
        $this->t('Anthropic')->__toString() => [
          'claude-sonnet-4-20250514' => $this->t('Claude Sonnet 4'),
          'claude-haiku-4-5-20251001' => $this->t('Claude Haiku 4.5'),
        ],
        $this->t('OpenAI')->__toString() => [
          'gpt-4o' => $this->t('GPT-4o'),
          'gpt-4o-mini' => $this->t('GPT-4o Mini'),
        ],
      ],
      '#default_value' => $config->get('model') ?: 'claude-sonnet-4-20250514',
      '#description' => $this->t('Default model for new queries.'),
    ];

    $form['max_tokens'] = [
      '#type' => 'number',
      '#title' => $this->t('Max Tokens'),
      '#default_value' => $config->get('max_tokens') ?: 4096,
      '#min' => 256,
      '#max' => 8192,
      '#description' => $this->t('Maximum tokens per response.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('dkan_nl_query.settings')
      ->set('provider', $form_state->getValue('provider'))
      ->set('anthropic_api_key', $form_state->getValue('anthropic_api_key'))
      ->set('openai_api_key', $form_state->getValue('openai_api_key'))
      ->set('model', $form_state->getValue('model'))
      ->set('max_tokens', (int) $form_state->getValue('max_tokens'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
