<?php

namespace Drupal\neo\Element;

use Drupal\Core\Render\Attribute\RenderElement;
use Drupal\Core\Render\Element\RenderElementBase;

/**
 * Provides a message element.
 *
 * Simple formatter for a single inline status message.
 *
 * Usage example:
 * @code
 * $build['status_message'] = [
 *   '#type' => 'status_message',
 * ];
 * @endcode
 */
#[RenderElement('status_message')]
class StatusMessage extends RenderElementBase {

  /**
   * {@inheritdoc}
   *
   * Generate the placeholder in a #pre_render callback, because the hash salt
   * needs to be accessed, which may not yet be available when this is called.
   */
  public function getInfo() {
    return [
      // Can be 'info', 'status', 'warning', or 'error'.
      '#style' => 'info',
      '#value' => NULL,
      '#pre_render' => [
        [static::class, 'preRenderStatusMessage'],
      ],
    ];
  }

  /**
   * Pre-render the status message.
   *
   * @param array $element
   *   An associative array containing the properties and children of the
   *   modal.
   *
   * @return array
   *   The modified element.
   */
  public static function preRenderStatusMessage($element) {
    return [
      '#theme' => 'status_messages',
      '#message_list' => [
        $element['#style'] ?? 'info' => [$element['#value']],
      ],
    ];
  }

}
