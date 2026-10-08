<?php

namespace Drupal\daglab_paragraphs;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Sql\SqlContentEntityStorage;
use Drupal\paragraphs\ParagraphInterface;

/**
 * Determines which paragraph types are actually in use.
 *
 * A paragraph entity is only "in use" if some node revision reaches it, either
 * directly or through a chain of parent paragraphs. The parent_id / parent_type
 * columns on the paragraph itself are not trustworthy for this: they survive
 * after a node revision stops referencing the paragraph, and they point at
 * different entity types depending on parent_type.
 */
class ParagraphUsageAnalyzer {

  /**
   * Number of ids per IN() condition while walking nested paragraphs.
   */
  private const CHUNK_SIZE = 1000;

  public function __construct(
    private Connection $database,
    private EntityTypeManagerInterface $entityTypeManager,
    private EntityFieldManagerInterface $entityFieldManager,
  ) {}

  /**
   * Finds the published nodes each paragraph type appears on.
   *
   * Starts from the paragraph fields on published nodes' current revisions,
   * then walks down through nested paragraphs, carrying along the nodes each
   * paragraph was reached from. A published block embedded by a paragraph
   * through a block field is walked into as well, so its paragraphs count for
   * the page the block is shown on.
   *
   * Only the current data tables are read, not the revision tables: a
   * paragraph that an old node revision kept does not count as published.
   *
   * @return array
   *   Sorted lists of node ids, keyed by paragraph type machine name. A type
   *   only in a placed block has an empty list; see ::publishedUsageDetails().
   *   Types that are not shown anywhere are left out.
   */
  public function publishedUsage(): array {
    return array_map(fn ($details) => $details['nids'], $this->publishedUsageDetails());
  }

  /**
   * Finds where each paragraph type is shown, with an example of each.
   *
   * Same walk as ::publishedUsage(), also starting from published blocks
   * placed through the block layout. Those are not on any one page, so they
   * are listed separately from the pages.
   *
   * @return array
   *   Keyed by paragraph type machine name, each with:
   *   - nids: the sorted ids of published nodes showing the type.
   *   - block_ids: the sorted ids of placed, published blocks holding it.
   *   - example_paragraph_id: a paragraph of the type on the newest of those
   *     nodes, or in a block when no node shows it.
   */
  public function publishedUsageDetails(): array {
    $node_type = $this->entityTypeManager->getDefinition('node');
    $node_table = $node_type->getDataTable();
    $id_key = $node_type->getKey('id');
    $langcode_key = $node_type->getKey('langcode');
    $published_key = $node_type->getKey('published');

    // Paragraph id => [host key => TRUE] for every paragraph reached so far,
    // where a host key is "node:ID" or "block_content:ID".
    $hosts_by_paragraph = [];
    // Paragraphs whose children still have to be visited.
    $frontier = [];

    foreach ($this->referenceTables('node', TRUE) as $reference) {
      $query = $this->database->select($reference['table'], 'f');
      $query->join($node_table, 'n', "[n].[$id_key] = [f].[entity_id] AND [n].[$langcode_key] = [f].[langcode]");
      $query->addField('f', 'entity_id', 'parent_id');
      $query->addField('f', $reference['column'], 'target_id');
      $query->condition("n.$published_key", 1);
      $query->condition('f.deleted', 0);
      $query->distinct();

      foreach ($query->execute() as $row) {
        $hosts_by_paragraph[$row->target_id]['node:' . $row->parent_id] = TRUE;
        $frontier[$row->target_id] = TRUE;
      }
    }

    $placed_blocks = $this->placedBlockIds();
    if ($placed_blocks) {
      foreach ($this->referenceTables('block_content', TRUE) as $reference) {
        $query = $this->database->select($reference['table'], 'f');
        $query->addField('f', 'entity_id', 'parent_id');
        $query->addField('f', $reference['column'], 'target_id');
        $query->condition('f.entity_id', $placed_blocks, 'IN');
        $query->condition('f.deleted', 0);
        $query->distinct();

        foreach ($query->execute() as $row) {
          $hosts_by_paragraph[$row->target_id]['block_content:' . $row->parent_id] = TRUE;
          $frontier[$row->target_id] = TRUE;
        }
      }
    }

    while ($frontier) {
      $next = [];
      foreach (array_chunk(array_keys($frontier), self::CHUNK_SIZE) as $chunk) {
        foreach ($this->currentChildParagraphs($chunk) as [$parent_id, $child_id]) {
          $parent_hosts = $hosts_by_paragraph[$parent_id];
          $known_hosts = $hosts_by_paragraph[$child_id] ?? [];

          // Only revisit a child when it picked up a host it did not have,
          // which also stops the walk if references ever form a loop.
          if (array_diff_key($parent_hosts, $known_hosts)) {
            $hosts_by_paragraph[$child_id] = $known_hosts + $parent_hosts;
            $next[$child_id] = TRUE;
          }
        }
      }
      $frontier = $next;
    }

    // Group by paragraph type.
    $types_by_paragraph = $this->database->select('paragraphs_item', 'p')
      ->fields('p', ['id', 'type'])
      ->execute()
      ->fetchAllKeyed();

    $result = [];
    foreach ($hosts_by_paragraph as $paragraph_id => $hosts) {
      if (!isset($types_by_paragraph[$paragraph_id])) {
        continue;
      }
      $type = $types_by_paragraph[$paragraph_id];
      $result[$type] ??= ['nids' => [], 'block_ids' => []];

      $nids = [];
      $block_ids = [];
      foreach (array_keys($hosts) as $host) {
        [$host_type, $host_id] = explode(':', $host);
        if ($host_type === 'node') {
          $nids[(int) $host_id] = TRUE;
        }
        else {
          $block_ids[(int) $host_id] = TRUE;
        }
      }
      $result[$type]['nids'] += $nids;
      $result[$type]['block_ids'] += $block_ids;

      // Prefer a paragraph on a page over one in a block, then the newest
      // page, then the lowest paragraph id.
      $rank = $nids ? [1, max(array_keys($nids)), -$paragraph_id] : [0, 0, -$paragraph_id];
      $example = $result[$type]['example'] ?? NULL;
      if (!$example || $rank > $example['rank']) {
        $result[$type]['example'] = ['rank' => $rank, 'paragraph_id' => (int) $paragraph_id];
      }
    }

    ksort($result);
    foreach ($result as $type => $details) {
      $nids = array_keys($details['nids']);
      $block_ids = array_keys($details['block_ids']);
      sort($nids);
      sort($block_ids);
      $result[$type] = [
        'nids' => $nids,
        'block_ids' => $block_ids,
        'example_paragraph_id' => $details['example']['paragraph_id'],
      ];
    }

    return $result;
  }

