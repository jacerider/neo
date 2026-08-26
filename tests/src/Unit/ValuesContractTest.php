<?php

declare(strict_types=1);

namespace Drupal\Tests\neo\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\neo_test\NeoTestValues;
use PHPUnit\Framework\Attributes\Group;

/**
 * Characterises the values contract: `ValuesTrait` and `ValuesInterface`.
 *
 * Not one class in `neo` uses `ValuesTrait`. Every consumer in existence lives
 * in another package, which is why phpstan reports it `trait.unused` and why it
 * has never run under a test. `NeoTestValues` — shipped by the `neo_test`
 * fixture module — is the first consumer in this repository, and the whole
 * contract is reached through it.
 *
 * The trait is a six-method facade over
 * `\Drupal\Component\Utility\NestedArray`, and two of its methods return **by
 * reference**. That is the part a caller can get wrong, so it is most of what
 * these tests are about.
 *
 * Two of the ticket's criteria describe behaviour the code does not have. Both
 * are pinned here as they actually behave, named in the docblock of the test
 * that pins them, and reported for the backlog rather than repaired.
 */
#[Group('neo')]
final class ValuesContractTest extends UnitTestCase {

  /**
   * Returns a value by key and by nested key path, and the default if absent.
   *
   * Covers: "it returns a value by key and by nested key path, and returns the
   * default when the key is absent".
   *
   * A string key and a one-element array key are the same lookup, because
   * `getValue()` casts whatever it is handed to an array before passing it to
   * `NestedArray::getValue()`.
   *
   * The last assertion is the ambiguity `NestedArray` was given a
   * `$key_exists` out-parameter to resolve: a key that exists holding NULL
   * answers NULL, **not** the default, because the default is reached only
   * when the key is absent.
   */
  public function testReturnsValuesByKeyByNestedPathAndTheDefaultWhenAbsent(): void {
    $values = new NeoTestValues([
      'top' => 'one',
      'nest' => ['deeper' => ['leaf' => 'two']],
      'nullish' => NULL,
    ]);

    $this->assertSame('one', $values->getValue('top'));
    $this->assertSame('one', $values->getValue(['top']));
    $this->assertSame('two', $values->getValue(['nest', 'deeper', 'leaf']));
    $this->assertSame(['deeper' => ['leaf' => 'two']], $values->getValue('nest'));

    $this->assertNull($values->getValue('absent'));
    $this->assertSame('fallback', $values->getValue('absent', 'fallback'));
    $this->assertSame('fallback', $values->getValue(['nest', 'absent'], 'fallback'));
    $this->assertNull($values->getValue('nullish', 'fallback'));
  }

  /**
   * Misses leave the store alone; only a present key is writable by reference.
   *
   * Covers: "it writes the default into the store when a missing key is read,
   * and hands back a reference a caller can assign through".
   *
   * **The first half of that criterion does not hold, and is pinned here as it
   * actually behaves.** `getValue()` reads like it writes the default into the
   * store — it assigns `$value = $default` through a reference it took from
   * `NestedArray::getValue()`. It does not. When the key is absent
   * `NestedArray::getValue()` returns a reference to a function-local `$null`
   * and never touches the array it was handed, so the assignment lands in that
   * discarded local. The default is returned and immediately forgotten: the
   * store is unchanged, the key is still absent on the next read, and a caller
   * holding the returned reference cannot write through it either.
   *
   * The second half does hold, for a key that exists. `getValues()` returns the
   * store by reference, `NestedArray::getValue()` takes its array by reference
   * and walks it with `$ref = &$ref[$parent]`, so the value handed back is a
   * live reference into the consumer's own array and assigning through it
   * mutates the store. That is the trap: whether `&getValue()` is a window onto
   * the store or a dead copy depends on whether the key was there.
   *
   * Characterised, not repaired, per the ticket.
   */
  public function testMissesLeaveTheStoreAloneAndOnlyPresentKeysAreWritable(): void {
    $values = new NeoTestValues(['present' => 'before']);

    // The default comes back, but it is not written into the store.
    $this->assertSame('written?', $values->getValue('absent', 'written?'));
    $this->assertSame(['present' => 'before'], $values->getValues());
    $this->assertFalse($values->hasValue('absent'));
    $this->assertNull($values->getValue('absent'));

    // Nor can a caller write through the reference a missing read hands back.
    $missing = &$values->getValue('absent', 'written?');
    $missing = 'assigned';
    $this->assertSame(['present' => 'before'], $values->getValues());

    // A key that exists is a live reference into the store.
    $present = &$values->getValue('present');
    $present = 'after';
    $this->assertSame('after', $values->getValue('present'));
    $this->assertSame(['present' => 'after'], $values->getValues());
  }

