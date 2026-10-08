<?php

namespace Drupal\Tests\daglab_paragraphs\Kernel;

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * @group daglab_paragraphs
 */
#[RunTestsInSeparateProcesses]
class ParagraphsTypeListBuilderTest extends ParagraphsKernelTestBase {

  /**
   * The paragraph types admin page lists every type, not just the first 50.
   */
  public function testListsEveryParagraphType(): void {
    foreach (range(1, 60) as $i) {
      $this->createParagraphType(sprintf('type_%02d', $i));
    }

    $list_builder = $this->container->get('entity_type.manager')->getListBuilder('paragraphs_type');

    $this->assertCount(60, $list_builder->load());
  }

}