  /**
   * Finds the published blocks placed through the block layout.
   *
   * @return array
   *   Ids of published block_content entities with an enabled placement in
   *   any theme.
   */
  private function placedBlockIds(): array {
    if (!$this->entityTypeManager->hasDefinition('block') || !$this->entityTypeManager->hasDefinition('block_content')) {
      return [];
    }

    $uuids = [];
    /** @var \Drupal\block\BlockInterface $placement */
    foreach ($this->entityTypeManager->getStorage('block')->loadByProperties(['status' => TRUE]) as $placement) {
      if (str_starts_with($placement->getPluginId(), 'block_content:')) {
        $uuids[] = substr($placement->getPluginId(), strlen('block_content:'));
      }
    }
    if (!$uuids) {
      return [];
    }

    $block_type = $this->entityTypeManager->getDefinition('block_content');
    $id_key = $block_type->getKey('id');
    $query = $this->database->select($block_type->getBaseTable(), 'b');
    $query->join($block_type->getDataTable(), 'd', "[d].[$id_key] = [b].[$id_key]");
    $query->addField('b', $id_key);
    $query->condition('b.' . $block_type->getKey('uuid'), $uuids, 'IN');
    $query->condition('d.' . $block_type->getKey('published'), 1);
    $query->distinct();

    return array_map('intval', $query->execute()->fetchCol());
  }

