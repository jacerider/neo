<?php

declare(strict_types=1);

namespace Drupal\Tests\neo\Unit;

use Drupal\Component\Transliteration\PhpTransliteration;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Tests\UnitTestCase;
use Drupal\neo\Helpers\Str;
use PHPUnit\Framework\Attributes\Group;

/**
 * Characterises `Helpers\Str`, the first of the two pure Neo helpers.
 *
 * Five converters sit under `Helpers\NestedArray`, under `SlideMenu`'s option
 * dispatcher, under `neo_image`'s style parser and under every Neo package
 * that has ever needed a machine name. None had ever been asserted.
 *
 * **The three caches are `protected static` and nothing resets them.**
 * `$snakeCache`, `$studlyCache` and `$camelCache` are keyed by input, so they
 * do not leak between assertions, but there is **no reset method** and they
 * live for the whole PHP process — which, under PHPUnit's default single
 * process, means for the whole test run. Two consequences for whoever touches
 * this class next:
 *
 * 1. A test cannot clear them between cases, so every case here uses an input
 *    no other case uses, or reads the cache through reflection rather than
 *    assuming it is empty.
 * 2. A future refactor of these helpers cannot simply mutate the caches
 *    between tests to prove a branch — the only handles are the input key and
 *    reflection.
 *
 * `machine()` is the one converter that reaches a service, so its test stubs a
 * container carrying core's transliteration. Everything after the
 * transliteration is pure.
 *
 * Characterised, not repaired. Three answers pinned here are quirks rather
 * than intentions, each named in the docblock of the test that pins it:
 *
 * 1. `snake()`'s "already lower-case" short-circuit tests `ctype_lower()`,
 *    which a space fails — so a lower-case string carrying a space is
 *    converted rather than returned untouched.
 * 2. `machine()` trims a **trailing** delimiter and not a leading one, so an
 *    input that opens with punctuation keeps the delimiter that punctuation
 *    became.
 * 3. `machine()` collapses the underscores it just produced into the caller's
 *    delimiter, so a non-default delimiter also rewrites underscores the
 *    caller supplied.
 */
#[Group('neo')]
final class StrHelperTest extends UnitTestCase {

  /**
   * Lower-cases a multibyte string, not only its ASCII characters.
   *
   * Covers: "it lower-cases a multibyte string rather than only its ASCII
   * characters".
   *
   * `lower()` is `mb_strtolower($value, 'UTF-8')`, and an accented or
   * non-Latin string is the input that proves it. The final assertion is the
   * instrument: PHP's own `strtolower()` is byte-wise and locale-independent,
   * so it returns those same strings unchanged. Any answer that agrees with it
   * is not doing the multibyte work.
   */
  public function testLowerCasesMultibyteStringsRatherThanOnlyTheirAsciiCharacters(): void {
    $this->assertSame('äöü', Str::lower('ÄÖÜ'));
    $this->assertSame('crème brûlée', Str::lower('CRÈME BRÛLÉE'));
    $this->assertSame('привет мир', Str::lower('ПРИВЕТ МИР'));

    // ASCII still works, so the multibyte path is not a special case.
    $this->assertSame('foo bar', Str::lower('FOO BAR'));

    // The instrument: `strtolower()` leaves every one of those alone.
    $this->assertSame('ÄÖÜ', strtolower('ÄÖÜ'));
    $this->assertSame('ПРИВЕТ МИР', strtolower('ПРИВЕТ МИР'));
  }

