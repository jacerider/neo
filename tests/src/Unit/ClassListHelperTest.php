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
 * Every expectation here was written as TODAY's answer, defects included, so
 * that the two tickets which change one would have to say so. Ticket 02 kept
 * every one of them, which is what said deleting the dead guard changed
 * nothing; ticket 03 moved the two that pinned NULL for an invalid list, and
 * only those two.
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
   * `array_filter($list, 'strlen')` is what kept the 0 -- a legal CSS class
   * name that a bare array_filter() would silently eat. Ticket 02 replaced the
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
   * The invalid list used to come back as NULL, and one caller does
   * `['' => '- Select -'] + $list` unguarded, so that was a white screen on
   * every node form carrying that field. Ticket 03 made it an empty array; the
   * list is still invalidated, only the empty value callers receive changed.
   */
  public function testAnOverLongBareLineInvalidatesTheListAndAnEmptyStringIsAnEmptyList(): void {
    $long = str_repeat('a', 256);
    $this->assertSame([], ClassList::parse($long));
    $this->assertSame([], ClassList::parse(''));
    // The regex branch runs first, so the same over-long text carrying a pipe
    // is never offered to the rule at all and the list stands.
    $this->assertSame([$long => 'Long'], ClassList::parse($long . '|Long'));
  }

  /**
   * One offending line still discards the whole list, not only itself.
   *
   * This is the half of the invalid-list semantics that did NOT change. A
   * defensible parser would drop the bad line and keep the good ones, but that
   * is a behaviour change on every site with no bug behind it, so the list is
   * still abandoned wholesale -- it just comes back empty rather than NULL.
   */
  public function testAnOffendingLineDiscardsTheWholeListNotOnlyItself(): void {
    $long = str_repeat('a', 256);
    $this->assertSame([], ClassList::parse("primary|Primary\n" . $long));
    $this->assertSame([], ClassList::parse($long . "\nprimary|Primary"));
  }

  /**
   * A whitespace-only list is an empty list, exactly as an empty string is.
   *
   * Neither reaches the class key rule -- every line is dropped before the
   * loop, so no line is ever offered as a key -- which is what makes this the
   * regression pin for the empty return rather than a second reading of it.
   */
  public function testWhitespaceOnlyAndEmptyStringsAreBothEmptyLists(): void {
    $this->assertSame([], ClassList::parse(''));
    $this->assertSame([], ClassList::parse("   \n\t\n  "));
  }

  /**
   * The result unions with a placeholder options array, which NULL could not.
   *
   * This is the fatal the plan exists to remove, written as the caller writes
   * it. `['' => '- Select -'] + NULL` throws `Unsupported operand types:
   * array + null` while the link widget builds its Style select, so every node
   * form carrying that field was a white screen until an administrator edited
   * the form display. With an empty list the select renders with only its
   * placeholder.
   */
  public function testTheResultCanBeUnionedWithPlaceholderOptions(): void {
    $placeholder = ['' => '- Select -'];
    $long = str_repeat('a', 256);

    $this->assertSame($placeholder, $placeholder + ClassList::parse($long));
    $this->assertSame($placeholder, $placeholder + ClassList::parse(''));
    $this->assertSame(
      $placeholder + ['primary' => 'Primary'],
      $placeholder + ClassList::parse('primary|Primary')
    );
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
    $this->assertSame([], ClassList::parse('abcd', $tight));

    // And a looser rule accepts a key the built-in 255 would have rejected,
    // which is what says the built-in is not consulted as well.
    $loose = static fn ($option) => NULL;
    $long = str_repeat('a', 256);
    $this->assertSame([$long => $long], ClassList::parse($long, $loose));
  }

}
