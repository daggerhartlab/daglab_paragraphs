<?php

namespace Drupal\Tests\daglab_paragraphs\Kernel;

use Drupal\daglab_paragraphs\Controller\ParagraphUsageController;
use Drupal\paragraphs\Entity\Paragraph;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @group daglab_paragraphs
 */
#[RunTestsInSeparateProcesses]
class ParagraphUsageControllerTest extends ParagraphsKernelTestBase {

  /**
   * Creates types on 3, 1 and 2 pages, so name and count orders disagree.
   */
  private function createTypesOnPages(): void {
    foreach (['beta' => 3, 'alpha' => 1, 'gamma' => 2] as $type => $pages) {
      $this->createParagraphType($type);
      for ($i = 0; $i < $pages; $i++) {
        $this->createPage([Paragraph::create(['type' => $type])]);
      }
    }
  }

  /**
   * Builds the In Use page and returns its machine names in row order.
   */
  private function inUseOrder(array $query = []): array {
    $this->pushRequest('daglab_paragraphs.usage_report', [], $query);
    $build = ParagraphUsageController::create($this->container)->usage();
    return array_map(fn ($row) => $row[1], $build['table']['#rows']);
  }

  /**
   * With no sort chosen, rows are in paragraph name order.
   */
  public function testInUseSortsByNameByDefault(): void {
    $this->createTypesOnPages();

    $this->assertSame(['alpha', 'beta', 'gamma'], $this->inUseOrder());
  }

  /**
   * Sorting by "# of pages" compares counts as numbers, both ways.
   */
  public function testInUseSortsByPageCount(): void {
    $this->createTypesOnPages();

    $this->assertSame(['beta', 'gamma', 'alpha'], $this->inUseOrder(['order' => '# of pages', 'sort' => 'desc']));
    $this->assertSame(['alpha', 'gamma', 'beta'], $this->inUseOrder(['order' => '# of pages', 'sort' => 'asc']));
  }

  /**
   * Types only on unpublished pages say why they are kept.
   */
  public function testNotOnPublishedRowsSummarizeReferences(): void {
    $this->createParagraphType('draft_only');
    $this->createPage([Paragraph::create(['type' => 'draft_only'])], FALSE);

    $this->pushRequest('daglab_paragraphs.unused_report');
    $table = ParagraphUsageController::create($this->container)->unused()['has_revisions']['table'];

    [$type, , $summary] = $table['#rows'][0];
    $this->assertSame('draft_only', $type);
    $this->assertSame(['Unpublished: 1 content item'], array_map('strval', $summary['data']['#items']));
  }

  /**
   * The viewer only shows revisions of the paragraph it was asked for.
   */
  public function testViewerRejectsAnotherParagraphsRevision(): void {
    $this->createParagraphType('hero');
    $hero = Paragraph::create(['type' => 'hero']);
    $other = Paragraph::create(['type' => 'hero']);
    $this->createPage([$hero, $other]);

    $this->expectException(NotFoundHttpException::class);
    ParagraphUsageController::create($this->container)->paragraph($hero, Request::create('/', 'GET', ['revision' => $other->getRevisionId()]));
  }

}
