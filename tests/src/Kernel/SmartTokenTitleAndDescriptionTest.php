<?php

declare(strict_types=1);

namespace Drupal\Tests\neo\Kernel;

use Drupal\Core\Path\PathMatcher;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;

/**
 * Characterises the two text resolvers behind `[neo:title]` and `[neo:*]`.
 *
 * `neo_tokens_title()` and `neo_tokens_description()` are what every page title
 * and every meta description on a Neo site ultimately comes from. Both are
 * short, both have four fallbacks, and neither has ever been asserted.
 *
 * **The title**, in the order it actually runs:
 *
 * 1. `path.matcher` says front page — return `system.site:name` and stop. The
 *    alter hook, the route and the entity are all behind this `return`.
 * 2. `hook_neo_token_title_alter()` runs, and a value it sets wins outright.
 * 3. Otherwise, if the current request carries a route object, the resolved
 *    page title for it, passed through `token_render_array_value()`.
 * 4. **Else if** there is an entity, its `label()`.
 * 5. Otherwise `NULL`.
 *
 * **The description**, in the same shape:
 *
 * 1. Front page — return `system.site:slogan` and stop.
 * 2. `hook_neo_token_description_alter()` runs, and a value it sets wins.
 * 3. Otherwise, a `taxonomy_term` contributes its own `description` value and
 *    every other entity type contributes nothing at all.
 * 4. A falsy result falls back to `system.site:slogan`.
 *
 * The slogan is therefore reachable by two separate routes — the front-page
 * short-circuit at step 1 and the fallback at step 4 — which is why both have
 * their own test method: a refactor that collapsed them would still pass one.
 *
 * The resolvers are called directly rather than through `neo_tokens()`. The
 * dispatcher and its two caches are ticket 11's subject and are pinned in
 * `SmartTokenDispatcherTest`; driving these two through it would only put a
 * memoisation layer between the assertion and the branch it is about.
 *
 * **The contrib `token` module is installed deliberately.**
 * `neo_tokens_title()` calls `token_render_array_value()`, a global function
 * that contrib `token` provides. `neo.info.yml` declares no dependency on
 * `token` and `composer.json` requires no such package, so on a site without it
 * this token is a fatal error on any non-front-page route that has a route
 * object — which is nearly every page. Installing it here is compensating for
 * an undeclared dependency so the branch can run at all. It is a finding for
 * `docs/improvements/neo.md`, not something this test repairs.
 *
 * **Characterised, not repaired.** Four further answers pinned below are quirks
 * rather than intentions:
 *
 * 1. **The route branch and the entity branch are `if`/`elseif`.** A request
 *    that carries a route object which resolves no title does not fall back to
 *    the entity label — it returns the empty string, because
 *    `token_render_array_value(NULL)` casts `NULL` to `''` and that is the end
 *    of the function. Only the *absence* of a route object reaches the entity.
 *    Pinned in testResolvesTheTitleFromTheRouteAndFallsBackToTheEntityLabel.
 * 2. **`neo_tokens_title()`'s own docblock is wrong.** It documents three steps
 *    — the alter hook, the route, the entity — and does not mention the
 *    front-page short-circuit that runs before all three and that no hook can
 *    reach. Pinned in
 *    testReturnsTheSiteNameOnTheFrontPageWithoutTheHookTheRouteOrTheEntity.
 * 3. **A falsy-but-present value is indistinguishable from nothing.** Both
 *    resolvers test their altered value with `if (!$value)`, so a hook that
 *    deliberately sets `'0'` — a legitimate title, and a legitimate
 *    description — is discarded and the branches behind it run anyway. Pinned
 *    in testTakesTheTitleAnAlterHookSetsInPreferenceToTheRouteAndTheEntity and
 *    testFallsBackToTheSiteSloganWhenTheEntityYieldsNoDescription.
 * 4. **Neither front-page short-circuit checks that it found anything.** An
 *    unset site name or an unset slogan is returned as the empty string, and
 *    nothing below is consulted to make up for it. Pinned in the two front-page
 *    methods.
 */
