<?php

declare(strict_types=1);

namespace Drupal\neo;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Render\Element;
use Drupal\Core\Security\TrustedCallbackInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Lays a container out as a shelf: a side panel fixed over a dimmed page.
 *
 * Any `container` element with a `#neo_shelf` array is a shelf. The element
 * stays where its builder put it, which matters for a form: a part of a form
 * cannot leave its <form>, so the shelf is presentation only. CSS fixes the
 * container over the page and the `neo/shelf` behavior makes it act like a
 * dialog. Without JS it renders in place, as the container it is.
 *
 * Nothing moves at build time. At render time the container's children are
 * split into a heading, a scrolling body and a footer holding its buttons, so
 * a button keeps the #array_parents its form found it by.
 *
 * Keys of `#neo_shelf`, all optional except `key`:
 * - key: a string that names this shelf across AJAX rebuilds.
 * - title: the panel heading.
 * - hint: a short line shown in the footer, beside the buttons.
 * - section: what the shelf is a part of ("Menu sections"); shown in the
 *   trail of the shelf and of shelves nested in it.
 * - label: what the shelf edits ("Main menu"); shown in the trail of shelves
 *   nested in it.
 * - confirm: the question asked before unsaved changes are thrown away.
 * - return: names of the buttons to focus once the shelf closes, in order of
 *   preference.
 * - owner: the id of the element to focus a button in when none of `return`
 *   is left.
 * - actions: paths, under the container, of the element holding its buttons.
 *   The first that exists is moved into the footer. Defaults to [['actions']].
 * - open: FALSE renders the shelf closed, for the page to open with
 *   Drupal.neoShelf.open(). Defaults to TRUE.
 * - open_on_error: renders a closed shelf open when a field in it has an
 *   error. Defaults to TRUE.
 *
 * In the footer, the button carrying `data-neo-shelf-primary` is pressed by
 * Enter in a text field, and the one carrying `data-neo-shelf-cancel` by Esc,
 * the close button and the backdrop.
 *
 * @see \Drupal\neo\Hook\NeoHooks::elementInfoAlter()
 */
final class Shelf implements TrustedCallbackInterface {

  /**
   * {@inheritdoc}
   */
  public static function trustedCallbacks() {
    return ['preRender'];
  }

  /**
   * Pre-render callback for containers: builds the shelf around one.
   *
   * @param array $element
   *   The container.
   *
   * @return array
   *   The container, holding a backdrop and a panel when it is a shelf.
   */
  public static function preRender(array $element): array {
    if (empty($element['#neo_shelf']) || !is_array($element['#neo_shelf'])) {
      return $element;
    }
    $shelf = $element['#neo_shelf'] + [
      'key' => '',
      'title' => '',
      'hint' => '',
      'actions' => [['actions']],
      'open' => TRUE,
      'open_on_error' => TRUE,
    ];

    $actions = [];
    foreach ($shelf['actions'] as $path) {
      $exists = FALSE;
      $found = NestedArray::getValue($element, $path, $exists);
      if ($exists && is_array($found)) {
        $actions = $found;
        NestedArray::unsetValue($element, $path);
        break;
      }
    }
    $actions['#attributes']['class'][] = 'neo-shelf__actions';

    $body = [];
    foreach (Element::children($element) as $key) {
      $body[$key] = $element[$key];
      unset($element[$key]);
    }

    $open = $shelf['open'] || ($shelf['open_on_error'] && static::hasErrors($body));
    $title_id = Html::getUniqueId('neo-shelf-title');

    $element['#attributes']['class'][] = 'neo-shelf';
    if (!$open) {
      $element['#attributes']['class'][] = 'is-closed';
    }
    $element['#attributes']['data-neo-shelf'] = $shelf['key'];
    $element['#attributes']['data-neo-shelf-title'] = $title_id;
    foreach (['section', 'label', 'confirm', 'owner'] as $name) {
      if (isset($shelf[$name]) && (string) $shelf[$name] !== '') {
        $element['#attributes']['data-neo-shelf-' . $name] = (string) $shelf[$name];
      }
    }
    if (!empty($shelf['return'])) {
      $element['#attributes']['data-neo-shelf-return'] = implode(' ', array_unique($shelf['return']));
    }
    $element['#attached']['library'][] = 'neo/shelf';

    $element['backdrop'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#value' => '',
      '#attributes' => [
        'class' => ['neo-shelf__backdrop'],
        'data-neo-shelf-dismiss' => 'backdrop',
      ],
    ];
    $element['panel'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['neo-shelf__panel']],
      'header' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['neo-shelf__header']],
        'heading' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['neo-shelf__heading']],
          // Filled in by the behavior, which knows the shelves around it.
          'trail' => [
            '#type' => 'html_tag',
            '#tag' => 'p',
            '#value' => '',
            '#attributes' => ['class' => ['neo-shelf__trail']],
          ],
          'title' => [
            '#type' => 'html_tag',
            '#tag' => 'h2',
            '#value' => $shelf['title'],
            '#attributes' => [
              'id' => $title_id,
              'class' => ['neo-shelf__title'],
            ],
          ],
        ],
        'close' => [
          '#type' => 'html_tag',
          '#tag' => 'button',
          '#value' => '<span class="neo-shelf__close-icon" aria-hidden="true"></span>',
          '#attributes' => [
            'type' => 'button',
            'class' => ['neo-shelf__close'],
            'aria-label' => new TranslatableMarkup('Close'),
            'title' => new TranslatableMarkup('Close'),
            'data-neo-shelf-dismiss' => 'close',
          ],
        ],
      ],
      'body' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['neo-shelf__body']],
      ] + $body,
      'footer' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['neo-shelf__footer']],
        'hint' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $shelf['hint'],
          '#attributes' => ['class' => ['neo-shelf__hint']],
        ],
        'actions' => $actions,
      ],
    ];

    return $element;
  }

  /**
   * Whether any element in a tree carries a form error.
   *
   * @param array $elements
   *   The elements to search.
   */
  private static function hasErrors(array $elements): bool {
    if (!empty($elements['#errors'])) {
      return TRUE;
    }
    foreach (Element::children($elements) as $key) {
      if (is_array($elements[$key]) && static::hasErrors($elements[$key])) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
