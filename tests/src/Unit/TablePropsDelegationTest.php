<?php

declare(strict_types=1);

namespace Drupal\Tests\neo\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\neo\Hook\FormAlterHook;
use PHPUnit\Framework\Attributes\Group;

/**
 * Pins the shape the table props move leaves behind in the package.
 *
 * Three things about that shape are contracts rather than tidiness, and none of
 * them is observable by calling anything:
 *
 * - The shim carries a `@deprecated` tag and **no** `@trigger_error`, because
 *   `neo_theme` is the one caller outside this package and an operator on any
 *   of roughly thirty sites did not ask for a notice on every admin table.
 *   `docs/adr/0010` is the reasoning.
 * - Nothing inside this package calls the shim any more. That is not style: a
 *   `@deprecated` function called from its own package is a
 *   `function.deprecated` finding, and this package is held to a fixed phpstan
 *   file-error count.
 * - `FormAlterHook` keeps its name — it is its own autowired service id — and
 *   stops claiming to be MyModule's.
 *
 * The assertions read source rather than behaviour because that is what these
 * three are. It is the same seam `ClassListDelegationTest` uses for the same
 * reason.
 */
#[Group('neo')]
class TablePropsDelegationTest extends UnitTestCase {

  /**
   * The global carries a @deprecated tag and no @trigger_error.
   *
   * Acceptance criterion: the global carries a `@deprecated` tag naming the
   * replacement and no `@trigger_error`.
   */
  public function testTheGlobalIsDeprecatedInItsDocblockAndNowhereElse(): void {
    $docblock = $this->shimDocblock();

    $this->assertStringContainsString('@deprecated', $docblock, 'The shim is deprecated.');
    $this->assertStringContainsString(
      'Drupal\neo\Helpers\TableProps::get()',
      $docblock,
      'The tag names the replacement.'
    );
    $this->assertMatchesRegularExpression(
      '/@deprecated in neo:\d+\.\d+\.\d+ and is removed from neo:\d+\.0\.0\./',
      preg_replace('/\s+/', ' ', $docblock),
      'The tag states a major-version removal in the standard format.'
    );

    $this->assertStringNotContainsString(
      'trigger_error',
      $this->shimSource(),
      'Nothing about the shim fires at runtime.'
    );
  }

  /**
   * No code inside this package calls the deprecated global any more.
   *
   * Acceptance criterion: no code inside this package calls the deprecated
   * global any more — the three call sites in `neo.module` and the one in
   * `FormAlterHook` all reach the static.
   *
   * The whole package is swept rather than the four known files, because the
   * cost of a missed caller is not style: `phpstan-deprecation-rules` runs at
   * the gate's own level, so any remaining in-package call is a
   * `function.deprecated` finding above the count this package is held to.
   */
  public function testNothingInsideThePackageCallsTheDeprecatedGlobal(): void {
    $callers = [];
    foreach ($this->packageSources() as $file) {
      $source = file_get_contents($file);
      // The declaration itself is not a call, and neither is the docblock or
      // the @see above it.
      $source = str_replace('function neo_table_props(', '', $source);
      if (str_contains($source, 'neo_table_props(')) {
        $callers[] = $file;
      }
    }
    $this->assertSame([], $callers, 'Nothing in the package calls neo_table_props().');

    // The four that were calling it are named rather than counted, because a
    // count would be satisfied by three calls in the wrong three places.
    foreach ([
      'neo_preprocess_views_ui_style_plugin_table',
      'neo_preprocess_views_view_table',
      'neo_config_schema_info_alter',
    ] as $function) {
      $this->assertStringContainsString(
        'TableProps::get()',
        $this->functionBody($this->module(), $function),
        "$function() reaches the static.",
      );
    }
    $this->assertStringContainsString(
      'TableProps::get()',
      file_get_contents(dirname(__DIR__, 3) . '/src/Hook/FormAlterHook.php'),
      "FormAlterHook's call site reaches the static.",
    );
  }

  /**
   * Reads one global function's body out of a source file.
   *
   * @param string $source
   *   The file, as written.
   * @param string $function
   *   The function to read.
   *
   * @return string
   *   The declaration and body, as written.
   */
  private function functionBody(string $source, string $function): string {
    $start = strpos($source, 'function ' . $function . '(');
    $this->assertNotFalse($start, "$function() is still declared.");
    return substr($source, $start, strpos($source, "\n}\n", $start) - $start);
  }

  /**
   * Every analysed source file in this package, tests excluded.
   *
   * @return list<string>
   *   Absolute paths, in whatever order the filesystem yields them.
   */
  private function packageSources(): array {
    $root = dirname(__DIR__, 3);
    $extensions = ['php', 'module', 'inc', 'install', 'theme', 'engine', 'profile'];
    $files = [];
    $directories = new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS);
    $filter = new \RecursiveCallbackFilterIterator($directories, static function ($current) use ($root) {
      // The suite names the global on purpose, and nothing under a build or
      // dependency directory is this package's own source.
      $skip = [$root . '/tests', $root . '/node_modules', $root . '/dist', $root . '/vendor'];
      return !in_array($current->getPathname(), $skip, TRUE);
    });
    foreach (new \RecursiveIteratorIterator($filter) as $file) {
      if (in_array($file->getExtension(), $extensions, TRUE)) {
        $files[] = $file->getPathname();
      }
    }
    sort($files);
    return $files;
  }

  /**
   * FormAlterHook says what it holds, and is not renamed.
   *
   * Acceptance criterion: `FormAlterHook`'s docblock names what the class holds
   * instead of MyModule, and the class is not renamed.
   *
   * The name is asserted because it is not free to change: a hook class is
   * autowired under its own fully-qualified name, so renaming it would change a
   * service id for no consumer's benefit.
   */
  public function testFormAlterHookSaysWhatItHoldsAndKeepsItsName(): void {
    $class = new \ReflectionClass(FormAlterHook::class);
    $this->assertSame('FormAlterHook', $class->getShortName(), 'The class is not renamed.');
    $this->assertSame('Drupal\neo\Hook', $class->getNamespaceName(), 'Nor moved.');

    $docblock = $class->getDocComment();
    $this->assertIsString($docblock, 'The class still carries a docblock.');
    $this->assertStringNotContainsString('MyModule', $docblock, 'The scaffold comment is gone.');
    $this->assertStringContainsString('Views', $docblock, 'It names the form it alters.');
  }

  /**
   * Reads neo.module.
   *
   * @return string
   *   The file, as written.
   */
  private function module(): string {
    return file_get_contents(dirname(__DIR__, 3) . '/neo.module');
  }

  /**
   * Reads the shim's docblock and declaration.
   *
   * @return string
   *   Everything from the opening of the docblock above `neo_table_props()` to
   *   the end of its declaration line.
   */
  private function shimSource(): string {
    $module = $this->module();
    $declaration = strpos($module, 'function neo_table_props(');
    $this->assertNotFalse($declaration, 'The shim is still declared in neo.module.');
    $docblock = strrpos(substr($module, 0, $declaration), '/**');
    $this->assertNotFalse($docblock, 'The shim still carries a docblock.');
    $body = strpos($module, "\n}\n", $declaration);
    return substr($module, $docblock, $body - $docblock);
  }

  /**
   * Reads the shim's docblock alone.
   *
   * @return string
   *   The docblock above `neo_table_props()`, as written.
   */
  private function shimDocblock(): string {
    $source = $this->shimSource();
    return substr($source, 0, strpos($source, '*/') + 2);
  }

}
