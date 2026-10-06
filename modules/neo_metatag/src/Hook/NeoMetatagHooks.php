<?php

declare(strict_types=1);

namespace Drupal\neo_metatag\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\neo_metatag\WidgetConverter;

/**
 * Hook implementations for Neo | Metatag.
 *
 * This is not an API. The methods are public because core's hook collector
 * only reads public methods, and nothing but the hook system calls them.
 */
class NeoMetatagHooks {

  /**
   * Implements hook_page_attachments_alter().
   *
   * Removes the Generator meta tag core adds to every page.
   */
  #[Hook('page_attachments_alter')]
  public function pageAttachmentsAlter(array &$attachments): void {
    foreach ($attachments['#attached']['html_head'] ?? [] as $key => $value) {
      if (($value[1] ?? NULL) === 'system_meta_generator') {
        unset($attachments['#attached']['html_head'][$key]);
      }
    }
  }

  /**
   * Implements hook_field_info_alter().
   *
   * New metatag fields get the side panel widget. Existing form displays are
   * left alone; `drush neo:metatag:widgets` converts those.
   */
  #[Hook('field_info_alter')]
  public function fieldInfoAlter(array &$info): void {
    if (isset($info['metatag'])) {
      $info['metatag']['default_widget'] = WidgetConverter::WIDGET;
    }
  }

}
