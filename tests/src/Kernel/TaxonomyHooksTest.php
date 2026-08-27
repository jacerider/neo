<?php

declare(strict_types=1);

namespace Drupal\Tests\neo\Kernel;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_taxonomy\Hook\NeoTaxonomyFormHooks;
use Drupal\neo_taxonomy\Hook\NeoTaxonomyHooks;
use Drupal\taxonomy\Entity\Vocabulary;
use PHPUnit\Framework\Attributes\Group;

/**
 * The sub-module's two non-form hooks, driven through the hook system.
 *
 * `neo_taxonomy.module` carried five hook implementations, two form callbacks
 * and one helper, and this ticket moves all of it onto two classes under a
 * `Hook` namespace the sub-module did not have — it had no `src/` directory at
 * all. Two of the five are not form alters and are therefore on
 * \Drupal\neo_taxonomy\Hook\NeoTaxonomyHooks: the bundle field info hook that
 * makes a term's description field required per vocabulary from a third-party
 * setting, and the library alter that blanks `taxonomy_manager`'s form CSS. The
 * other three, the two callbacks and the helper are on
 * \Drupal\neo_taxonomy\Hook\NeoTaxonomyFormHooks, which is core's
 * `…Hooks` / `…FormHooks` split and which `TaxonomyFormHooksTest` owns.
 *
 * The bodies move as they stand, with one substitution here: the bundle field
 * hook's `\Drupal::config()` reach becomes a constructor argument. Nothing
 * either of them decides moved with them.
 *
 * So what is at risk is a registration rather than a decision. A method nobody
 * invokes is not a hook implementation, and a wrong hook name, a class in a
 * namespace nothing scans or a missing attribute produces a class that reads
 * correctly and never runs. Every assertion below therefore goes through the
 * module handler or the entity field manager rather than through the object,
 * and the registration test covers all five hooks across both classes, because
 * "no function remains in `neo_taxonomy.module`" is a statement about the
 * sub-module rather than about one class.
 */
