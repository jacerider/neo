<?php

namespace Drupal\neo\Plugin\views\area;

use Drupal\views\Plugin\views\area\Result;

/**
 * Views area handler to display some configurable result summary.
 *
 * @ingroup views_area_handlers
 */
class NeoResult extends Result {

  /**
   * {@inheritdoc}
   */
  public function render($empty = FALSE) {
    $build = parent::render($empty);
    if (!empty($build['#markup'])) {
      $build['#markup'] = '<div class="view-result">' . $build['#markup'] . '</div>';
    }
    return $build;
  }

}
