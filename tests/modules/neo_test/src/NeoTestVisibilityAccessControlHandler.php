<?php

declare(strict_types=1);

namespace Drupal\neo_test;

use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityHandlerInterface;
use Drupal\neo\VisibilityEntityAccessControlTrait;

/**
 * The access handler both fixture entity types use.
 *
 * The whole class is the trait. Nothing is overridden, and that is the point:
 * `getDefaultVisibilityAccess()` is deliberately **left alone**.
 *
 * `neo_settings` and `neo_toolbar` both override it — one to return
 * `AccessResult::allowed()` with a `user.permissions` cache context, the other
 * likewise — so the trait's own default has never run anywhere in the stack. It
 * calls `parent::checkAccess($entity, $operation, $account, TRUE)`, passing a
 * fourth argument to a three-parameter core signature that ignores it, and what
 * that resolves to is unknown until a consumer that has not overridden it says
 * so. This handler is that consumer.
 *
 * Both entity types share it because the trait is entity-type agnostic: it
 * branches on whether the entity implements `VisibilityEntityPluginInterface`,
 * not on which type it is. One handler therefore covers the delegation branch
 * and the default branch both.
 *
 * @see \Drupal\neo\VisibilityEntityAccessControlTrait
 */
class NeoTestVisibilityAccessControlHandler extends EntityAccessControlHandler implements EntityHandlerInterface {

  use VisibilityEntityAccessControlTrait;

}