  /**
   * Converts studly to snake, honours a delimiter, skips already-lower input.
   *
   * Covers: "it converts a studly string to snake case, honours a non-default
   * delimiter, and returns an already-lower string untouched".
   *
   * The conversion is two `preg_replace()` passes: whitespace is stripped
   * after `ucwords()`, then a delimiter is inserted before every capital and
   * the whole thing is lower-cased. Studly and camel inputs therefore land on
   * the same answer, because `ucwords()` cannot tell them apart once the
   * capitals are already there.
   *
   * The delimiter is a parameter, and it is also part of the cache key — the
   * snake cache is two levels deep, `[$value][$delimiter]`, which is why the
   * same input can hold two different answers at once.
   *
   * The last pair is the short-circuit: `ctype_lower()` passes, so the
   * conversion is skipped entirely and the input is returned as it arrived —
   * digits included, because `'v2'` never reaches the branch that would have
   * split it.
   */
  public function testConvertsStudlyToSnakeHonoursTheDelimiterAndLeavesAnAlreadyLowerStringAlone(): void {
    $this->assertSame('foo_bar_baz', Str::snake('FooBarBaz'));
    $this->assertSame('foo_bar_baz', Str::snake('fooBarBaz'));

    // The delimiter is a parameter and part of the cache key, so the same
    // input holds one answer per delimiter.
    $this->assertSame('foo-bar-baz', Str::snake('FooBarBaz', '-'));
    $this->assertSame('foo.bar.baz', Str::snake('FooBarBaz', '.'));
    $this->assertSame('foo_bar_baz', Str::snake('FooBarBaz'));

    // Already entirely lower-case: `ctype_lower()` passes and the conversion
    // is skipped, so the input comes back exactly as it went in.
    $this->assertSame('foobar', Str::snake('foobar'));
    $this->assertSame('foobar', Str::snake('foobar', '-'));

    // `ctype_lower()` fails on a digit, so this one is converted after all —
    // it just has no capital for the delimiter pass to find.
    $this->assertSame('foobarv2', Str::snake('foobarv2'));
  }

  /**
   * Converts a spaced lower-case string rather than short-circuiting on it.
   *
   * Covers: "it converts a spaced lower-case string to snake case rather than
   * short-circuiting on it".
   *
   * The other side of the branch criterion 2 pinned, and the quirk in it. The
   * short-circuit reads "already lower-case, nothing to do", but it is spelled
   * `ctype_lower()`, which requires **every** character to be a lower-case
   * letter. A space is not, so `'foo bar'` fails the check and is converted —
   * `ucwords()` capitalises across the space, `\s+` deletes the space, and the
   * delimiter pass then finds the capital that `ucwords()` just created.
   *
   * That is the whole reason a space produces a delimiter at all: without the
   * failed `ctype_lower()` check the string would be returned with its space
   * intact. The first two assertions are the instrument for it.
   *
   * Pinned as it behaves, not repaired.
   */
  public function testConvertsSpacedLowerCaseStringsRatherThanShortCircuitingOnThem(): void {
    // The instrument: the string is lower-case to a reader, but not to
    // `ctype_lower()`, which is what the short-circuit actually asks.
    $this->assertFalse(ctype_lower('foo bar'));
    $this->assertTrue(ctype_lower('foobar'));

    $this->assertSame('foo_bar', Str::snake('foo bar'));
    $this->assertSame('foo-bar', Str::snake('foo bar', '-'));

    // Runs of whitespace collapse, because `\s+` deletes them all before the
    // delimiter pass runs.
    $this->assertSame('foo_bar_baz', Str::snake("foo   bar\tbaz"));

    // A leading or trailing space leaves no delimiter behind, for the same
    // reason: the space is deleted, and no capital is created beside it.
    $this->assertSame('foo_bar', Str::snake('  foo bar  '));
  }

