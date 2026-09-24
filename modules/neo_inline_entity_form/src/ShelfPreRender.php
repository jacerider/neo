<?php

declare(strict_types=1);

namespace Drupal\neo_inline_entity_form;

use Drupal\Component\Utility\Html;
use Drupal\Core\Render\Element;
use Drupal\Core\Security\TrustedCallbackInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Lays an open inline entity form out as a shelf, at render time.
 *
 * The form is fully built and processed by now, so its elements can be moved
 * into a heading, a body and a footer without IEF noticing. Doing the same at
 * build time would move the buttons away from the entity form that
 * \Drupal\inline_entity_form\ElementSubmit::trigger() finds through their
 * #array_parents.
 *
 * The shelf is the container IEF wraps the form in, which stays where IEF put
 * it: in a table row for an edit form, below the table for an add form.
 *
 * @see \Drupal\neo_inline_entity_form\Plugin\Field\FieldWidget\NeoInlineEntityFormComplex::prepareShelf()
 */
final class ShelfPreRender implements TrustedCallbackInterface {

  /**
   * {@inheritdoc}
   */
  public static function trustedCallbacks() {
    return ['preRender'];
  }

  /**
   * Pre-render callback: splits the container into the shelf's parts.
   *
   * @param array $element
   *   The container IEF wraps an open form in.
   *
   * @return array
   *   The container, holding a backdrop and a panel.
   */
  public static function preRender(array $element): array {
    $shelf = $element['#neo_ief_shelf'] ?? [];

    // An entity form carries its buttons; the reference form holds them
    // directly.
    $actions = [];
    if (isset($element['inline_entity_form']['actions'])) {
      $actions = $element['inline_entity_form']['actions'];
      unset($element['inline_entity_form']['actions']);
    }
    elseif (isset($element['actions'])) {
      $actions = $element['actions'];
      unset($element['actions']);
    }
    $actions['#attributes']['class'][] = 'neo-ief-shelf__actions';

    $body = [];
    foreach (Element::children($element) as $key) {
      $body[$key] = $element[$key];
      unset($element[$key]);
    }

    $title_id = Html::getUniqueId('neo-ief-shelf-title');
    $element['#attributes']['data-neo-ief-title'] = $title_id;

    $element['backdrop'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#value' => '',
      '#attributes' => [
        'class' => ['neo-ief-shelf__backdrop'],
        'data-neo-ief-shelf-dismiss' => 'backdrop',
      ],
    ];
    $element['panel'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['neo-ief-shelf__panel']],
      'header' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['neo-ief-shelf__header']],
        'heading' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['neo-ief-shelf__heading']],
          // Filled in by the behavior, which knows the shelves around it.
          'trail' => [
            '#type' => 'html_tag',
            '#tag' => 'p',
            '#value' => '',
            '#attributes' => ['class' => ['neo-ief-shelf__trail']],
          ],
          'title' => [
            '#type' => 'html_tag',
            '#tag' => 'h2',
            '#value' => $shelf['title'] ?? '',
            '#attributes' => [
              'id' => $title_id,
              'class' => ['neo-ief-shelf__title'],
            ],
          ],
        ],
        'close' => [
          '#type' => 'html_tag',
          '#tag' => 'button',
          '#value' => '<span class="neo-ief-shelf__close-icon" aria-hidden="true"></span>',
          '#attributes' => [
            'type' => 'button',
            'class' => ['neo-ief-shelf__close'],
            'aria-label' => new TranslatableMarkup('Close'),
            'title' => new TranslatableMarkup('Close'),
            'data-neo-ief-shelf-dismiss' => 'close',
          ],
        ],
      ],
      'body' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['neo-ief-shelf__body']],
      ] + $body,
      'footer' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['neo-ief-shelf__footer']],
        'hint' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $shelf['hint'] ?? '',
          '#attributes' => ['class' => ['neo-ief-shelf__hint']],
        ],
        'actions' => $actions,
      ],
    ];

    return $element;
  }

}
