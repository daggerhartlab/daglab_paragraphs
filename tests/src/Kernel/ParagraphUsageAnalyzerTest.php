<?php

namespace Drupal\Tests\daglab_paragraphs\Kernel;

use Drupal\paragraphs\Entity\Paragraph;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * @group daglab_paragraphs
 */
#[RunTestsInSeparateProcesses]
class ParagraphUsageAnalyzerTest extends ParagraphsKernelTestBase {

  /**
   * A paragraph entity that no node revision references is orphaned.
   *
   * This is the hero_generic_loans case: the paragraph row survives in the
   * database, its parent_id still points at a published node, but no revision
   * of that node references it any more.
   */
  public function testParagraphUnreferencedByAnyNodeRevisionIsOrphaned(): void {
    $this->createParagraphType('hero');
    $node = $this->createPage();

    // The orphan still claims the published page as its parent.
    $orphan = Paragraph::create([
      'type' => 'hero',
      'parent_type' => 'node',
      'parent_id' => $node->id(),
      'parent_field_name' => 'field_paragraphs',
    ]);
    $orphan->save();

    $result = $this->analyzer()->classifyUnusedTypes();

    $this->assertSame(['hero'], $result['orphaned']);
    $this->assertSame([], $result['never_created']);
    $this->assertSame([], $result['not_on_published']);
    $this->assertFalse($this->analyzer()->parentReferences($orphan));
    $this->assertSame([], $this->analyzer()->hostReferencesForParagraph((int) $orphan->id()));
  }

  /**
   * A stored parent that does reference the paragraph is confirmed.
   */
  public function testParentReferencesConfirmsARealParent(): void {
    $this->createParagraphType('hero');
    $hero = Paragraph::create(['type' => 'hero']);
    $this->createPage([$hero]);

    // The parent id is only filled in once the new page has one, on a later
    // save, so read the paragraph back.
    $hero = Paragraph::load($hero->id());
    $this->assertTrue($this->analyzer()->parentReferences($hero));

    $unparented = Paragraph::create(['type' => 'hero']);
    $unparented->save();
    $this->assertNull($this->analyzer()->parentReferences($unparented));
  }

  /**
   * A defined type that never had an entity created is "never created".
   */
  public function testTypeWithNoEntitiesIsNeverCreated(): void {
    $this->createParagraphType('hero');

    $result = $this->analyzer()->classifyUnusedTypes();

    $this->assertSame(['hero'], $result['never_created']);
    $this->assertSame([], $result['orphaned']);
    $this->assertSame([], $result['not_on_published']);
  }

  /**
   * A paragraph on an unpublished node is referenced, not orphaned.
   */
  public function testParagraphOnUnpublishedNodeIsNotOnPublished(): void {
    $this->createParagraphType('hero');
    $this->createPage([Paragraph::create(['type' => 'hero'])], FALSE);

    $result = $this->analyzer()->classifyUnusedTypes();

    $this->assertSame(['hero'], $result['not_on_published']);
    $this->assertSame([], $result['orphaned']);
    $this->assertSame([], $result['never_created']);
  }

  /**
   * A paragraph nested inside a referenced parent is reachable.
   */
  public function testNestedParagraphUnderReferencedParentIsNotOrphaned(): void {
    $this->createParagraphType('wrapper');
    $this->createParagraphType('child');
    $this->createParagraphField('paragraph', 'wrapper', 'field_children');

    $this->createPage([
      Paragraph::create([
        'type' => 'wrapper',
        'field_children' => [Paragraph::create(['type' => 'child'])],
      ]),
    ]);

    $result = $this->analyzer()->classifyUnusedTypes();

    $this->assertSame([], $result['orphaned']);
    $this->assertSame([], $result['not_on_published']);
  }

  /**
   * A paragraph dropped from a node's current revision stays reachable via the
   * old revision, so it is not reported as an orphan.
   */
  public function testParagraphKeptOnlyByAnOldRevisionIsNotOrphaned(): void {
    $this->createParagraphType('hero');
    $node = $this->createPage([Paragraph::create(['type' => 'hero'])]);
    $this->saveNewRevision($node, []);

    $result = $this->analyzer()->classifyUnusedTypes();

    $this->assertSame([], $result['orphaned']);
    $this->assertSame(['hero'], $result['not_on_published']);
  }

