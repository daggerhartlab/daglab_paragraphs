<?php

namespace Drupal\daglab_paragraphs;

use Drupal\paragraphs\Controller\ParagraphsTypeListBuilder as BaseParagraphsTypeListBuilder;

/**
 * Lists every paragraph type on one page.
 *
 * Paragraphs pages its type list 50 at a time. Config entities are all loaded
 * to run the query anyway, so paging them saves nothing and only hides types.
 *
 * @see \Drupal\daglab_paragraphs\Hook\ParagraphsTypeListHooks
 */
class ParagraphsTypeListBuilder extends BaseParagraphsTypeListBuilder {

  /**
   * {@inheritdoc}
   */
  protected $limit = FALSE;

}
