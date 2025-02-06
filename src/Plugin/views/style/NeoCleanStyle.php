<?php

declare(strict_types=1);

namespace Drupal\neo\Plugin\views\style;

use Drupal\views\Plugin\views\style\StylePluginBase;

/**
 * Style plugin for the Clean HTML view.
 *
 * @ViewsStyle(
 *   id = "neo_clean",
 *   title = @Translation("Neo | Clean"),
 *   help = @Translation("Displays content without HTML tags."),
 *   theme = "views_view_neo_clean",
 *   display_types = {"normal"}
 * )
 */
class NeoCleanStyle extends StylePluginBase {

  /**
   * {@inheritdoc}
   */
  protected $usesRowPlugin = TRUE;

  /**
   * Does the style plugin support custom css class for the rows.
   *
   * @var bool
   */
  protected $usesRowClass = FALSE;
}
