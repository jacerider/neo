<?php

declare(strict_types=1);

namespace Drupal\Tests\neo\Unit;

use Drupal\Component\Utility\NestedArray as CoreNestedArray;
use Drupal\Tests\UnitTestCase;
use Drupal\neo\Helpers\NestedArray;
use PHPUnit\Framework\Attributes\Group;

/**
 * Characterises `Helpers\NestedArray`, the second of the two pure Neo helpers.
 *
 * It extends core's nested-array utility with four methods the rest of the
 * stack merges settings, compares defaults and normalises keys through — the
 * strict deep merge in its two forms, the keyed deep intersect, the deep diff
 * and the camel-case key walk. None had ever been asserted.
 *
 * Every method here is `static` and pure: no container, no service, no state
 * that survives a call. The one exception is `keysToCamel()`, which routes
 * each key through `Helpers\Str::camel()` and therefore writes into that
 * class's two unresettable static caches — keyed by input, so nothing leaks
 * between cases, but see `StrHelperTest` before assuming a cache is empty.
 *
 * Characterised, not repaired. Seven answers pinned here are quirks rather
 * than intentions, each named in the docblock of the test that pins it:
 *
 * 1. **`mergeDeepStrict()`'s own docblock example is wrong.** It was copied
 *    from core's `mergeDeep()` and still promises the concatenated
 *    `['a', 'b', 'c', 'd']` class list, which is precisely the behaviour this
 *    method exists to prevent. The real answer is `['c', 'd']`.
 * 2. **The list rule reads only the incoming value's first key**, so it is
 *    one-directional and shape-sensitive: a keyed array arriving *after* a
 *    list merges into it rather than replacing it, and a mixed array whose
 *    first key happens to be an integer is treated as a list.
 * 3. **Null-stripping only reaches levels the merge recursed into.** A null
 *    inside an array that was copied rather than merged survives, even at the
 *    flag's default.
 * 4. **`insersectKeyDeepArray()` is misspelled**, and its forcing loop is
 *    **duplicated verbatim** — once in `forceArrayValue()` and once inline in
 *    the caller. Removing either copy changes nothing.
 * 5. **A double-underscore key does not itself survive the intersection**
 *    unless the second array independently carries it, which is the opposite
 *    of what "the prefixed key is copied onto both arrays" invites a reader
 *    to expect.
 * 6. **Forcing overwrites an unprefixed key the first array already had.**
 * 7. **`diffDeep()`'s `is_null()` clause is dead.** `gettype()` returns
 *    `'NULL'` and nothing else does, so the type clause beside it already
 *    catches every null-against-non-null case on its own.
 */
#[Group('neo')]
final class NestedArrayHelperTest extends UnitTestCase {

  /**
   * Replaces a scalar with a later scalar; recurses into two keyed arrays.
   *
   * Covers: "it replaces a scalar with a later scalar and recurses into two
   * keyed arrays".
   *
   * This is the whole reason the method exists. `array_merge_recursive()`
   * turns two scalars under one key into a list of both; this one takes the
   * later value. Where both sides are keyed arrays it recurses instead, so a
   * key only the earlier array carries survives the merge.
   *
   * Both entry points are asserted against the same expectation.
   * `mergeDeepStrict()` is variadic and forwards `func_get_args()` straight
   * into `mergeDeepArrayStrict()`, so they are the same function wearing two
   * signatures — with one consequence: the variadic form has **no way to pass
   * the null-stripping flag**, because a third argument would be read as a
   * third array to merge.
   *
   * The last block is the instrument: core's own `mergeDeep()` on the same
   * input recurses the same way, so recursion into keyed arrays is the half of
   * the contract this class inherits rather than the half it changes.
   */
  public function testReplacesScalarsWithLaterScalarsAndRecursesIntoTwoKeyedArrays(): void {
    $first = ['fragment' => 'x', 'attributes' => ['title' => 'X', 'id' => 'one']];
    $second = ['fragment' => 'y', 'attributes' => ['title' => 'Y']];
    $expected = ['fragment' => 'y', 'attributes' => ['title' => 'Y', 'id' => 'one']];

    $this->assertSame($expected, NestedArray::mergeDeepStrict($first, $second));
    $this->assertSame($expected, NestedArray::mergeDeepArrayStrict([$first, $second]));

    // More than two arrays: each pass overrides the accumulated result, so the
    // last writer of a key wins and every key seen along the way survives.
    $this->assertSame(
      ['a' => 2, 'b' => 4, 'c' => 5],
      NestedArray::mergeDeepStrict(['a' => 1], ['a' => 2, 'b' => 3], ['b' => 4, 'c' => 5])
    );

    // A scalar replaces an array and an array replaces a scalar, because
    // neither pair reaches the recursion branch.
    $this->assertSame(['a' => 'scalar'], NestedArray::mergeDeepStrict(['a' => ['k' => 1]], ['a' => 'scalar']));
    $this->assertSame(['a' => ['k' => 1]], NestedArray::mergeDeepStrict(['a' => 'scalar'], ['a' => ['k' => 1]]));

    // The instrument: core recurses into keyed arrays too. That half is
    // inherited; the list rule below is what this class adds.
    $this->assertSame($expected, CoreNestedArray::mergeDeep($first, $second));
  }

