<?php

namespace Drupal\daglab_paragraphs\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\daglab_paragraphs\ParagraphUsageAnalyzer;
use Drupal\paragraphs\ParagraphsTypeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Confirms and deletes the orphaned paragraphs of one paragraph type.
 *
 * Orphans are worked out again when the form is submitted, not reused from
 * when it was shown, so a paragraph that became referenced in between is left
 * alone.
 */
class DeleteOrphansForm extends ConfirmFormBase {

  /**
   * Number of paragraphs deleted per batch operation.
   */
  private const BATCH_SIZE = 50;

  /**
   * The paragraph type whose orphans are being deleted.
   */
  private ParagraphsTypeInterface $paragraphsType;

  /**
   * Number of orphans found when the form was built.
   */
  private int $orphanCount = 0;

  public function __construct(
    private readonly ParagraphUsageAnalyzer $usageAnalyzer,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get(ParagraphUsageAnalyzer::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'daglab_paragraphs_delete_orphans';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?ParagraphsTypeInterface $paragraphs_type = NULL) {
    $this->paragraphsType = $paragraphs_type;
    $this->orphanCount = count($this->usageAnalyzer->orphanedParagraphIds($paragraphs_type->id()));

    $form = parent::buildForm($form, $form_state);

    if (!$this->orphanCount) {
      $form['description'] = [
        '#markup' => '<p>' . $this->t('There are no orphaned %type paragraphs to delete.', ['%type' => $paragraphs_type->label()]) . '</p>',
      ];
      unset($form['actions']['submit']);
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->formatPlural(
      $this->orphanCount,
      'Delete 1 orphaned %type paragraph?',
      'Delete @count orphaned %type paragraphs?',
      ['%type' => $this->paragraphsType->label()],
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('These paragraphs are not referenced by any revision of any page or other host, so nothing on the site shows them. Any paragraphs nested inside them are queued for cleanup by Entity Reference Revisions on the next cron run, if nothing else uses them either. This cannot be undone.');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Delete orphans');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return Url::fromRoute('daglab_paragraphs.unused_report');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $form_state->setRedirectUrl($this->getCancelUrl());

    $type = $this->paragraphsType->id();
    $ids = $this->usageAnalyzer->orphanedParagraphIds($type);
    if (!$ids) {
      $this->messenger()->addStatus($this->t('There are no orphaned %type paragraphs to delete.', ['%type' => $this->paragraphsType->label()]));
      return;
    }

    $operations = [];
    foreach (array_chunk($ids, self::BATCH_SIZE) as $chunk) {
      $operations[] = [
        static::class . '::batchDelete',
        [$type, $chunk],
      ];
    }

    batch_set([
      'title' => $this->t('Deleting orphaned %type paragraphs.', ['%type' => $this->paragraphsType->label()]),
      'init_message' => $this->t('Starting.'),
      'progress_message' => $this->t('Processed @current out of @total.'),
      'error_message' => $this->t('Deleting orphaned paragraphs has encountered an error.'),
      'finished' => static::class . '::batchFinish',
      'operations' => $operations,
    ]);
  }

  /**
   * Deletes one chunk of orphaned paragraphs.
   *
   * @param string $type
   *   The paragraph type machine name, for the finished message.
   * @param array $ids
   *   Paragraph ids to delete.
   * @param array|\ArrayAccess $context
   *   The batch context.
   */
  public static function batchDelete(string $type, array $ids, &$context) {
    $storage = \Drupal::entityTypeManager()->getStorage('paragraph');
    $paragraphs = $storage->loadMultiple($ids);
    $storage->delete($paragraphs);

    $context['results']['type'] = $type;
    $context['results']['deleted'] = ($context['results']['deleted'] ?? 0) + count($paragraphs);
  }

  /**
   * Finished callback for the delete batch.
   *
   * @param bool $success
   *   Whether the batch finished without errors.
   * @param array $results
   *   The paragraph type and the number of paragraphs deleted.
   * @param array $operations
   *   The operations that remained unprocessed.
   */
  public static function batchFinish($success, $results, $operations) {
    $args = [
      '@count' => $results['deleted'] ?? 0,
      '%type' => $results['type'] ?? '',
    ];

    \Drupal::logger('daglab_paragraphs')->notice('Deleted @count orphaned %type paragraphs.', $args);

    if ($success) {
      \Drupal::messenger()->addStatus(new TranslatableMarkup('Deleted @count orphaned %type paragraphs.', $args));
    }
    else {
      \Drupal::messenger()->addError(new TranslatableMarkup('An error occurred after deleting @count orphaned %type paragraphs. Run it again to delete the rest.', $args));
    }
  }

}
