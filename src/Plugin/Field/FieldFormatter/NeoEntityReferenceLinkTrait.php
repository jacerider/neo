<?php

namespace Drupal\neo\Plugin\Field\FieldFormatter;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;

/**
 * Provides a trait for selecting which entities to view.
 */
trait NeoEntityReferenceLinkTrait {

  /**
   * {@inheritdoc}
   */
  public static function linkDefaultSettings() {
    return [
      'image_link' => '',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function linkSettingsSummary() {
    $summary = [];

    $link_types = $this->getLinkFieldOptions();
    // Display this setting only if image is linked.
    $image_link_setting = $this->getSetting('image_link');
    if (isset($link_types[$image_link_setting])) {
      $summary[] = $this->t('Link to: @type', ['@type' => $link_types[$image_link_setting]]);
    }

    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public function linkSettingsForm(array &$form, FormStateInterface $form_state) {
    $element['image_link'] = [
      '#title' => $this->t('Link image to'),
      '#type' => 'select',
      '#default_value' => $this->getSetting('image_link'),
      '#empty_option' => $this->t('- Nothing -'),
      '#options' => $this->getLinkFieldOptions(),
    ];
    return $element;
  }

  /**
   * Get link field options.
   */
  public function getLinkFieldOptions() {
    $options = [
      'content' => $this->t('Content'),
      'file' => $this->t('File'),
    ];
    $fields = \Drupal::service('entity_field.manager')->getFieldDefinitions($this->fieldDefinition->getTargetEntityTypeId(), $this->fieldDefinition->getTargetBundle());
    foreach ($fields as $field_name => $field) {
      if ($field->getType() == 'link') {
        $options[$field->getName()] = $this->t('Field @label', ['@label' => $field->getLabel()]);
      }
    }
    return $options;
  }

  /**
   * Get the URL for the file.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $parentEntity
   *   The parent entity that the field belongs to.
   * @param \Drupal\media\MediaInterface|\Drupal\file\FileInterface $entity
   *   The entity that the field belongs to.
   *
   * @return \Drupal\Core\Url|null
   *   The URL object for the file item or null if we don't want to add
   *   a link.
   */
  protected function getLinkUrl(ContentEntityInterface $parentEntity, MediaInterface|FileInterface $entity) {
    $url = NULL;
    try {
      $image_link_setting = $this->getSetting('image_link');
      // Check if the formatter involves a link.
      if ($image_link_setting == 'content') {
        if (!$parentEntity->isNew()) {
          $url = $parentEntity->toUrl();
        }
      }
      elseif ($image_link_setting === 'file') {
        if ($entity instanceof MediaInterface) {
          $fieldDefinition = $entity->getSource()->getSourceFieldDefinition($entity->bundle->entity);
          $item = $entity->get($fieldDefinition->getName())->first();
          if (!$item) {
            throw new \InvalidArgumentException('The media entity does not have a file.');
          }
          $entity = $item->entity;
        }
        $url = \Drupal::service('file_url_generator')->generateAbsoluteString($entity->getFileUri());
      }
    }
    catch (\Exception $e) {
    }
    return $url;
  }

}