  /**
   * Replaces a list-shaped array wholesale instead of merging it.
   *
   * Covers: "it replaces a list-shaped array wholesale instead of merging it".
   *
   * The rule core has no equivalent for: when the incoming value is an array
   * whose first key is an integer, it is assigned straight over whatever was
   * there. A list of classes therefore does not accumulate.
   *
   * The first instrument block is core's `mergeDeep()` on the same input,
   * which concatenates — `[1, 2, 3, 9]` against this class's `[9]`.
   *
   * Two quirks pinned as they behave, not repaired:
   *
   * 1. **The method's own docblock example is wrong.** It promises
   *    `['a', 'b', 'c', 'd']` for the `class` key, which is core's answer and
   *    the exact behaviour this method was written to prevent. The real answer
   *    is `['c', 'd']`.
   * 2. **The test is `is_int(key($value))` on the incoming value only**, so it
   *    is one-directional and reads a single key. A keyed array arriving after
   *    a list merges *into* it rather than replacing it; an array whose first
   *    key is an integer is a list even when later keys are strings; and an
   *    empty array is not a list at all, because `key([])` is `NULL`.
   */
  public function testReplacesListShapedArraysWholesaleInsteadOfMergingThem(): void {
    $this->assertSame(['x' => [9]], NestedArray::mergeDeepStrict(['x' => [1, 2, 3]], ['x' => [9]]));

    // The instrument: core concatenates the very same input.
    $this->assertSame(
      ['x' => [1, 2, 3, 9]],
      CoreNestedArray::mergeDeep(['x' => [1, 2, 3]], ['x' => [9]])
    );

    // A list also replaces a keyed array outright.
    $this->assertSame(['x' => [9]], NestedArray::mergeDeepStrict(['x' => ['k' => 1]], ['x' => [9]]));

    // Quirk 1: the docblock's own example, answered as the code answers it.
    $one = ['fragment' => 'x', 'attributes' => ['title' => 'X', 'class' => ['a', 'b']]];
    $two = ['fragment' => 'y', 'attributes' => ['title' => 'Y', 'class' => ['c', 'd']]];
    $this->assertSame(
      ['fragment' => 'y', 'attributes' => ['title' => 'Y', 'class' => ['c', 'd']]],
      NestedArray::mergeDeepStrict($one, $two)
    );

    // Quirk 2a: one-directional. The keyed array arrives second, so the list
    // rule never fires and the two are merged by key instead.
    $this->assertSame(
      ['x' => [0 => 1, 1 => 2, 'k' => 1]],
      NestedArray::mergeDeepStrict(['x' => [1, 2]], ['x' => ['k' => 1]])
    );

    // Quirk 2b: only the *first* key is read, so a mixed array is a list when
    // it opens with an integer and is merged when it opens with a string.
    $this->assertSame(
      ['x' => [0 => 'a', 'k' => 2]],
      NestedArray::mergeDeepStrict(['x' => ['k' => 1]], ['x' => [0 => 'a', 'k' => 2]])
    );
    $this->assertSame(
      ['x' => ['k' => 2, 0 => 'a']],
      NestedArray::mergeDeepStrict(['x' => ['k' => 1]], ['x' => ['k' => 2, 0 => 'a']])
    );

    // Quirk 2c: `key([])` is NULL, so an empty array is not list-shaped and
    // merges — which is why it cannot be used to clear a key.
    $this->assertSame(['x' => ['k' => 1]], NestedArray::mergeDeepStrict(['x' => ['k' => 1]], ['x' => []]));
    $this->assertSame(['x' => ['k' => 1]], NestedArray::mergeDeepStrict(['x' => []], ['x' => ['k' => 1]]));
  }

