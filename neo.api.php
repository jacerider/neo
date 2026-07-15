<?php

/**
 * @file
 * Hooks provided by the Neo module.
 */

use Drupal\Core\Menu\MenuLinkTreeElement;

/**
 * @addtogroup hooks
 * @{
 */

/**
 * Alter each item a slide_menu element generates from a menu tree.
 *
 * Invoked once per menu link (recursively, children included) as the
 * slide_menu render element maps a loaded menu tree onto slide menu items in
 * \Drupal\neo\Element\SlideMenu::generateItemFromMenuTree().
 *
 * $item starts as ['title' => string, 'url' => \Drupal\Core\Url] plus, when
 * the link has children, 'children' (nested items of the same shape, already
 * altered). Useful keys understood by \Drupal\neo\SlideMenu::buildItem():
 * - 'content': a render array shown in place of the link — the row becomes a
 *   content-only row (e.g. neo_alchemist_menu renders a region item's
 *   component tree this way). Any cacheability on the render array bubbles
 *   when it is rendered.
 * - 'content_attributes': attributes for the wrapper around 'content' (e.g.
 *   ['class' => ['neo-slide-menu--region']]).
 * - 'after': a render array appended after the link.
 * - 'item_attributes' / 'link_attributes': attribute overrides for this item.
 *
 * Set $item to NULL to drop the item from the menu entirely.
 *
 * @param array|null $item
 *   The slide menu item. Set to NULL to drop it.
 * @param \Drupal\Core\Menu\MenuLinkTreeElement $element
 *   The source menu tree element, including its link plugin. Treat as
 *   read-only context.
 *
 * @see \Drupal\neo\Element\SlideMenu
 * @see \Drupal\neo\SlideMenu
 */
function hook_neo_slide_menu_item_alter(?array &$item, MenuLinkTreeElement $element): void {
  // Example: append a badge after links flagged in the menu link options.
  $options = $element->link->getUrlObject()->getOptions();
  if (!empty($options['badge'])) {
    $item['after'] = ['#markup' => '<span class="badge">' . $options['badge'] . '</span>'];
  }
}

/**
 * @} End of "addtogroup hooks".
 */
