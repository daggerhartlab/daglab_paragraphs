<?php

namespace Drupal\Tests\daglab_paragraphs\Kernel;

use Drupal\daglab_paragraphs\Form\DeleteOrphansForm;
use Drupal\paragraphs\Entity\Paragraph;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the form that deletes orphaned paragraphs.
 *
 * @group daglab_paragraphs
 */
#[RunTestsInSeparateProcesses]
class DeleteOrphansFormTest extends ParagraphsKernelTestBase {

  /**
   * Submitting deletes the type's orphans and nothing that is referenced.
   */
  public function testSubmitDeletesOnlyOrphansOfTheType(): void {
    $hero = $this->createParagraphType('hero');
    $this->createParagraphType('banner');

    // Orphans of the type being cleaned up.
    $orphans = [];
    for ($n = 0; $n < 3; $n++) {
      $orphan = Paragraph::create(['type' => 'hero']);
      $orphan->save();
      $orphans[] = $orphan->id();
    }

    // An orphan of a different type, and a referenced paragraph of this type,
    // both of which must survive.
    $other_orphan = Paragraph::create(['type' => 'banner']);
    $other_orphan->save();
    $referenced = Paragraph::create(['type' => 'hero']);
    $this->createPage([$referenced], FALSE);

    $this->submitTypeForm(DeleteOrphansForm::class, 'daglab_paragraphs.delete_orphans', $hero);

    $storage = $this->container->get('entity_type.manager')->getStorage('paragraph');
    $storage->resetCache();
    $this->assertSame([], $storage->loadMultiple($orphans));
    $this->assertNotNull($storage->load($other_orphan->id()));
    $this->assertNotNull($storage->load($referenced->id()));
  }

}
