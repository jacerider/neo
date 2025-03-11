<?php

namespace Drupal\neo_menu_link;

use Drupal\user\Plugin\Menu\LoginLogoutMenuLink;

/**
 * A menu link that shows "Log in" or "Log out" as appropriate.
 */
class NeoMenuLinkLoginLogoutMenuLink extends LoginLogoutMenuLink {

  /**
   * {@inheritdoc}
   */
  protected $overrideAllowed = [
    'menu_name' => 1,
    'parent' => 1,
    'weight' => 1,
    'expanded' => 1,
    'enabled' => 1,
    // Allow override of this variable.
    'options' => 1,
  ];

}