  /**
   * Converts dashed and underscored strings to studly case, and to camel case.
   *
   * Covers: "it converts dashed and underscored strings to studly case and to
   * camel case".
   *
   * `studly()` turns both separators into spaces, capitalises each word and
   * then removes the spaces, so a dashed, an underscored, a spaced and a mixed
   * input all land on the same answer. `camel()` is `lcfirst(studly())` — it
   * has no conversion of its own and no separate parser, which is why every
   * studly answer here has a camel answer one lower-case letter apart.
   *
   * Two things `studly()` does not do, pinned because callers assume
   * otherwise: it does not lower the rest of a word it capitalises, so an
   * already-studly segment survives intact; and it capitalises only after a
   * separator, so a camel hump inside a segment is left where it is.
   */
  public function testConvertsDashedAndUnderscoredStringsToStudlyAndToCamel(): void {
    $this->assertSame('FooBarBaz', Str::studly('foo-bar-baz'));
    $this->assertSame('FooBarBaz', Str::studly('foo_bar_baz'));
    $this->assertSame('FooBarBaz', Str::studly('foo-bar_baz'));
    $this->assertSame('FooBarBaz', Str::studly('foo bar baz'));

    $this->assertSame('fooBarBaz', Str::camel('foo-bar-baz'));
    $this->assertSame('fooBarBaz', Str::camel('foo_bar_baz'));
    $this->assertSame('fooBarQux', Str::camel('foo-bar_qux'));

    // `ucwords()` only touches the character after a separator, so anything
    // already capitalised inside a segment stays capitalised.
    $this->assertSame('FooBARBaz', Str::studly('foo-BAR-baz'));
    $this->assertSame('fooBarBaz', Str::camel('fooBar-baz'));
  }

  /**
   * Transliterates, collapses runs of non-alphanumerics, trims a trailing one.
   *
   * Covers: "it transliterates a machine name, collapses runs of
   * non-alphanumeric characters to the delimiter, and trims a trailing one".
   *
   * `machine()` is the one converter that reaches a service, so this is the
   * one test with a container. The stub carries nothing but core's
   * transliteration, which is instantiable directly — the component class
   * takes no arguments at all, where `\Drupal\Core\Transliteration\
   * PhpTransliteration` would additionally want a module handler for an alter
   * hook `machine()` never triggers.
   *
   * Everything after the transliteration is pure: `strtolower()` — the
   * byte-wise one, not `lower()`, which is safe only because transliteration
   * has already reduced the string to ASCII — then every run of characters
   * outside `[a-z0-9_]` collapsed to `_`, then every run of `_` collapsed to
   * the delimiter, then a trailing delimiter trimmed.
   *
   * Two quirks pinned as they behave, not repaired:
   *
   * 1. **Only the trailing delimiter is trimmed.** `rtrim()` is not `trim()`,
   *    so an input that opens with punctuation keeps the delimiter that
   *    punctuation became.
   * 2. **A non-default delimiter rewrites the caller's own underscores.** The
   *    delimiter is applied by collapsing `_+`, and by that point the caller's
   *    underscores are indistinguishable from the ones the previous pass just
   *    produced.
   *
   * An input that is entirely punctuation collapses to a single delimiter and
   * is then trimmed to the empty string — `machine()` has no guard that would
   * return anything else.
   */
  public function testTransliteratesMachineNamesCollapsesRunsAndTrimsTheTrailingDelimiter(): void {
    $container = new ContainerBuilder();
    $container->set('transliteration', new PhpTransliteration());
    \Drupal::setContainer($container);

    // Transliteration: accents and non-Latin scripts reach ASCII.
    $this->assertSame('creme_brulee', Str::machine('Crème Brûlée'));
    $this->assertSame('privet_mir', Str::machine('Привет мир'));

    // A whole run of non-alphanumerics becomes exactly one delimiter.
    $this->assertSame('hello_world', Str::machine('Hello -- World'));
    $this->assertSame('hello_world', Str::machine('Hello   ///   World'));

    // A trailing run is collapsed and then trimmed away.
    $this->assertSame('hello_world', Str::machine('Hello World!!!'));
    $this->assertSame('hello_world', Str::machine('Hello World   '));

    // Quirk 1: `rtrim()` only, so a leading delimiter survives.
    $this->assertSame('_hello_world', Str::machine('!!!Hello World'));

    // Entirely punctuation collapses to one delimiter, which is then trimmed.
    $this->assertSame('', Str::machine('!!!'));
    $this->assertSame('', Str::machine('   '));

    // A non-default delimiter, including the trailing trim.
    $this->assertSame('hello-world', Str::machine('Hello World', '-'));
    $this->assertSame('hello-world', Str::machine('Hello World!!!', '-'));

    // Quirk 2: the caller's own underscores are rewritten too.
    $this->assertSame('foo-bar', Str::machine('foo_bar', '-'));
  }