  /**
   * Strips null values by default and keeps them when told to.
   *
   * Covers: "it strips null values by default and keeps them when told to".
   *
   * `$remove_null` defaults to TRUE, so the merge ends in an `array_filter()`
   * that drops every null. The consequence callers rely on is that a later
   * array setting a key to NULL **removes** that key rather than overriding it
   * with NULL — which is how a Neo settings form unsets a value it inherited.
   *
   * The flag is forwarded into every recursive call, so it reaches nested
   * arrays the merge descends into.
   *
   * Two things pinned as they behave, not repaired:
   *
   * 1. **The variadic `mergeDeepStrict()` cannot turn the flag off**, because
   *    a third argument is read as a third array to merge. Only the
   *    array-argument form exposes it.
   * 2. **Stripping only reaches levels the merge actually recursed into.** An
   *    array copied through the "use the latter value" branch — which is what
   *    happens when only one array carries the key — is stored as it arrived,
   *    nulls included, and the top-level filter sees only that the array
   *    itself is not null.
   */
  public function testStripsNullValuesByDefaultAndKeepsThemWhenToldTo(): void {
    // A later NULL removes the key rather than overriding it with NULL.
    $this->assertSame(['a' => 1], NestedArray::mergeDeepStrict(['a' => 1, 'b' => 2], ['b' => NULL]));

    // A NULL nobody overrides is dropped just the same.
    $this->assertSame(['a' => 1], NestedArray::mergeDeepStrict(['a' => 1, 'b' => NULL]));

    // Told to keep them, the key survives carrying NULL.
    $this->assertSame(
      ['a' => 1, 'b' => NULL],
      NestedArray::mergeDeepArrayStrict([['a' => 1, 'b' => 2], ['b' => NULL]], FALSE)
    );

    // The flag reaches recursion, in both settings.
    $this->assertSame(
      ['x' => ['a' => 1]],
      NestedArray::mergeDeepStrict(['x' => ['a' => 1, 'b' => 2]], ['x' => ['b' => NULL]])
    );
    $this->assertSame(
      ['x' => ['a' => 1, 'b' => NULL]],
      NestedArray::mergeDeepArrayStrict([['x' => ['a' => 1, 'b' => 2]], ['x' => ['b' => NULL]]], FALSE)
    );

    // An array whose value is NULL is removed outright, so a later NULL clears
    // a whole subtree rather than emptying it.
    $this->assertSame([], NestedArray::mergeDeepStrict(['a' => ['k' => 1]], ['a' => NULL]));

    // Quirk 2: no recursion happened here, so the nested NULL survives the
    // default flag — the filter only ever sees the top level of each call.
    $this->assertSame(
      ['x' => ['a' => 1, 'b' => NULL]],
      NestedArray::mergeDeepStrict(['x' => ['a' => 1, 'b' => NULL]])
    );
    // Same array reached through a merge instead: now it is stripped.
    $this->assertSame(
      ['x' => ['a' => 1]],
      NestedArray::mergeDeepStrict(['x' => ['a' => 1, 'b' => NULL]], ['x' => ['a' => 1]])
    );
  }

