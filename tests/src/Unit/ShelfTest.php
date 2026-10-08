<?php

declare(strict_types=1);

namespace Drupal\Tests\neo\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\neo\Shelf;
use PHPUnit\Framework\Attributes\Group;

/**
 * The render-time layout of a container carrying #neo_shelf.
 *
 * The shelf is presentation only, so what a builder relies on is the shape
 * preRender() leaves: its children in the body, its buttons in the footer,
 * the data attributes the behavior reads, and closed or open as asked. A
 * container without #neo_shelf, which is nearly every container, must come
 * back untouched, because the callback runs on all of them.
 */
#[Group('neo')]
final class ShelfTest extends UnitTestCase {

  /**
   * A container without #neo_shelf is left exactly as it was.
   */
  public function testPlainContainerIsUntouched(): void {
    $element = [
      '#type' => 'container',
      'child' => ['#markup' => 'Hello'],
    ];
    $this->assertSame($element, Shelf::preRender($element));
  }

  /**
   * Children go to the body, the first actions path found to the footer.
   */
  public function testChildrenAndActionsAreSplit(): void {
    $element = Shelf::preRender([
      '#type' => 'container',
      '#neo_shelf' => [
        'key' => 'test|1',
        'title' => 'Edit thing',
        'hint' => 'Saved with the page.',
        'actions' => [['missing'], ['form', 'actions']],
      ],
      'intro' => ['#markup' => 'Intro'],
      'form' => [
        'field' => ['#markup' => 'Field'],
        'actions' => ['done' => ['#markup' => 'Done']],
      ],
    ]);

    $this->assertSame(['backdrop', 'panel'], array_values(array_filter(
      array_keys($element),
      fn ($key) => !str_starts_with((string) $key, '#'),
    )));
    $body = $element['panel']['body'];
    $this->assertSame('Intro', $body['intro']['#markup']);
    $this->assertSame('Field', $body['form']['field']['#markup']);
    $this->assertArrayNotHasKey('actions', $body['form']);

    $footer = $element['panel']['footer'];
    $this->assertSame('Done', $footer['actions']['done']['#markup']);
    $this->assertContains('neo-shelf__actions', $footer['actions']['#attributes']['class']);
    $this->assertSame('Saved with the page.', $footer['hint']['#value']);
    $this->assertSame('Edit thing', $element['panel']['header']['heading']['title']['#value']);
    $this->assertContains('neo/shelf', $element['#attached']['library']);
  }

  /**
   * The behavior's data attributes, with empty optional ones left off.
   */
  public function testDataAttributes(): void {
    $element = Shelf::preRender([
      '#type' => 'container',
      '#neo_shelf' => [
        'key' => 'test|2',
        'section' => 'Sections',
        'label' => '',
        'confirm' => 'Discard?',
        'return' => ['edit', 'add', 'edit'],
        'owner' => 'wrapper-id',
      ],
    ]);
    $attributes = $element['#attributes'];

    $this->assertContains('neo-shelf', $attributes['class']);
    $this->assertNotContains('is-closed', $attributes['class']);
    $this->assertSame('test|2', $attributes['data-neo-shelf']);
    $this->assertSame('Sections', $attributes['data-neo-shelf-section']);
    $this->assertArrayNotHasKey('data-neo-shelf-label', $attributes);
    $this->assertSame('Discard?', $attributes['data-neo-shelf-confirm']);
    $this->assertSame('edit add', $attributes['data-neo-shelf-return']);
    $this->assertSame('wrapper-id', $attributes['data-neo-shelf-owner']);
    $this->assertSame(
      $attributes['data-neo-shelf-title'],
      $element['panel']['header']['heading']['title']['#attributes']['id'],
    );
  }

  /**
   * A shelf asked to render closed does, unless a field in it has an error.
   */
  public function testClosedUnlessAnError(): void {
    $closed = [
      '#type' => 'container',
      '#neo_shelf' => ['key' => 'test|3', 'open' => FALSE],
      'group' => ['field' => ['#type' => 'textfield']],
    ];
    $this->assertContains('is-closed', Shelf::preRender($closed)['#attributes']['class']);

    $invalid = $closed;
    $invalid['group']['field']['#errors'] = 'Required.';
    $this->assertNotContains('is-closed', Shelf::preRender($invalid)['#attributes']['class']);

    $invalid['#neo_shelf']['open_on_error'] = FALSE;
    $this->assertContains('is-closed', Shelf::preRender($invalid)['#attributes']['class']);
  }

}
