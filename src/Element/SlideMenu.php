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
      '#items' => [],
      '#attributes' => [],
      '#item_attrbutes' => [],
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
    ]);
    $element['slide'] = $slideMenu->toRenderable();
    return $element;
  }

}
