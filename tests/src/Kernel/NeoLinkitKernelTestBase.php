<?php

declare(strict_types=1);

namespace Drupal\Tests\neo\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\link\LinkItemInterface;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;

/**
 * Bootstraps what the Linkit seam reaches, and nothing else.
 *
 * `neo` itself is deliberately not installed. The module declares
 * `neo_build`, `neo_color` and `neo_icon` as dependencies and none of them has
 * anything to do with URI resolution, so installing it would make this
 * package's first tests slow and brittle in exchange for nothing. Both traits
 * are plain PHP reached through the fixture consumer classes, so the module
 * being enabled is not what makes them load.
 *
 * What is installed is what the seam actually calls into:
 *
 * - `system` and `user` are the floor, and `system` also supplies the `<front>`
 *   route the write path resolves `<front>` against.
 * - `node` supplies the entity type every criterion resolves to, and its
 *   `entity.node.canonical` route is what both the `Url::fromUri()` fallback
 *   in the write path and the canonical substitution in the read path need.
 * - `field`, `text` and `link` supply the link field type, which is the only
 *   way to get a real `LinkItemInterface` for the read path.
 * - `path_alias` supplies the alias manager the write path consults before it
 *   gives up on a path.
 * - `linkit` supplies the profile entity type, the matcher plugins and the
 *   substitution manager, and `filter` is its own dependency.
 */
abstract class NeoLinkitKernelTestBase extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'link',
    'node',
    'path_alias',
    'linkit',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('path_alias');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['field', 'filter', 'node']);

    NodeType::create([
      'type' => 'page',
      'name' => 'Page',
    ])->save();
  }

  /**
   * Creates and saves a node.
   *
   * The node has to be saved rather than merely built, because every
   * criterion here either loads it back by id or asks it for its canonical
   * URL.
   *
   * @param string $title
   *   The node title.
   *
   * @return \Drupal\node\Entity\Node
   *   The saved node.
   */
  protected function createPage(string $title = 'Landing'): Node {
    $node = Node::create([
      'type' => 'page',
      'title' => $title,
    ]);
    $node->save();
    return $node;
  }

  /**
   * Attaches a link field to the `page` bundle.
   *
   * A real `LinkItemInterface` is only obtainable from a real link field, and
   * the read path takes one as its argument, so any test that touches
   * `getLinkitUrl()` needs this first.
   */
  protected function createLinkField(): void {
    FieldStorageConfig::create([
      'field_name' => 'field_link',
      'entity_type' => 'node',
      'type' => 'link',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_link',
      'entity_type' => 'node',
      'bundle' => 'page',
      'label' => 'Link',
      'settings' => ['link_type' => LinkItemInterface::LINK_GENERIC],
    ])->save();
  }

  /**
   * Builds a link item without saving the node that holds it.
   *
   * Only the item is under test; the node it hangs off is a carrier. Nothing
   * reads it back, so it is never saved.
   *
   * @param mixed $uri
   *   The stored uri. Deliberately untyped: one criterion stores something
   *   that is not a string.
   * @param array $options
   *   The item's options.
   *
   * @return \Drupal\link\LinkItemInterface
   *   The first item of the link field.
   */
  protected function linkItem($uri, array $options = []): LinkItemInterface {
    $holder = Node::create(['type' => 'page', 'title' => 'Holder']);
    $holder->set('field_link', [['uri' => $uri, 'options' => $options]]);
    $item = $holder->get('field_link')->first();
    assert($item instanceof LinkItemInterface);
    return $item;
  }

}
