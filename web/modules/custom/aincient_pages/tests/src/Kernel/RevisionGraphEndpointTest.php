<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_pages\Kernel;

use Drupal\aincient_pages\Controller\RevisionGraphController;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\revision_graph\Controller\RevisionGraphController as ContribController;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * GET /atelier/page/{node}/revisions — the console's read-only revision rail.
 *
 * Pins the plan's contract (plans/revision-graph-console.md "Tests"): renderer
 * shape (`refs`), colours present, `next_offset` paging, the limit clamp, NO
 * `urls` key at any depth (the server-side read-only gate), and the access
 * floor — Content studio permission at the route, `view all revisions` in the
 * controller. Multilingual on purpose: a lane per language is the rail's point.
 *
 * @group aincient
 * @covers \Drupal\aincient_pages\Controller\RevisionGraphController
 */
#[RunTestsInSeparateProcesses]
final class RevisionGraphEndpointTest extends KernelTestBase {

  use UserCreationTrait;

  protected static $modules = [
    'system', 'user', 'field', 'text', 'filter', 'node', 'language', 'content_translation',
    'workflows', 'content_moderation', 'aincient_core', 'aincient_pages',
    'revision_graph',
    // The route's permission (`use aincient studio content`) is derived from
    // the Content studio's manifest, so the studio frame + module must exist.
    'key', 'file', 'image', 'media', 'aincient_chat', 'aincient_studio_content',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('configurable_language');
    $this->installEntitySchema('content_moderation_state');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'language', 'content_translation', 'revision_graph']);

    ConfigurableLanguage::createFromLangcode('de')->save();
    NodeType::create(['type' => 'aincient_page', 'name' => 'AIncient page'])->save();
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    \Drupal::service('content_translation.manager')->setEnabled('node', 'aincient_page', TRUE);

    // uid 1 would bypass every access check; burn it.
    $this->createUser();
    $this->setUpCurrentUser([], [
      'use aincient studio content',
      'access content',
      'view all revisions',
    ]);
  }

  /**
   * An EN page with $enEdits extra revisions, then a DE translation + DE edit.
   */
  private function page(int $enEdits = 2): NodeInterface {
    $node = Node::create(['type' => 'aincient_page', 'title' => 'Our Craft', 'langcode' => 'en']);
    $node->setRevisionLogMessage('Created');
    $node->save();
    for ($i = 1; $i <= $enEdits; $i++) {
      $node->setNewRevision(TRUE);
      $node->setTitle("Our Craft v$i");
      $node->setRevisionLogMessage("EN edit $i");
      $node->save();
    }
    $de = $node->addTranslation('de', ['title' => 'Unser Handwerk']);
    $de->setNewRevision(TRUE);
    $de->setRevisionLogMessage('DE translation');
    $de->save();
    $de->setNewRevision(TRUE);
    $de->setTitle('Unser Handwerk v2');
    $de->setRevisionLogMessage('DE edit');
    $de->save();
    return Node::load($node->id());
  }

  private function call(NodeInterface $node, array $query = []): JsonResponse {
    $controller = RevisionGraphController::create($this->container);
    return $controller->revisions($node, Request::create('/atelier/page/' . $node->id() . '/revisions', 'GET', $query));
  }

  private function payload(JsonResponse $response): array {
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    return json_decode((string) $response->getContent(), TRUE);
  }

  /**
   * Counts `urls` keys anywhere in the payload.
   */
  private function countUrlsKeys(mixed $data): int {
    if (!is_array($data)) {
      return 0;
    }
    $n = array_key_exists('urls', $data) ? 1 : 0;
    foreach ($data as $value) {
      $n += $this->countUrlsKeys($value);
    }
    return $n;
  }

  public function testPayloadIsRendererShapedWithColoursAndNoUrls(): void {
    $node = $this->page();
    $data = $this->payload($this->call($node));

    $this->assertArrayHasKey('commits', $data);
    $this->assertArrayHasKey('node', $data);
    $this->assertArrayHasKey('total', $data);
    $this->assertArrayHasKey('next_offset', $data);
    $this->assertNull($data['next_offset'], 'A 5-revision history fits one page.');
    $this->assertSame(5, $data['total']);

    // Both language lanes are present, each commit carries refs = [branch].
    $branches = [];
    foreach ($data['commits'] as $commit) {
      $this->assertArrayHasKey('refs', $commit);
      $this->assertSame([$commit['branch']], $commit['refs']);
      $branches[$commit['branch']] = TRUE;
      foreach (['key', 'id', 'parents', 'message', 'author', 'timestamp', 'state'] as $field) {
        $this->assertArrayHasKey($field, $commit);
      }
    }
    $this->assertEqualsCanonicalizing(['en', 'de'], array_keys($branches));
    $messages = array_column($data['commits'], 'message');
    $this->assertContains('DE translation', array_map('strip_tags', $messages));

    // Colours: the shipped palette + the language table under branchColors.
    $this->assertNotEmpty($data['palette']);
    $this->assertSame(array_values($data['palette']), $data['palette']);
    $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/i', $data['palette'][0]);
    $this->assertIsArray($data['branchColors']);
    $this->assertArrayHasKey('de', $data['branchColors']);

    // The contrib builder DOES emit urls for this user (sanity: the gate is
    // doing work), and our payload carries none at any depth.
    $raw = $this->container->get('revision_graph.builder')->build($node, 0, 50)->toArray();
    $this->assertGreaterThan(0, $this->countUrlsKeys($raw), 'Contrib emits urls; the strip must be load-bearing.');
    $this->assertSame(0, $this->countUrlsKeys($data));
    $this->assertStringNotContainsString('"urls"', (string) $this->call($node)->getContent());
  }

  public function testConfiguredColoursFlowThrough(): void {
    $this->config('revision_graph.settings')
      ->set('palette', ['#111111', '#222222'])
      ->set('branch_colors', ['de' => '#333333'])
      ->save();
    $data = $this->payload($this->call($this->page(0)));
    $this->assertSame(['#111111', '#222222'], $data['palette']);
    $this->assertSame('#333333', $data['branchColors']['de']);
  }

  public function testPagesByNextOffset(): void {
    $node = $this->page(4);
    $all = $this->payload($this->call($node, ['limit' => 50]));
    $this->assertNull($all['next_offset']);
    $allKeys = array_column($all['commits'], 'key');

    $seen = [];
    $offset = 0;
    $pages = 0;
    do {
      $page = $this->payload($this->call($node, ['offset' => $offset, 'limit' => 2]));
      $this->assertSame(2, $page['limit']);
      $this->assertSame(0, $this->countUrlsKeys($page));
      $seen = array_merge($seen, array_column($page['commits'], 'key'));
      $offset = $page['next_offset'];
      $pages++;
    } while ($offset !== NULL && $pages < 20);

    $this->assertGreaterThan(1, $pages, 'A limit of 2 over 7 revisions must page.');
    $this->assertSame(count($seen), count(array_unique($seen)), 'No commit repeats across pages.');
    $this->assertEqualsCanonicalizing($allKeys, $seen);
  }

  public function testLimitIsClamped(): void {
    $this->assertSame(ContribController::PAGE_LIMIT, RevisionGraphController::PAGE_LIMIT);
    $this->assertSame(50, RevisionGraphController::clampLimit(NULL));
    $this->assertSame(50, RevisionGraphController::clampLimit('0'));
    $this->assertSame(50, RevisionGraphController::clampLimit('-3'));
    $this->assertSame(50, RevisionGraphController::clampLimit('100000'));
    $this->assertSame(7, RevisionGraphController::clampLimit('7'));

    // 56 revisions (1 + 53 EN + 2 DE): an oversized or absent limit loads one
    // page, never the lot.
    $node = $this->page(53);
    foreach ([['limit' => 100000], []] as $query) {
      $data = $this->payload($this->call($node, $query));
      $this->assertSame(50, $data['limit']);
      $this->assertSame(56, $data['total']);
      $this->assertNotNull($data['next_offset'], 'The clamp leaves the tail for the next page.');
    }
  }

  public function testAccessFloor(): void {
    $node = $this->page(0);

    // Route: the Content studio permission gates the endpoint.
    $access = $this->container->get('access_manager');
    $operator = $this->createUser(['use aincient studio content', 'access content']);
    // No studio-content permission → route-level 403, revision access or not.
    $outsider = $this->createUser(['access content', 'view all revisions']);
    $params = ['node' => $node->id()];
    $this->assertTrue($access->checkNamedRoute('aincient_pages.page_revisions', $params, $operator));
    $this->assertFalse($access->checkNamedRoute('aincient_pages.page_revisions', $params, $outsider));
    $route = $this->container->get('router.route_provider')->getRouteByName('aincient_pages.page_revisions');
    $this->assertSame('/atelier/page/{node}/revisions', $route->getPath());
    $this->assertSame(['GET'], $route->getMethods());
    // Stamped from the studio permission: a switched-off Content studio 403s.
    $this->assertSame('content', $route->getRequirement('_aincient_studio_enabled'));

    // Controller: studio permission but no `view all revisions` → 403.
    $this->setCurrentUser($operator);
    $denied = $this->call($node);
    $this->assertSame(403, $denied->getStatusCode());
    $this->assertStringNotContainsString('commits', (string) $denied->getContent());

    // Bundle-specific revision permission is enough.
    $this->setCurrentUser($this->createUser([
      'use aincient studio content',
      'access content',
      'view aincient_page revisions',
    ]));
    $this->assertSame(200, $this->call($node)->getStatusCode());
  }

  public function testNonPageNodeIs404(): void {
    $article = Node::create(['type' => 'article', 'title' => 'Not a page']);
    $article->save();
    $this->assertSame(404, $this->call($article)->getStatusCode());
  }

}
