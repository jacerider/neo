<?php

declare(strict_types=1);

namespace Drupal\neo_test\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\neo\VisibilityEntityInterface;
use Drupal\neo\VisibilityEntityTrait;

/**
 * The plain visibility consumer: conditions and nothing else.
 *
 * `VisibilityEntityTrait` has no consumer in `neo` — `neo_settings` and
 * `neo_toolbar` hold every one that exists — so this is the first config entity
 * in this repository that carries a visibility condition collection.
 *
 * It deliberately does **not** implement `VisibilityEntityPluginInterface`.
 * That is what makes it the instrument for the branch of
 * `VisibilityEntityAccessControlTrait::checkAccess()` that falls through to
 * `getDefaultVisibilityAccess()`: with no plugin to delegate to, an entity
 * whose conditions all pass reaches the trait's own default, which both real
 * consumers override away. `NeoTestPluginVisibility` is the other half.
 *
 * @ConfigEntityType(
 *   id = "neo_test_visibility",
 *   label = @Translation("Neo test visibility"),
 *   label_collection = @Translation("Neo test visibility entities"),
 *   label_singular = @Translation("neo test visibility entity"),
 *   label_plural = @Translation("neo test visibility entities"),
 *   handlers = {
 *     "access" = "Drupal\neo_test\NeoTestVisibilityAccessControlHandler",
 *     "form" = {
 *       "default" = "Drupal\neo_test\Form\NeoTestVisibilityForm",
 *       "add" = "Drupal\neo_test\Form\NeoTestVisibilityForm",
 *       "edit" = "Drupal\neo_test\Form\NeoTestVisibilityForm",
 *     },
 *   },
 *   config_prefix = "neo_test_visibility",
 *   admin_permission = "administer neo_test",
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "label",
 *     "uuid" = "uuid",
 *     "status" = "status",
 *   },
 *   config_export = {
 *     "id",
 *     "label",
 *     "visibility",
 *   },
 * )
 */
final class NeoTestVisibility extends ConfigEntityBase implements VisibilityEntityInterface {

  use VisibilityEntityTrait;

  /**
   * The entity id.
   *
   * @var string
   */
  protected $id;

  /**
   * The entity label.
   *
   * @var string
   */
  protected $label;

  /**
   * {@inheritdoc}
   */
  public function getPluginCollections() {
    return [
      'visibility' => $this->getVisibilityConditions(),
    ];
  }

}