  /**
   * Buckets every defined paragraph type that is not on a published page.
   *
   * @return array
   *   Keyed by bucket, each holding a sorted list of machine names:
   *   - never_created: defined in config, but no entity was ever created.
   *   - orphaned: entities exist, but no node revision references them.
   *   - not_on_published: referenced, but not shown by any published node's
   *     current revision or placed published block.
   */
  public function classifyUnusedTypes(): array {
    $published_types = array_keys($this->publishedUsage());

    $defined_types = array_keys(
      $this->entityTypeManager->getStorage('paragraphs_type')->loadMultiple()
    );
    sort($defined_types);

    // Every paragraph entity that exists, as id => bundle.
    $entity_types = $this->database->select('paragraphs_item', 'p')
      ->fields('p', ['id', 'type'])
      ->execute()
      ->fetchAllKeyed();

    // Bundles that have at least one entity, and bundles that have at least
    // one entity a node revision can reach.
    $created_types = array_flip($entity_types);
    $reachable_types = [];
    foreach (array_keys($this->reachableParagraphIds()) as $id) {
      if (isset($entity_types[$id])) {
        $reachable_types[$entity_types[$id]] = TRUE;
      }
    }

    $result = [
      'never_created' => [],
      'orphaned' => [],
      'not_on_published' => [],
    ];

    foreach ($defined_types as $type) {
      if (!isset($created_types[$type])) {
        $result['never_created'][] = $type;
      }
      elseif (!isset($reachable_types[$type])) {
        $result['orphaned'][] = $type;
      }
      elseif (!in_array($type, $published_types, TRUE)) {
        $result['not_on_published'][] = $type;
      }
    }

    return $result;
  }

  /**
   * Lists the paragraphs of one type that nothing references.
   *
   * These are the entities behind the "Orphaned Paragraphs" table on the
   * Unused Paragraphs report: the rows survive in the database, but no revision
   * of any host entity points at them.
   *
   * @param string $paragraph_type
   *   The paragraph type machine name.
   *
   * @return array
   *   Paragraph ids, safe to delete.
   */
  public function orphanedParagraphIds(string $paragraph_type): array {
    $ids = $this->database->select('paragraphs_item', 'p')
      ->fields('p', ['id'])
      ->condition('type', $paragraph_type)
      ->execute()
      ->fetchCol();

    $reachable = $this->reachableParagraphIds();

    return array_values(array_filter($ids, function ($id) use ($reachable) {
      return !isset($reachable[$id]);
    }));
  }

  /**
   * Counts the paragraphs nothing references, for every type at once.
   *
   * Same rule as ::orphanedParagraphIds(), but with a single walk instead of
   * one per type.
   *
   * @return array
   *   Orphan counts keyed by paragraph type machine name. Types without
   *   orphans are left out.
   */
  public function orphanCounts(): array {
    return array_map(fn ($summary) => $summary['count'], $this->orphanSummary());
  }

  /**
   * Counts the paragraphs nothing references, with an example of each type.
   *
   * @return array
   *   Keyed by paragraph type machine name, each with a 'count' and an
   *   'example_paragraph_id' (the lowest orphan id). Types without orphans
   *   are left out.
   */
  public function orphanSummary(): array {
    $reachable = $this->reachableParagraphIds();
    $types_by_paragraph = $this->database->select('paragraphs_item', 'p')
      ->fields('p', ['id', 'type'])
      ->orderBy('id')
      ->execute()
      ->fetchAllKeyed();

    $summary = [];
    foreach ($types_by_paragraph as $id => $type) {
      if (!isset($reachable[$id])) {
        $summary[$type]['count'] = ($summary[$type]['count'] ?? 0) + 1;
        $summary[$type]['example_paragraph_id'] ??= (int) $id;
      }
    }

    ksort($summary);
    return $summary;
  }

