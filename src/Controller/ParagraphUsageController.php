<?php

namespace Drupal\daglab_paragraphs\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Link;
use Drupal\Core\Pager\PagerManagerInterface;
use Drupal\Core\Url;
use Drupal\Core\Utility\TableSort;
use Drupal\daglab_paragraphs\ParagraphUsageAnalyzer;
use Drupal\paragraphs\ParagraphInterface;
use Drupal\paragraphs\ParagraphsTypeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Renders the Reports > Paragraph Usage pages.
 *
 * Everything is worked out live from the database on each request, so there
 * is nothing to rebuild after content changes or a cache clear.
 */
class ParagraphUsageController extends ControllerBase {

  /**
   * Number of pages listed per page on a paragraph type's usage page.
   */
  private const PAGE_SIZE = 50;

  /**
   * Cache tags covering everything the reports read.
   *
   * Saving or deleting any node, block, paragraph, paragraph type, or block
   * placement changes the results, so those list tags keep the pages fresh
   * without a rebuild.
   */
  private const CACHE_TAGS = [
    'node_list',
    'block_content_list',
    'paragraph_list',
    'config:paragraphs_type_list',
    'config:block_list',
    // Deleting revisions invalidates no list tag, so the delete forms
    // invalidate this one.
    'daglab_paragraphs:usage',
  ];

