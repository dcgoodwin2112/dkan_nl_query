<?php

namespace Drupal\dkan_nl_query\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
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
    ['id' => 'claude-sonnet-4-20250514', 'label' => 'Claude Sonnet 4', 'provider' => 'Anthropic'],
    ['id' => 'claude-haiku-4-5-20251001', 'label' => 'Claude Haiku 4.5', 'provider' => 'Anthropic'],
    ['id' => 'gpt-4o', 'label' => 'GPT-4o', 'provider' => 'OpenAI'],
    ['id' => 'gpt-4o-mini', 'label' => 'GPT-4o Mini', 'provider' => 'OpenAI'],
  ];

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = new static($configuration, $plugin_id, $plugin_definition);
    $instance->configFactory = $container->get('config.factory');
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
            'defaultModel' => $config->get('model') ?: 'claude-sonnet-4-20250514',
          ],
        ],
      ],
    ];
  }

}