  /**
   * Lists everything that still references the paragraphs of one type.
   *
   * Explains why a type is kept: which hosts reference its paragraphs, and
   * whether through their current revision, older revisions, or newer
   * (forward) revisions. Walks up from the type's paragraph revisions through
   * the exact parent revisions that contain them, so a page only counts as
   * current when its current revision really shows the paragraph.
   *
   * @param string $paragraph_type
   *   The paragraph type machine name.
   *
   * @return array
   *   One entry per host entity, current references first, each with:
   *   - entity_type, id: the host entity.
   *   - current: whether the host's current revision references the type.
   *   - published: whether the host is published, or NULL if it has no
   *     published status.
   *   - old_revisions: how many older revisions reference it.
   *   - newer_revisions: how many forward revisions reference it.
   *   - old_revision_ids: the ids of those older revisions.
   *   - example: the 'paragraph_id' and 'revision_id' of one paragraph of the
   *     type this host shows, from its current revision where it has one,
   *     otherwise its newest older revision.
   *   Blocks also have:
   *   - placements: the block layout placements showing the block, each with
   *     an 'id', 'theme', 'region' and 'enabled'.
   *   - embedded_in: the hosts of paragraphs that embed the block through a
   *     block field, in this same format.
   */
  public function hostReferences(string $paragraph_type): array {
    $paragraph_definition = $this->entityTypeManager->getDefinition('paragraph');
    $id_key = $paragraph_definition->getKey('id');
    $revision_key = $paragraph_definition->getKey('revision');

    $query = $this->database->select($paragraph_definition->getRevisionTable(), 'r');
    $query->join($paragraph_definition->getBaseTable(), 'p', "[p].[$id_key] = [r].[$id_key]");
    $query->addField('r', $revision_key);
    $query->condition('p.' . $paragraph_definition->getKey('bundle'), $paragraph_type);

    return $this->hostsOfParagraphRevisions($query->execute()->fetchCol(), 0);
  }

  /**
   * Lists everything that references one paragraph, in any of its revisions.
   *
   * @param int $paragraph_id
   *   The paragraph id.
   *
   * @return array
   *   Host entries, as described on ::hostReferences().
   */
  public function hostReferencesForParagraph(int $paragraph_id): array {
    $definition = $this->entityTypeManager->getDefinition('paragraph');
    $revision_ids = $this->database->select($definition->getRevisionTable(), 'r')
      ->fields('r', [$definition->getKey('revision')])
      ->condition($definition->getKey('id'), $paragraph_id)
      ->execute()
      ->fetchCol();

    return $revision_ids ? $this->hostsOfParagraphRevisions($revision_ids, 0) : [];
  }

  /**
   * Checks whether a paragraph's stored parent really references it.
   *
   * The parent_type, parent_id and parent_field_name values on a paragraph
   * are kept after the parent stops using it, so they can name a parent that
   * no revision of which references the paragraph any more.
   *
   * @param \Drupal\paragraphs\ParagraphInterface $paragraph
   *   The paragraph.
   *
   * @return bool|null
   *   TRUE if some revision of the stored parent references the paragraph,
   *   FALSE if none does, or NULL if the paragraph names no usable parent.
   */
  public function parentReferences(ParagraphInterface $paragraph): ?bool {
    $parent_type = $paragraph->get('parent_type')->value;
    $parent_id = $paragraph->get('parent_id')->value;
    $field_name = $paragraph->get('parent_field_name')->value;
    if (!$parent_type || !$parent_id || !$field_name || !$this->entityTypeManager->hasDefinition($parent_type)) {
      return NULL;
    }

    foreach ($this->referenceTables($parent_type) as $reference) {
      if ($reference['column'] !== $field_name . '_target_id') {
        continue;
      }
      return (bool) $this->database->select($reference['table'], 'f')
        ->condition('entity_id', $parent_id)
        ->condition($reference['column'], $paragraph->id())
        ->countQuery()
        ->execute()
        ->fetchField();
    }

    return NULL;
  }