  /**
   * A paragraph referenced from a non-node host entity is not orphaned.
   *
   * Nodes are not the only host: blocks, for one, carry paragraph fields too,
   * and a user field stands in for them here. The user entity type is also
   * not revisionable, so this covers reading the base field table rather than
   * a revision table.
   */
  public function testParagraphReferencedFromNonNodeHostIsNotOrphaned(): void {
    $this->createParagraphType('hero');
    $this->createParagraphField('user', 'user');

    User::create([
      'name' => 'holder',
      'field_paragraphs' => [Paragraph::create(['type' => 'hero'])],
    ])->save();

    $result = $this->analyzer()->classifyUnusedTypes();

    $this->assertSame([], $result['orphaned']);
    $this->assertSame(['hero'], $result['not_on_published']);
  }

  /**
   * Orphan ids and counts include only the unreferenced paragraphs.
   */
  public function testOrphanIdsAndCountsListOnlyUnreferencedParagraphs(): void {
    $this->createParagraphType('hero');
    $this->createParagraphType('banner');

    $orphans = [];
    foreach ([1, 2] as $i) {
      $orphan = Paragraph::create(['type' => 'hero']);
      $orphan->save();
      $orphans[] = $orphan->id();
    }
    // Referenced paragraphs of both types, which must not be counted.
    $this->createPage([
      Paragraph::create(['type' => 'hero']),
      Paragraph::create(['type' => 'banner']),
    ]);

    $this->assertSame($orphans, $this->analyzer()->orphanedParagraphIds('hero'));
    $this->assertSame(['hero' => [
      'count' => 2,
      'example_paragraph_id' => (int) $orphans[0],
    ]], $this->analyzer()->orphanSummary());
  }

  /**
   * Nested paragraphs are credited to the published node at the top.
   */
  public function testPublishedUsageIncludesNestedParagraphs(): void {
    $this->createParagraphType('wrapper');
    $this->createParagraphType('child');
    $this->createParagraphField('paragraph', 'wrapper', 'field_children');

    $node = $this->createPage([
      Paragraph::create([
        'type' => 'wrapper',
        'field_children' => [Paragraph::create(['type' => 'child'])],
      ]),
    ]);

    $this->assertSame([
      'child' => [(int) $node->id()],
      'wrapper' => [(int) $node->id()],
    ], $this->intIds($this->analyzer()->publishedUsage()));
  }

  /**
   * The example for a type is its own paragraph on the newest page.
   *
   * For a nested type that is the nested paragraph, not its wrapper.
   */
  public function testPublishedUsageDetailsPicksExampleOnNewestPage(): void {
    $this->createParagraphType('wrapper');
    $this->createParagraphType('child');
    $this->createParagraphField('paragraph', 'wrapper', 'field_children');

    $older_child = Paragraph::create(['type' => 'child']);
    $this->createPage([Paragraph::create(['type' => 'wrapper', 'field_children' => [$older_child]])]);
    $newer_child = Paragraph::create(['type' => 'child']);
    $newer_wrapper = Paragraph::create(['type' => 'wrapper', 'field_children' => [$newer_child]]);
    $this->createPage([$newer_wrapper]);

    $details = $this->analyzer()->publishedUsageDetails();

    $this->assertSame((int) $newer_child->id(), $details['child']['example_paragraph_id']);
    $this->assertSame((int) $newer_wrapper->id(), $details['wrapper']['example_paragraph_id']);
  }

  /**
   * One paragraph type on several published nodes lists every node once.
   */
  public function testPublishedUsageListsEveryNode(): void {
    $this->createParagraphType('hero');

    $nids = [];
    foreach ([1, 2] as $i) {
      // Two of the same type on one node still counts the node once.
      $nids[] = (int) $this->createPage([
        Paragraph::create(['type' => 'hero']),
        Paragraph::create(['type' => 'hero']),
      ])->id();
    }

    $this->assertSame(['hero' => $nids], $this->intIds($this->analyzer()->publishedUsage()));
  }

