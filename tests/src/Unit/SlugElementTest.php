<?php

declare(strict_types=1);

namespace Drupal\Tests\neo\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Form\FormState;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Tests\UnitTestCase;
use Drupal\neo\Element\Slug;
use PHPUnit\Framework\Attributes\Group;

/**
 * Characterises the `slug` form element: its validator and its source binding.
 *
 * `Element\Slug` is a `Textfield` with two callbacks of its own — one
 * `#element_validate` handler and one `#process` handler — and neither had ever
 * been asserted. The validator is the one that matters: it took the module's
 * only slug bug fix, which stopped an empty value raising an error, and nothing
 * stood behind that fix.
 *
 * Both callbacks are static and neither reaches a database. The validator calls
 * the global `t()`, whose `TranslatableMarkup` resolves `string_translation`
 * out of the container when it is rendered, and the process callback reads the
 * complete form out of the form state. A stub container and a real `FormState`
 * are therefore the whole harness.
 *
 * **The process callback ignores its own `$complete_form` argument.** It reads
 * `$form_state->getCompleteForm()` instead, which is a different array during
 * `#process` than the one core passes in. Every process test here proves that
 * by passing an empty argument and a populated form state, and is the reason
 * the ticket says a form state carrying a complete form is enough.
 *
 * Characterised, not repaired. Three answers pinned here are quirks rather than
 * intentions, each named in the docblock of the test that pins it:
 *
 * 1. The pattern `^[a-z0-9\-]+$` puts no constraint on where a dash may sit, so
 *    a leading dash, a trailing dash and a run of dashes are all accepted — the
 *    one case in the ticket's own list of "what a real editor produces" that
 *    the validator lets through.
 * 2. The validator trims for the comparison and for the message, but never
 *    writes the trimmed value back to `#value`, so a padded slug validates
 *    clean and is then stored with its padding.
 * 3. The guard is `if ($value && ...)`, a truthiness test rather than a
 *    comparison against the empty string, so the literal slug `0` skips the
 *    pattern check altogether. It is valid either way, so nothing observable
 *    turns on it today — but the branch that makes the empty-value fix work is
 *    the same branch that swallows `0`.
 */
