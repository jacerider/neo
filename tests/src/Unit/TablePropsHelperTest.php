<?php

declare(strict_types=1);

namespace Drupal\Tests\neo\Unit;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Tests\UnitTestCase;
use Drupal\neo\Helpers\TableProps;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the table props against the answers the global gave.
 *
 * The **table props** are the presentation keys `neo` adds to a Views table
 * style: one per column select in the Views UI, one per class the table
 * preprocessor stamps, and one entry each in the `views.style.table` config
 * schema. Their body used to be the `neo_table_props()` global in
 * `neo.module`, which is why a class-based hook had to reach back into a
 * `.module` file to read them.
 *
 * The static is pure — no argument, no container, no config — so the whole
 * fixture is a call and an array.
 *
 * **The ticket says three props and there are four.** `neo_style`, `neo_size`
 * and `neo_align` are the three the spec was written against; `neo_sticky` was
 * added to the same array on 2026-08-25 in `ed76673`, a day before the spec was
 * written and after it was scanned. The move is verbatim, so all four are
 * pinned here — dropping the fourth to match the prose would be the one thing
 * this ticket is trying not to do, since `neo_theme` reads the array by key.
 */
#[Group('neo')]
class TablePropsHelperTest extends UnitTestCase {

  /**
   * It returns the table prop keys with their labels and option maps.
   *
   * Acceptance criterion: it returns the three table prop keys with their
   * labels and option maps, from a static that takes nothing and reaches no
   * container service.
   */
  public function testReturnsTheTablePropKeysWithTheirLabelsAndOptionMaps(): void {
    // UnitTestCase::setUp() unsets the container, so a static that reached one
    // would fatal rather than merely be impure.
    $this->assertFalse(\Drupal::hasContainer(), 'No container is in play.');

    $method = new \ReflectionMethod(TableProps::class, 'get');
    $this->assertTrue($method->isStatic(), 'The props come from a static.');
    $this->assertTrue($method->isPublic(), 'The static is public.');
    $this->assertCount(0, $method->getParameters(), 'The static takes nothing.');

    $props = TableProps::get();
    $this->assertSame([
      'neo_style',
      'neo_size',
      'neo_align',
      'neo_sticky',
    ], array_keys($props), 'Every table prop key survives the move.');

    $labels = array_map(
      static fn (array $prop) => $prop['label']->getUntranslatedString(),
      $props
    );
    $this->assertSame([
      'neo_style' => 'Neo Style',
      'neo_size' => 'Neo Size',
      'neo_align' => 'Neo Align',
      'neo_sticky' => 'Neo Sticky',
    ], $labels, 'Every table prop keeps its label.');

    $options = array_map(
      static fn (array $prop) => array_map(
        static fn (TranslatableMarkup $option) => $option->getUntranslatedString(),
        $prop['options']
      ),
      $props
    );
    $this->assertSame([
      'neo_style' => [
        'default' => 'Default',
        'heading' => 'Heading',
        'sm' => 'Small',
        'xs' => 'Extra Small',
      ],
      'neo_size' => [
        'default' => 'Default',
        'min' => 'Minimum',
      ],
      // Align carries no fixed map: the Views UI select it feeds is built from
      // an empty options array, which is why the two preprocessors that filter
      // on a non-empty map skip it.
      'neo_align' => [],
      'neo_sticky' => [
        'none' => 'None',
        'left' => 'Left',
        'right' => 'Right',
      ],
    ], $options, 'Every table prop keeps its option map.');

    // Sticky is the one prop the table preprocessor must not stamp: its class
    // is computed downstream by neo_base once every column has been seen.
    $this->assertFalse($props['neo_sticky']['apply'], 'Sticky is still not applied here.');
    foreach (['neo_style', 'neo_size', 'neo_align'] as $key) {
      $this->assertArrayNotHasKey('apply', $props[$key], "$key still carries no apply flag.");
    }

    $this->assertFalse(\Drupal::hasContainer(), 'The static reached no container service.');
  }

  /**
   * It builds its labels as translatable markup rather than through t().
   *
   * Acceptance criterion: it builds its labels as translatable markup rather
   * than through `t()`.
   *
   * Core's `t()` is one line returning `new TranslatableMarkup(...)`, so the
   * two are indistinguishable at runtime and the instance check below says
   * only that the labels are translatable at all. What separates them is the
   * source:
   * a static has no `$this->t()` and a bare `t()` in a class is the phpcs
   * warning `FormAlterHook` already carries, so the reason this move did not
   * simply copy the body across is that the copy would have brought that
   * warning into a new file.
   */
  public function testBuildsItsLabelsAsTranslatableMarkupRatherThanThroughT(): void {
    foreach (TableProps::get() as $key => $prop) {
      $this->assertInstanceOf(TranslatableMarkup::class, $prop['label'], "$key's label is translatable markup.");
      foreach ($prop['options'] as $option => $label) {
        $this->assertInstanceOf(TranslatableMarkup::class, $label, "$key's $option label is translatable markup.");
      }
    }

    $body = $this->body(new \ReflectionMethod(TableProps::class, 'get'));
    $this->assertMatchesRegularExpression('/new TranslatableMarkup\(/', $body, 'The labels are constructed directly.');
    $this->assertDoesNotMatchRegularExpression('/(?<![\w>$])t\(/', $body, 'Nothing in the helper calls t().');
  }

  /**
   * Reads a method's source, so how the labels are built can be asserted.
   *
   * @param \ReflectionMethod $method
   *   The method to read.
   *
   * @return string
   *   The declaration and body, as written.
   */
  private function body(\ReflectionMethod $method): string {
    $lines = file($method->getFileName());
    $start = $method->getStartLine() - 1;
    return implode('', array_slice($lines, $start, $method->getEndLine() - $start));
  }

}
