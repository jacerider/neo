<?php

declare(strict_types=1);

namespace Drupal\Tests\neo\Fixtures;

use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\neo\VisibilityEntityInterface;
use Drupal\neo\VisibilityEntityPluginInterface;
use Drupal\neo\VisibilityEntityTrait;

/**
 * A visibility consumer whose plugin is not a `VisibilityPluginInterface`.
 *
 * `VisibilityEntityAccessControlTrait::checkAccess()` reads
 * `VisibilityEntityPluginInterface` off the entity to decide whether to
 * delegate, and then reads `VisibilityPluginInterface` off whatever
 * `getPlugin()` handed back to decide whether it can actually ask it. The
 * second check has an `else` arm — the plugin is there but is the wrong shape,
 * so the answer falls back to `getDefaultVisibilityAccess()` — and nothing in
 * the stack can reach it: `neo_settings` and `neo_toolbar` both return a real
 * visibility plugin, and `neo_test`'s plugin-backed fixture returns
 * `NeoTestVisibilityPlugin`, which implements the interface for every one of
 * its five declared answers.
 *
 * This class is the missing instrument, and the whole of it is one settable
 * plugin. It is deliberately **not** registered as an entity type: it carries
 * no annotation, `neo_test` does not ship it, and no storage ever sees it. It
 * is constructed directly with `neo_test_plugin_visibility` as its entity type
 * id so that the handler under test is the fixture handler and the config cache
 * tag on the result reads the way a real one would, and it is handed to that
 * handler's public `access()` like any other entity. Nothing on the path from
 * `access()` to `checkAccess()` asks whether the object is the class the entity
 * type declares.
 *
 * Registering it instead would mean editing `neo_test`, which ticket 01 owns
 * and three other tickets assert against, to add a branch that only one test
 * ever takes.
 *
 * @see \Drupal\neo\VisibilityEntityAccessControlTrait::checkAccess()
 */
final class TestNeoForeignPluginVisibility extends ConfigEntityBase implements VisibilityEntityInterface, VisibilityEntityPluginInterface {

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
   * Whatever this entity should hand back as its plugin.
   *
   * @var mixed
   */
  protected $plugin;

  /**
   * Sets the object this entity exposes as its plugin.
   *
   * @param mixed $plugin
   *   The plugin, of any shape at all. The point of this class is that it is
   *   free to be something the access trait cannot ask for an answer.
   *
   * @return $this
   */
  public function setPlugin($plugin) {
    $this->plugin = $plugin;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getPlugin() {
    return $this->plugin;
  }

  /**
   * {@inheritdoc}
   */
  public function getPluginCollections() {
    return [
      'visibility' => $this->getVisibilityConditions(),
    ];
  }

}