#[Group('neo')]
final class SlugElementTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $container = new ContainerBuilder();
    $container->set('string_translation', $this->getStringTranslationStub());
    \Drupal::setContainer($container);
  }

  /**
   * Runs the validator over one submitted value and returns the form errors.
   *
   * @param string $value
   *   The submitted `#value`, exactly as a form hands it over.
   *
   * @return array
   *   The errors `FormState` recorded, keyed by element name.
   */
  private function validate(string $value): array {
    $element = [
      '#parents' => ['slug'],
      '#value' => $value,
    ];
    $complete_form = [];
    $form_state = new FormState();
    Slug::validateSlug($element, $form_state, $complete_form);
    return $form_state->getErrors();
  }

  /**
   * Runs the process callback and returns the processed element.
   *
   * The `$complete_form` argument is deliberately left empty: the callback
   * reads the complete form out of the form state, not out of its argument.
   *
   * @param array $element
   *   The element to process.
   * @param array $complete_form
   *   The complete form to seed the form state with.
   *
   * @return array
   *   The processed element.
   */
  private function process(array $element, array $complete_form = []): array {
    $form_state = new FormState();
    $form_state->setCompleteForm($complete_form);
    $ignored_argument = [];
    return Slug::processSlug($element, $form_state, $ignored_argument);
  }

  /**
   * Accepts a well-formed slug and records no error.
   *
   * Covers: "it accepts a well-formed slug and sets no form error".
   *
   * Lower-case letters, digits and dashes in any combination pass, and a
   * well-formed slug padded with whitespace passes too, because the validator
   * trims before it matches.
   *
   * **Quirk 1 is pinned here.** `^[a-z0-9\-]+$` constrains the alphabet and
   * nothing else, so a leading dash, a trailing dash and a run of dashes are
   * all well-formed as far as this validator is concerned. The ticket lists a
   * leading or trailing dash among the things a real editor produces; the
   * validator does not reject it. Reported for the backlog, not repaired.
   *
   * **Quirk 2 is pinned here too.** `trim()` is applied to a local, never
   * written back, so the padded value validates clean and is stored padded.
   */
  public function testAcceptsWellFormedSlugsAndSetsNoFormError(): void {
    $this->assertSame([], $this->validate('hello-world'));
    $this->assertSame([], $this->validate('a'));
    $this->assertSame([], $this->validate('2026'));
    $this->assertSame([], $this->validate('neo-1-0-137'));

    // The trim is load-bearing: padding does not reach the pattern.
    $this->assertSame([], $this->validate('  hello-world  '));
    $this->assertSame([], $this->validate("\thello-world\n"));

    // Quirk 1: dashes are unconstrained in position.
    $this->assertSame([], $this->validate('-hello-world'));
    $this->assertSame([], $this->validate('hello-world-'));
    $this->assertSame([], $this->validate('---'));

    // Quirk 2: the trimmed value is never written back to the element.
    $element = [
      '#parents' => ['slug'],
      '#value' => '  hello-world  ',
    ];
    $complete_form = [];
    $form_state = new FormState();
    Slug::validateSlug($element, $form_state, $complete_form);
    $this->assertSame('  hello-world  ', $element['#value']);
  }

  /**
   * Accepts an empty value and a whitespace-only value without an error.
   *
   * Covers: "it accepts an empty value and a whitespace-only value without an
   * error".
   *
   * This is the fix the plan exists to pin. `''` is falsy, so the pattern is
   * never reached and no error is raised — an optional slug left blank is
   * simply blank. A whitespace-only value takes the same path, because the
   * trim turns it into the empty string before the guard sees it; without the
   * trim, a lone space would fail the pattern and raise the error the fix
   * removed.
   *
   * Requiredness is not this handler's business: an empty value on a
   * `#required` element is caught by core's own required-field validation,
   * which runs before `#element_validate`.
   */
  public function testAcceptsEmptyAndWhitespaceOnlyValuesWithoutAnError(): void {
    $this->assertSame([], $this->validate(''));

    // Whitespace-only values reach the empty path through the trim.
    $this->assertSame([], $this->validate(' '));
    $this->assertSame([], $this->validate('     '));
    $this->assertSame([], $this->validate("\t"));
    $this->assertSame([], $this->validate("\n"));
    $this->assertSame([], $this->validate(" \t\r\n "));
  }

  /**
   * Rejects the characters a real editor actually types.
   *
   * Covers: "it rejects an uppercase letter, a space, an underscore, a slash
   * and an accented character".
   *
   * Each of these is one plausible mistake: the title pasted verbatim, the
   * space that was never replaced, the underscore borrowed from a machine
   * name, the path separator, and the accent that survived a copy-paste.
   *
   * The accented character is worth its own note: the pattern carries no `u`
   * modifier, so it matches bytes rather than code points, and the two bytes
   * of a UTF-8 `é` fail `[a-z0-9\-]` individually. The rejection is right; the
   * reason it is right is an accident of the missing modifier.
   */
  public function testRejectsUppercaseSpaceUnderscoreSlashAndAccentedCharacters(): void {
    // An uppercase letter, anywhere in the value.
    $this->assertArrayHasKey('slug', $this->validate('Hello-world'));
    $this->assertArrayHasKey('slug', $this->validate('hello-World'));
    $this->assertArrayHasKey('slug', $this->validate('HELLO'));

    // An interior space survives the trim and fails the pattern.
    $this->assertArrayHasKey('slug', $this->validate('hello world'));

    // An underscore is not a dash.
    $this->assertArrayHasKey('slug', $this->validate('hello_world'));

    // A slash, which is what a pasted path leaves behind.
    $this->assertArrayHasKey('slug', $this->validate('hello/world'));
    $this->assertArrayHasKey('slug', $this->validate('/hello'));

    // An accented character.
    $this->assertArrayHasKey('slug', $this->validate('café'));
    $this->assertArrayHasKey('slug', $this->validate('crème-brûlée'));

    // An interior newline is not trimmed away and fails too.
    $this->assertArrayHasKey('slug', $this->validate("hello\nworld"));
  }

  /**
   * Names the offending value in the message it sets.
   *
   * Covers: "it names the offending value in the error message it sets".
   *
   * The error is recorded against the element's `#parents` path, and its
   * message is a `TranslatableMarkup` carrying the offending value as the
   * `%slug` argument — the **trimmed** value, not the raw submission, so the
   * editor is shown the string that was actually judged.
   *
   * Rendering it puts the value through core's placeholder formatting, which
   * is where the `%` prefix earns its `<em class="placeholder">` wrapper and
   * where the value is escaped.
   */
  public function testNamesTheOffendingValueInTheErrorMessageItSets(): void {
    $errors = $this->validate('  Hello World  ');

    $this->assertArrayHasKey('slug', $errors);
    $message = $errors['slug'];
    $this->assertInstanceOf(TranslatableMarkup::class, $message);

    $this->assertSame(
      'The slug %slug is invalid. Only lowercase letters, numbers, and dashes are allowed.',
      $message->getUntranslatedString()
    );

    // The trimmed value is what the message names, not the raw submission.
    $this->assertSame(['%slug' => 'Hello World'], $message->getArguments());
    $this->assertStringContainsString(
      '<em class="placeholder">Hello World</em>',
      (string) $message
    );

    // A value carrying markup is escaped by the placeholder formatting.
    $errors = $this->validate('<b>x</b>');
    $this->assertStringContainsString(
      '&lt;b&gt;x&lt;/b&gt;',
      (string) $errors['slug']
    );

    // The error is keyed by the element's #parents path.
    $element = [
      '#parents' => ['settings', 'slug'],
      '#value' => 'Nope',
    ];
    $complete_form = [];
    $form_state = new FormState();
    Slug::validateSlug($element, $form_state, $complete_form);
    $this->assertArrayHasKey('settings][slug', $form_state->getErrors());
  }

  /**
   * Attaches the slug library and the slug class to every processed element.
   *
   * Covers: "it attaches the slug library and the slug class to every
   * processed element".
   *
   * Both happen unconditionally, before the source binding is even considered,
   * so an element with no `#slug` still gets the behaviour that makes it a
   * slug field in the browser. Both append rather than assign, so an element
   * that already carries a library or a class keeps it.
   */
  public function testAttachesTheSlugLibraryAndTheSlugClassToEveryProcessedElement(): void {
    // A bare element, with no #slug key at all.
    $processed = $this->process([]);
    $this->assertSame(['neo/slug'], $processed['#attached']['library']);
    $this->assertSame(['neo-slug'], $processed['#attributes']['class']);

    // An element declaring a source it cannot resolve still gets both.
    $processed = $this->process(['#slug' => ['source' => ['title']]]);
    $this->assertSame(['neo/slug'], $processed['#attached']['library']);
    $this->assertSame(['neo-slug'], $processed['#attributes']['class']);

    // Existing libraries and classes are appended to, not replaced.
    $processed = $this->process([
      '#attached' => ['library' => ['neo/other']],
      '#attributes' => ['class' => ['custom']],
    ]);
    $this->assertSame(['neo/other', 'neo/slug'], $processed['#attached']['library']);
    $this->assertSame(['custom', 'neo-slug'], $processed['#attributes']['class']);
  }

  /**
   * Binds the source element's id, and leaves the attribute unset otherwise.
   *
   * Covers: "it copies the source element's id onto the element when a source
   * is declared, and leaves the attribute unset when the source has no id or
   * resolves to nothing".
   *
   * `#slug['source']` is a parents array, so it addresses the source element
   * by its position in the complete form and is walked with
   * `NestedArray::getValue()`. The guard is `!empty($source['#id'])`, which is
   * why all three of the misses below leave `data-neo-slug-source` absent
   * rather than present and empty — the browser side reads the attribute's
   * presence, and an empty attribute would bind the field to nothing.
   *
   * `NestedArray::getValue()` is handed a `$key_exists` out-parameter that the
   * callback then never reads; the `!empty()` guard covers both the missing
   * path and the missing id in one test, so the variable is dead. Pinned as
   * behaviour, not repaired.
   */
  public function testCopiesTheSourceIdAndLeavesTheAttributeUnsetOtherwise(): void {
    $complete_form = [
      'title' => ['#id' => 'edit-title'],
      'no_id' => ['#type' => 'textfield'],
      'empty_id' => ['#id' => ''],
      'group' => [
        'nested' => ['#id' => 'edit-group-nested'],
      ],
    ];

    // A declared source that resolves to an element with an id.
    $processed = $this->process(['#slug' => ['source' => ['title']]], $complete_form);
    $this->assertArrayHasKey('data-neo-slug-source', $processed['#attributes']);
    $this->assertSame('edit-title', $processed['#attributes']['data-neo-slug-source']);

    // The parents array is walked, so a nested source resolves too.
    $processed = $this->process(['#slug' => ['source' => ['group', 'nested']]], $complete_form);
    $this->assertArrayHasKey('data-neo-slug-source', $processed['#attributes']);
    $this->assertSame('edit-group-nested', $processed['#attributes']['data-neo-slug-source']);

    // A source that resolves to nothing: the attribute stays unset.
    $processed = $this->process(['#slug' => ['source' => ['absent']]], $complete_form);
    $this->assertArrayNotHasKey('data-neo-slug-source', $processed['#attributes']);

    $processed = $this->process(['#slug' => ['source' => ['group', 'absent']]], $complete_form);
    $this->assertArrayNotHasKey('data-neo-slug-source', $processed['#attributes']);

    // A source that resolves to an element carrying no id: unset, not empty.
    $processed = $this->process(['#slug' => ['source' => ['no_id']]], $complete_form);
    $this->assertArrayNotHasKey('data-neo-slug-source', $processed['#attributes']);

    // A source whose id is the empty string: unset, not empty.
    $processed = $this->process(['#slug' => ['source' => ['empty_id']]], $complete_form);
    $this->assertArrayNotHasKey('data-neo-slug-source', $processed['#attributes']);

    // No source declared at all, and a declared-but-empty source.
    $processed = $this->process([], $complete_form);
    $this->assertArrayNotHasKey('data-neo-slug-source', $processed['#attributes']);

    $processed = $this->process(['#slug' => ['source' => []]], $complete_form);
    $this->assertArrayNotHasKey('data-neo-slug-source', $processed['#attributes']);

    // The complete form comes from the form state, not from the callback's
    // own $complete_form argument, which every case above left empty.
    $processed = $this->process(['#slug' => ['source' => ['title']]], $complete_form);
    $this->assertArrayHasKey('data-neo-slug-source', $processed['#attributes']);
  }

}