  /**
   * Finds the hosts whose revisions contain some paragraph revisions.
   *
   * @param array $revision_ids
   *   Paragraph revision ids.
   * @param int $depth
   *   How many block embeds deep this lookup is, to stop runaway nesting.
   *
   * @return array
   *   Host entries, as described on ::hostReferences().
   */
  private function hostsOfParagraphRevisions(array $revision_ids, int $depth): array {
    // Every paragraph revision that contains one of the starting revisions,
    // however deeply nested, mapped to a starting revision it contains.
    $containing = array_combine($revision_ids, $revision_ids);
    $frontier = $revision_ids;
    $nested_references = $this->referenceTables('paragraph');
    while ($frontier) {
      $next = [];
      foreach (array_chunk($frontier, self::CHUNK_SIZE) as $chunk) {
        foreach ($nested_references as $reference) {
          $query = $this->database->select($reference['table'], 'f');
          $query->addField('f', 'revision_id', 'parent_revision_id');
          $query->addField('f', $reference['revision_column'], 'child_revision_id');
          $query->condition($reference['revision_column'], $chunk, 'IN');
          $query->distinct();
          foreach ($query->execute() as $row) {
            if (!isset($containing[$row->parent_revision_id])) {
              $containing[$row->parent_revision_id] = $containing[$row->child_revision_id];
              $next[] = $row->parent_revision_id;
            }
          }
        }
      }
      $frontier = $next;
    }

    $hosts = [];
    foreach ($this->hostEntityTypeIds() as $host_type) {
      if ($host_type === 'paragraph') {
        continue;
      }
      $revisionable = $this->entityTypeManager->getDefinition($host_type)->isRevisionable();

      // Host id => [host revision id => starting paragraph revision it holds],
      // with 0 as the revision of non-revisionable hosts.
      $referencing = [];
      foreach ($this->referenceTables($host_type) as $reference) {
        foreach (array_chunk(array_keys($containing), self::CHUNK_SIZE) as $chunk) {
          $query = $this->database->select($reference['table'], 'f');
          $query->addField('f', 'entity_id');
          $query->addField('f', $reference['revision_column'], 'target_revision_id');
          if ($revisionable) {
            $query->addField('f', 'revision_id');
          }
          $query->condition($reference['revision_column'], $chunk, 'IN');
          $query->distinct();
          foreach ($query->execute() as $row) {
            $referencing[$row->entity_id][$row->revision_id ?? 0] = $containing[$row->target_revision_id];
          }
        }
      }
      if (!$referencing) {
        continue;
      }

      $current = $this->currentRevisions($host_type, array_keys($referencing));
      foreach ($referencing as $id => $host_revisions) {
        // Skip references left behind by a host that no longer exists.
        if (!isset($current[$id])) {
          continue;
        }

        $host = [
          'entity_type' => $host_type,
          'id' => (int) $id,
          'current' => FALSE,
          'published' => $current[$id]['published'],
          'old_revisions' => 0,
          'newer_revisions' => 0,
          'old_revision_ids' => [],
        ];
        // The example comes from the current revision if it has one, then the
        // newest older revision, then a newer draft.
        $examples = [];
        foreach ($host_revisions as $revision_id => $paragraph_revision_id) {
          if (!$revisionable || $revision_id == $current[$id]['revision_id']) {
            $host['current'] = TRUE;
            $examples[2][0] = $paragraph_revision_id;
          }
          elseif ($revision_id < $current[$id]['revision_id']) {
            $host['old_revisions']++;
            $host['old_revision_ids'][] = (int) $revision_id;
            $examples[1][$revision_id] = $paragraph_revision_id;
          }
          else {
            $host['newer_revisions']++;
            $examples[0][$revision_id] = $paragraph_revision_id;
          }
        }
        krsort($examples);
        $best = reset($examples);
        krsort($best);
        $host['example_revision_id'] = (int) reset($best);

        sort($host['old_revision_ids']);
        if ($host_type === 'block_content') {
          $host += $this->blockDisplay((int) $id, $depth);
        }
        $hosts[] = $host;
      }
    }

    // Turn example revisions into paragraph id and revision pairs.
    $revision_ids = array_column($hosts, 'example_revision_id');
    $paragraph_ids = $revision_ids ? $this->paragraphIdsOfRevisions($revision_ids) : [];
    foreach ($hosts as &$host) {
      $revision_id = $host['example_revision_id'];
      unset($host['example_revision_id']);
      $host['example'] = [
        'paragraph_id' => $paragraph_ids[$revision_id] ?? NULL,
        'revision_id' => $revision_id,
      ];
    }
    unset($host);

    usort($hosts, fn ($a, $b) => [$b['current'], $a['entity_type'], $a['id']] <=> [$a['current'], $b['entity_type'], $b['id']]);
    return $hosts;
  }

  /**
   * Looks up which paragraph each of some paragraph revisions belongs to.
   *
   * @param array $revision_ids
   *   Paragraph revision ids.
   *
   * @return array
   *   Paragraph ids, keyed by revision id.
   */
  private function paragraphIdsOfRevisions(array $revision_ids): array {
    $definition = $this->entityTypeManager->getDefinition('paragraph');
    $result = [];
    foreach (array_chunk(array_unique($revision_ids), self::CHUNK_SIZE) as $chunk) {
      $result += array_map('intval', $this->database->select($definition->getRevisionTable(), 'r')
        ->fields('r', [$definition->getKey('revision'), $definition->getKey('id')])
        ->condition($definition->getKey('revision'), $chunk, 'IN')
        ->execute()
        ->fetchAllKeyed());
    }
    return $result;
  }

