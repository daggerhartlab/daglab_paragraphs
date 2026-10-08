<?php

namespace Drupal\Tests\daglab_paragraphs\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\daglab_paragraphs\ParagraphUsageAnalyzer;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\Entity\ParagraphsType;
use Drupal\paragraphs\ParagraphsTypeInterface;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Sets up nodes that hold paragraphs, for the module's kernel tests.
 *
 * Everything is created here from scratch, so the tests do not depend on any
 * site's content types or fields. Nodes of type "page" get a paragraph field
 * named "field_paragraphs".
 */
abstract class ParagraphsKernelTestBase extends KernelTestBase {

  use UserCreationTrait;

  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'node',
    'file',
    'entity_reference_revisions',
    'paragraphs',
    'daglab_paragraphs',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('paragraph');
    $this->installEntitySchema('file');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['node', 'filter', 'paragraphs']);
    $this->container->get('router.builder')->rebuild();

    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    $this->createParagraphField('node', 'page');
  }

  /**
   * Creates a paragraph reference field on a bundle.
   */
  protected function createParagraphField(string $entity_type, string $bundle, string $field_name = 'field_paragraphs'): void {
    if (!FieldStorageConfig::loadByName($entity_type, $field_name)) {
      FieldStorageConfig::create([
        'field_name' => $field_name,
        'entity_type' => $entity_type,
        'type' => 'entity_reference_revisions',
        'cardinality' => FieldStorageConfig::CARDINALITY_UNLIMITED,
        'settings' => ['target_type' => 'paragraph'],
      ])->save();
    }
    FieldConfig::create([
      'field_name' => $field_name,
      'entity_type' => $entity_type,
      'bundle' => $bundle,
      'label' => $field_name,
    ])->save();
  }

  /**
   * Creates a paragraph type, labelled after its machine name.
   */
  protected function createParagraphType(string $id): ParagraphsTypeInterface {
    $type = ParagraphsType::create(['id' => $id, 'label' => ucfirst(str_replace('_', ' ', $id))]);
    $type->save();
    return $type;
  }

  /**
   * Creates a page holding the given paragraphs.
   *
   * @param \Drupal\paragraphs\ParagraphInterface[] $paragraphs
   *   Paragraphs for the page's paragraph field, saved along with it.
   * @param bool $published
   *   Whether the page is published.
   */
  protected function createPage(array $paragraphs = [], bool $published = TRUE): NodeInterface {
    $node = Node::create([
      'type' => 'page',
      'title' => $published ? 'Published page' : 'Unpublished page',
      'status' => $published,
      'field_paragraphs' => $paragraphs,
    ]);
    $node->save();
    return $node;
  }

  /**
   * Saves a new revision of a page with different paragraphs.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The page.
   * @param array $paragraphs
   *   The paragraphs the new revision holds.
   * @param bool $default
   *   FALSE to save a forward (draft) revision, leaving the current one.
   */
  protected function saveNewRevision(NodeInterface $node, array $paragraphs, bool $default = TRUE): void {
    $node->set('field_paragraphs', $paragraphs);
    $node->setNewRevision(TRUE);
    $node->isDefaultRevision($default);
    $node->save();
  }

  /**
   * Returns the analyzer under test.
   */
  protected function analyzer(): ParagraphUsageAnalyzer {
    return $this->container->get(ParagraphUsageAnalyzer::class);
  }

  /**
   * Makes a request to one of the module's routes the current request.
   *
   * Controllers read the query string, messages need a session, and batch
   * processing records the route it started from.
   */
  protected function pushRequest(string $route_name, array $parameters = [], array $query = []): void {
    $request = Request::create('/', 'GET', $query);
    $request->setSession(new Session(new MockArraySessionStorage()));
    $request->attributes->set(RouteObjectInterface::ROUTE_NAME, $route_name);
    $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, $this->container->get('router.route_provider')->getRouteByName($route_name));
    $request->attributes->set('_raw_variables', new InputBag($parameters));
    $this->container->get('request_stack')->push($request);
  }

  /**
   * Confirms one of the module's per-type forms, running its batch.
   */
  protected function submitTypeForm(string $form_class, string $route_name, ParagraphsTypeInterface $type): void {
    $this->pushRequest($route_name, ['paragraphs_type' => $type->id()]);
    $form_state = new FormState();
    $form_state->setValues(['confirm' => 1]);
    $form_state->addBuildInfo('args', [$type]);
    $this->container->get('form_builder')->submitForm($form_class, $form_state);
    $this->assertSame([], $form_state->getErrors());
  }

  /**
   * Casts ids to int, since database drivers may return strings.
   */
  protected function intIds(array $ids_by_key): array {
    return array_map(fn ($ids) => array_map('intval', $ids), $ids_by_key);
  }

}
