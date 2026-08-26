<?php

declare(strict_types=1);

namespace Drupal\neo_test\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\neo\VisibilityEntityInterface;
use Drupal\neo\VisibilityEntityPluginInterface;
use Drupal\neo\VisibilityEntityTrait;
use Drupal\neo_test\NeoTestVisibilityPlugin;

/**
 * The plugin-backed visibility consumer.
 *
 * Identical to `NeoTestVisibility` except that it also implements
 * `VisibilityEntityPluginInterface`, which is the flag
 * `VisibilityEntityAccessControlTrait::checkAccess()` reads to decide whether
 * to delegate its answer to a plugin instead of falling through to
 * `getDefaultVisibilityAccess()`.
 *
 * The access answer is declared **per entity**, in the entity's own `settings`,
 * not per plugin — the shape `neo_toolbar`'s fixtures already took. One plugin
 * class then serves every permutation the delegation branch has, and two
 * entities of the same plugin stay distinguishable, which a plugin switched
 * globally from state could not manage.
 *
 * @ConfigEntityType(
 *   id = "neo_test_plugin_visibility",
 *   label = @Translation("Neo test plugin visibility"),
 *   label_collection = @Translation("Neo test plugin visibility entities"),
 *   label_singular = @Translation("neo test plugin visibility entity"),
 *   label_plural = @Translation("neo test plugin visibility entities"),
 *   handlers = {
 *     "access" = "Drupal\neo_test\NeoTestVisibilityAccessControlHandler",
 *     "form" = {
 *       "default" = "Drupal\neo_test\Form\NeoTestVisibilityForm",
 *       "add" = "Drupal\neo_test\Form\NeoTestVisibilityForm",
 *       "edit" = "Drupal\neo_test\Form\NeoTestVisibilityForm",
 *     },
 *   },
 *   config_prefix = "neo_test_plugin_visibility",
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
 *     "settings",
 *     "visibility",
 *   },
 * )
 */
final class NeoTestPluginVisibility extends ConfigEntityBase implements VisibilityEntityInterface, VisibilityEntityPluginInterface {

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
   * The plugin settings, carrying this entity's own access answer.
   *
   * @var array
   */
  protected $settings = [];

  /**
   * {@inheritdoc}
   */
  public function getPluginCollections() {
    return [
      'visibility' => $this->getVisibilityConditions(),
    ];
  }

  /**
   * {@inheritdoc}
   *
   * Built fresh on every call and handed this entity's own settings, so the
   * answer the access handler delegates to is the one this entity declared.
   */
  public function getPlugin() {
    return new NeoTestVisibilityPlugin((string) $this->id(), $this->settings);
  }

}
