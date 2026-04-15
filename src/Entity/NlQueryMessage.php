<?php

namespace Drupal\dkan_nl_query\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;

/**
 * Defines the NL Query Message entity.
 *
 * @ContentEntityType(
 *   id = "nl_query_message",
 *   label = @Translation("NL Query Message"),
 *   base_table = "nl_query_messages",
 *   entity_keys = {
 *     "id" = "id",
 *   },
 *   internal = TRUE,
 * )
 */
class NlQueryMessage extends ContentEntityBase {

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['conversation_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Conversation'))
      ->setSetting('target_type', 'nl_query_conversation')
      ->setRequired(TRUE)
      ->setTranslatable(FALSE);

    $fields['role'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Role'))
      ->setRequired(TRUE)
      ->setTranslatable(FALSE)
      ->setSettings(['max_length' => 16]);

    $fields['content'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Content'))
      ->setTranslatable(FALSE);

    $fields['chart_spec'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Chart Spec'))
      ->setTranslatable(FALSE);

    $fields['table_data'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Table Data'))
      ->setTranslatable(FALSE);

    $fields['tool_calls'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Tool Calls'))
      ->setTranslatable(FALSE);

    $fields['weight'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Weight'))
      ->setDefaultValue(0)
      ->setTranslatable(FALSE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Created'))
      ->setTranslatable(FALSE);

    return $fields;
  }

}
