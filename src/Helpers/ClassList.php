<?php

namespace Drupal\neo\Helpers;

use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * The class list parser.
 *
 * A class list is the newline-delimited setting behind a CSS-class select: one
 * line per choice, either `class|Label` or a bare `class` used as both key and
 * label. Two surfaces render one — `neo`'s link widget and `neo_menu_link`'s
 * settings — and each used to carry its own character-for-character copy of the
 * parse below.
 *
 * The parse is pure, so it lives here as a static rather than a trait: the two
 * surfaces read their string from different places, so a trait method would
 * have taken the string as an argument anyway.
 */
class ClassList {

  /**
   * Extracts the allowed values array from the allowed_values element.
   *
   * @param string $string
   *   The class list: one `key|label` or bare `key` per line.
   * @param callable|null $validate
   *   The caller's class key rule, if it has one. It is handed a line and
   *   returns a falsy value when that line may be used as a key — the shape
   *   both surfaces' validateClassListValue() already has. Omitted, the
   *   built-in rule below applies, so the parser is usable without a caller.
   *
   * @return array|null
   *   The array of extracted key/value pairs, or NULL if the string is invalid.
   *
   * @see \Drupal\options\Plugin\Field\FieldType\ListItemBase::allowedValuesString()
   */
  public static function parse($string, ?callable $validate = NULL) {
    if (empty($string)) {
      return [];
    }
    if ($validate === NULL) {
      $validate = static fn ($option) => static::validateClassKey($option);
    }
    $values = [];

    $list = explode("\n", $string);
    $list = array_map('trim', $list);
    $list = array_filter($list, 'strlen');

    $generated_keys = $explicit_keys = FALSE;
    foreach ($list as $position => $text) {
      // Check for an explicit key.
      $matches = [];
      if (preg_match('/(.*)\|(.*)/', $text, $matches)) {
        // Trim key and value to avoid unwanted spaces issues.
        $key = trim($matches[1]);
        $value = trim($matches[2]);
        $explicit_keys = TRUE;
      }
      // Otherwise see if we can use the value as the key.
      elseif (!$validate($text)) {
        $key = $value = $text;
        $explicit_keys = TRUE;
      }
      else {
        return;
      }

      $values[$key] = $value;
    }

    // We generate keys only if the list contains no explicit key at all.
    if ($explicit_keys && $generated_keys) {
      return;
    }

    return $values;
  }

  /**
   * The built-in class key rule.
   *
   * Applied only when a caller supplies no rule of its own. Both surfaces do
   * supply one — their own validateClassListValue() — so an override on a site
   * that has subclassed either of them still governs what parses.
   *
   * @param string $option
   *   The line being considered as a key.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup|null
   *   A message when the line may not be used as a key, NULL when it may.
   */
  protected static function validateClassKey($option) {
    if (mb_strlen($option) > 255) {
      return new TranslatableMarkup('Allowed values list: each key must be a string at most 255 characters long.');
    }
  }

}