  /**
   * Looks up the current revision and published status of some entities.
   *
   * @param string $entity_type_id
   *   The entity type.
   * @param array $ids
   *   Entity ids.
   *
   * @return array
   *   Keyed by entity id, each with a 'revision_id' (NULL if the type is not
   *   revisionable) and 'published' (TRUE if any translation is published,
   *   NULL if the type has no published status).
   */
  private function currentRevisions(string $entity_type_id, array $ids): array {
    $definition = $this->entityTypeManager->getDefinition($entity_type_id);
    $id_key = $definition->getKey('id');
    $revision_key = $definition->getKey('revision');
    $published_key = $definition->getKey('published');
    $data_table = $definition->getDataTable() ?: $definition->getBaseTable();

    $result = [];
    foreach (array_chunk($ids, self::CHUNK_SIZE) as $chunk) {
      $query = $this->database->select($definition->getBaseTable(), 'b');
      $query->addField('b', $id_key, 'id');
      if ($revision_key) {
        $query->addField('b', $revision_key, 'revision_id');
      }
      if ($published_key) {
        $query->leftJoin($data_table, 'd', "[d].[$id_key] = [b].[$id_key]");
        $query->addExpression("MAX([d].[$published_key])", 'published');
        $query->groupBy("b.$id_key");
        if ($revision_key) {
          $query->groupBy("b.$revision_key");
        }
      }
      $query->condition("b.$id_key", $chunk, 'IN');

      foreach ($query->execute() as $row) {
        $result[$row->id] = [
          'revision_id' => $row->revision_id ?? NULL,
          'published' => $published_key ? (bool) $row->published : NULL,
        ];
      }
    }

    return $result;
  }

  /**
   * Works out where a block is shown.
   *
   * @param int $block_id
   *   The block_content entity id.
   * @param int $depth
   *   How many block embeds deep this lookup is.
   *
   * @return array
   *   The 'placements' and 'embedded_in' parts of a host entry.
   */
  private function blockDisplay(int $block_id, int $depth): array {
    $display = ['placements' => [], 'embedded_in' => []];
    $uuid = $this->database->select($this->entityTypeManager->getDefinition('block_content')->getBaseTable(), 'b')
      ->fields('b', ['uuid'])
      ->condition('id', $block_id)
      ->execute()
      ->fetchField();
    if (!$uuid) {
      return $display;
    }
    $plugin_id = 'block_content:' . $uuid;

    if ($this->entityTypeManager->hasDefinition('block')) {
      /** @var \Drupal\block\BlockInterface $placement */
      foreach ($this->entityTypeManager->getStorage('block')->loadByProperties(['plugin' => $plugin_id]) as $placement) {
        $display['placements'][] = [
          'id' => $placement->id(),
          'theme' => $placement->getTheme(),
          'region' => $placement->getRegion(),
          'enabled' => $placement->status(),
        ];
      }
    }

    // Paragraphs embedding the block, then whatever hosts those paragraphs.
    if ($depth < 3) {
      $embedding = [];
      foreach ($this->blockFieldTables('paragraph') as $reference) {
        $query = $this->database->select($reference['table'], 'f')
          ->fields('f', ['revision_id'])
          ->condition($reference['column'], $plugin_id)
          ->distinct();
        $embedding = array_merge($embedding, $query->execute()->fetchCol());
      }
      if ($embedding) {
        $display['embedded_in'] = $this->hostsOfParagraphRevisions(array_unique($embedding), $depth + 1);
      }
    }

    return $display;
  }