#[Group('neo')]
final class TaxonomyHooksTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   *
   * `neo` will not install on `system` and `user` alone: `neo.services.yml`
   * declares `neo.linkit_resolver`, which needs `path_alias.manager` and
   * `plugin.manager.linkit.substitution`, so the container fails to compile
   * without `path_alias` and `linkit`. `taxonomy` is the module whose entity
   * type and vocabularies both hooks in this class are about, and it brings
   * `node`, `text` and `filter` with it.
   */
  protected static $modules = [
    'system',
    'user',
    'path_alias',
    'linkit',
    'neo',
    'field',
    'text',
    'filter',
    'node',
    'taxonomy',
    'neo_taxonomy',
  ];

  /**
   * {@inheritdoc}
   *
   * Two vocabularies, differing only in the third-party setting the bundle
   * field hook reads. The second one is the control: the hook has to leave a
   * vocabulary that never asked for a required description exactly as core
   * declared it.
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('taxonomy_term');
    $this->installConfig(['filter']);

    Vocabulary::create(['vid' => 'strict', 'name' => 'Strict'])
      ->setThirdPartySetting('neo_taxonomy', 'description_required', TRUE)
      ->save();
    Vocabulary::create(['vid' => 'loose', 'name' => 'Loose'])->save();
  }

  /**
   * All five hooks answer from the two classes, and no function is left.
   *
   * Acceptance criterion: it registers all five hooks from the two classes
   * rather than from the `.module`, and no function remains in
   * `neo_taxonomy.module`.
   *
   * Both halves are needed. A class-based implementation registered beside a
   * surviving global would run the same body twice, and a global left behind
   * would still be found by the collector. The library alter is the one hook
   * with no acceptance criterion of its own, so it is driven here rather than
   * merely counted: three lines that blank one library's CSS, proving the class
   * answers rather than only that it is listed.
   */
  public function testRegistersAllFiveHooksFromTheTwoClasses(): void {
    $expected = [
      'entity_bundle_field_info' => NeoTaxonomyHooks::class . '::entityBundleFieldInfo',
      'library_info_alter' => NeoTaxonomyHooks::class . '::libraryInfoAlter',
      'form_alter' => NeoTaxonomyFormHooks::class . '::formAlter',
      'form_taxonomy_overview_vocabularies_alter' => NeoTaxonomyFormHooks::class . '::formTaxonomyOverviewVocabulariesAlter',
      'form_taxonomy_overview_terms_alter' => NeoTaxonomyFormHooks::class . '::formTaxonomyOverviewTermsAlter',
    ];
    foreach ($expected as $hook => $identifier) {
      $implementations = $this->hookImplementations($hook);
      $this->assertContains(
        'neo_taxonomy: ' . $identifier,
        $implementations,
        sprintf('hook_%s is implemented by a class.', $hook)
      );
      $this->assertNotContains(
        'neo_taxonomy: neo_taxonomy_' . $hook,
        $implementations,
        sprintf('No procedural implementation of %s is registered.', $hook)
      );
    }

    // The five hooks, the two form callbacks and the one helper: nothing the
    // file held is a global function any more.
    foreach ([
      'neo_taxonomy_entity_bundle_field_info',
      'neo_taxonomy_library_info_alter',
      'neo_taxonomy_form_alter',
      'neo_taxonomy_form_taxonomy_overview_vocabularies_alter',
      'neo_taxonomy_form_taxonomy_overview_terms_alter',
      'neo_taxonomy_description_after_build',
      'neo_taxonomy_form_taxonomy_vocabulary_form_submit',
      'neo_taxonomy_form_alter_form_taxonomy_vocabulary',
    ] as $function) {
      $this->assertFalse(
        function_exists($function),
        sprintf('%s() no longer exists.', $function)
      );
    }
    $this->assertFileDoesNotExist(
      $this->packageRoot() . '/modules/neo_taxonomy/neo_taxonomy.module',
      'Nothing was left in the .module file, so it is gone.'
    );

    // And the library alter still decides what it decided: `taxonomy_manager`'s
    // form CSS is blanked, everything else about that extension is untouched,
    // and no other extension is reached at all.
    $libraries = [
      'form' => ['css' => [['data' => 'css/taxonomy-manager.form.css']]],
      'tree' => ['css' => [['data' => 'css/taxonomy-manager.tree.css']]],
    ];
    $extension = 'taxonomy_manager';
    $this->container->get('module_handler')->alter('library_info', $libraries, $extension);
    $this->assertSame([], $libraries['form']['css']);
    $this->assertSame([['data' => 'css/taxonomy-manager.tree.css']], $libraries['tree']['css']);

    $other = ['form' => ['css' => [['data' => 'css/other.css']]]];
    $other_extension = 'taxonomy';
    $this->container->get('module_handler')->alter('library_info', $other, $other_extension);
    $this->assertSame([['data' => 'css/other.css']], $other['form']['css']);
  }

  /**
   * The description is required only where the vocabulary asked for it.
   *
   * Acceptance criterion: it makes a term's description field required for a
   * vocabulary whose third-party setting asks for it, and leaves other
   * vocabularies alone.
   *
   * Driven through the entity field manager, which is what invokes
   * `hook_entity_bundle_field_info` in production, rather than by calling the
   * method: the hook's whole job is to be found for the right bundle.
   */
  public function testMakesTheDescriptionRequiredOnlyWhereTheVocabularyAsked(): void {
    $fieldManager = $this->container->get('entity_field.manager');

    $strict = $fieldManager->getFieldDefinitions('taxonomy_term', 'strict');
    $this->assertTrue($strict['description']->isRequired(), 'The vocabulary that asked gets it.');

    $loose = $fieldManager->getFieldDefinitions('taxonomy_term', 'loose');
    $this->assertFalse($loose['description']->isRequired(), 'The vocabulary that did not is untouched.');

    // The base definition itself is not mutated — the hook clones it, which is
    // what keeps one vocabulary's setting out of every other vocabulary.
    $base = $fieldManager->getBaseFieldDefinitions('taxonomy_term');
    $this->assertFalse($base['description']->isRequired(), 'The base definition is left alone.');

    // Another entity type with a description base field is not reached, which
    // is the entity type half of the guard.
    $userFields = $fieldManager->getFieldDefinitions('user', 'user');
    $this->assertArrayNotHasKey('description', $userFields);

    // The behaviour above came from the hook class, not from a function the
    // collector is still reading out of the `.module` file.
    $this->assertContains(
      'neo_taxonomy: ' . NeoTaxonomyHooks::class . '::entityBundleFieldInfo',
      $this->hookImplementations('entity_bundle_field_info')
    );
    // And the config factory it reads the setting through is a constructor
    // argument rather than a static reach.
    $constructor = (new \ReflectionClass(NeoTaxonomyHooks::class))->getConstructor();
    $this->assertNotNull($constructor, 'The class declares a constructor.');
    $types = [];
    foreach ($constructor->getParameters() as $parameter) {
      $types[] = (string) $parameter->getType();
    }
    $this->assertContains(ConfigFactoryInterface::class, $types);
  }

  /**
   * The package root.
   *
   * @return string
   *   The absolute path.
   */
  protected function packageRoot(): string {
    return dirname(__DIR__, 3);
  }

  /**
   * The implementations the hook system resolved for a hook, in order.
   *
   * @param string $hook
   *   The hook name, without the `hook_` prefix.
   *
   * @return string[]
   *   One `module: identifier` string per implementation, where the identifier
   *   is `Class::method` for a class-based implementation and the function name
   *   for a procedural one.
   */
  protected function hookImplementations(string $hook): array {
    $implementations = [];
    $this->container->get('module_handler')->invokeAllWith(
      $hook,
      static function (callable $implementation, string $module) use (&$implementations): void {
        if (is_array($implementation)) {
          $identifier = get_class($implementation[0]) . '::' . $implementation[1];
        }
        elseif (is_string($implementation)) {
          $identifier = $implementation;
        }
        else {
          $identifier = get_debug_type($implementation);
        }
        $implementations[] = $module . ': ' . $identifier;
      }
    );
    return $implementations;
  }

}