  /**
   * Intersects on keys recursively and forces a double-underscore key through.
   *
   * Covers: "it intersects on keys recursively and copies a double-underscore
   * key onto both sides before intersecting".
   *
   * `insersectKeyDeepArray()` keeps only the keys the second array has, takes
   * the **first** array's values, and recurses while both sides are still
   * arrays. The moment the second side stops being an array the first array's
   * value is kept whole, unexamined.
   *
   * The forcing rule is the escape hatch: a key prefixed `__` is copied onto
   * *both* arrays under its unprefixed name before `array_intersect_key()`
   * runs, so a value can be pushed through an intersection that would
   * otherwise drop it.
   *
   * Four quirks pinned as they behave, not repaired:
   *
   * 1. **The method name is misspelled** — `insersect`, not `intersect`. It is
   *    a public static on a public class, so the typo is API.
   * 2. **The forcing loop is duplicated verbatim.** `forceArrayValue()` is
   *    called on the first line and its body is then repeated inline. The
   *    second pass is a no-op, so deleting either copy changes no answer here.
   * 3. **The prefixed key does not itself survive**, contrary to what "copied
   *    onto both arrays" invites. `forceArrayValue()` writes the *unprefixed*
   *    name onto the second array, never the prefixed one, so `__f` is dropped
   *    by the intersection unless the second array independently carries it.
   * 4. **Forcing overwrites an unprefixed key the first array already had**,
   *    so `__f` beside `f` is not "a default for `f`" — it replaces it.
   */
  public function testIntersectsOnKeysRecursivelyAndCopiesDoubleUnderscoreKeysOntoBothSides(): void {
    // Keys absent from the second array are dropped; the first array's values
    // are what survive, so the second array is a key mask and nothing more.
    $this->assertSame(
      ['a' => 1, 'c' => 3],
      NestedArray::insersectKeyDeepArray(['a' => 1, 'b' => 2, 'c' => 3], ['a' => 'ignored', 'c' => NULL])
    );

    // Recursion while both sides are arrays.
    $this->assertSame(
      ['a' => ['x' => 1]],
      NestedArray::insersectKeyDeepArray(['a' => ['x' => 1, 'y' => 2], 'b' => 3], ['a' => ['x' => 'ignored']])
    );

    // Unbounded depth.
    $this->assertSame(
      ['a' => ['b' => ['c' => 1]]],
      NestedArray::insersectKeyDeepArray(
        ['a' => ['b' => ['c' => 1, 'd' => 2], 'e' => 3]],
        ['a' => ['b' => ['c' => NULL]]]
      )
    );

    // The second side stops being an array: the first array's value is kept
    // whole rather than intersected against a scalar.
    $this->assertSame(
      ['a' => ['x' => 1]],
      NestedArray::insersectKeyDeepArray(['a' => ['x' => 1]], ['a' => 'scalar'])
    );

    // Lists are intersected by index like any other keyed array.
    $this->assertSame(['a' => [0 => 1]], NestedArray::insersectKeyDeepArray(['a' => [1, 2, 3]], ['a' => [9]]));

    // Forcing: `__f` puts `f` on both sides, so `f` survives a mask that never
    // mentioned it.
    $this->assertSame(
      ['b' => 2, 'f' => 'forced'],
      NestedArray::insersectKeyDeepArray(['__f' => 'forced', 'b' => 2], ['b' => 2])
    );
    $this->assertSame(['f' => 'forced'], NestedArray::insersectKeyDeepArray(['__f' => 'forced'], []));

    // Forcing works at any depth, because every recursive call runs it again.
    $this->assertSame(
      ['a' => ['f' => 'forced']],
      NestedArray::insersectKeyDeepArray(['a' => ['__f' => 'forced', 'z' => 1]], ['a' => []])
    );

    // The forced value can itself be an array, and is copied by value.
    $this->assertSame(
      ['f' => ['x' => 1]],
      NestedArray::insersectKeyDeepArray(['__f' => ['x' => 1]], [])
    );

    // Quirk 3: `__f` is dropped by the intersection — only `f` was written
    // onto the second array...
    $this->assertArrayNotHasKey(
      '__f',
      NestedArray::insersectKeyDeepArray(['__f' => 'forced', 'b' => 2], ['b' => 2])
    );
    // ...and survives only when the second array carries `__f` on its own,
    // in which case the first array's value wins for both names.
    $this->assertSame(
      ['__f' => 'forced', 'f' => 'forced'],
      NestedArray::insersectKeyDeepArray(['__f' => 'forced'], ['__f' => 'other'])
    );

    // Quirk 4: forcing replaces the first array's own `f`, it does not defer
    // to it.
    $this->assertSame(
      ['f' => 'forced'],
      NestedArray::insersectKeyDeepArray(['__f' => 'forced', 'f' => 'original'], ['f' => 'other'])
    );
  }

