<?php

namespace Drupal\daglab_paragraphs\Form;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\PluralTranslatableMarkup;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\daglab_paragraphs\ParagraphUsageAnalyzer;
use Drupal\paragraphs\ParagraphsTypeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Confirms and deletes the older revisions that reference a paragraph type.
 *
 * Only revisions older than a host's current revision are deleted. The
 * current revision and any newer (forward) draft revisions are never touched.
 * Revisions are worked out again when the form is submitted, and each one is
 * checked against the user's usual access to delete it.
 */
class DeleteOlderRevisionsForm extends ConfirmFormBase {

  /**
   * Number of revisions deleted per batch operation.
   */
  private const BATCH_SIZE = 20;

  /**
   * The paragraph type whose referencing revisions are being deleted.
   */
  private ParagraphsTypeInterface $paragraphsType;

  /**
   * Host entries from the analyzer, as of when the form was built.
   */
  private array $hosts = [];

  public function __construct(
    private readonly ParagraphUsageAnalyzer $usageAnalyzer,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get(ParagraphUsageAnalyzer::class),
      $container->get(EntityTypeManagerInterface::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'daglab_paragraphs_delete_older_revisions';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?ParagraphsTypeInterface $paragraphs_type = NULL) {
    $this->paragraphsType = $paragraphs_type;
    $this->hosts = $this->usageAnalyzer->hostReferences($paragraphs_type->id());

    $form = parent::buildForm($form, $form_state);

    $with_old = array_filter($this->hosts, fn ($host) => $host['old_revisions'] > 0);
    if (!$with_old) {
      $form['description'] = [
        '#markup' => '<p>' . $this->t('No older revisions reference the %type paragraph type.', ['%type' => $paragraphs_type->label()]) . '</p>',
      ];
      unset($form['actions']['submit']);
      return $form;
    }

    // What will be deleted, host by host.
    $rows = [];
    foreach ($with_old as $host) {
      $entity = $this->entityTypeManager->getStorage($host['entity_type'])->load($host['id']);
      $rows[] = [
        $entity ? $entity->toLink(NULL, $entity->hasLinkTemplate('canonical') ? 'canonical' : 'edit-form', ['attributes' => ['target' => '_blank']]) : $host['entity_type'] . ':' . $host['id'],
        $this->entityTypeManager->getDefinition($host['entity_type'])->getLabel(),
        $host['old_revisions'],
      ];
    }
    $form['revisions'] = [
      '#theme' => 'table',
      '#header' => ['Older revisions of', 'Kind', 'Revisions to delete'],
      '#rows' => $rows,
      '#weight' => -10,
    ];

    // Warn when deleting these will not free the type.
    $still_referenced = count(array_filter($this->hosts, fn ($host) => $host['current'] || $host['newer_revisions']));
    if ($still_referenced) {
      $form['still_referenced'] = [
        '#markup' => '<p><strong>' . $this->formatPlural(
          $still_referenced,
          '1 other page or block still references this type through its current or a newer draft revision, which this does not touch, so the type will stay in use afterwards.',
          '@count other pages or blocks still reference this type through their current or newer draft revisions, which this does not touch, so the type will stay in use afterwards.',
        ) . '</strong></p>',
        '#weight' => -5,
      ];
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    $total = array_sum(array_column($this->hosts, 'old_revisions'));
    return $this->formatPlural(
      $total,
      'Delete 1 older revision that references %type paragraphs?',
      'Delete @count older revisions that reference %type paragraphs?',
      ['%type' => $this->paragraphsType->label()],
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('Each revision is deleted in full: its history for everything on that page or block is lost, in every language, not just the %type paragraphs. Current revisions and newer draft revisions are never deleted. Revisions you do not have permission to delete are skipped. Afterwards the %type paragraphs show as orphaned, ready for "Delete orphans". This cannot be undone.', [
      '%type' => $this->paragraphsType->label(),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Delete older revisions');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return Url::fromRoute('daglab_paragraphs.references', ['paragraphs_type' => $this->paragraphsType->id()]);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $form_state->setRedirectUrl(Url::fromRoute('daglab_paragraphs.unused_report'));

    $type = $this->paragraphsType->id();
    $operations = [];
    foreach ($this->usageAnalyzer->hostReferences($type) as $host) {
      foreach (array_chunk($host['old_revision_ids'], self::BATCH_SIZE) as $chunk) {
        $operations[] = [
          static::class . '::batchDelete',
          [$type, $host['entity_type'], $chunk],
        ];
      }
    }

    if (!$operations) {
      $this->messenger()->addStatus($this->t('No older revisions reference the %type paragraph type.', ['%type' => $this->paragraphsType->label()]));
      return;
    }

    batch_set([
      'title' => $this->t('Deleting older revisions that reference %type paragraphs.', ['%type' => $this->paragraphsType->label()]),
      'init_message' => $this->t('Starting.'),
      'progress_message' => $this->t('Processed @current out of @total.'),
      'error_message' => $this->t('Deleting older revisions has encountered an error.'),
      'finished' => static::class . '::batchFinish',
      'operations' => $operations,
    ]);
  }

  /**
   * Deletes one chunk of older revisions.
   *
   * @param string $type
   *   The paragraph type machine name, for the finished message.
   * @param string $entity_type_id
   *   The host entity type.
   * @param array $revision_ids
   *   Host revision ids to delete.
   * @param array|\ArrayAccess $context
   *   The batch context.
   */
  public static function batchDelete(string $type, string $entity_type_id, array $revision_ids, &$context) {
    /** @var \Drupal\Core\Entity\RevisionableStorageInterface $storage */
    $storage = \Drupal::entityTypeManager()->getStorage($entity_type_id);
    $context['results']['type'] = $type;
    $context['results'] += ['deleted' => 0, 'skipped' => 0];

    foreach ($revision_ids as $revision_id) {
      $revision = $storage->loadRevision($revision_id);
      if (!$revision) {
        continue;
      }

      // Never the current revision, even if it moved since the form was
      // built, and only what the user could delete through the normal UI.
      if ($revision->isDefaultRevision() || !$revision->access('delete revision')) {
        $context['results']['skipped']++;
        continue;
      }

      $storage->deleteRevision($revision_id);
      $context['results']['deleted']++;
    }
  }

  /**
   * Finished callback for the delete batch.
   *
   * @param bool $success
   *   Whether the batch finished without errors.
   * @param array $results
   *   The paragraph type and how many revisions were deleted or skipped.
   * @param array $operations
   *   The operations that remained unprocessed.
   */
  public static function batchFinish($success, $results, $operations) {
    // Deleting revisions does not invalidate entity list cache tags, so
    // refresh the reports directly.
    Cache::invalidateTags(['daglab_paragraphs:usage']);

    $args = [
      '@deleted' => $results['deleted'] ?? 0,
      '@skipped' => $results['skipped'] ?? 0,
      '%type' => $results['type'] ?? '',
    ];

    \Drupal::logger('daglab_paragraphs')->notice('Deleted @deleted older revisions referencing %type paragraphs, skipped @skipped.', $args);

    $messenger = \Drupal::messenger();
    if (!$success) {
      $messenger->addError(new TranslatableMarkup('An error occurred after deleting @deleted older revisions that referenced %type paragraphs. Run it again to delete the rest.', $args));
      return;
    }

    $messenger->addStatus(new PluralTranslatableMarkup($args['@deleted'], 'Deleted 1 older revision that referenced %type paragraphs.', 'Deleted @count older revisions that referenced %type paragraphs.', $args));
    if ($args['@skipped']) {
      $messenger->addWarning(new PluralTranslatableMarkup($args['@skipped'], 'Skipped 1 revision that you do not have permission to delete, or that has become current.', 'Skipped @count revisions that you do not have permission to delete, or that have become current.', $args));
    }
  }

}
