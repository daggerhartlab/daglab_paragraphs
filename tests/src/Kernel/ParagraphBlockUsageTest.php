<?php

namespace Drupal\Tests\daglab_paragraphs\Kernel;

use Drupal\Core\Extension\ExtensionDiscovery;
use Drupal\block\Entity\Block;
use Drupal\block_content\Entity\BlockContent;
use Drupal\block_content\Entity\BlockContentType;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\Entity\Paragraph;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Paragraphs held by blocks, embedded through a block field or placed.
 *
 * Block fields come from the contrib block_field module, which this module
 * supports but does not require, so these tests skip without it.
 *
 * @group daglab_paragraphs
 */
#[RunTestsInSeparateProcesses]
class ParagraphBlockUsageTest extends ParagraphsKernelTestBase {

  protected static $modules = [
    'block',
    'block_content',
    'block_field',
  ];

  protected function setUp(): void {
    // Check before the parent tries to enable it. Drupal 11.4 sets the root
    // before setUp(); older versions only inside it.
    $root = isset($this->root) ? $this->root : static::getDrupalRoot();
    $modules = (new ExtensionDiscovery($root, FALSE))->scan('module');
    if (!isset($modules['block_field'])) {
      $this->markTestSkipped('The block_field module is not available.');
    }

    parent::setUp();
    $this->installEntitySchema('block_content');
    $this->installConfig(['block_content']);
    $this->container->get('theme_installer')->install(['stark']);

    BlockContentType::create(['id' => 'reusable', 'label' => 'Reusable'])->save();
    $this->createParagraphField('block_content', 'reusable');
    $this->createParagraphType('inner');
    $this->createParagraphType('embed');

    FieldStorageConfig::create([
      'field_name' => 'field_block',
      'entity_type' => 'paragraph',
      'type' => 'block_field',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_block',
      'entity_type' => 'paragraph',
      'bundle' => 'embed',
      'label' => 'Block',
    ])->save();
  }

  /**
   * Creates a block holding an "inner" paragraph.
   */
  private function createBlock(bool $published): BlockContent {
    $block = BlockContent::create([
      'type' => 'reusable',
      'info' => 'Reusable block',
      'status' => $published,
      'field_paragraphs' => [Paragraph::create(['type' => 'inner'])],
    ]);
    $block->save();
    return $block;
  }

  /**
   * Creates a page embedding a block through an "embed" paragraph.
   */
  private function createEmbeddingPage(BlockContent $block, bool $published): NodeInterface {
    return $this->createPage([
      Paragraph::create([
        'type' => 'embed',
        'field_block' => ['plugin_id' => 'block_content:' . $block->uuid(), 'settings' => []],
      ]),
    ], $published);
  }

  /**
   * A published block embedded on a published page counts for that page.
   */
  public function testEmbeddedPublishedBlockCountsForThePage(): void {
    $node = $this->createEmbeddingPage($this->createBlock(TRUE), TRUE);

    $usage = $this->intIds($this->analyzer()->publishedUsage());

    $this->assertSame([(int) $node->id()], $usage['inner']);
    $this->assertSame([(int) $node->id()], $usage['embed']);
  }

  /**
   * An unpublished block, or an unpublished page, does not count.
   */
  public function testUnpublishedBlockOrPageDoesNotCount(): void {
    $this->createEmbeddingPage($this->createBlock(FALSE), TRUE);
    $this->createEmbeddingPage($this->createBlock(TRUE), FALSE);

    $this->assertArrayNotHasKey('inner', $this->analyzer()->publishedUsage());
  }

  /**
   * A block host reports the pages embedding it and where it is placed.
   */
  public function testHostReferencesReportsBlockEmbedsAndPlacements(): void {
    $block = $this->createBlock(TRUE);
    $node = $this->createEmbeddingPage($block, FALSE);
    Block::create([
      'id' => 'reusable_block',
      'plugin' => 'block_content:' . $block->uuid(),
      'theme' => 'stark',
      'region' => 'content',
    ])->save();

    [$host] = $this->analyzer()->hostReferences('inner');

    $this->assertSame('block_content', $host['entity_type']);
    $this->assertSame((int) $block->id(), $host['id']);
    $this->assertTrue($host['current']);
    $this->assertTrue($host['published']);
    $this->assertSame([[
      'id' => 'reusable_block',
      'theme' => 'stark',
      'region' => 'content',
      'enabled' => TRUE,
    ]], $host['placements']);
    $this->assertCount(1, $host['embedded_in']);
    $this->assertSame('node', $host['embedded_in'][0]['entity_type']);
    $this->assertSame((int) $node->id(), $host['embedded_in'][0]['id']);
    $this->assertFalse($host['embedded_in'][0]['published']);
  }

  /**
   * Places a block through the block layout.
   */
  private function placeBlock(BlockContent $block, bool $enabled = TRUE): void {
    Block::create([
      'id' => 'placed_' . $block->id(),
      'plugin' => 'block_content:' . $block->uuid(),
      'theme' => 'stark',
      'region' => 'content',
      'status' => $enabled,
    ])->save();
  }

  /**
   * A placed, published block makes its paragraphs in use, on no one page.
   */
  public function testPlacedPublishedBlockCountsAsInUse(): void {
    $block = $this->createBlock(TRUE);
    $this->placeBlock($block);

    $details = $this->analyzer()->publishedUsageDetails();

    $this->assertSame([], $details['inner']['nids']);
    $this->assertSame([(int) $block->id()], $details['inner']['block_ids']);
    $this->assertSame((int) $block->get('field_paragraphs')->target_id, $details['inner']['example_paragraph_id']);
    $this->assertNotContains('inner', $this->analyzer()->classifyUnusedTypes()['not_on_published']);
  }

  /**
   * A disabled placement, or an unpublished placed block, does not count.
   */
  public function testDisabledPlacementOrUnpublishedBlockDoesNotCount(): void {
    $this->placeBlock($this->createBlock(TRUE), FALSE);
    $this->placeBlock($this->createBlock(FALSE));

    $this->assertArrayNotHasKey('inner', $this->analyzer()->publishedUsageDetails());
    $this->assertContains('inner', $this->analyzer()->classifyUnusedTypes()['not_on_published']);
  }

}
