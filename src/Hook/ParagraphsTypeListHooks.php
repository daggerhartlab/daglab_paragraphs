<?php

namespace Drupal\daglab_paragraphs\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\daglab_paragraphs\ParagraphsTypeListBuilder;
use Drupal\paragraphs\Controller\ParagraphsTypeListBuilder as BaseParagraphsTypeListBuilder;

/**
 * Shows every paragraph type on the paragraph types admin page.
 */
class ParagraphsTypeListHooks {

  /**
   * Implements hook_entity_type_alter().
   *
   * Only replaces Paragraphs' own list builder, so another module that has
   * customized the list is left alone.
   */
  #[Hook('entity_type_alter')]
  public function entityTypeAlter(array &$entity_types): void {
    $paragraphs_type = $entity_types['paragraphs_type'] ?? NULL;
    if ($paragraphs_type?->getListBuilderClass() === BaseParagraphsTypeListBuilder::class) {
      $paragraphs_type->setListBuilderClass(ParagraphsTypeListBuilder::class);
    }
  }

}