#[Group('neo')]
final class SmartTokenTitleAndDescriptionTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   *
   * `neo` will not install on `system` and `user` alone: `neo.services.yml`
   * declares `neo.linkit_resolver`, which needs `path_alias.manager` and
   * `plugin.manager.linkit.substitution`, so the container fails to compile
   * without `path_alias` and `linkit`. `KernelTestBase` installs exactly what
   * is named and does not resolve the info file's dependency closure, so
   * `neo_build`, `neo_color` and `neo_icon` stay out.
   *
   * `node` and `taxonomy` supply the two entity types the description resolver
   * distinguishes between, `user` a third that is neither. `token` is here for
   * the undeclared dependency named in the class docblock.
   */
  protected static $modules = [
    'system',
    'user',
    'path_alias',
    'linkit',
    'field',
    'text',
    'filter',
    'node',
    'taxonomy',
    'token',
    'neo',
    'neo_test',
  ];

  /**
   * A node, so the resolvers have an entity that is not a taxonomy term.
   *
   * @var \Drupal\node\Entity\Node
   */
  protected Node $node;

  /**
   * A taxonomy term carrying a description.
   *
   * @var \Drupal\taxonomy\Entity\Term
   */
  protected Term $term;

  /**
   * A taxonomy term with no description value at all.
   *
   * @var \Drupal\taxonomy\Entity\Term
   */
  protected Term $emptyTerm;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    // Updating a node deletes its access grants, which needs the table.
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'filter', 'node']);

    // `neo_tokens_title()` and `neo_tokens_description()` live in an include
    // the module loads on demand, so nothing has pulled it in yet.
    \Drupal::moduleHandler()->loadInclude('neo', 'tokens.inc');
    // The front-page comparison turns the current route into a path through the
    // URL generator, so the fixture routes have to be in the route provider.
    \Drupal::service('router.builder')->rebuild();

    $this->config('system.site')
      ->set('name', 'Fixture site name')
      ->set('slogan', 'Fixture site slogan')
      ->save();

    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    $this->node = Node::create(['type' => 'page', 'title' => 'The node label']);
    $this->node->save();

    Vocabulary::create(['vid' => 'tags', 'name' => 'Tags'])->save();
    $this->term = Term::create([
      'vid' => 'tags',
      'name' => 'The term label',
      'description' => [
        'value' => 'The term description',
        'format' => 'plain_text',
      ],
    ]);
    $this->term->save();
    $this->emptyTerm = Term::create(['vid' => 'tags', 'name' => 'The empty term']);
    $this->emptyTerm->save();
  }

  /**
   * Returns the site name on the front page, and nothing else runs.
   *
   * Covers: "it returns the site name on the front page without invoking the
   * alter hook, the route or the entity".
   *
   * All three of the branches below the short-circuit are armed before the
   * call — the alter hook is switched on, the request carries a route object
   * whose resolved title is a different string, and the entity's label is a
   * third — so the site name being returned is the short-circuit and not a
   * coincidence. The fixture records every invocation of the title hook whether
   * or not it is switched on, so an untouched record is direct evidence that
   * the hook never ran rather than merely that it did not win.
   *
   * Quirks 2 and 4 from the class docblock are pinned in the last two blocks:
   * the function's own docblock documents three steps and omits this one, and
   * an unset site name is returned as the empty string rather than falling
   * through to any of them.
   */
  public function testReturnsTheSiteNameOnTheFrontPageWithoutTheHookTheRouteOrTheEntity(): void {
    $this->config('system.site')->set('page.front', '/neo-test/open')->save();
    \Drupal::state()->set('neo_test.token_title_alter', 'The hook title');
    $this->enterRequest('neo_test.open');
    $this->assertTrue(\Drupal::service('path.matcher')->isFrontPage());

    // The three things the short-circuit is about to skip past, each of which
    // would answer with something other than the site name.
    $this->assertSame('The node label', $this->node->label());
    $this->assertSame('Neo test open', (string) \Drupal::service('title_resolver')->getTitle(
      \Drupal::request(),
      \Drupal::request()->attributes->get(RouteObjectInterface::ROUTE_OBJECT)
    ));

    $this->assertSame('Fixture site name', neo_tokens_title([], $this->node));
    $this->assertNull(\Drupal::state()->get('neo_test.token_title_alter_seen'));

    // The parameters are not consulted either, and neither is the absence of an
    // entity: the front page answers the same way for every caller.
    $this->assertSame('Fixture site name', neo_tokens_title(['crop', '1200'], NULL));
    $this->assertNull(\Drupal::state()->get('neo_test.token_title_alter_seen'));

    // Quirk 2: the function's own docblock lists the three branches this test
    // just proved unreachable, and never mentions the front page at all.
    $docblock = (new \ReflectionFunction('neo_tokens_title'))->getDocComment();
    $this->assertStringContainsString('hook_neo_token_title_alter()', (string) $docblock);
    $this->assertStringNotContainsString('front', (string) $docblock);

    // Quirk 4: an unset site name is the empty string, not a fallback.
    $this->config('system.site')->set('name', '')->save();
    $this->assertSame('', neo_tokens_title([], $this->node));
    $this->assertNull(\Drupal::state()->get('neo_test.token_title_alter_seen'));
  }

  /**
   * Prefers the title an alter hook sets over the route and the entity.
   *
   * Covers: "it takes the title an alter hook sets in preference to the route
   * and the entity".
   *
   * The hook is handed the running value, the parameters and the entity, and
   * the value it is handed on the first pass is `NULL` — there is nothing for
   * it to refine, only something for it to supply. The route object is on the
   * request throughout, so the hook is beating a branch that would otherwise
   * have answered.
   *
   * The last block pins quirk 3: the hook's contribution is tested with
   * `if (!$title)`, so `'0'` is discarded and the route answers instead.
   */
  public function testTakesTheTitleAnAlterHookSetsInPreferenceToTheRouteAndTheEntity(): void {
    $this->enterRequest('neo_test.open');
    $this->assertFalse(\Drupal::service('path.matcher')->isFrontPage());

    \Drupal::state()->set('neo_test.token_title_alter', 'The hook title');
    $this->assertSame('The hook title', neo_tokens_title(['a', 'b'], $this->node));

    $seen = \Drupal::state()->get('neo_test.token_title_alter_seen');
    $this->assertNull($seen['title']);
    $this->assertSame(['a', 'b'], $seen['params']);
    $this->assertSame('node:' . $this->node->id(), $seen['entity']);

    // With no entity at all the hook is still the first thing consulted, and
    // still beats the route.
    $this->assertSame('The hook title', neo_tokens_title([], NULL));
    $this->assertNull(\Drupal::state()->get('neo_test.token_title_alter_seen')['entity']);

    // Switched off, the branches behind it answer, which is what makes the two
    // assertions above a preference rather than the only available value.
    \Drupal::state()->delete('neo_test.token_title_alter');
    $this->assertSame('Neo test open', neo_tokens_title([], $this->node));

    // Quirk 3: `'0'` is a legitimate title and is discarded as though the hook
    // had contributed nothing.
    \Drupal::state()->set('neo_test.token_title_alter', '0');
    $this->assertSame('Neo test open', neo_tokens_title([], $this->node));
    $this->assertSame('0', \Drupal::state()->get('neo_test.token_title_alter'));
  }

  /**
   * Resolves from the route, and falls back to the entity label without one.
   *
   * Covers: "it resolves the title from the current route when nothing is
   * altered in, and falls back to the entity label when there is no route
   * object".
   *
   * The route branch is the one that needs contrib `token`: the resolved title
   * is a `TranslatableMarkup` and `token_render_array_value()` is what turns it
   * into a string. The assertion that the function exists is not decoration —
   * without it this method is a fatal error rather than a failure, which is
   * exactly what a site without `token` gets.
   *
   * The middle block pins quirk 1: the two branches are `if`/`elseif`, so a
   * route object that resolves no title returns the empty string and the entity
   * label behind it is never reached.
   */
  public function testResolvesTheTitleFromTheRouteAndFallsBackToTheEntityLabel(): void {
    $this->assertTrue(function_exists('token_render_array_value'));
    $this->enterRequest('neo_test.open');
    $this->assertFalse(\Drupal::service('path.matcher')->isFrontPage());
    \Drupal::state()->delete('neo_test.token_title_alter');

    // The route wins over the entity, and the value is a rendered string rather
    // than the `TranslatableMarkup` the title resolver returns.
    $title = neo_tokens_title([], $this->node);
    $this->assertSame('Neo test open', $title);
    $this->assertIsString($title);
    // The hook did run and did decline; the route is the second branch, not the
    // first.
    $this->assertSame(
      'node:' . $this->node->id(),
      \Drupal::state()->get('neo_test.token_title_alter_seen')['entity']
    );

    // The route answers with no entity present at all.
    $this->assertSame('Neo test open', neo_tokens_title([], NULL));

    // Quirk 1: a route object that resolves no title short-circuits the entity
    // label, because the two branches are `if`/`elseif` and
    // `token_render_array_value(NULL)` is `''`.
    $this->enterRequestWithRouteObject(new Route('/neo-test/titleless'));
    $this->assertNull(\Drupal::service('title_resolver')->getTitle(
      \Drupal::request(),
      \Drupal::request()->attributes->get(RouteObjectInterface::ROUTE_OBJECT)
    ));
    $this->assertSame('', neo_tokens_title([], $this->node));

    // With no route object on the request the entity's label is the answer.
    $this->enterRequest(NULL);
    $this->assertSame('The node label', neo_tokens_title([], $this->node));
    $this->assertSame('The term label', neo_tokens_title([], $this->term));

    // And with neither a route object nor an entity, nothing resolves.
    $this->assertNull(neo_tokens_title([], NULL));
  }

  /**
   * Returns the site slogan on the front page.
   *
   * Covers: "it returns the site slogan for the description on the front page".
   *
   * This is the first of the slogan's two routes into the answer. The entity in
   * scope is a taxonomy term carrying a description of its own, so the slogan
   * being returned is the short-circuit rather than the step-4 fallback that
   * the other route takes — and the alter hook is switched on and never
   * invoked, exactly as the title's short-circuit leaves it.
   *
   * The last block pins quirk 4: an unset slogan is the empty string, and
   * nothing below is consulted to make up for it.
   */
  public function testReturnsTheSiteSloganForTheDescriptionOnTheFrontPage(): void {
    $this->config('system.site')->set('page.front', '/neo-test/open')->save();
    \Drupal::state()->set('neo_test.token_description_alter', 'The hook description');
    $this->enterRequest('neo_test.open');
    $this->assertTrue(\Drupal::service('path.matcher')->isFrontPage());

    // The term would answer with its own description off the front page.
    $this->assertSame('The term description', $this->term->get('description')->value);

    $this->assertSame('Fixture site slogan', neo_tokens_description([], $this->term));
    $this->assertNull(\Drupal::state()->get('neo_test.token_description_alter_seen'));

    // The parameters are not consulted, and neither is the absence of an
    // entity.
    $this->assertSame('Fixture site slogan', neo_tokens_description(['crop'], NULL));
    $this->assertNull(\Drupal::state()->get('neo_test.token_description_alter_seen'));

    // Quirk 4: an unset slogan is the empty string, not a fallback.
    $this->config('system.site')->set('slogan', '')->save();
    $this->assertSame('', neo_tokens_description([], $this->term));
    $this->assertNull(\Drupal::state()->get('neo_test.token_description_alter_seen'));
  }

  /**
   * Prefers the description an alter hook sets over the entity and the slogan.
   *
   * Covers: "it takes the description an alter hook sets in preference to the
   * entity and the slogan".
   *
   * The entity in scope is a term that carries its own description and the
   * slogan is set, so both of the branches behind the hook would have answered.
   * As with the title, the value the hook is handed is `NULL`.
   */
  public function testTakesTheDescriptionAnAlterHookSetsInPreferenceToTheEntityAndTheSlogan(): void {
    $this->enterRequest('neo_test.open');
    $this->assertFalse(\Drupal::service('path.matcher')->isFrontPage());

    \Drupal::state()->set('neo_test.token_description_alter', 'The hook description');
    $this->assertSame('The hook description', neo_tokens_description(['x'], $this->term));

    $seen = \Drupal::state()->get('neo_test.token_description_alter_seen');
    $this->assertNull($seen['description']);
    $this->assertSame(['x'], $seen['params']);
    $this->assertSame('taxonomy_term:' . $this->term->id(), $seen['entity']);

    // It beats the slogan with no entity in scope at all.
    $this->assertSame('The hook description', neo_tokens_description([], NULL));
    $this->assertNull(\Drupal::state()->get('neo_test.token_description_alter_seen')['entity']);

    // Switched off, both branches behind it answer, which is what makes the
    // above a preference.
    \Drupal::state()->delete('neo_test.token_description_alter');
    $this->assertSame('The term description', neo_tokens_description([], $this->term));
    $this->assertSame('Fixture site slogan', neo_tokens_description([], $this->node));
  }

  /**
   * Takes a term's description, and nothing from any other entity type.
   *
   * Covers: "it takes a taxonomy term's own description and takes nothing from
   * an entity of any other type".
   *
   * The `match` is on the entity **type id**, not on whether the entity has
   * something description-shaped, so a node and a user contribute nothing even
   * though both carry text a description could plausibly have come from. The
   * slogan is blanked for the second half so that "nothing from the entity" is
   * distinguishable from "the slogan" — with a slogan set, every one of these
   * would answer with the same string for the opposite reason.
   */
  public function testTakesTheTaxonomyTermsOwnDescriptionAndNothingFromAnyOtherEntityType(): void {
    $this->enterRequest('neo_test.open');
    $this->assertFalse(\Drupal::service('path.matcher')->isFrontPage());
    \Drupal::state()->delete('neo_test.token_description_alter');

    $this->assertSame('The term description', neo_tokens_description([], $this->term));

    // With the slogan out of the way, what the entity contributes is all that
    // is left — and for anything but a term that is nothing.
    $this->config('system.site')->set('slogan', '')->save();
    $this->assertSame('The term description', neo_tokens_description([], $this->term));

    $this->assertSame('', neo_tokens_description([], $this->node));
    $this->assertSame('The node label', $this->node->label());

    $user = User::create(['name' => 'describable']);
    $user->save();
    $this->assertSame('', neo_tokens_description([], $user));

    // The hook still saw every one of them; the entity branch is what declined.
    $this->assertSame(
      'user:' . $user->id(),
      \Drupal::state()->get('neo_test.token_description_alter_seen')['entity']
    );
  }

  /**
   * Falls back to the slogan when the entity yields no description.
   *
   * Covers: "it falls back to the site slogan when the entity yields no
   * description".
   *
   * This is the slogan's second route into the answer, and the one the front
   * page never takes. Three different ways of yielding nothing all land on it:
   * a term with no description value, an entity type the `match` has no arm
   * for, and no entity at all.
   *
   * The last block pins quirk 3 on this side of the file: the fallback test is
   * `if (!$description)`, so a term whose description is the single character
   * `'0'` is treated as having none.
   */
  public function testFallsBackToTheSiteSloganWhenTheEntityYieldsNoDescription(): void {
    $this->enterRequest('neo_test.open');
    $this->assertFalse(\Drupal::service('path.matcher')->isFrontPage());
    \Drupal::state()->delete('neo_test.token_description_alter');

    // A term whose description was never set.
    $this->assertNull($this->emptyTerm->get('description')->value);
    $this->assertSame('Fixture site slogan', neo_tokens_description([], $this->emptyTerm));

    // An entity type the match has no arm for.
    $this->assertSame('Fixture site slogan', neo_tokens_description([], $this->node));

    // No entity at all — the fallback is reached without the entity branch
    // running.
    $this->assertSame('Fixture site slogan', neo_tokens_description([], NULL));
    $this->assertNull(\Drupal::state()->get('neo_test.token_description_alter_seen')['entity']);

    // Quirk 3: `'0'` is a description and is discarded as though it were not.
    $this->term->set('description', ['value' => '0', 'format' => 'plain_text']);
    $this->assertSame('0', $this->term->get('description')->value);
    $this->assertSame('Fixture site slogan', neo_tokens_description([], $this->term));
  }

  /**
   * Enters a fresh request carrying the named route, or no route at all.
   *
   * A new request object each time is what makes the front-page answer
   * observable more than once in a process: `CurrentRouteMatch` memoises its
   * match per request object and `PathMatcher` memoises its own answer on
   * itself, so both are replaced rather than mutated.
   *
   * @param string|null $routeName
   *   The route to put on the request, or NULL for a request with no route
   *   object — the shape a replacement outside a routed context really has.
   */
  private function enterRequest(?string $routeName): void {
    $request = Request::create('/');
    if ($routeName !== NULL) {
      $request->attributes->set(
        RouteObjectInterface::ROUTE_OBJECT,
        \Drupal::service('router.route_provider')->getRouteByName($routeName)
      );
      $request->attributes->set(RouteObjectInterface::ROUTE_NAME, $routeName);
    }
    $this->pushRequest($request);
  }

  /**
   * Enters a fresh request carrying a route object with no name behind it.
   *
   * `PathMatcher` needs a route *name* to build a path to compare against
   * `page.front`, so a nameless route object is never the front page — which
   * leaves the title resolver looking at a route that has no `_title` to give.
   *
   * @param \Symfony\Component\Routing\Route $route
   *   The route object to hang on the request.
   */
  private function enterRequestWithRouteObject(Route $route): void {
    $request = Request::create('/');
    $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, $route);
    $this->pushRequest($request);
  }

  /**
   * Makes a request current and gives `path.matcher` an unused instance.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request to push onto the stack.
   */
  private function pushRequest(Request $request): void {
    // The harness's own teardown reads a session off whatever request is
    // current, so the mock session `DrupalKernel::preHandle()` started has to
    // travel with the replacement — which is also what a real request has.
    $current = \Drupal::requestStack()->getCurrentRequest();
    if ($current !== NULL && $current->hasSession()) {
      $request->setSession($current->getSession());
    }
    \Drupal::requestStack()->push($request);
    $this->container->set('path.matcher', new PathMatcher(
      $this->container->get('config.factory'),
      $this->container->get('current_route_match')
    ));
    \Drupal::state()->delete('neo_test.token_title_alter_seen');
    \Drupal::state()->delete('neo_test.token_description_alter_seen');
  }

}