  public function __construct(
    private readonly ParagraphUsageAnalyzer $usageAnalyzer,
    private readonly PagerManagerInterface $pagerManager,
    private readonly RequestStack $requestStack,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get(ParagraphUsageAnalyzer::class),
      $container->get(PagerManagerInterface::class),
      $container->get(RequestStack::class),
      $container->get(DateFormatterInterface::class),
    );
  }

  /**
   * Lists each paragraph type on a published page, with a count and example.
   */
  public function usage(): array {
    $details = $this->usageAnalyzer->publishedUsageDetails();
    $orphan_summary = $this->usageAnalyzer->orphanSummary();
    $paragraph_types = $this->entityTypeManager()->getStorage('paragraphs_type')->loadMultiple(array_keys($details));

    // Link to the newest node as an example.
    // Note: We're using the newest instead of the oldest because a lot of the
    // oldest ones are generic template pages.
    $newest = array_filter(array_map(fn ($type_details) => end($type_details['nids']), $details));
    $examples = $this->entityTypeManager()->getStorage('node')->loadMultiple($newest);

    // The rows come from the walk rather than a query, so the 'field' keys
    // name the values sortRows() sorts on.
    $header = [
      ['data' => 'Paragraph name', 'field' => 'label', 'sort' => 'asc'],
      ['data' => 'Machine name', 'field' => 'machine_name'],
      ['data' => '# of pages', 'field' => 'count', 'initial_click_sort' => 'desc'],
      ['data' => 'Orphans', 'field' => 'orphans', 'initial_click_sort' => 'desc'],
      'Example',
      'Operations',
    ];

    $rows = [];
    foreach ($details as $type => $type_details) {
      $example = $examples[$newest[$type] ?? NULL] ?? NULL;
      $rows[] = [
        'label' => ($paragraph_types[$type] ?? NULL)?->label() ?? $type,
        'machine_name' => $type,
        'count' => count($type_details['nids']),
        'blocks' => count($type_details['block_ids']),
        'orphans' => $orphan_summary[$type]['count'] ?? 0,
        'example' => $example ? $example->toLink(NULL, 'canonical', ['attributes' => ['target' => '_blank']]) : '',
      ];
    }

    $rows = array_map(fn ($row) => [
      Link::createFromRoute($row['label'], 'daglab_paragraphs.usage_type', ['paragraphs_type' => $row['machine_name']]),
      $row['machine_name'],
      // Placed blocks are shown site-wide rather than on particular pages.
      $row['blocks']
        ? $this->formatPlural($row['blocks'], '@pages (+1 placed block)', '@pages (+@count placed blocks)', ['@pages' => $row['count']])
        : $row['count'],
      $row['orphans'],
      $row['example'],
      $this->typeOperations(
        $row['machine_name'],
        $this->viewExampleOperation($details[$row['machine_name']]['example_paragraph_id'])
          + $this->deleteOrphansOperation($row['machine_name'], $row['orphans']),
      ),
    ], $this->sortRows($rows, $header));

    return [
      'description' => [
        '#markup' => '<p>' . $this->t('Paragraph types shown on at least one published page, or in a published block placed in the block layout. Orphans are leftover paragraphs of the type that nothing references. Types not shown anywhere are listed under <a href=":unused">Unused</a>.', [
          ':unused' => Url::fromRoute('daglab_paragraphs.unused_report')->toString(),
        ]) . '</p>',
      ],
      'table' => [
        '#theme' => 'table',
        '#header' => $header,
        '#rows' => $rows,
        '#empty' => 'No paragraphs appear on published pages.',
      ],
      '#cache' => [
        'tags' => self::CACHE_TAGS,
        // The sort links set these, and each order is a different page. The
        // operations depend on the viewer's permissions.
        'contexts' => ['url.query_args:order', 'url.query_args:sort', 'user.permissions'],
      ],
    ];
  }


  /**
   * Sorts rows by the table header the current request asks for.
   *
   * @param array $rows
   *   Rows keyed by the header 'field' values.
   * @param array $header
   *   The table header.
   *
   * @return array
   *   The sorted rows. Ties fall back to the paragraph name.
   */
  private function sortRows(array $rows, array $header): array {
    $request = $this->requestStack->getCurrentRequest();
    $field = TableSort::getOrder($header, $request)['sql'] ?? 'label';
    $direction = TableSort::getSort($header, $request) === TableSort::DESC ? -1 : 1;

    usort($rows, function ($a, $b) use ($field, $direction) {
      $result = is_int($a[$field])
        ? $a[$field] <=> $b[$field]
        : strnatcasecmp($a[$field], $b[$field]);
      return ($result * $direction) ?: strnatcasecmp($a['label'], $b['label']);
    });

    return $rows;
  }

  /**
   * Lists the published pages one paragraph type appears on.
   */
  public function type(ParagraphsTypeInterface $paragraphs_type): array {
    $details = $this->usageAnalyzer->publishedUsageDetails()[$paragraphs_type->id()] ?? ['nids' => [], 'block_ids' => []];
    $nids = $details['nids'];
    $orphans = $this->usageAnalyzer->orphanSummary()[$paragraphs_type->id()]['count'] ?? 0;

    $pager = $this->pagerManager->createPager(count($nids), self::PAGE_SIZE);
    $page_nids = array_slice($nids, $pager->getCurrentPage() * self::PAGE_SIZE, self::PAGE_SIZE);

    $rows = [];
    /** @var \Drupal\node\NodeInterface $node */
    foreach ($this->entityTypeManager()->getStorage('node')->loadMultiple($page_nids) as $node) {
      $rows[] = [
        $node->toLink(NULL, 'canonical', ['attributes' => ['target' => '_blank']]),
        $node->id(),
        $node->type->entity?->label() ?? $node->bundle(),
        $node->toLink($this->t('Edit'), 'edit-form'),
      ];
    }

    $build = [
      'description' => [
        '#markup' => '<p>' . $this->formatPlural(
          count($nids),
          'The %type paragraph type (%machine_name) appears on 1 published page.',
          'The %type paragraph type (%machine_name) appears on @count published pages.',
          ['%type' => $paragraphs_type->label(), '%machine_name' => $paragraphs_type->id()],
        ) . '</p>',
      ],
    ];

    if ($details['block_ids']) {
      $build['blocks'] = [
        '#theme' => 'item_list',
        '#title' => $this->t('Also in placed blocks, shown site-wide'),
        '#items' => $this->placedBlockItems($details['block_ids']),
      ];
    }

    if ($orphans) {
      $delete = $this->deleteOrphansOperation($paragraphs_type->id(), $orphans);
      $build['orphans'] = [
        '#type' => 'container',
        'text' => [
          '#markup' => '<p>' . $this->formatPlural(
            $orphans,
            '1 more paragraph of this type is an orphan: nothing references it.',
            '@count more paragraphs of this type are orphans: nothing references them.',
          ) . '</p>',
        ],
        'delete' => $delete ? [
          '#type' => 'link',
          '#title' => $delete['delete_orphans']['title'],
          '#url' => $delete['delete_orphans']['url'],
          '#attributes' => ['class' => ['button', 'button--small']],
        ] : [],
      ];
    }

    return $build + [
      'table' => [
        '#theme' => 'table',
        '#header' => ['Page', 'Node ID', 'Content type', 'Operations'],
        '#rows' => $rows,
        '#empty' => 'No published pages use this paragraph type.',
      ],
      'pager' => [
        '#type' => 'pager',
      ],
      '#cache' => [
        'tags' => self::CACHE_TAGS,
        // The delete link depends on the viewer's permissions.
        'contexts' => ['user.permissions'],
      ],
    ];
  }

  /**
   * Lists placed blocks, with where each is placed.
   *
   * @param array $block_ids
   *   block_content entity ids.
   *
   * @return array
   *   Items for an item list.
   */
  private function placedBlockItems(array $block_ids): array {
    $items = [];
    /** @var \Drupal\block_content\BlockContentInterface $block */
    foreach ($this->entityTypeManager()->getStorage('block_content')->loadMultiple($block_ids) as $block) {
      $placements = $this->entityTypeManager()->getStorage('block')->loadByProperties([
        'plugin' => 'block_content:' . $block->uuid(),
        'status' => TRUE,
      ]);
      $items[] = [
        'link' => $this->entityLink($block),
        'where' => [
          '#markup' => ' (' . implode(', ', array_map(
            fn ($placement) => $placement->getTheme() . ': ' . $placement->getRegion(),
            $placements,
          )) . ')',
        ],
      ];
    }
    return $items;
  }

  /**
   * Builds a "Delete orphans" operation, returning to the current page.
   *
   * @param string $type
   *   The paragraph type machine name.
   * @param int $orphans
   *   How many orphans the type has. No operation is built without any.
   *
   * @return array
   *   Zero or one operations.
   */
  private function deleteOrphansOperation(string $type, int $orphans): array {
    if (!$orphans || !$this->currentUser()->hasPermission('daglab delete orphaned paragraphs')) {
      return [];
    }
    return [
      'delete_orphans' => [
        'title' => $this->t('Delete orphans'),
        'url' => Url::fromRoute('daglab_paragraphs.delete_orphans', ['paragraphs_type' => $type], ['query' => $this->getDestinationArray()]),
      ],
    ];
  }


  /**
   * Title callback for a paragraph type's usage page.
   */
  public function typeTitle(ParagraphsTypeInterface $paragraphs_type): string {
    return 'Paragraph Usage: ' . $paragraphs_type->label();
  }

  /**
   * Lists the paragraph types that are not on any published page.
   */
  public function unused(): array {
    $defined_paragraph_types = $this->entityTypeManager()->getStorage('paragraphs_type')->loadMultiple();
    $buckets = $this->usageAnalyzer->classifyUnusedTypes();

    $orphaned = $this->renderUnusedBucket(
      'Orphaned Paragraphs',
      'Paragraphs of this type still exist in the database, but nothing references them any more — not the current revision of any page, and not an older or draft one either. Delete the leftover paragraphs before removing the type.',
      'There are no orphaned paragraphs.',
      $buckets['orphaned'],
      $defined_paragraph_types,
    );

    // Add each type's orphan count, and a link to delete them for those who
    // may.
    $orphan_summary = $this->usageAnalyzer->orphanSummary();
    $orphaned['table']['#header'][] = 'Orphans';
    $orphaned['table']['#header'][] = 'Operations';
    foreach ($buckets['orphaned'] as $delta => $type) {
      $count = $orphan_summary[$type]['count'] ?? 0;
      $orphaned['table']['#rows'][$delta][] = $count;
      $orphaned['table']['#rows'][$delta][] = $this->typeOperations(
        $type,
        $this->viewExampleOperation($orphan_summary[$type]['example_paragraph_id'] ?? NULL)
          + $this->deleteOrphansOperation($type, $count),
      );
    }

    $not_on_published = $this->renderUnusedBucket(
      'Existing Paragraphs without Published Revisions',
      'Something still references these — an older or draft revision, an unpublished page, or a block — but they do not appear on any published page. The details list exactly what references each one.',
      'Every paragraph type that exists in the database is on a published page.',
      $buckets['not_on_published'],
      $defined_paragraph_types,
    );

    // Summarize what still references each type, with a link to the details.
    $not_on_published['table']['#header'][] = 'Referenced by';
    $not_on_published['table']['#header'][] = 'Operations';
    $can_delete_revisions = $this->currentUser()->hasPermission('daglab delete older revisions holding paragraphs');
    foreach ($buckets['not_on_published'] as $delta => $type) {
      $hosts = $this->usageAnalyzer->hostReferences($type);
      $not_on_published['table']['#rows'][$delta][] = [
        'data' => [
          '#theme' => 'item_list',
          '#items' => $this->summarizeReferences($hosts),
        ],
      ];
      // Hosts come current first, so this prefers what a page shows now.
      $example = current(array_filter(array_column($hosts, 'example'), fn ($example) => $example['paragraph_id']));
      $operations = [
        'details' => [
          'title' => $this->t('Details'),
          'url' => Url::fromRoute('daglab_paragraphs.references', ['paragraphs_type' => $type]),
        ],
      ] + ($example ? $this->viewExampleOperation($example['paragraph_id'], $example['revision_id']) : []);
      if ($can_delete_revisions && array_filter(array_column($hosts, 'old_revisions'))) {
        $operations['delete_older_revisions'] = [
          'title' => $this->t('Delete older revisions'),
          'url' => Url::fromRoute('daglab_paragraphs.delete_older_revisions', ['paragraphs_type' => $type]),
        ];
      }
      $not_on_published['table']['#rows'][$delta][] = $this->typeOperations($type, $operations);
    }

    $never_created = $this->renderUnusedBucket(
      'Never Used Paragraphs',
      'Defined in config, but no paragraph of this type exists in the database. There is no content to clean up, so the type itself can be removed from config.',
      'Every paragraph type defined in config has at least one paragraph in the database.',
      $buckets['never_created'],
      $defined_paragraph_types,
    );
    $never_created['table']['#header'][] = 'Operations';
    foreach ($buckets['never_created'] as $delta => $type) {
      $never_created['table']['#rows'][$delta][] = $this->typeOperations($type);
    }

    return [
      'no_revisions' => $never_created,
      'orphaned' => $orphaned,
      'has_revisions' => $not_on_published,
      '#cache' => [
        'tags' => self::CACHE_TAGS,
        // The operations depend on the viewer's permissions.
        'contexts' => ['user.permissions'],
      ],
    ];
  }

  /**
   * Lists everything that still references one paragraph type's paragraphs.
   */
  public function references(ParagraphsTypeInterface $paragraphs_type): array {
    $hosts = $this->usageAnalyzer->hostReferences($paragraphs_type->id());

    $pager = $this->pagerManager->createPager(count($hosts), self::PAGE_SIZE);
    $page_hosts = array_slice($hosts, $pager->getCurrentPage() * self::PAGE_SIZE, self::PAGE_SIZE);

    $rows = [];
    foreach ($page_hosts as $host) {
      $rows[] = $this->referenceRow($host);
    }

    $actions = [];
    $old_revisions = array_sum(array_column($hosts, 'old_revisions'));
    if ($old_revisions && $this->currentUser()->hasPermission('daglab delete older revisions holding paragraphs')) {
      $actions['delete_older_revisions'] = [
        '#type' => 'link',
        '#title' => $this->formatPlural($old_revisions, 'Delete 1 older revision', 'Delete @count older revisions'),
        '#url' => Url::fromRoute('daglab_paragraphs.delete_older_revisions', ['paragraphs_type' => $paragraphs_type->id()]),
        '#attributes' => ['class' => ['button', 'button--small']],
      ];
    }

    return [
      'actions' => $actions,
      'description' => [
        '#markup' => '<p>' . $this->t('What still references the %type paragraph type (%machine_name), and through which revisions. The type can only be removed once none of these reference it.', [
          '%type' => $paragraphs_type->label(),
          '%machine_name' => $paragraphs_type->id(),
        ]) . '</p>',
      ],
      'table' => [
        '#theme' => 'table',
        '#header' => ['Referenced by', 'Kind', 'Status', 'Revisions', 'Operations'],
        '#rows' => $rows,
        '#empty' => 'Nothing references this paragraph type.',
      ],
      'pager' => [
        '#type' => 'pager',
      ],
      '#cache' => [
        'tags' => self::CACHE_TAGS,
        // Operations depend on the viewer's access to each entity.
        'contexts' => ['user'],
      ],
    ];
  }

  /**
   * Title callback for a paragraph type's references page.
   */
  public function referencesTitle(ParagraphsTypeInterface $paragraphs_type): string {
    return 'Paragraph References: ' . $paragraphs_type->label();
  }

  /**
   * Builds one row of the references table.
   *
   * @param array $host
   *   A host entry from ParagraphUsageAnalyzer::hostReferences().
   *
   * @return array
   *   A table row.
   */
  private function referenceRow(array $host): array {
    $entity_type = $this->entityTypeManager()->getDefinition($host['entity_type']);
    $entity = $this->entityTypeManager()->getStorage($host['entity_type'])->load($host['id']);

    // The host, plus where a block is shown.
    $label = [
      'link' => $entity ? $this->entityLink($entity) : ['#markup' => $host['entity_type'] . ':' . $host['id']],
    ];
    if (!empty($host['placements']) || !empty($host['embedded_in'])) {
      $label['shown'] = [
        '#theme' => 'item_list',
        '#items' => $this->blockDisplayItems($host),
      ];
    }

    // The paragraph as this host shows it, the revision history when older or
    // newer revisions keep the reference, then the standard operations.
    $operations = [];
    if (!empty($host['example']['paragraph_id'])) {
      $operations += $this->viewExampleOperation($host['example']['paragraph_id'], $host['example']['revision_id'], $this->t('View paragraph'));
    }
    if ($entity && ($host['old_revisions'] || $host['newer_revisions']) && $entity->hasLinkTemplate('version-history')) {
      $url = $entity->toUrl('version-history');
      if ($url->access()) {
        $operations['revisions'] = ['title' => $this->t('Revisions'), 'url' => $url];
      }
    }
    if ($entity) {
      $operations += $this->entityTypeManager()->getListBuilder($host['entity_type'])->getOperations($entity);
    }

    $bundle = $entity && $entity_type->getBundleEntityType()
      ? $this->entityTypeManager()->getStorage($entity_type->getBundleEntityType())->load($entity->bundle())?->label()
      : NULL;

    return [
      ['data' => $label],
      $bundle ? $entity_type->getLabel() . ': ' . $bundle : $entity_type->getLabel(),
      match ($host['published']) {
        TRUE => 'Published',
        FALSE => 'Unpublished',
        NULL => '—',
      },
      $this->describeRevisions($host),
      [
        'data' => $operations ? [
          '#type' => 'operations',
          '#links' => $operations,
        ] : '',
      ],
    ];
  }

  /**
   * Links to an entity, preferring its page over its edit form.
   */
  private function entityLink(EntityInterface $entity): array {
    $rel = $entity->hasLinkTemplate('canonical') ? 'canonical' : 'edit-form';
    return $entity->toLink(NULL, $rel, ['attributes' => ['target' => '_blank']])->toRenderable();
  }

  /**
   * Says which revisions of a host reference the paragraph type.
   *
   * @param array $host
   *   A host entry from ParagraphUsageAnalyzer::hostReferences().
   *
   * @return string
   *   For example "Current revision, 3 older".
   */
  private function describeRevisions(array $host): string {
    $parts = [];
    if ($host['current']) {
      $parts[] = 'Current revision';
    }
    if ($host['old_revisions']) {
      $parts[] = (string) $this->formatPlural($host['old_revisions'], '1 older revision', '@count older revisions');
    }
    if ($host['newer_revisions']) {
      $parts[] = (string) $this->formatPlural($host['newer_revisions'], '1 newer draft revision', '@count newer draft revisions');
    }
    if (!$host['current'] && $parts) {
      $parts[] = 'not the current one';
    }
    return implode(', ', $parts);
  }

  /**
   * Lists where a block host is shown: placements and embedding pages.
   *
   * @param array $host
   *   A block_content entry from ParagraphUsageAnalyzer::hostReferences().
   *
   * @return array
   *   Items for an item list.
   */
  private function blockDisplayItems(array $host): array {
    $items = [];
    foreach ($host['placements'] ?? [] as $placement) {
      $items[] = $this->t('Placed in the %theme theme, %region region (@status)', [
        '%theme' => $placement['theme'],
        '%region' => $placement['region'],
        '@status' => $placement['enabled'] ? 'enabled' : 'disabled',
      ]);
    }

    foreach ($host['embedded_in'] ?? [] as $embed) {
      $entity = $this->entityTypeManager()->getStorage($embed['entity_type'])->load($embed['id']);
      $items[] = [
        'prefix' => ['#markup' => $this->t('Embedded on') . ' '],
        'link' => $entity ? $this->entityLink($entity) : ['#markup' => $embed['entity_type'] . ':' . $embed['id']],
        'suffix' => [
          '#markup' => ' (' . match ($embed['published']) {
            TRUE => 'published',
            FALSE => 'unpublished',
            NULL => 'no status',
          } . ', ' . mb_strtolower($this->describeRevisions($embed)) . ')',
        ],
      ];
    }

    return $items;
  }

  /**
   * Summarizes what references a type, for the Unused table.
   *
   * @param array $hosts
   *   Host entries from ParagraphUsageAnalyzer::hostReferences().
   *
   * @return array
   *   Items for an item list, such as "Unpublished: 2 content items".
   */
  private function summarizeReferences(array $hosts): array {
    // Reason => entity type id => count.
    $counts = [];
    $warnings = [];
    foreach ($hosts as $host) {
      $reason = match (TRUE) {
        $host['current'] && $host['published'] !== FALSE => 'Published',
        $host['current'] => 'Unpublished',
        $host['newer_revisions'] > 0 => 'Newer draft revisions only',
        default => 'Older revisions only',
      };
      $counts[$reason][$host['entity_type']] = ($counts[$reason][$host['entity_type']] ?? 0) + 1;

      // A published block may still put the paragraphs on a page.
      if ($host['current'] && $host['published'] !== FALSE) {
        foreach ($host['placements'] ?? [] as $placement) {
          if ($placement['enabled']) {
            $warnings['placed'] = 'A published block holding these is placed in the block layout, so they may be visible on the site.';
          }
        }
        foreach ($host['embedded_in'] ?? [] as $embed) {
          if ($embed['current'] && $embed['published'] !== FALSE) {
            $warnings['embedded'] = 'A published block holding these is embedded on a published page.';
          }
        }
      }
    }

    $items = [];
    foreach (['Published', 'Unpublished', 'Newer draft revisions only', 'Older revisions only'] as $reason) {
      foreach ($counts[$reason] ?? [] as $entity_type_id => $count) {
        $items[] = $reason . ': ' . $this->entityTypeManager()->getDefinition($entity_type_id)->getCountLabel($count);
      }
    }
    foreach ($warnings as $warning) {
      $items[] = ['#markup' => '<strong>' . $warning . '</strong>'];
    }

    return $items;
  }

  /**
   * Shows one paragraph as the site renders it, and what references it.
   *
   * A "revision" query parameter shows that revision of the paragraph
   * instead of its current one.
   */
  public function paragraph(ParagraphInterface $paragraph, Request $request): array {
    $shown = $paragraph;
    if ($revision_id = $request->query->get('revision')) {
      $shown = $this->entityTypeManager()->getStorage('paragraph')->loadRevision($revision_id);
      if (!$shown || $shown->id() !== $paragraph->id()) {
        throw new NotFoundHttpException();
      }
    }

    $type = $paragraph->getParagraphType();
    $manage = $type ? Url::fromRoute('entity.paragraphs_type.edit_form', ['paragraphs_type' => $type->id()]) : NULL;
    $details = [
      [
        '#markup' => $this->t('Type: %label (%machine_name)', [
          '%label' => $type?->label() ?? $paragraph->bundle(),
          '%machine_name' => $paragraph->bundle(),
        ]),
        'manage' => $manage && $manage->access() ? [
          '#prefix' => ' ',
          '#type' => 'link',
          '#title' => $this->t('Manage type'),
          '#url' => $manage,
        ] : [],
      ],
      $this->t('Paragraph ID: @id', ['@id' => $paragraph->id()]),
      $shown->isDefaultRevision()
        ? $this->t('Revision: @revision (current)', ['@revision' => $shown->getRevisionId()])
        : $this->t('Revision: @revision (not current; the current revision is @current)', [
          '@revision' => $shown->getRevisionId(),
          '@current' => $paragraph->getRevisionId(),
        ]),
      $this->t('Created: @date', ['@date' => $this->dateFormatter->format($paragraph->getCreatedTime(), 'short')]),
      $shown->isPublished() ? $this->t('Status: Published') : $this->t('Status: Unpublished'),
      $this->storedParentItem($paragraph),
    ];

    $rows = array_map(fn ($host) => $this->referenceRow($host), $this->usageAnalyzer->hostReferencesForParagraph((int) $paragraph->id()));

    return [
      '#attached' => ['library' => ['daglab_paragraphs/paragraph_viewer']],
      'preview' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['daglab-paragraph-viewer__preview']],
        'paragraph' => $this->entityTypeManager()->getViewBuilder('paragraph')->view($shown),
      ],
      'details' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['daglab-paragraph-viewer__details']],
        'about' => [
          '#theme' => 'item_list',
          '#title' => $this->t('About this paragraph'),
          '#items' => $details,
        ],
        'references' => [
          '#type' => 'html_tag',
          '#tag' => 'h2',
          '#value' => $this->t('Where it is used'),
        ],
        'table' => [
          '#theme' => 'table',
          '#attributes' => [
            'class' => ['table'],
          ],
          '#header' => ['Referenced by', 'Kind', 'Status', 'Revisions', 'Operations'],
          '#rows' => $rows,
          '#empty' => $this->t('Nothing references this paragraph, in any revision. It is an orphan.'),
        ],
      ],
      '#cache' => [
        'tags' => self::CACHE_TAGS,
        // Operations depend on the viewer's access to each entity.
        'contexts' => ['url.query_args:revision', 'user'],
      ],
    ];
  }

  /**
   * Title callback for the paragraph viewer.
   */
  public function paragraphTitle(ParagraphInterface $paragraph): string {
    return 'Paragraph ' . $paragraph->id() . ': ' . ($paragraph->getParagraphType()?->label() ?? $paragraph->bundle());
  }

  /**
   * Describes the parent a paragraph has stored, and whether it is real.
   *
   * @param \Drupal\paragraphs\ParagraphInterface $paragraph
   *   The paragraph.
   *
   * @return array
   *   An item for the details list.
   */
  private function storedParentItem(ParagraphInterface $paragraph): array {
    $parent = $paragraph->getParentEntity();
    if (!$parent) {
      return ['#markup' => $this->t('Stored parent: none, or it no longer exists.')];
    }

    $item = [
      'label' => ['#markup' => $this->t('Stored parent:') . ' '],
      'link' => $parent instanceof ParagraphInterface
        ? Link::createFromRoute($this->paragraphTitle($parent), 'daglab_paragraphs.paragraph', ['paragraph' => $parent->id()])->toRenderable()
        : $this->entityLink($parent),
      'field' => ['#markup' => ' (' . $paragraph->get('parent_field_name')->value . ')'],
    ];
    if ($this->usageAnalyzer->parentReferences($paragraph) === FALSE) {
      $item['warning'] = [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#attributes' => ['class' => ['daglab-paragraph-viewer__warning']],
        '#value' => $this->t('No revision of this parent references the paragraph any more. Where it is used, below, is what actually references it.'),
      ];
    }
    return $item;
  }

  /**
   * Builds a "View example" operation for a paragraph.
   *
   * @param int|null $paragraph_id
   *   The paragraph, or NULL for no operation.
   * @param int|null $revision_id
   *   A specific revision to show, or NULL for the current one.
   * @param string|null $title
   *   The link text, "View example" by default.
   *
   * @return array
   *   Zero or one operations.
   */
  private function viewExampleOperation(?int $paragraph_id, ?int $revision_id = NULL, $title = NULL): array {
    if (!$paragraph_id) {
      return [];
    }
    return [
      'view_example' => [
        'title' => $title ?? $this->t('View example'),
        'url' => Url::fromRoute(
          'daglab_paragraphs.paragraph',
          ['paragraph' => $paragraph_id],
          $revision_id ? ['query' => ['revision' => $revision_id]] : [],
        ),
      ],
    ];
  }

  /**
   * Builds the operations cell for a paragraph type's row.
   *
   * @param string $type
   *   The paragraph type machine name.
   * @param array $operations
   *   Report-specific operations, listed first.
   *
   * @return array
   *   A table cell holding an operations dropbutton, with a link to manage
   *   the paragraph type last for those who may.
   */
  private function typeOperations(string $type, array $operations = []): array {
    $manage = Url::fromRoute('entity.paragraphs_type.edit_form', ['paragraphs_type' => $type]);
    if ($manage->access()) {
      $operations['manage_type'] = [
        'title' => $this->t('Manage type'),
        'url' => $manage,
      ];
    }

    return [
      'data' => $operations ? [
        '#type' => 'operations',
        '#links' => $operations,
      ] : '',
    ];
  }

  /**
   * Renders one table of paragraph types.
   *
   * @param string $title
   *   The details element title.
   * @param string $description
   *   Explains what landing in this table means.
   * @param string $empty
   *   Shown when there is nothing in this bucket.
   * @param array $types
   *   Machine names of the paragraph types to list.
   * @param array $defined_paragraph_types
   *   All paragraph type entities, keyed by machine name.
   *
   * @return array
   *   A render array.
   */
  private function renderUnusedBucket(string $title, string $description, string $empty, array $types, array $defined_paragraph_types): array {
    return [
      '#type' => 'details',
      '#title' => $title,
      '#open' => TRUE,
      'description' => [
        '#markup' => '<p>' . $description . '</p>',
      ],
      'table' => [
        '#theme' => 'table',
        '#empty' => $empty,
        '#header' => ['Paragraph type', 'Name'],
        '#rows' => array_map(function ($type) use ($defined_paragraph_types) {
          $paragraph_type = $defined_paragraph_types[$type];
          return [
            $paragraph_type->id(),
            $paragraph_type->label(),
          ];
        }, $types),
      ],
    ];
  }

}
