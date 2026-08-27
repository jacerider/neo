<?php

namespace Drupal\neo\Helpers;

use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * The table props.
 *
 * A table prop is a presentation key `neo` adds to a Views table style, one per
 * column: the Views UI renders a select for each prop carrying an option map,
 * the config schema alter extends `views.style.table`'s per-column mapping with
 * each prop's label, and the table preprocessor turns a chosen option into the
 * `{prop}--{option}` class on that column's cell.
 *
 * The set is fixed and reaches nothing — no container service, no config, no
 * argument — so it lives here as a static beside the other Neo helpers rather
 * than as a service. Its shape is read by key, both by the four call sites in
 * this package and by `neo_theme`'s `NeoBasePreRender`, so it is an array
 * rather than a type.
 *
 * The labels are `TranslatableMarkup` instances rather than `t()` calls,
 * because a static has no `$this->t()` and `t()` in a class is a coding
 * standards warning.
 */
class TableProps {

  /**
   * Gets the table props.
   *
   * @return array
   *   Every table prop, keyed by the column setting it is stored under. Each
   *   carries a `label` and an `options` map — empty for a prop whose values
   *   are open — and, where the prop is not stamped by the table preprocessor,
   *   an `apply` flag set to FALSE.
   */
  public static function get(): array {
    return [
      'neo_style' => [
        'label' => new TranslatableMarkup('Neo Style'),
        'options' => [
          'default' => new TranslatableMarkup('Default'),
          'heading' => new TranslatableMarkup('Heading'),
          'sm' => new TranslatableMarkup('Small'),
          'xs' => new TranslatableMarkup('Extra Small'),
        ],
      ],
      'neo_size' => [
        'label' => new TranslatableMarkup('Neo Size'),
        'options' => [
          'default' => new TranslatableMarkup('Default'),
          'min' => new TranslatableMarkup('Minimum'),
        ],
      ],
      'neo_align' => [
        'label' => new TranslatableMarkup('Neo Align'),
        'options' => [],
      ],
      'neo_sticky' => [
        'label' => new TranslatableMarkup('Neo Sticky'),
        'options' => [
          'none' => new TranslatableMarkup('None'),
          'left' => new TranslatableMarkup('Left'),
          'right' => new TranslatableMarkup('Right'),
        ],
        // Unlike the other props, sticky is structural, not presentational:
        // pinning one column implies pinning everything between it and the
        // edge, and each pinned column needs an offset derived from its
        // neighbours. That normalization has to see every column at once, so
        // this prop is persisted and exposed in the UI here while the class
        // itself is computed downstream by \Drupal\neo_base\NeoBasePreRender.
        'apply' => FALSE,
      ],
    ];
  }

}
