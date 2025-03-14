<?php

namespace Drupal\neo;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\DraggableListBuilderTrait;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Form\FormInterface;

/**
 * Defines a class to build a draggable listing of content entities.
 */
abstract class DraggableContentListBuilder extends EntityListBuilder implements FormInterface {

  use DraggableListBuilderTrait;

  /**
   * {@inheritdoc}
   */
  public function __construct(EntityTypeInterface $entity_type, EntityStorageInterface $storage) {
    parent::__construct($entity_type, $storage);

    // Do not inject the form builder for backwards-compatibility.
    $this->formBuilder = \Drupal::formBuilder();

    // Check if the entity type supports weighting.
    if ($this->entityType->hasKey('weight')) {
      $this->weightKey = $this->entityType->getKey('weight');
    }
    $this->limit = FALSE;
  }

  /**
   * {@inheritdoc}
   */
  protected function getWeight(EntityInterface $entity): int|float {
    /** @var \Drupal\Core\Entity\ContentEntityInterface $entity */
    return $entity->get($this->weightKey)->value ?: 0;
  }

  /**
   * {@inheritdoc}
   */
  protected function setWeight(EntityInterface $entity, int|float $weight): ContentEntityInterface {
    /** @var \Drupal\Core\Entity\ContentEntityInterface $entity */
    $entity->set($this->weightKey, $weight);
    return $entity;
  }

  /**
   * Returns a query object for loading entity IDs from the storage.
   *
   * @return \Drupal\Core\Entity\Query\QueryInterface
   *   A query object used to load entity IDs.
   */
  protected function getEntityListQuery(): QueryInterface {
    $query = $this->getStorage()->getQuery()
      ->accessCheck(TRUE);

    if ($this->entityType->hasKey('weight')) {
      $query->sort($this->entityType->getKey('weight'));
    }

    // Only add the pager if a limit is specified.
    if ($this->limit) {
      $query->pager($this->limit);
    }
    return $query;
  }

}
