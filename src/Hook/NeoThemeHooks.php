<?php

declare(strict_types=1);

namespace Drupal\neo\Hook;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Render\Element;
use Drupal\Core\Template\Attribute;
use Drupal\neo\Helpers\Str;
use Drupal\neo\Helpers\TableProps;

/**
 * The module's theme registration, suggestion and preprocessors.
 *
 * Split from \Drupal\neo\Hook\NeoHooks the way core splits its own dozen-odd
 * `…Hooks` / `…ThemeHooks` pairs: those five alter a data structure something
 * else then acts on, these produce markup. This is core's pattern rather than
 * an adaptation of it — a dozen core modules in 11.4.4 register their theme
 * hooks from a `#[Hook('theme')]` class — and the template path default is
 * derived from the extension's path rather than from where the implementation
 * lives, so nothing about template resolution changed with the move.
 *
 * The registration array below is what stood in `neo.module`: the same six
 * theme hooks, the same variables, the same render elements. Four keys are new,
 * and they are the reason this class exists rather than a tidier `.module`.
 *
 * `template_preprocess_HOOK()` is deprecated as of Drupal 11.3 and removed in
 * Drupal 12, and the theme registry raises a deprecation for every one it
 * finds; this module raised four on every registry build — for `accordion`,
 * `accordion_item`, `slide_list` and `input__number_plus_minus`. The
 * replacement is an **initial preprocess** entry in the theme hook's own
 * definition, naming this class and the method, which the theme manager
 * resolves through the callable resolver against the container. Position is
 * preserved exactly: an initial preprocess callback runs before every module
 * and theme preprocess function, which is where the deleted functions ran, so
 * nothing that reads the variables they set can observe the change.
 *
 * Every body below is what stood in `neo.module`, unchanged — including the
 * three parts that look accidental and are not. The Views table preprocessor
 * reaches `Attribute` objects through a `foreach` value copy and mutates them,
 * which works because they are objects. The accordion item preprocessor blanks
 * `$variables['element']['#attributes']` after reading it. The slide list
 * preprocessor reads `$variables['theme_hook_original']` to inherit the theme
 * onto nested children. All three survive verbatim.
 *
 * Nothing here reaches a container service, so the class takes no constructor
 * arguments at all; the one collaborator it needs is
 * \Drupal\neo\Helpers\TableProps, which is a static rather than a global so
 * that this class does not depend on `neo.module` having been loaded.
 *
 * This is not an API and it is not `final`. The methods are public because
 * core's hook collector only reads public methods; the only ones anything
 * outside the hook system reaches are the four initial preprocess methods,
 * reached through the theme registry by the callable the theme hook names.
 */
class NeoThemeHooks {

  /**
   * Implements hook_theme().
   */
  #[Hook('theme')]
  public function theme(): array {
    return [
      'description_list' => [
        'variables' => [
          'items' => [],
          'attributes' => [],
          'neo_style' => '',
          'neo_size' => 'md',
        ],
      ],
      'accordion' => [
        'render element' => 'element',
        'initial preprocess' => static::class . ':preprocessAccordion',
      ],
      'accordion_item' => [
        'render element' => 'element',
        'initial preprocess' => static::class . ':preprocessAccordionItem',
      ],
      'slide_list' => [
        'variables' => ['items' => [], 'attributes' => []],
        'initial preprocess' => static::class . ':preprocessSlideList',
      ],
      'input__number_plus_minus' => [
        'render element' => 'element',
        'initial preprocess' => static::class . ':preprocessInputNumberPlusMinus',
      ],
      'views_neo_clean' => [
        'variables' => [
          'attributes' => [],
          'view_array' => [],
          'view' => NULL,
          'rows' => [],
          'header' => [],
          'footer' => [],
          'empty' => [],
          'exposed' => [],
          'more' => [],
          'feed_icons' => [],
          'pager' => [],
          'title' => '',
          'attachment_before' => [],
          'attachment_after' => [],
        ],
      ],
    ];
  }

  /**
   * Implements hook_theme_suggestions_HOOK() for views_view.
   */
  #[Hook('theme_suggestions_views_view')]
  public function themeSuggestionsViewsView(array $variables): array {
    /** @var \Drupal\views\ViewExecutable $view */
    $view = $variables['view'];
    $suggestions = [];
    if ($view->getStyle()->getPluginId() === 'neo_clean') {
      $suggestions[] = 'views_view__neo_clean';
    }
    return $suggestions;
  }

  /**
   * Implements hook_preprocess_HOOK() for select elements.
   */
  #[Hook('preprocess_select')]
  public function preprocessSelect(array &$variables): void {
    if (!isset($variables['element']['#options'])) {
      return;
    }
    if (!empty($variables['element']['#is_weight'])) {
      return;
    }
    $variables['attributes']['class'][] = 'neo-select';
    $variables['#attached']['library'][] = 'neo/tom-select';
    $variables['attributes']['size'] = 1;
    if (!empty($variables['element']['#title'])) {
      $variables['attributes']['data-neo-title'] = $variables['element']['#title'];
    }
    if (!empty($variables['element']['#neo_style'])) {
      $variables['attributes']['data-neo-style'] = $variables['element']['#neo_style'];
    }
    if (!empty($variables['element']['#multiple'])) {
      $variables['attributes']['class'][] = 'neo-select-multi';
    }
    else {
      $variables['attributes']['class'][] = 'neo-select-single';
    }
  }

