<?php

namespace Drupal\Tests\daglab_paragraphs\Kernel;

use Drupal\daglab_paragraphs\Form\DeleteOlderRevisionsForm;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\paragraphs\ParagraphsTypeInterface;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * @group daglab_paragraphs
 */
#[RunTestsInSeparateProcesses]
class DeleteOlderRevisionsFormTest extends ParagraphsKernelTestBase {

  private ParagraphsTypeInterface $hero;

  protected function setUp(): void {
    parent::setUp();
    $this->hero = $this->createParagraphType('hero');

    // Core only lets this user delete node revisions with all of these.
    $this->setUpCurrentUser([], ['access content', 'bypass node access', 'delete all revisions']);
  }

  /**
   * Submits the form for the hero type.
   */
  private function submit(): void {
    $this->submitTypeForm(DeleteOlderRevisionsForm::class, 'daglab_paragraphs.delete_older_revisions', $this->hero);
  }

  /**
   * Loads a node revision fresh from storage.
   */
  private function loadRevision(int|string $revision_id): ?NodeInterface {
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $storage->resetCache();
    return $storage->loadRevision($revision_id);
  }

  /**
   * Deleting the old revision leaves the type's paragraphs orphaned.
   */
  public function testDeletesOlderRevisionsAndLeavesParagraphsOrphaned(): void {
    $node = $this->createPage([Paragraph::create(['type' => 'hero'])]);
    $old_revision = $node->getRevisionId();
    $this->saveNewRevision($node, []);

    $this->assertSame(['hero'], $this->analyzer()->classifyUnusedTypes()['not_on_published']);

    $this->submit();

    $this->assertNull($this->loadRevision($old_revision));
    $this->assertNotNull($this->loadRevision($node->getRevisionId()));
    $this->assertSame([], $this->analyzer()->hostReferences('hero'));
    $this->assertSame(['hero'], $this->analyzer()->classifyUnusedTypes()['orphaned']);
  }

  /**
   * Current and newer draft revisions are never deleted.
   */
  public function testKeepsCurrentAndNewerRevisions(): void {
    $node = $this->createPage([Paragraph::create(['type' => 'hero'])]);
    $old_revision = $node->getRevisionId();
    $this->saveNewRevision($node, []);

    // Another page with a hero in its current revision and a newer draft.
    $other = $this->createPage([Paragraph::create(['type' => 'hero'])], FALSE);
    $other_current = $other->getRevisionId();
    $this->saveNewRevision($other, [Paragraph::create(['type' => 'hero'])], FALSE);
    $other_newer = $other->getRevisionId();

    $this->submit();

    $this->assertNull($this->loadRevision($old_revision));
    $this->assertNotNull($this->loadRevision($other_current));
    $this->assertNotNull($this->loadRevision($other_newer));
  }

}
