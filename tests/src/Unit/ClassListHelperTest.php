<?php

declare(strict_types=1);

namespace Drupal\Tests\neo\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\neo\Helpers\ClassList;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the class list parser against the answers it gives today.
 *
 * The parse used to live twice — once in the link widget's getClassList(),
 * once in neo_menu_link's settings class — and neither copy had ever been
 * reached by a test, because neither is callable without a field-widget plugin
 * or the settings repository around it. The static is pure, so the whole
 * fixture is a string in and an array out: no container, no config, no entity.
 *
 * Every expectation here is TODAY's answer, defects included. Two of them are
 * about to move: an invalid list returning NULL rather than an empty list, and
 * a list mixing key|label lines with bare lines being accepted in full. They
 * are pinned here precisely so the tickets that change them have to say so.
 */
#[Group('neo')]
class ClassListHelperTest extends UnitTestCase {

  /**
   * An explicit key|label line yields that pair, with both sides trimmed.
   */
  public function testExplicitKeysAreTrimmed(): void {
    $this->assertSame([
      'primary' => 'Primary',
      'secondary' => 'Secondary',
    ], ClassList::parse("primary|Primary\n  secondary  |  Secondary  "));
  }

  /**
   * A bare line is its own label, and the split lands on the last pipe.
   *
   * The acceptance criterion for this one says "splits on the first pipe so a
   * further pipe stays in the label". It does not: `/(.*)\|(.*)/` is greedy on
   * the left, so it splits on the LAST pipe and the further pipe stays in the
   * KEY. This ticket moves the body verbatim and changes nothing about what it
   * does, so today's answer is what is pinned. Core's own allowedValuesString()
   * uses the identical pattern and behaves the identical way.
   */
  public function testBareLineIsItsOwnLabelAndTheFurtherPipeStaysWithTheKey(): void {
    $this->assertSame(['secondary' => 'secondary'], ClassList::parse('secondary'));
    $this->assertSame(['is|striped' => 'Striped'], ClassList::parse('is|striped|Striped'));
  }

  /**
   * A list mixing explicit and bare lines keeps every entry.
   *
   * This is one of the two answers about to change. The dead $generated_keys
   * guard reads as though a mixed list were rejected; it is not, because
   * nothing ever sets $generated_keys. Ticket 02 deletes the guard, and this
   * assertion is what says the deletion changed nothing.
   */
  public function testMixedListReturnsEveryEntry(): void {
    $this->assertSame([
      'primary' => 'Primary',
      'secondary' => 'secondary',
      'tertiary' => 'Tertiary',
    ], ClassList::parse("primary|Primary\nsecondary\ntertiary|Tertiary"));
  }

  /**
   * Blank and whitespace-only lines drop out; a line reading 0 does not.
   *
   * `array_filter($list, 'strlen')` is what keeps the 0 -- a legal CSS class
   * name that a bare array_filter() would silently eat. Ticket 03 replaces the
   * string function with an explicit test, and this is the pin that says the
   * 0 survived it.
   */
  public function testBlankLinesAreDroppedAndTheLineReadingZeroIsKept(): void {
    $this->assertSame([
      'primary' => 'Primary',
      '0' => '0',
      'secondary' => 'Secondary',
    ], ClassList::parse("primary|Primary\n\n   \n0\n\t\nsecondary|Secondary"));
  }

  /**
   * An over-long bare line invalidates the list; an empty string is no list.
   *
   * The NULL is the second answer about to change: one caller does
   * `['' => '- Select -'] + $list` unguarded, so today this is a white screen
   * on every node form carrying that field. Ticket 03 makes it an empty array.
   */
  public function testAnOverLongBareLineInvalidatesTheListAndAnEmptyStringIsAnEmptyList(): void {
    $long = str_repeat('a', 256);
    $this->assertNull(ClassList::parse($long));
    $this->assertSame([], ClassList::parse(''));
    // The regex branch runs first, so the same over-long text carrying a pipe
    // is never offered to the rule at all and the list stands.
    $this->assertSame([$long => 'Long'], ClassList::parse($long . '|Long'));
  }

  /**
   * A caller-supplied rule is consulted in place of the built-in one.
   *
   * Both surfaces hand in their own validateClassListValue(), which is the
   * only shape in which a site that has subclassed either of them to tighten
   * the class key rule still governs what parses.
   */
  public function testCallerSuppliedRuleReplacesTheBuiltInOne(): void {
    $tight = static fn ($option) => mb_strlen($option) > 3 ? 'too long' : NULL;
    $this->assertSame(['abc' => 'abc'], ClassList::parse('abc', $tight));
    $this->assertNull(ClassList::parse('abcd', $tight));

    // And a looser rule accepts a key the built-in 255 would have rejected,
    // which is what says the built-in is not consulted as well.
    $loose = static fn ($option) => NULL;
    $long = str_repeat('a', 256);
    $this->assertSame([$long => $long], ClassList::parse($long, $loose));
  }

}