  /**
   * Returns the same answer on the second call for each cached converter.
   *
   * Covers: "it returns the same answer on a second call for each cached
   * converter".
   *
   * Three of the five converters memoise: `snake()` into `$snakeCache`,
   * `studly()` into `$studlyCache` and `camel()` into `$camelCache`.
   * `lower()` and `machine()` do not, and are recomputed on every call.
   *
   * **All three caches are `protected static` and nothing resets them.** There
   * is no reset method, no `drupal_static()` registration and no hook that
   * clears them; they live for the whole PHP process. They are keyed by input
   * — `$snakeCache` two levels deep by input and delimiter — so they do not
   * leak between assertions, but a test cannot empty one between cases, and a
   * future refactor of these helpers cannot mutate them between tests to prove
   * a branch. The only handles are a fresh input key and reflection, which is
   * why this test reaches for reflection rather than asserting an empty cache.
   *
   * Equality on a second call alone would pass against a pure function, so
   * each converter is asserted three ways: the answer, the cache entry the
   * first call wrote, and the same answer coming back. The entry is what says
   * the second call was served from memory.
   *
   * `camel()` writes **two** entries for one call, its own and `studly()`'s,
   * because it is implemented by calling `studly()`.
   */
  public function testReturnsTheSameAnswerOnTheSecondCallForEachCachedConverter(): void {
    // snake(): keyed by input *and* delimiter, so one input holds two entries.
    $this->assertSame('cached_snake_input', Str::snake('cachedSnakeInput'));
    $this->assertSame('cached-snake-input', Str::snake('cachedSnakeInput', '-'));
    $this->assertSame(
      ['_' => 'cached_snake_input', '-' => 'cached-snake-input'],
      self::readCache('snakeCache')['cachedSnakeInput']
    );
    $this->assertSame('cached_snake_input', Str::snake('cachedSnakeInput'));
    $this->assertSame('cached-snake-input', Str::snake('cachedSnakeInput', '-'));

    // studly(): keyed by input alone.
    $this->assertSame('CachedStudlyInput', Str::studly('cached-studly-input'));
    $this->assertSame('CachedStudlyInput', self::readCache('studlyCache')['cached-studly-input']);
    $this->assertSame('CachedStudlyInput', Str::studly('cached-studly-input'));

    // camel(): writes its own entry and, through studly(), a studly one.
    $this->assertSame('cachedCamelInput', Str::camel('cached-camel-input'));
    $this->assertSame('cachedCamelInput', self::readCache('camelCache')['cached-camel-input']);
    $this->assertSame('CachedCamelInput', self::readCache('studlyCache')['cached-camel-input']);
    $this->assertSame('cachedCamelInput', Str::camel('cached-camel-input'));

    // The uncached pair: same answer, no entry anywhere to serve it from.
    $this->assertSame('cached lower input', Str::lower('CACHED LOWER INPUT'));
    $this->assertSame('cached lower input', Str::lower('CACHED LOWER INPUT'));
    $this->assertArrayNotHasKey('CACHED LOWER INPUT', self::readCache('snakeCache'));
    $this->assertArrayNotHasKey('CACHED LOWER INPUT', self::readCache('studlyCache'));
    $this->assertArrayNotHasKey('CACHED LOWER INPUT', self::readCache('camelCache'));
  }

  /**
   * Reads one of `Str`'s three protected static caches.
   *
   * There is no accessor and no reset method, so reflection is the only way to
   * see what a call wrote.
   *
   * @param string $property
   *   The cache property name: `snakeCache`, `studlyCache` or `camelCache`.
   *
   * @return array
   *   The cache as it currently stands, for the whole PHP process.
   */
  private static function readCache(string $property): array {
    return (new \ReflectionProperty(Str::class, $property))->getValue();
  }

}
