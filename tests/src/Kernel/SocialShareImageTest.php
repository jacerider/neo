<?php

declare(strict_types=1);

namespace Drupal\Tests\neo\Kernel;

use Drupal\Core\Path\PathMatcher;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\image\Entity\ImageStyle;
use Drupal\neo\Hook\NeoTokensHooks;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;

/**
 * The share image tokens keep the image's own format.
 *
 * `[neo:image]` and `[neo:logo]` fill og:image and twitter:image. Built with
 * neo_image's styles they came out as AVIF, which the social networks do not
 * show in link previews. Without parameters they are now built with the
 * neo_social image style — shipped as optional config, added to existing sites
 * by neo_update_11002() — which fits the image within 1200×630 in its source
 * format. The derivative is written when the token is resolved, so the width
 * and height tokens can read it.
 *
 * neo_image is not installed here: with the style present its branch is never
 * reached for a parameterless token, and without the style the fallback is
 * what SmartTokenLogoAndImageTest already pins.
 *
 * @see \Drupal\neo\Hook\NeoTokensHooks::imageData()
 * @see neo_update_11002()
 */
#[Group('neo')]
final class SocialShareImageTest extends KernelTestBase {

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
    'token',
    'neo',
    'neo_test',
  ];

  /**
   * The source image: a 2400×1000 PNG.
   */
  private const SOURCE = 'public://neo-share-source.png';

  /**
   * The instance under test, rebuilt when the request changes.
   */
  private ?NeoTokensHooks $hooks = NULL;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('file');
    $this->installConfig(['system', 'file', 'image', 'neo']);
    \Drupal::service('router.builder')->rebuild();

    $image = imagecreatetruecolor(2400, 1000);
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
   * The share image is a PNG from the neo_social style, not AVIF.
   */
  public function testTheShareImageKeepsItsFormat(): void {
    $this->assertInstanceOf(ImageStyle::class, ImageStyle::load('neo_social'), 'Premise: the optional config installed the style.');

    $url = $this->invoke('image', [[], NULL]);
    $this->assertStringContainsString('/styles/neo_social/public/neo-share-source.png', $url);
    $this->assertStringContainsString('itok=', $url);
    $this->assertStringNotContainsString('.avif', $url);

    // Fitted within 1200×630, and written so the dimension tokens can read it.
    $this->assertSame('1200', $this->invoke('image', [['width'], NULL]));
    $this->assertSame('500', $this->invoke('image', [['height'], NULL]));
    $this->assertFileExists(ImageStyle::load('neo_social')->buildUri(self::SOURCE));
  }

  /**
   * Without the style the tokens fall back as before.
   */
  public function testWithoutTheStyleTheFallbackIsUnchanged(): void {
    ImageStyle::load('neo_social')->delete();
    $url = $this->invoke('image', [[], NULL]);
    $this->assertSame(\Drupal::service('file_url_generator')->generateAbsoluteString(self::SOURCE), $url);
  }

  /**
   * The update adds the style to a site that lacks it, and only then.
   */
  public function testTheUpdateAddsTheStyle(): void {
    ImageStyle::load('neo_social')->delete();
    \Drupal::moduleHandler()->loadInclude('neo', 'install');

    $this->assertStringContainsString('Added the neo_social image style', neo_update_11002());
    $style = ImageStyle::load('neo_social');
    $this->assertInstanceOf(ImageStyle::class, $style);
    $effect = $style->getEffects()->getIterator()->current();
    $this->assertSame('image_scale', $effect->getPluginId());
    $this->assertSame(['width' => 1200, 'height' => 630, 'upscale' => FALSE], $effect->getConfiguration()['data']);

    $this->assertStringContainsString('already exists', neo_update_11002());
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
