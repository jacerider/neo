<?php

declare(strict_types=1);

namespace Drupal\Tests\neo\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\neo\Helpers\TableProps;
use PHPUnit\Framework\Attributes\Group;

/**
 * Pins the table props shim to the static it now delegates to.
 *
 * `neo_table_props()` stays in `neo.module` because one caller lives outside
 * this package — `neo_theme`'s `neo_base/src/NeoBasePreRender.php` — and
 * deleting it would be a breaking release across every site that installs the
 * theme. `docs/adr/0010` records why it is deprecated in its docblock and
 * nowhere else, and why `neo_theme` is not edited to clear it.
 *
 * The whole of the shim's contract is that it answers what the static answers,
 * so that is the whole of this test. It runs at the Kernel tier rather than the
 * Unit tier because a global in a `.module` file only exists once the module
 * has been loaded, which is exactly the dependency the move was made to get out
 * of the hook class.
 */
#[Group('neo')]
final class TablePropsShimTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   *
   * `neo` will not install on `system` and `user` alone: `neo.services.yml`
   * declares `neo.linkit_resolver`, which needs `path_alias.manager` and
   * `plugin.manager.linkit.substitution`, so the container fails to compile
   * without `path_alias` and `linkit`. Nothing beyond that is needed — the
   * shim reaches no service, no config and no entity.
   */
  protected static $modules = [
    'system',
    'user',
    'path_alias',
    'linkit',
    'neo',
  ];

  /**
   * The deprecated global answers exactly what the static answers.
   *
   * Acceptance criterion: the deprecated global answers exactly what the static
   * answers.
   *
   * Equality rather than identity, because the labels are freshly constructed
   * TranslatableMarkup objects on each call and were before the move too. The
   * rendered strings are compared as well, so a shim that answered the right
   * shape with the wrong labels would still be caught.
   */
  public function testTheGlobalAnswersExactlyWhatTheStaticAnswers(): void {
    $this->assertTrue(function_exists('neo_table_props'), 'The shim is still a global.');

    $static = TableProps::get();
    // Calling the deprecated shim is the point of this test, and phpstan runs
    // phpstan-deprecation-rules at the gate's own level, so the call has to say
    // so or the package's file-error count moves for the one caller that is
    // supposed to be there.
    // @phpstan-ignore function.deprecated
    $global = neo_table_props();

    $this->assertEquals($static, $global, 'The shim answers what the static answers.');
    $this->assertSame(array_keys($static), array_keys($global), 'In the same key order.');
    $this->assertSame(
      $this->flatten($static),
      $this->flatten($global),
      'Down to every rendered label.'
    );
  }

  /**
   * Renders a table props array down to plain strings.
   *
   * @param array $props
   *   The table props.
   *
   * @return array
   *   The same shape, with every label cast to its translated string.
   */
  private function flatten(array $props): array {
    $rendered = [];
    foreach ($props as $key => $prop) {
      $rendered[$key] = [
        'label' => (string) $prop['label'],
        'options' => array_map('strval', $prop['options']),
      ];
      if (array_key_exists('apply', $prop)) {
        $rendered[$key]['apply'] = $prop['apply'];
      }
    }
    return $rendered;
  }

}
