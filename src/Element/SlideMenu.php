<?php

namespace Drupal\neo\Element;

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
        $items += static::generateItemFromMenuTree($tree);
        // Refresh whatever render-caches this element when the menu changes.
        $element['#cache']['tags'][] = 'config:system.menu.' . $mid;
      }
      $element['#items'] = $items;
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
   *
   * @return array
   *   The generated menu items.
   */
  protected static function generateItemFromMenuTree($menu_tree) {
    $items = [];
    $moduleHandler = \Drupal::moduleHandler();
    foreach ($menu_tree as $key => $element) {
      $item = [];
      $item['title'] = $element->link->getTitle();
      $item['url'] = $element->link->getUrlObject();
      if ($element->hasChildren) {
        $item['children'] = static::generateItemFromMenuTree($element->subtree);
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