  /**
   * Reports a difference for a changed value, a null, and a differing type.
   *
   * Covers: "it reports a difference for a changed value, a null against a
   * non-null, and two values of different types".
   *
   * `diffDeep()` returns only what differs, and its comparison is deliberately
   * stricter than `!=`: a value also differs when one side is null and the
   * other is not, or when the two have different PHP types. That is what every
   * Neo settings form depends on to decide what to store — without the type
   * clause, a `'1'` a form posted would compare equal to the `1` in a default
   * and would never be written.
   *
   * The values returned are the **first** array's, so the result reads as
   * "what this array says that the other one does not".
   *
   * One quirk pinned as it behaves, not repaired: **the `is_null()` clause is
   * dead code.** `gettype()` returns `'NULL'` for null and for nothing else,
   * so the type clause beside it already fires on every null-against-non-null
   * pair. Removing the null clause changes no answer; removing the type clause
   * changes several.
   */
  public function testReportsDifferencesForChangedValuesNullsAndDifferingTypes(): void {
    // A changed value, and only that key.
    $this->assertSame(['b' => 2], NestedArray::diffDeep(['a' => 1, 'b' => 2], ['a' => 1, 'b' => 3]));

    // Null against a non-null, in both directions, where `!=` sees nothing.
    $this->assertSame(['a' => NULL], NestedArray::diffDeep(['a' => NULL], ['a' => '']));
    $this->assertSame(['a' => ''], NestedArray::diffDeep(['a' => ''], ['a' => NULL]));
    $this->assertSame(['a' => NULL], NestedArray::diffDeep(['a' => NULL], ['a' => 0]));

    // Null against null is not a difference.
    $this->assertSame([], NestedArray::diffDeep(['a' => NULL], ['a' => NULL]));

    // Two values of different types, where `!=` again sees nothing.
    $this->assertSame(['a' => '1'], NestedArray::diffDeep(['a' => '1'], ['a' => 1]));
    $this->assertSame(['a' => TRUE], NestedArray::diffDeep(['a' => TRUE], ['a' => 1]));
    $this->assertSame(['a' => 1.0], NestedArray::diffDeep(['a' => 1.0], ['a' => 1]));

    // Same type, same value: no difference.
    $this->assertSame([], NestedArray::diffDeep(['a' => '1'], ['a' => '1']));

    // An array against a scalar never reaches the recursion, so it lands in
    // the value comparison and comes back whole.
    $this->assertSame(['a' => ['b' => 1]], NestedArray::diffDeep(['a' => ['b' => 1]], ['a' => 'scalar']));

    // The instrument: a loose comparison finds none of the three strict cases.
    $loose = ['1' != 1, TRUE != 1, 1.0 != 1, NULL != ''];
    $this->assertSame([FALSE, FALSE, FALSE, FALSE], $loose);
  }

