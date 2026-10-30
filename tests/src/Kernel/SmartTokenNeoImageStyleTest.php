<?php

declare(strict_types=1);

namespace Drupal\Tests\neo\Kernel;

use Drupal\Core\Path\PathMatcher;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\neo\Hook\NeoTokensHooks;
use Drupal\neo_image\NeoImageStyle;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;

/**
 * The image tokens never build a neo_image derivative while a page renders.
 *
 * `[neo:image:<type>:<args>]` names a neo_image style. The token used to
 * build its derivative there and then, and every neo_image style converts to
 * AVIF: on a cold page that was a full re-encode of the source inside the
 * page request. Large sources made each one take tens of seconds and over
 * 100MB, and a few at once had Pantheon kill the PHP workers (502).
 *
 * The worst of it was a parameter neo_image does not know. neo_seo asks for
 * `[neo:image:alt]` beside every share image, and `alt` became a style with
 * no effects but its format conversion — the whole original, as AVIF — on
 * every page. An unknown parameter now has no value, and a known one only
 * names its derivative, which neo_image builds when it is first fetched.
 *
 * Skipped where neo_image is not in the codebase: neo does not require it.
 *
 * @see \Drupal\neo\Hook\NeoTokensHooks::imageData()
 */
#[Group('neo')]
final class SmartTokenNeoImageStyleTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'path_alias',
    'linkit',
    'file',
    'image',
    'breakpoint',
    'token',
    'neo',
    'neo_settings',
    'neo_image',
    'neo_test',
  ];

  /**
   * The source image: a 2400×1600 PNG.
   */
  private const SOURCE = 'public://neo-token-style-source.png';

  /**
   * The instance under test, rebuilt when the request changes.
   */
  private ?NeoTokensHooks $hooks = NULL;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    if (!class_exists(NeoImageStyle::class)) {
      $this->markTestSkipped('neo_image is not in the codebase.');
    }
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('file');
    $this->installConfig(['system', 'file', 'image', 'neo']);
    \Drupal::service('router.builder')->rebuild();

    $image = imagecreatetruecolor(2400, 1600);
    ob_start();
    imagepng($image);
    file_put_contents(self::SOURCE, (string) ob_get_clean());
    imagedestroy($image);
    clearstatcache();

    // Off the front page, where the image token asks the image alter hook.
    $this->enterRequest('neo_test.open');
    \Drupal::state()->set('neo_test.token_image_alter', self::SOURCE);
  }

  /**
   * A parameter neo_image does not know has no value and builds nothing.
   */
  public function testAnUnknownParameterHasNoValue(): void {
    $this->assertNull($this->invoke('image', [['alt'], NULL]));
    $this->assertNull($this->invoke('logo', [['alt'], NULL]));
    $this->assertSame([], $this->derivatives(), 'No derivative was written.');
  }

  /**
   * A style is named, not built, and its dimensions come from the source.
   */
  public function testStyleIsNamedNotBuilt(): void {
    $url = $this->invoke('image', [['crop', '600', '400'], NULL]);
    $this->assertStringContainsString('/styles/neo-c--w-600_h-400', $url);
    $this->assertSame('600', $this->invoke('image', [['width', 'crop', '600', '400'], NULL]));
    $this->assertSame('400', $this->invoke('image', [['height', 'crop', '600', '400'], NULL]));
    $this->assertSame([], $this->derivatives(), 'No derivative was written.');
  }

  /**
   * The style-derivative files under public://styles, relative to it.
   *
   * @return string[]
   *   The files, empty when none was written.
   */
  private function derivatives(): array {
    $root = \Drupal::service('file_system')->realpath('public://styles');
    if (!$root || !is_dir($root)) {
      return [];
    }
    $files = [];
    $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
      $files[] = substr($file->getPathname(), strlen($root) + 1);
    }
    return $files;
  }

  /**
   * Replaces the current request with one on the given route.
   *
   * @param string $routeName
   *   The route to put on the request.
   */
  private function enterRequest(string $routeName): void {
    $request = Request::create('/');
    $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, \Drupal::service('router.route_provider')->getRouteByName($routeName));
    $request->attributes->set(RouteObjectInterface::ROUTE_NAME, $routeName);
    $current = \Drupal::requestStack()->getCurrentRequest();
    if ($current !== NULL && $current->hasSession()) {
      $request->setSession($current->getSession());
    }
    \Drupal::requestStack()->push($request);
    $this->container->set('path.matcher', new PathMatcher(
      $this->container->get('config.factory'),
      $this->container->get('current_route_match')
    ));
    $this->hooks = NULL;
  }

  /**
   * Calls a private method on the hook class.
   */
  private function invoke(string $method, array $arguments): mixed {
    $this->hooks ??= new NeoTokensHooks(
      $this->container->get('cache.default'),
      $this->container->get('module_handler'),
      $this->container->get('path.matcher'),
      $this->container->get('config.factory'),
      $this->container->get('request_stack'),
      $this->container->get('title_resolver'),
      $this->container->get('file_url_generator'),
      $this->container->get('entity_type.manager'),
    );
    return (new \ReflectionMethod($this->hooks, $method))->invokeArgs($this->hooks, $arguments);
  }

}
