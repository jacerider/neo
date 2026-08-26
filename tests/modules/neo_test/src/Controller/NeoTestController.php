<?php

declare(strict_types=1);

namespace Drupal\neo_test\Controller;

use Drupal\Core\Controller\ControllerBase;

/**
 * Serves the pages the fixture menu links point at.
 *
 * The routes exist so the menu links have something real to resolve against —
 * a menu tree drops a link whose route does not exist, and access is decided
 * by the route's own requirements. What the page renders is irrelevant; that
 * it renders at all is the point.
 */
final class NeoTestController extends ControllerBase {

  /**
   * Returns a trivial page.
   *
   * @return array
   *   A render array.
   */
  public function page(): array {
    return ['#markup' => 'Neo test page.'];
  }

}
