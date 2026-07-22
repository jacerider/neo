<?php

namespace Drupal\neo;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\Tags;
use Drupal\Core\Entity\EntityAutocompleteMatcher;
use Drupal\Core\Entity\EntityReferenceSelection\SelectionPluginManagerInterface;

/**
 * Matcher class to get autocompletion results for entity reference.
 */
class NeoEntityAutocompleteMatcher extends EntityAutocompleteMatcher {

  /**
   * The entity reference selection handler plugin manager.
   *
   * @var \Drupal\Core\Entity\EntityReferenceSelection\SelectionPluginManagerInterface
   */
  protected $selectionManager;

  /**
   * Constructs an EntityAutocompleteMatcher object.
   *
   * @param \Drupal\Core\Entity\EntityReferenceSelection\SelectionPluginManagerInterface $selection_manager
   *   The entity reference selection handler plugin manager.
   */
  public function __construct(SelectionPluginManagerInterface $selection_manager) {
    $this->selectionManager = $selection_manager;
  }

  /**
   * {@inheritdoc}
   */
  public function getMatches($target_type, $selection_handler, $selection_settings, $string = '') {
    $matches = [];

    $options = $selection_settings + [
      'target_type' => $target_type,
      'handler' => $selection_handler,
    ];
    $handler = $this->selectionManager->getInstance($options);

    if (!empty($string)) {
      // Get an array of matching entities.
      $match_operator = !empty($selection_settings['match_operator']) ? $selection_settings['match_operator'] : 'CONTAINS';
      $match_limit = isset($selection_settings['match_limit']) ? (int) $selection_settings['match_limit'] : 10;
      $entity_labels = $handler->getReferenceableEntities($string, $match_operator, $match_limit);

      // Loop through the entities and convert them into autocomplete output.
      foreach ($entity_labels as $values) {
        foreach ($values as $entity_id => $label) {
          $option = NULL;
          // Label can be an array with additional data. If 'option' is set,
          // it will be used as the option available for selection within the
          // autocomplete dropdown. HTML is supported if using tom-select.
          if (is_array($label)) {
            assert(isset($label['value']), 'The label array must contain a "value" key.');
            $option = $label['option'] ?? NULL;
            $label = $label['value'] ?? '';
          }
          $key = "$label ($entity_id)";
          // Strip things like starting/trailing white spaces, line breaks and
          // tags.
          $key = preg_replace('/\s\s+/', ' ', str_replace("\n", '', trim(Html::decodeEntities(strip_tags($key)))));
          // Names containing commas or quotes must be wrapped in quotes.
          $key = Tags::encode($key);
          // The selection handler HTML-escapes labels (e.g. an apostrophe
          // becomes &#039;), but tom-select escapes the label again when
          // rendering the option, which double-encodes it and displays the
          // literal entity. Decode here so the label is plain text that
          // tom-select can safely re-escape. Markup for display belongs in
          // the separate 'option' key.
          $label = Html::decodeEntities($label);
          $matches[] = ['value' => $key, 'label' => $label, 'option' => $option];
        }
      }
    }

    return $matches;
  }

}