  /**
   * Implements hook_preprocess_HOOK() for textfield input elements.
   */
  #[Hook('preprocess_input__textfield')]
  public function preprocessInputTextfield(array &$variables): void {
    if (($variables['element']['#type'] ?? '') === 'entity_autocomplete') {
      $variables['element']['#attached']['library'] = $variables['element']['#attached']['library'] ?? [];
      $variables['element']['#attached']['library'] = array_filter($variables['element']['#attached']['library'], function ($library) {
        return $library !== 'core/drupal.autocomplete';
      });
      $variables['#attached']['library'][] = 'neo/tom-select';
      $variables['attributes']['class'] = array_filter($variables['attributes']['class'], function ($class) {
        return $class !== 'form-autocomplete';
      });
      $variables['attributes']['class'][] = 'neo-entity-autocomplete';
      if (!empty($variables['element']['#autocreate'])) {
        $variables['attributes']['class'][] = 'neo-autocreate';
      }
      if (!empty($variables['element']['#tags'])) {
        $variables['attributes']['class'][] = 'neo-select-multi';
      }
    }
  }

  /**
   * Implements hook_preprocess_HOOK() for style plugin table templates.
   *
   * Default template: views-ui-style-plugin-table.html.twig.
   */
  #[Hook('preprocess_views_ui_style_plugin_table')]
  public function preprocessViewsUiStylePluginTable(array &$variables): void {
    $form = $variables['form'];
    if (empty($form['neo'])) {
      return;
    }
    foreach ($form['#neo_header'] as $key => $label) {
      $variables['table']['#header'][$key] = $label;
    }
    $props = array_filter(TableProps::get(), fn ($prop) => !empty($prop['options']));
    $delta = 0;
    foreach (Element::children($form['neo']) as $id) {
      foreach ($props as $key => $prop) {
        $variables['table']['#rows'][$delta][]['data'] = $form['neo'][$id][$key];
      }
      $delta++;
    }
    unset($variables['form']['neo']);
  }

  /**
   * Implements hook_preprocess_HOOK() for views table templates.
   *
   * Default template: views-view-table.html.twig.
   */
  #[Hook('preprocess_views_view_table')]
  public function preprocessViewsViewTable(array &$variables): void {
    $view = $variables['view'];
    $options = $view->style_plugin->options;
    // Props flagged 'apply' => FALSE are persisted and exposed in the Views UI
    // but their class is worked out further down the render pipeline, so
    // stamping one here would only have to be undone later.
    $props = array_filter(TableProps::get(), fn ($prop) => ($prop['apply'] ?? TRUE) !== FALSE);
    foreach ($variables['rows'] as $row_id => $row) {
      foreach ($row['columns'] as $column_id => $column) {
        foreach ($props as $key => $prop) {
          $className = $options['info'][$column_id][$key] ?? NULL;
          if ($className) {
            foreach ($prop['options'] as $option => $label) {
              $column['attributes']->removeClass(str_replace('neo_', '', $key) . '--' . $option);
            }
            $column['attributes']->addClass(str_replace('neo_', '', $key) . '--' . $className);
          }
        }
      }
    }
  }

  /**
   * Prepares variables for number plus minus input templates.
   *
   * Default template: input--number-plus-minus.html.twig.
   *
   * This was `template_preprocess_input__number_plus_minus()`. It is named as
   * the theme hook's initial preprocess callback rather than being found by
   * name, which is the same position with none of the deprecation.
   *
   * @param array $variables
   *   An associative array containing:
   *   - element: An associative array containing the properties of the element.
   *     Properties used: #minus_attributes, #plus_attributes.
   */
  public function preprocessInputNumberPlusMinus(array &$variables): void {
    $variables['minus_attributes'] = new Attribute($variables['element']['#minus_attributes'] ?? []);
    $variables['plus_attributes'] = new Attribute($variables['element']['#plus_attributes'] ?? []);
  }

  /**
   * Prepares variables for accordion templates.
   *
   * Default template: accordion.html.twig.
   *
   * This was `template_preprocess_accordion()`, moved for the same reason and
   * into the same position.
   *
   * @param array $variables
   *   An associative array containing:
   *   - element: An associative array containing the properties and children of
   *     the details element. Properties used: #children.
   */
  public function preprocessAccordion(array &$variables): void {
    $element = $variables['element'];
    $variables['children'] = (!empty($element['#children'])) ? $element['#children'] : '';
  }

