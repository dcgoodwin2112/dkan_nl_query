<?php

namespace Drupal\dkan_nl_query\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Natural language query block for dataset pages.
 *
 * @Block(
 *   id = "dkan_nl_query",
 *   admin_label = @Translation("DKAN Natural Language Query"),
 *   category = @Translation("DKAN"),
 * )
 */
class NlQueryBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * Available models grouped by provider.
   */
  private const MODELS = [
    ['id' => 'claude-opus-4-6', 'label' => 'Claude Opus 4.6', 'provider' => 'Anthropic'],
    ['id' => 'claude-sonnet-4-6', 'label' => 'Claude Sonnet 4.6', 'provider' => 'Anthropic'],
    ['id' => 'claude-haiku-4-5', 'label' => 'Claude Haiku 4.5', 'provider' => 'Anthropic'],
    ['id' => 'gpt-5.4', 'label' => 'GPT-5.4', 'provider' => 'OpenAI'],
    ['id' => 'gpt-5.4-mini-2026-03-17', 'label' => 'GPT-5.4 Mini', 'provider' => 'OpenAI'],
    ['id' => 'gpt-5.4-nano-2026-03-17', 'label' => 'GPT-5.4 Nano', 'provider' => 'OpenAI'],
  ];

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected AccountProxyInterface $currentUser;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = new static($configuration, $plugin_id, $plugin_definition);
    $instance->configFactory = $container->get('config.factory');
    $instance->currentUser = $container->get('current_user');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'dataset_id' => '',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state): array {
    $form['dataset_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Dataset UUID'),
      '#default_value' => $this->configuration['dataset_id'],
      '#description' => $this->t('Scope to a single dataset. Leave empty to show a dataset selector and allow cross-dataset queries.'),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state): void {
    $this->configuration['dataset_id'] = $form_state->getValue('dataset_id');
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $datasetId = $this->configuration['dataset_id'];
    $config = $this->configFactory->get('dkan_nl_query.settings');

    return [
      '#theme' => 'nl_query_widget',
      '#dataset_id' => $datasetId,
      '#attached' => [
        'library' => ['dkan_nl_query/nl_query'],
        'drupalSettings' => [
          'dkanNlQuery' => [
            'datasetId' => $datasetId,
            'endpoint' => '/api/nl-query',
            'models' => self::MODELS,
            'defaultModel' => $config->get('model') ?: 'claude-haiku-4-5',
            'showModelSelector' => $config->get('show_model_selector') ?? TRUE,
            'showExamples' => $config->get('show_examples') ?? TRUE,
            'showDebugPanel' => $config->get('show_debug_panel') ?? FALSE,
            'showApiCallButton' => $config->get('show_api_call_button') ?? TRUE,
            'showSqlButton' => $config->get('show_sql_button') ?? TRUE,
            'showSqlInDebug' => $config->get('show_sql_in_debug') ?? TRUE,
            'saveChatHistory' => $config->get('save_chat_history') ?? TRUE,
            'userAuthenticated' => $this->currentUser->isAuthenticated(),
          ],
        ],
      ],
    ];
  }

}