  /**
   * Unpublished nodes, old revisions, and non-node hosts do not count.
   */
  public function testPublishedUsageIgnoresUnpublishedOldAndNonNodeHosts(): void {
    $this->createParagraphType('draft_only');
    $this->createParagraphType('old_only');
    $this->createParagraphType('user_only');
    $this->createParagraphField('user', 'user');

    $this->createPage([Paragraph::create(['type' => 'draft_only'])], FALSE);

    $node = $this->createPage([Paragraph::create(['type' => 'old_only'])]);
    $this->saveNewRevision($node, []);

    User::create([
      'name' => 'holder',
      'field_paragraphs' => [Paragraph::create(['type' => 'user_only'])],
    ])->save();

    $this->assertSame([], $this->analyzer()->publishedUsage());
  }

  /**
   * An unpublished node's current revision is reported as current.
   */
  public function testHostReferencesReportsUnpublishedCurrentRevision(): void {
    $this->createParagraphType('hero');
    $hero = Paragraph::create(['type' => 'hero']);
    $node = $this->createPage([$hero], FALSE);

    $this->assertSame([[
      'entity_type' => 'node',
      'id' => (int) $node->id(),
      'current' => TRUE,
      'published' => FALSE,
      'old_revisions' => 0,
      'newer_revisions' => 0,
      'old_revision_ids' => [],
      'example' => [
        'paragraph_id' => (int) $hero->id(),
        'revision_id' => (int) $hero->getRevisionId(),
      ],
    ]], $this->analyzer()->hostReferences('hero'));
  }

  /**
   * A paragraph dropped from the current revision is counted as older.
   */
  public function testHostReferencesCountsOlderRevisions(): void {
    $this->createParagraphType('hero');
    $node = $this->createPage([Paragraph::create(['type' => 'hero'])]);
    $first_revision = (int) $node->getRevisionId();
    $this->saveNewRevision($node, []);

    [$host] = $this->analyzer()->hostReferences('hero');

    $this->assertFalse($host['current']);
    $this->assertTrue($host['published']);
    $this->assertSame(1, $host['old_revisions']);
    $this->assertSame([$first_revision], $host['old_revision_ids']);
    $this->assertSame(0, $host['newer_revisions']);
  }

  /**
   * A forward revision newer than the current one is counted separately.
   */
  public function testHostReferencesCountsNewerRevisions(): void {
    $this->createParagraphType('hero');
    $node = $this->createPage();
    $this->saveNewRevision($node, [Paragraph::create(['type' => 'hero'])], FALSE);

    [$host] = $this->analyzer()->hostReferences('hero');

    $this->assertFalse($host['current']);
    $this->assertSame(0, $host['old_revisions']);
    $this->assertSame(1, $host['newer_revisions']);
  }

  /**
   * Only the parent revisions that really contain a child count.
   *
   * The wrapper stays on the page, but its newer revision no longer holds the
   * child, so the child is only in the page's older revision.
   */
  public function testHostReferencesFollowsExactParentRevisions(): void {
    $this->createParagraphType('wrapper');
    $this->createParagraphType('child');
    $this->createParagraphField('paragraph', 'wrapper', 'field_children');

    $child = Paragraph::create(['type' => 'child']);
    $node = $this->createPage([
      Paragraph::create([
        'type' => 'wrapper',
        'field_children' => [$child],
      ]),
    ]);

    $wrapper = $node->get('field_paragraphs')->entity;
    $wrapper->set('field_children', []);
    $this->saveNewRevision($node, [$wrapper]);

    [$wrapper_host] = $this->analyzer()->hostReferences('wrapper');
    $this->assertTrue($wrapper_host['current']);

    [$child_host] = $this->analyzer()->hostReferences('child');
    $this->assertFalse($child_host['current']);
    $this->assertSame(1, $child_host['old_revisions']);

    // The example is the child itself, as the older page revision held it.
    $this->assertSame([
      'paragraph_id' => (int) $child->id(),
      'revision_id' => (int) $child->getRevisionId(),
    ], $child_host['example']);
  }

}