  /**
   * Replaces the store outright through setValues, and returns the consumer.
   *
   * Covers: "it replaces the whole store through setValues and returns the
   * consumer for chaining".
   *
   * The replacement is total, not a merge: keys the previous store held and
   * the new array does not are gone. That is worth pinning because the method
   * is a reference assignment — `$existingValues = &$this->getValues()` and
   * then `$existingValues = $values` — which reads like it could be doing
   * something subtler than `$this->values = $values`, and does not.
   *
   * It returns `$this`, so it chains. The assertion is `assertSame()` rather
   * than `assertInstanceOf()`, because a fluent setter returning a *different*
   * instance is the failure this guards against.
   */
  public function testSetValuesReplacesTheWholeStoreAndReturnsTheConsumer(): void {
    $values = new NeoTestValues(['keep' => 'old', 'drop' => 'old']);

    $returned = $values->setValues(['keep' => 'new']);

    $this->assertSame($values, $returned);
    $this->assertSame(['keep' => 'new'], $values->getValues());
    $this->assertFalse($values->hasValue('drop'));

    // Chained through the returned consumer, and emptied outright.
    $this->assertSame([], $values->setValues([])->getValues());
  }

  /**
   * Forces missing parents into being on set; removes only the leaf on unset.
   *
   * Covers: "it creates missing parents when setting a nested key path and
   * removes a nested key on unset".
   *
   * The trait always passes `$force = TRUE` to `NestedArray::setValue()`, and
   * the caller has no say in it. That does two things worth pinning: it creates
   * every missing parent along the path, and it **replaces a scalar already
   * sitting in the path with an array** rather than raising an error. A store
   * holding `['scalar' => 'string']` therefore silently loses that string when
   * something writes to `['scalar', 'child']`.
   *
   * `unsetValue()` removes the leaf and stops there — the parents it walked
   * through are left behind, empty. Removing `['nest', 'deeper', 'leaf']` from
   * a store leaves `['nest' => ['deeper' => []]]`, not `[]`, so a caller
   * checking the parent with `hasValue()` still finds it. `isValueEmpty()` is
   * the one that answers what was probably meant.
   *
   * Both return `$this`.
   */
  public function testSetValueCreatesMissingParentsAndUnsetValueRemovesTheLeaf(): void {
    $values = new NeoTestValues(['scalar' => 'clobbered']);

    $this->assertSame($values, $values->setValue(['nest', 'deeper', 'leaf'], 'two'));
    $this->assertSame(['deeper' => ['leaf' => 'two']], $values->getValue('nest'));

    // A scalar in the path is forced into an array rather than raising.
    $values->setValue(['scalar', 'child'], 'forced');
    $this->assertSame(['child' => 'forced'], $values->getValue('scalar'));

    // A one-element path is the plain top-level set.
    $values->setValue('top', 'one');
    $this->assertSame('one', $values->getValue('top'));

    // Unset removes the leaf, and leaves the parents it walked through behind.
    $this->assertSame($values, $values->unsetValue(['nest', 'deeper', 'leaf']));
    $this->assertFalse($values->hasValue(['nest', 'deeper', 'leaf']));
    $this->assertSame(['deeper' => []], $values->getValue('nest'));
    $this->assertTrue($values->hasValue(['nest', 'deeper']));

    // Unsetting a path that was never there is a no-op, not an error.
    $before = $values->getValues();
    $values->unsetValue(['never', 'was', 'here']);
    $this->assertSame($before, $values->getValues());
  }

