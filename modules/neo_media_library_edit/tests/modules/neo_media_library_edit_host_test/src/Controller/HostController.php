<?php

declare(strict_types=1);

namespace Drupal\neo_media_library_edit_host_test\Controller;

use Drupal\Component\Serialization\Json;
use Drupal\node\NodeInterface;

/**
 * A page that opens a node's edit form in a modal.
 */
class HostController {

  /**
   * Renders the link that opens the node edit form in a modal.
   */
  public function page(NodeInterface $node): array {
    return [
      '#type' => 'link',
      '#title' => 'Open host form',
      '#url' => $node->toUrl('edit-form'),
      '#attributes' => [
        'class' => ['use-ajax', 'host-open'],
        'data-dialog-type' => 'modal',
        'data-dialog-options' => Json::encode(['width' => '90%']),
      ],
      '#attached' => ['library' => ['core/drupal.dialog.ajax']],
    ];
  }

}
