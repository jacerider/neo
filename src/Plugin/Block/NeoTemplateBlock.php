<?php

namespace Drupal\neo\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Provides a 'Template' block.
 */
#[Block(
  id: "neo_template_block",
  admin_label: new TranslatableMarkup("Template")
)]
class NeoTemplateBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return ['label_display' => FALSE];
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    return [
      '#markup' => '<span>' . $this->t('Customize via template %template.', [
        '%template' => 'block--BLOCK-ID.html.twig',
      ]) . '</span>',
    ];
  }

}
