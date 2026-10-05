<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_studio_components\Kernel;

use Drupal\aincient_studio_components\Controller\KindController;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * The per-kind scope (DECISIONS 0455, P1b): the "Applies to" scopes come from
 * the kind registry (block included, as a fragment), a kind save narrows its
 * palette (allow/deny, include_new, opener, limits), a recipe kind refuses,
 * and the dry run reports pages an UNSAVED change would affect.
 *
 * @group aincient
 */
#[RunTestsInSeparateProcesses]
final class KindControllerTest extends KernelTestBase {

  protected static $modules = [
    'system', 'user', 'field', 'filter', 'text', 'node', 'key',
    'workflows', 'content_moderation', 'aincient_core', 'aincient_pages',
    'aincient_chat', 'aincient_studio_components',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installConfig(['workflows', 'content_moderation', 'aincient_pages']);
    if (!NodeType::load('aincient_page')) {
      NodeType::create(['type' => 'aincient_page', 'name' => 'Page'])->save();
    }
    if (!FieldStorageConfig::loadByName('node', 'field_page_structure')) {
      FieldStorageConfig::create(['field_name' => 'field_page_structure', 'entity_type' => 'node', 'type' => 'string_long'])->save();
    }
    if (!FieldConfig::loadByName('node', 'aincient_page', 'field_page_structure')) {
      FieldConfig::create(['field_name' => 'field_page_structure', 'entity_type' => 'node', 'bundle' => 'aincient_page', 'label' => 'S'])->save();
    }
  }

  private function controller(): KindController {
    return KindController::create($this->container);
  }

  private function post(string $method, array $body, ?string $kind = NULL): array {
    $request = new Request(content: json_encode($body, JSON_THROW_ON_ERROR));
    $response = $kind === NULL ? $this->controller()->$method($request) : $this->controller()->$method($kind, $request);
    return json_decode((string) $response->getContent(), TRUE) + ['status' => $response->getStatusCode()];
  }

  public function testScopesComeFromTheRegistryIncludingTheBlockKind(): void {
    $scopes = array_column($this->controller()->scopes(), NULL, 'id');
    $this->assertArrayHasKey('landing', $scopes);
    $this->assertArrayHasKey('blog', $scopes);
    $this->assertTrue($scopes['block']['fragment']);
    // Never a page type.
    $this->assertArrayNotHasKey('block', $this->container->get('aincient_pages.catalog')->kinds());
  }

  public function testKindSaveNarrowsThePalette(): void {
    $result = $this->post('save', [
      'include_new' => TRUE,
      'removed' => ['pricing'],
      'components' => ['hero' => ['variants' => ['split']]],
      'opener' => 'hero',
      'limits' => ['cta' => 1],
    ], 'landing');
    $this->assertSame(200, $result['status']);
    $this->assertNotContains('pricing', $result['effective']['placeable']);
    $this->assertContains('newsletter', $result['effective']['placeable'], 'Automatic: unnamed components stay offered.');
    $this->assertSame(['split'], $result['effective']['variants']['hero']);
    $this->assertSame('hero', $result['effective']['opener']);
    $this->assertSame(['cta' => 1], $result['effective']['limits']);

    $hold = $this->post('save', ['include_new' => FALSE, 'components' => ['hero' => [], 'cta' => []]], 'landing');
    $this->assertEqualsCanonicalizing(['hero', 'cta'], $hold['effective']['placeable']);
  }

  public function testRecipeKindAndGuardsRefuse(): void {
    $this->assertSame(422, $this->post('save', ['removed' => ['hero']], 'blog')['status']);
    $this->assertSame(422, $this->post('save', ['components' => ['hero' => ['variants' => ['nope']]]], 'landing')['status']);
    $this->assertSame(404, $this->post('save', [], 'nope')['status']);
  }

  public function testDryRunReportsAffectedPagesWithoutSaving(): void {
    Node::create([
      'type' => 'aincient_page',
      'title' => 'Home',
      'field_page_structure' => json_encode(['type' => 'landing', 'slots' => [
        ['id' => 's1', 'component' => 'cta'],
        ['id' => 's2', 'component' => 'newsletter'],
      ]]),
    ])->save();

    $site = $this->post('check', ['scope' => 'site', 'draft' => ['components' => ['newsletter']]]);
    $this->assertSame(1, $site['impacts']);
    $this->assertSame('newsletter', $site['rows'][0]['component']);

    $kind = $this->post('check', ['scope' => 'landing', 'draft' => ['opener' => 'hero', 'limits' => ['cta' => 1]]]);
    $this->assertSame(['opener missing'], array_column($kind['rows'], 'impact'));

    // Nothing was saved.
    $this->assertSame([], $this->config('aincient_pages.site_constraint')->get('components') ?? []);
    $this->assertNull($this->container->get('aincient_pages.catalog')->for('landing')->opener());
  }

}
