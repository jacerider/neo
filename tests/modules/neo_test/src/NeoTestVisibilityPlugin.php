<?php

declare(strict_types=1);

namespace Drupal\neo_test;

use Drupal\Component\Plugin\Exception\ContextException;
use Drupal\Component\Plugin\Exception\MissingValueContextException;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Session\AccountInterface;
use Drupal\neo\VisibilityPluginInterface;
use Drupal\neo_test\Entity\NeoTestPluginVisibility;

/**
 * The plugin a `NeoTestPluginVisibility` entity delegates its access answer to.
 *
 * One class serves every permutation the delegation branch of
 * `VisibilityEntityAccessControlTrait::checkAccess()` has, because the answer
 * is read out of the **entity's** settings rather than baked into the plugin.
 * Two entities of the same plugin therefore stay distinguishable, which is what
 * lets a test say which entity's answer it is looking at.
 *
 * Five answers, matching the five outcomes that branch can produce:
 *
 * - `allowed`, `forbidden`, `neutral` — the three access results. `neutral` is
 *   not `forbidden`: the caller sees "no opinion", and what that turns into is
 *   the caller's business.
 * - `missing_context` — throws `ContextException`, which the handler turns into
 *   a forbidden result with `max-age: 0`, because a missing context means the
 *   cacheable metadata is unknown.
 * - `missing_value` — throws `MissingValueContextException`, which the handler
 *   turns into a plain forbidden result that stays cacheable. The distinction
 *   between these two is the whole reason both branches exist.
 *
 * Every result carries a cache tag naming the entity, so a test can prove that
 * a particular entity's plugin answered and not merely that some plugin did.
 *
 * This is not a `\Drupal\Component\Plugin\PluginInspectionInterface` plugin and
 * has no manager. `VisibilityEntityPluginInterface::getPlugin()` is documented
 * to return "mixed", and the only thing the access handler asks of what comes
 * back is whether it is a `VisibilityPluginInterface`. A discovery layer would
 * add a cache to invalidate and nothing a test could reach.
 */
final class NeoTestVisibilityPlugin implements VisibilityPluginInterface {

  /**
   * Answer: an allowed access result.
   */
  const ALLOWED = 'allowed';

  /**
   * Answer: a forbidden access result.
   */
  const FORBIDDEN = 'forbidden';

  /**
   * Answer: a neutral access result.
   */
  const NEUTRAL = 'neutral';

  /**
   * Answer: throw a ContextException.
   */
  const MISSING_CONTEXT = 'missing_context';

  /**
   * Answer: throw a MissingValueContextException.
   */
  const MISSING_VALUE = 'missing_value';

  /**
   * The id of the entity this plugin was built for.
   *
   * @var string
   */
  protected $entityId;

  /**
   * The entity's plugin settings.
   *
   * @var array
   */
  protected $settings;

  /**
   * Constructs the fixture visibility plugin.
   *
   * @param string $entity_id
   *   The id of the entity this plugin was built for.
   * @param array $settings
   *   The entity's own settings, whose `access` key declares the answer.
   */
  public function __construct($entity_id, array $settings = []) {
    $this->entityId = $entity_id;
    $this->settings = $settings;
  }

  /**
   * Returns the cache tag this plugin's answers carry.
   *
   * @param string $entity_id
   *   The entity id.
   *
   * @return string
   *   The cache tag.
   *
   * @see \Drupal\neo_test\Entity\NeoTestPluginVisibility
   */
  public static function cacheTag($entity_id): string {
    return 'neo_test_plugin:' . $entity_id;
  }

  /**
   * {@inheritdoc}
   */
  public function access(AccountInterface $account, $return_as_object = FALSE) {
    $answer = $this->settings['access'] ?? self::ALLOWED;

    if ($answer === self::MISSING_CONTEXT) {
      throw new ContextException(sprintf('The neo_test plugin for "%s" was asked for a context it does not have.', $this->entityId));
    }
    if ($answer === self::MISSING_VALUE) {
      throw new MissingValueContextException([NeoTestPluginVisibility::class]);
    }

    $access = match ($answer) {
      self::FORBIDDEN => AccessResult::forbidden(),
      self::NEUTRAL => AccessResult::neutral(),
      default => AccessResult::allowed(),
    };
    $access->addCacheTags([self::cacheTag($this->entityId)]);

    return $return_as_object ? $access : $access->isAllowed();
  }

}