  /**
   * Finds the paragraphs shown inside some paragraphs' current revisions.
   *
   * Covers nested paragraph fields, and paragraphs inside a published block
   * that a block field embeds.
   *
   * @param array $paragraph_ids
   *   The parent paragraph ids.
   *
   * @return array
   *   Pairs of [parent paragraph id, child paragraph id].
   */
  private function currentChildParagraphs(array $paragraph_ids): array {
    $pairs = [];

    foreach ($this->referenceTables('paragraph', TRUE) as $reference) {
      $query = $this->database->select($reference['table'], 'f');
      $query->addField('f', 'entity_id', 'parent_id');
      $query->addField('f', $reference['column'], 'target_id');
      $query->condition('f.entity_id', $paragraph_ids, 'IN');
      $query->condition('f.deleted', 0);
      $query->distinct();
      foreach ($query->execute() as $row) {
        $pairs[] = [$row->parent_id, $row->target_id];
      }
    }

    // Paragraph id => block uuids it embeds.
    $embeds = [];
    foreach ($this->blockFieldTables('paragraph', TRUE) as $reference) {
      $query = $this->database->select($reference['table'], 'f');
      $query->addField('f', 'entity_id', 'parent_id');
      $query->addField('f', $reference['column'], 'plugin_id');
      $query->condition('f.entity_id', $paragraph_ids, 'IN');
      $query->condition($reference['column'], 'block_content:%', 'LIKE');
      $query->condition('f.deleted', 0);
      foreach ($query->execute() as $row) {
        $embeds[$row->parent_id][] = substr($row->plugin_id, strlen('block_content:'));
      }
    }
    if (!$embeds || !$this->entityTypeManager->hasDefinition('block_content')) {
      return $pairs;
    }

    // Block uuid => paragraph ids in that block's current revision, for
    // published blocks only.
    $block_type = $this->entityTypeManager->getDefinition('block_content');
    $id_key = $block_type->getKey('id');
    $query = $this->database->select($block_type->getBaseTable(), 'b');
    $query->join($block_type->getDataTable(), 'd', "[d].[$id_key] = [b].[$id_key]");
    $query->addField('b', $id_key, 'id');
    $query->addField('b', $block_type->getKey('uuid'), 'uuid');
    $query->condition('b.' . $block_type->getKey('uuid'), array_merge(...array_values($embeds)), 'IN');
    $query->condition('d.' . $block_type->getKey('published'), 1);
    $query->distinct();
    $uuids_by_block = $query->execute()->fetchAllKeyed();

    $paragraphs_by_uuid = [];
    if ($uuids_by_block) {
      foreach ($this->referenceTables('block_content', TRUE) as $reference) {
        $query = $this->database->select($reference['table'], 'f');
        $query->addField('f', 'entity_id', 'block_id');
        $query->addField('f', $reference['column'], 'target_id');
        $query->condition('f.entity_id', array_keys($uuids_by_block), 'IN');
        $query->condition('f.deleted', 0);
        $query->distinct();
        foreach ($query->execute() as $row) {
          $paragraphs_by_uuid[$uuids_by_block[$row->block_id]][] = $row->target_id;
        }
      }
    }

    foreach ($embeds as $parent_id => $uuids) {
      foreach ($uuids as $uuid) {
        foreach ($paragraphs_by_uuid[$uuid] ?? [] as $child_id) {
          $pairs[] = [$parent_id, $child_id];
        }
      }
    }

    return $pairs;
  }

  /**
   * Collects every paragraph id reachable from a host entity.
   *
   * Starts from the paragraphs that host entities reference directly, then
   * walks down through nested paragraph reference fields until no new ids turn
   * up. Anything left out is an orphan.
   *
   * @return array
   *   Paragraph ids as array keys.
   */
  private function reachableParagraphIds(): array {
    $reachable = [];
    $frontier = [];

    // Nodes are not the only host: block_content and content_version carry
    // paragraph fields too. Seed from all of them except paragraphs, which the
    // walk below handles.
    foreach ($this->hostEntityTypeIds() as $entity_type_id) {
      if ($entity_type_id === 'paragraph') {
        continue;
      }
      foreach ($this->referenceTables($entity_type_id) as $reference) {
        foreach ($this->targetIds($reference) as $id) {
          if (!isset($reachable[$id])) {
            $reachable[$id] = TRUE;
            $frontier[] = $id;
          }
        }
      }
    }

    $nested_references = $this->referenceTables('paragraph');

    while ($frontier) {
      $next = [];
      foreach (array_chunk($frontier, self::CHUNK_SIZE) as $chunk) {
        foreach ($nested_references as $reference) {
          foreach ($this->targetIds($reference, $chunk) as $id) {
            if (!isset($reachable[$id])) {
              $reachable[$id] = TRUE;
              $next[] = $id;
            }
          }
        }
      }
      $frontier = $next;
    }

    return $reachable;
  }

