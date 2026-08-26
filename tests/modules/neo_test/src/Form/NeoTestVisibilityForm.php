<?php

declare(strict_types=1);

namespace Drupal\neo_test\Form;

use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\neo\VisibilityFormTrait;

/**
 * The entity form both fixture visibility entity types use.
 *
 * `VisibilityFormTrait` is the fourth of the four traits with no consumer in
 * `neo`, and it is the largest: a build pass that runs every `neo`-context
 * condition plugin's configuration form into a vertical-tabs group and then
 * reshapes four of them by hand, a validate pass, and a submit pass that writes
 * the resulting configuration back onto the entity's condition collection.
 *
 * The form is kept to the minimum that makes those three passes reachable: a
 * label, a machine name, a status checkbox and the visibility group. Anything
 * more would be form code of this fixture's own that a test could mistake for
 * the trait's.
 *
 * `$form['visibility']` is seeded as an empty array and handed to
 * `buildVisibility()`, which is how `neo_toolbar` and `neo_settings` both call
 * it — the trait sets `#tree` on what it is given and nests every condition
 * under it, so the key the group lives under is the caller's choice and
 * `validateVisibility()`/`submitVisibility()` both assume it is `visibility`.
 */
final class NeoTestVisibilityForm extends EntityForm {

  use VisibilityFormTrait;

  /**
   * The entity being edited.
   *
   * @var \Drupal\neo\VisibilityEntityInterface
   */
  protected $entity;

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);

    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label'),
      '#maxlength' => 255,
      '#default_value' => $this->entity->label(),
      '#required' => TRUE,
    ];

    $form['id'] = [
      '#type' => 'machine_name',
      '#default_value' => $this->entity->id(),
      '#machine_name' => [
        'exists' => [$this, 'exists'],
      ],
      '#disabled' => !$this->entity->isNew(),
    ];

    $form['status'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enabled'),
      '#default_value' => $this->entity->status(),
    ];

    $form['visibility'] = [];
    $form['visibility'] = $this->buildVisibility($form['visibility'], $form_state);

    return $form;
  }

  /**
   * Machine name existence callback.
   *
   * @param string $id
   *   The proposed machine name.
   *
   * @return bool
   *   TRUE if an entity of this type already uses the id.
   */
  public function exists($id): bool {
    $storage = $this->entityTypeManager->getStorage($this->entity->getEntityTypeId());
    return (bool) $storage->load($id);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);
    $this->validateVisibility($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    parent::submitForm($form, $form_state);
    $this->submitVisibility($form, $form_state);
  }

}
