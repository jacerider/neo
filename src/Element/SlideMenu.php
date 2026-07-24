<?php

namespace Drupal\neo\Element;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Render\Attribute\RenderElement;
use Drupal\Core\Render\Element\RenderElementBase;
use Drupal\neo\SlideMenu as NeoSlideMenu;

/**
 * Provides a render element that will close a modal.
 */
#[RenderElement('slide_menu')]
class SlideMenu extends RenderElementBase {

  /**
   * {@inheritdoc}
   */
  public function getInfo() {
    $class = static::class;
    return [
      '#title' => '',
      '#menu_ids' => [],
      '#items' => [],
      '#attributes' => [],
      '#item_attributes' => [],
      '#link_attributes' => [],
      '#child_icon' => 'chevron-right',
      '#child_icon_attributes' => [],
      '#back_status' => TRUE,
      '#back_label' => 'Back',
      '#back_icon' => 'chevron-left',
      '#back_attributes' => [],
      '#back_icon_attributes' => [],
      '#all_status' => TRUE,
      '#all_prefix' => t('View All'),
      '#all_suffix' => '',
      // Depth at which children render expanded within the current panel
      // (as group headings with inline lists) instead of opening another
      // slide level. 0 always slides; 2 = a mobile mega menu.
      '#expand_depth' => 0,
      '#process' => [
        [$class, 'processGroup'],
      ],
      '#pre_render' => [
        [$class, 'preRenderGroup'],
        [$class, 'preRenderSlideMenu'],
      ],
      '#value' => NULL,
    ];
  }

  /**
   * Pre-render the slide menu.
   *
   * @param array $element
   *   An associative array containing the properties and children of the
   *   modal.
   *
   * @return array
   *   The modified element.
   */
  public static function preRenderSlideMenu($element) {
    if (!empty($element['#menu_ids'])) {
      $items = [];
      // Collects the access + link cacheability of the whole tree so the
      // rendered menu varies by whatever the access results vary by. Without
      // this the menu render-caches with no user variance at all, and the first
      // request to build it fixes that markup for every subsequent viewer.
      $cacheability = new CacheableMetadata();
      $menuLinkTree = \Drupal::menuTree();
      foreach ($element['#menu_ids'] as $mid) {
        $parameters = $menuLinkTree->getCurrentRouteMenuTreeParameters($mid);

        $parameters->expandedParents = [];
        $menuTree = $menuLinkTree->load($mid, $parameters);
        $manipulators = [
          ['callable' => 'menu.default_tree_manipulators:checkAccess'],
          ['callable' => 'menu.default_tree_manipulators:generateIndexAndSort'],
        ];
        $tree = $menuLinkTree->transform($menuTree, $manipulators);
        $items += static::generateItemFromMenuTree($tree, $cacheability);
        // Refresh whatever render-caches this element when the menu changes.
        $element['#cache']['tags'][] = 'config:system.menu.' . $mid;
      }
      $element['#items'] = $items;
      // Merge rather than applyTo() alone: applyTo() replaces #cache outright
      // and would drop the menu config tags added above.
      $cacheability
        ->merge(CacheableMetadata::createFromRenderArray($element))
        ->applyTo($element);
    }
    $slideMenu = new NeoSlideMenu($element['#items'], [
      'item_attributes' => $element['#item_attributes'],
      'link_attributes' => $element['#link_attributes'],
      'child_icon' => $element['#child_icon'],
      'child_icon_attributes' => $element['#child_icon_attributes'],
      'back_status' => $element['#back_status'],
      'back_label' => $element['#back_label'],
      'back_attributes' => $element['#back_attributes'],
      'back_icon_attributes' => $element['#back_icon_attributes'],
      'all_status' => $element['#all_status'],
      'all_prefix' => $element['#all_prefix'],
      'all_suffix' => $element['#all_suffix'],
      'expand_depth' => $element['#expand_depth'],
    ]);
    $element['slide'] = $slideMenu->toRenderable();
    return $element;
  }

  /**
   * Recursively generate menu items from a menu tree.
   *
   * @param \Drupal\Core\Menu\MenuLinkTreeElement[] $menu_tree
   *   The menu tree.
   * @param \Drupal\Core\Cache\CacheableMetadata|null $cacheability
   *   (optional) Collects the access and link cacheability of the tree.
   *
   * @return array
   *   The generated menu items.
   */
  protected static function generateItemFromMenuTree($menu_tree, ?CacheableMetadata $cacheability = NULL) {
    $items = [];
    $moduleHandler = \Drupal::moduleHandler();
    foreach ($menu_tree as $key => $element) {
      // Gather cacheability for EVERY element, including inaccessible ones —
      // that is what lets the menu be render-cached while still varying by the
      // cache contexts the access results depend on. Links may also be dynamic
      // (a title that varies by user, a dynamic route), so their cacheability
      // is collected too. Mirrors \Drupal\Core\Menu\MenuLinkTree::buildItems().
      if ($cacheability) {
        if ($element->access instanceof AccessResultInterface) {
          $cacheability->addCacheableDependency($element->access);
        }
        $cacheability->addCacheableDependency($element->link);
      }

      // Only render accessible links. checkAccess() deliberately KEEPS
      // inaccessible top-level links in the tree so their cacheability can
      // bubble, swapping in an InaccessibleMenuLink whose getTitle() returns
      // the literal string "Inaccessible" — rendering those leaks a
      // placeholder row into the menu.
      if ($element->access instanceof AccessResultInterface && !$element->access->isAllowed()) {
        continue;
      }

      $item = [];
      $item['title'] = $element->link->getTitle();
      $item['url'] = $element->link->getUrlObject();
      if ($element->hasChildren) {
        $item['children'] = static::generateItemFromMenuTree($element->subtree, $cacheability);
      }
      // Let modules enrich or veto individual items (set $item to NULL to
      // drop one). For example, neo_alchemist_menu swaps its region items
      // for their rendered component tree.
      $moduleHandler->alter('neo_slide_menu_item', $item, $element);
      if ($item === NULL) {
        continue;
      }
      $items[$key] = $item;
    }
    return $items;
  }

}