  /**
   * Reads referenced paragraph ids out of one field revision table.
   *
   * @param array $reference
   *   A table/column pair from ::referenceTables().
   * @param array|null $parent_ids
   *   Limit to rows belonging to these parent entities, or NULL for all rows.
   *
   * @return array
   *   Referenced paragraph ids.
   */
  private function targetIds(array $reference, ?array $parent_ids = NULL): array {
    $query = $this->database->select($reference['table'], 'f')
      ->fields('f', [$reference['column']])
      ->distinct();

    if ($parent_ids !== NULL) {
      $query->condition('entity_id', $parent_ids, 'IN');
    }

    return $query->execute()->fetchCol();
  }

  /**
   * Lists every entity type that can reference paragraphs.
   *
   * @return array
   *   Entity type ids.
   */
  private function hostEntityTypeIds(): array {
    $field_map = $this->entityFieldManager->getFieldMapByFieldType('entity_reference_revisions');
    return array_keys($field_map);
  }

  /**
   * Finds the tables holding paragraph references for an entity type.
   *
   * @param string $entity_type_id
   *   The entity type owning the reference fields.
   * @param bool $current_only
   *   TRUE for the data tables, which hold only the current revision.
   *
   * @return array
   *   Each entry has a 'table', a 'column' holding the paragraph id, and a
   *   'revision_column' holding the paragraph revision id.
   */
  private function referenceTables(string $entity_type_id, bool $current_only = FALSE): array {
    $tables = $this->dedicatedTables($entity_type_id, $current_only, function ($definition) {
      return $definition->getType() === 'entity_reference_revisions'
        && $definition->getSetting('target_type') === 'paragraph';
    });

    return array_map(fn ($table) => [
      'table' => $table['table'],
      'column' => $table['field_name'] . '_target_id',
      'revision_column' => $table['field_name'] . '_target_revision_id',
    ], $tables);
  }

  /**
   * Finds the tables holding block field values for an entity type.
   *
   * @param string $entity_type_id
   *   The entity type owning the block fields.
   * @param bool $current_only
   *   TRUE for the data tables, which hold only the current revision.
   *
   * @return array
   *   Each entry has a 'table' and a 'column' holding the block plugin id.
   */
  private function blockFieldTables(string $entity_type_id, bool $current_only = FALSE): array {
    $tables = $this->dedicatedTables($entity_type_id, $current_only, function ($definition) {
      return $definition->getType() === 'block_field';
    });

    return array_map(fn ($table) => [
      'table' => $table['table'],
      'column' => $table['field_name'] . '_plugin_id',
    ], $tables);
  }

  /**
   * Finds the dedicated field tables for the fields that match a condition.
   *
   * @param string $entity_type_id
   *   The entity type owning the fields.
   * @param bool $current_only
   *   TRUE for the data tables, which hold only the current revision.
   * @param callable $matches
   *   Given a field storage definition, returns whether to include it.
   *
   * @return array
   *   Each entry has a 'table' and a 'field_name'.
   */
  private function dedicatedTables(string $entity_type_id, bool $current_only, callable $matches): array {
    if (!$this->entityTypeManager->hasDefinition($entity_type_id)) {
      return [];
    }
    $storage = $this->entityTypeManager->getStorage($entity_type_id);
    if (!$storage instanceof SqlContentEntityStorage) {
      return [];
    }

    $table_mapping = $storage->getTableMapping();
    $schema = $this->database->schema();
    $revisionable = $this->entityTypeManager->getDefinition($entity_type_id)->isRevisionable();
    $tables = [];

    $definitions = $this->entityFieldManager->getFieldStorageDefinitions($entity_type_id);
    foreach ($definitions as $definition) {
      if (!$matches($definition)) {
        continue;
      }
      if (!$table_mapping->requiresDedicatedTableStorage($definition)) {
        continue;
      }

      // Otherwise the revision table where there is one, so a paragraph kept
      // only by an old or forward revision still counts as reachable.
      $table = $revisionable && !$current_only
        ? $table_mapping->getDedicatedRevisionTableName($definition)
        : $table_mapping->getDedicatedDataTableName($definition);
      if (!$schema->tableExists($table)) {
        continue;
      }

      $tables[] = [
        'table' => $table,
        'field_name' => $definition->getName(),
      ];
    }

    return $tables;
  }

}