  /**
   * Prepares variables for accordion item templates.
   *
   * Default template: accordion-item.html.twig.
   *
   * This was `template_preprocess_accordion_item()`, moved for the same reason
   * and into the same position. It blanks `$element['#attributes']` after
   * reading it, which the template depends on and which therefore survives
   * verbatim.
   *
   * @param array $variables
   *   An associative array containing:
   *   - element: An associative array containing the properties of the element.
   *     Properties used: #attributes, #children, #description, #required,
   *     #summary_attributes, #title, #value.
   */
  public function preprocessAccordionItem(array &$variables): void {
    $element = $variables['element'];
    $variables['attributes'] = $element['#attributes'];
    $variables['summary_attributes'] = new Attribute($element['#summary_attributes']);
    $variables['content_attributes'] = new Attribute($element['#content_attributes'] ?? []);
    if (!empty($element['#title'])) {
      $variables['summary_attributes']['role'] = 'button';
      if (!empty($element['#attributes']['id'])) {
        $variables['summary_attributes']['id'] = $element['#attributes']['id'] . '-summary';
        $variables['summary_attributes']['aria-controls'] = $element['#attributes']['id'] . '-content';
        $variables['content_attributes']['id'] = $element['#attributes']['id'] . '-content';
        $variables['content_attributes']['aria-labelledby'] = $element['#attributes']['id'] . '-summary';
      }
      $variables['summary_attributes']['aria-expanded'] = !empty($element['#attributes']['open']) ? 'true' : 'false';
      $variables['alpine_id'] = 'acc' . Str::machine(implode('', $element['#parents']));
    }
    $variables['title'] = (!empty($element['#title'])) ? $element['#title'] : '';
    // If the element title is a string, wrap it a render array so that markup
    // will not be escaped (but XSS-filtered).
    if (is_string($variables['title']) && $variables['title'] !== '') {
      $variables['title'] = ['#markup' => $variables['title']];
    }
    $variables['description'] = (!empty($element['#description'])) ? $element['#description'] : '';
    $variables['children'] = (isset($element['#children'])) ? $element['#children'] : '';
    $variables['value'] = (isset($element['#value'])) ? $element['#value'] : '';
    $variables['required'] = !empty($element['#required']) ? $element['#required'] : NULL;

    $variables['element']['#attributes'] = [];

    // Suppress error messages.
    $variables['errors'] = NULL;
  }

  /**
   * Prepares variables for slide list templates.
   *
   * Default template: slide-list.html.twig.
   *
   * This was `template_preprocess_slide_list()`, moved for the same reason and
   * into the same position. It is virtually identical to the core
   * item-list.html.twig template's preprocessor, and it reads
   * `$variables['theme_hook_original']` to inherit the theme onto nested
   * children — the theme manager hands that key to an initial preprocess
   * callback exactly as it handed it to the function.
   *
   * @param array $variables
   *   An associative array containing:
   *   - items: An array of items to be displayed in the list. Each item can be
   *     either a string or a render array. If #type, #theme, or #markup
   *     properties are not specified for child render arrays, they will be
   *     inherited from the parent list, allowing callers to specify larger
   *     nested lists without having to explicitly specify and repeat the
   *     render properties for all nested child lists.
   *   - title: A title to be prepended to the list.
   *   - list_type: The type of list to return (e.g. "ul", "ol").
   *   - wrapper_attributes: HTML attributes to be applied to the list wrapper.
   *
   * @see https://www.drupal.org/node/1842756
   */
  public function preprocessSlideList(array &$variables): void {
    foreach ($variables['items'] as &$item) {
      $attributes = [];
      // If the item value is an array, then it is a render array.
      if (is_array($item)) {
        $attributes = NestedArray::mergeDeep($attributes, $item['#wrapper_attributes'] ?? []);
        // Determine whether there are any child elements in the item that are
        // not fully-specified render arrays. If there are any, then the child
        // elements present nested lists and we automatically inherit the render
        // array properties of the current list to them.
        foreach (Element::children($item) as $key) {
          $child = &$item[$key];
          // If this child element does not specify how it can be rendered, then
          // we need to inherit the render properties of the current list.
          if (!isset($child['#type']) && !isset($child['#theme']) && !isset($child['#markup'])) {
            // Since slide-list.html.twig supports both strings and render
            // arrays as items, the items of the nested list may have been
            // specified as the child elements of the nested list, instead of
            // #items. For convenience, we automatically move them into #items.
            if (!isset($child['#items'])) {
              // This is the same condition as in
              // \Drupal\Core\Render\Element::children(), which cannot be used
              // here, since it triggers an error on string values.
              foreach ($child as $child_key => $child_value) {
                if (is_int($child_key) || $child_key === '' || $child_key[0] !== '#') {
                  $child['#items'][$child_key] = $child_value;
                  unset($child[$child_key]);
                }
              }
            }
            // Lastly, inherit the original theme variables of the current list.
            $child['#theme'] = $variables['theme_hook_original'];
          }
        }
      }

      // Set the item's value and attributes for the template.
      $item = [
        'value' => $item,
        'attributes' => new Attribute($attributes),
      ];
    }
  }

}
