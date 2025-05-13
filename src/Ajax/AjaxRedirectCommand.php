<?php

namespace Drupal\neo\Ajax;

use Drupal\Core\Ajax\CommandInterface;
use Drupal\Core\Ajax\CommandWithAttachedAssetsInterface;
use Drupal\Core\Ajax\CommandWithAttachedAssetsTrait;
use Drupal\Core\Url;

/**
 * Defines an AJAX command that will perform a Drupal ajax request to a URL.
 *
 * @ingroup ajax
 */
class AjaxRedirectCommand implements CommandInterface, CommandWithAttachedAssetsInterface {

  use CommandWithAttachedAssetsTrait;

  /**
   * The content for the matched element(s).
   *
   * Either a render array or an HTML string.
   *
   * @var string|array
   */
  protected $content;

  /**
   * The URL to redirect to.
   *
   * @var \Drupal\Core\Url
   */
  protected $url;

  /**
   * The options for the URL.
   *
   * @var array
   */
  protected $options;

  /**
   * Constructs an InsertCommand object.
   */
  public function __construct(Url $url, array $options = []) {
    $this->url = $url;
    $this->options = $options;
    $this->content = [
      '#attached' => [
        'library' => [
          'neo/ajax',
        ],
      ],
    ];
  }

  /**
   * Will open the ajax request as a dialog of type 'modal'.
   *
   * @param array $options
   *   An array of options to pass to the modal.
   *
   * @return $this
   */
  public function setAsModal(array $options = []): self {
    $this->options['dialogType'] = 'modal';
    $this->options['dialog'] = $options;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function render() {
    $this->getRenderedContent();
    return [
      'command' => 'ajaxRedirect',
      'data' => [
        'url' => $this->url->toString(),
      ] + $this->options,
    ];
  }

}