  /**
   * Returns an absent key whole and omits a nested comparison finding nothing.
   *
   * Covers: "it returns a key absent from the second array whole and omits a
   * nested comparison that finds nothing".
   *
   * Two shapes of the same "only what differs" contract. A key the second
   * array does not have at all is copied over untouched, whatever its value —
   * array, null or scalar — because there is nothing to compare it against.
   * A key both arrays have as arrays is recursed, and the recursive answer is
   * stored **only if it is non-empty**, so a subtree that matches disappears
   * rather than appearing as an empty array.
   *
   * The comparison is one-directional: a key only the second array carries is
   * never visited, so `diffDeep($a, $b)` and `diffDeep($b, $a)` answer
   * different questions.
   */
  public function testReturnsAbsentKeysWholeAndOmitsNestedComparisonsThatFindNothing(): void {
    // Absent from the second array: returned whole.
    $this->assertSame(['b' => 2], NestedArray::diffDeep(['a' => 1, 'b' => 2], ['a' => 1]));
    $this->assertSame(['b' => ['x' => 1]], NestedArray::diffDeep(['a' => 1, 'b' => ['x' => 1]], ['a' => 1]));

    // Even a NULL, because `array_key_exists()` is what is asked and it says
    // the key is not there at all.
    $this->assertSame(['b' => NULL], NestedArray::diffDeep(['b' => NULL], []));

    // A nested comparison that finds nothing is dropped, not returned empty.
    $this->assertSame([], NestedArray::diffDeep(['a' => ['b' => 1], 'c' => 2], ['a' => ['b' => 1], 'c' => 2]));
    $this->assertArrayNotHasKey('a', NestedArray::diffDeep(['a' => []], ['a' => []]));

    // A nested comparison that finds something returns only that.
    $this->assertSame(
      ['a' => ['c' => 2]],
      NestedArray::diffDeep(['a' => ['b' => 1, 'c' => 2]], ['a' => ['b' => 1, 'c' => 9]])
    );

    // Dropping is recursive: an inner subtree that matches vanishes and takes
    // its now-empty parent with it.
    $this->assertSame(
      [],
      NestedArray::diffDeep(['a' => ['b' => ['c' => 1]]], ['a' => ['b' => ['c' => 1]]])
    );

    // One-directional: a key only the second array has is never visited.
    $this->assertSame([], NestedArray::diffDeep(['a' => 1], ['a' => 1, 'z' => 9]));
    $this->assertSame(['z' => 9], NestedArray::diffDeep(['a' => 1, 'z' => 9], ['a' => 1]));
  }

  /**
   * Camel-cases keys recursively and leaves values untouched.
   *
   * Covers: "it camel-cases keys recursively and leaves values untouched".
   *
   * `keysToCamel()` walks the array, routes every key through
   * `Helpers\Str::camel()` and descends into any value that is an array. Every
   * other value is assigned across as it arrived — no conversion, no casting,
   * and an object by the same identity.
   *
   * Two consequences worth pinning. An integer key round-trips: `Str::camel()`
   * stringifies it, the string comes back unchanged, and PHP casts the numeric
   * string back to an integer on assignment — so a list keeps its shape.
   * And because `camel()` is `lcfirst(studly())`, dashes and underscores are
   * both word boundaries here, which is what lets a key written either way
   * reach the same property name.
   */
  public function testCamelCasesKeysRecursivelyAndLeavesValuesUntouched(): void {
    $this->assertSame(
      ['fooBar' => 1, 'bazQux' => ['nestedKey' => 'untouched_value']],
      NestedArray::keysToCamel(['foo_bar' => 1, 'baz-qux' => ['nested_key' => 'untouched_value']])
    );

    // Unbounded depth.
    $this->assertSame(
      ['oneTwo' => ['threeFour' => ['fiveSix' => 'seven_eight']]],
      NestedArray::keysToCamel(['one_two' => ['three-four' => ['five_six' => 'seven_eight']]])
    );

    // Values of every shape come back as they arrived, the object by identity.
    $object = new \stdClass();
    $this->assertSame(
      ['aB' => NULL, 'cD' => TRUE, 'eF' => 1.5, 'gH' => $object, 'iJ' => '0'],
      NestedArray::keysToCamel([
        'a_b' => NULL,
        'c-d' => TRUE,
        'e_f' => 1.5,
        'g_h' => $object,
        'i_j' => '0',
      ])
    );

    // Integer keys round-trip as integers, so a list keeps its shape.
    $this->assertSame(['fooBar' => [0 => 1, 1 => 2]], NestedArray::keysToCamel(['foo_bar' => [1, 2]]));
    $this->assertSame([0 => 'a_b'], NestedArray::keysToCamel([0 => 'a_b']));

    // An already-camel key is left where it is, so the walk is idempotent.
    $this->assertSame(['fooBar' => 1], NestedArray::keysToCamel(['fooBar' => 1]));

    // An empty array walks to an empty array.
    $this->assertSame([], NestedArray::keysToCamel([]));
  }

}
