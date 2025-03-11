<?php

namespace Drupal\neo_menu_link\Form;

use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Menu\Form\MenuLinkDefaultForm;

/**
 * Provides an edit form for static menu links.
 *
 * @see \Drupal\Core\Menu\MenuLinkDefault
 */
class NeoMenuLinkDefaultForm extends MenuLinkDefaultForm {

  use DependencySerializationTrait;

}