  /**
   * Neither predicate distinguishes an absent key from one holding NULL.
   *
   * Covers: "it distinguishes a key that is absent from a key that exists
   * holding null, in hasValue and in isValueEmpty".
   *
   * **The criterion does not hold, and the collapse is pinned here as it
   * actually behaves.** Both methods ask `NestedArray::getValue()` for the
   * `$key_exists` out-parameter that exists precisely to tell those two cases
   * apart, and both then throw the distinction away by combining it with a
   * value check:
   *
   * - `hasValue()` is `$exists && isset($value)`, and `isset()` is FALSE for
   *   NULL — so a key holding NULL answers FALSE, exactly as an absent key
   *   does. That is `isset()` semantics, not `array_key_exists()` semantics,
   *   despite the interface documenting it as "TRUE if the $key is set".
   * - `isValueEmpty()` is `!$exists || empty($value)` — so a key holding NULL
   *   answers TRUE, exactly as an absent key does.
   *
   * The two predicates therefore agree about NULL and neither can see it. The
   * only way to tell the cases apart through this contract is to read the store
   * itself, which is what the last two assertions do.
   *
   * Characterised, not repaired, per the ticket.
   */
  public function testHasValueAndIsValueEmptyCollapseAbsentIntoNull(): void {
    $values = new NeoTestValues(['nullish' => NULL, 'set' => 'x']);

    $this->assertFalse($values->hasValue('nullish'));
    $this->assertFalse($values->hasValue('absent'));
    $this->assertTrue($values->isValueEmpty('nullish'));
    $this->assertTrue($values->isValueEmpty('absent'));

    // A nested NULL and a nested absence collapse the same way.
    $values->setValue(['nest', 'nullish'], NULL);
    $this->assertFalse($values->hasValue(['nest', 'nullish']));
    $this->assertFalse($values->hasValue(['nest', 'absent']));
    $this->assertTrue($values->isValueEmpty(['nest', 'nullish']));
    $this->assertTrue($values->isValueEmpty(['nest', 'absent']));

    // A key holding a value answers the other way in both.
    $this->assertTrue($values->hasValue('set'));
    $this->assertFalse($values->isValueEmpty('set'));

    // The store is the only place the distinction survives.
    $this->assertArrayHasKey('nullish', $values->getValues());
    $this->assertArrayNotHasKey('absent', $values->getValues());
  }

  /**
   * An empty string, an empty array and zero are empty but still present.
   *
   * Covers: "it reports a key holding an empty string, an empty array and zero
   * as empty while still reporting it as present".
   *
   * This is the pair of predicates behaving as their names promise, and it is
   * the reason both exist: `hasValue()` answers "is there something under this
   * key", `isValueEmpty()` answers "is what is under it worth anything". For
   * every value PHP calls falsy except NULL the two disagree, and a caller
   * picking the wrong one gets the wrong answer for a legitimately stored `0`
   * or `''`.
   *
   * `'0'` and `FALSE` behave the same way and are asserted alongside them,
   * because `empty()` is what decides and `empty()` does not tell them apart.
   * NULL is the one exception, and it is
   * `testHasValueAndIsValueEmptyCollapseAbsentIntoNull()`'s subject.
   */
  public function testAnEmptyStringAnEmptyArrayAndZeroAreEmptyButPresent(): void {
    $values = new NeoTestValues([
      'empty_string' => '',
      'empty_array' => [],
      'zero' => 0,
      'zero_string' => '0',
      'false' => FALSE,
    ]);

    foreach (['empty_string', 'empty_array', 'zero', 'zero_string', 'false'] as $key) {
      $this->assertTrue($values->hasValue($key), "hasValue() reports '$key' as present");
      $this->assertTrue($values->isValueEmpty($key), "isValueEmpty() reports '$key' as empty");
    }

    // The stored values themselves are handed back untouched.
    $this->assertSame('', $values->getValue('empty_string'));
    $this->assertSame([], $values->getValue('empty_array'));
    $this->assertSame(0, $values->getValue('zero'));

    // And the default is not reached for any of them, because they exist.
    $this->assertSame(0, $values->getValue('zero', 'fallback'));

    // The same holds down a nested path.
    $values->setValue(['nest', 'zero'], 0);
    $this->assertTrue($values->hasValue(['nest', 'zero']));
    $this->assertTrue($values->isValueEmpty(['nest', 'zero']));
  }

}
