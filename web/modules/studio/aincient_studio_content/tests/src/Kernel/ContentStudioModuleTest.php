<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_studio_content\Kernel;

use Drupal\aincient_studio_content\Controller\BlockController;
use Drupal\aincient_studio_content\Controller\PageController;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The Content studio's seams as a studio MODULE (plans/studio-modules.md Phase E.4).
 *
 * One frozen id (`content`) declared by a manifest instead of a `#[Studio]`
 * class in `aincient_chat`; the page + block editing routes stamped with the
 * on/off switch under their original paths; the shared routes (editor lock,
 * reference picker, media upload) left in core and ungated; and the pages
 * agent's composing verb claimed as this studio's while the shared verbs
 * (`list_pages`, `find_reference`) stay core's.
 *
 * @group aincient
 */
#[RunTestsInSeparateProcesses]
final class ContentStudioModuleTest extends KernelTestBase {

  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'text',
    'node',
    'key',
    'file',
    'image',
    'media',
    'workflows',
    'content_moderation',
    'aincient_core',
    'aincient_pages',
    'aincient_chat',
    'aincient_studio_content',
  ];

  /**
   * The studio is discovered from the manifest under the module's provider,
   * with its frozen id, weight, admin label, one agent and one verb.
   */
  public function testStudioComesFromTheManifest(): void {
    $manager = $this->container->get('plugin.manager.aincient.studios');

    $content = $manager->get('content');
    $this->assertNotNull($content);
    $this->assertSame('aincient_studio_content', $content->getPluginDefinition()['provider']);
    $this->assertSame('Content', $content->label());
    $this->assertSame(50, $content->weight());
    $this->assertSame('ui/index.tsx', $content->uiEntry());
    $this->assertSame('use aincient studio content', $content->permission());
    $this->assertSame(['aincient_studio_content:preview_page'], $content->capabilities());
    $this->assertSame(['aincient_pages_agent'], $content->flows());
  }

  /**
   * Every page + block editing route is this studio's: gated by its derived
   * permission, stamped by the route subscriber (so a switched-off Content
   * 403s the whole editing surface), under the paths the console's page-state
   * store fetches, on this module's controllers.
   */
  public function testEditingRoutesAreGatedByTheContentStudio(): void {
    $this->container->get('router.builder')->rebuild();
    $provider = $this->container->get('router.route_provider');
    $this->assertInstanceOf(RouteProviderInterface::class, $provider);

    $pages = [
      'apply' => ['/atelier/page/apply', 'POST', 'apply'],
      'preview' => ['/atelier/page/preview', 'POST', 'preview'],
      'save' => ['/atelier/page/save', 'POST', 'save'],
      'publish' => ['/atelier/page/publish', 'POST', 'publish'],
      'submit_review' => ['/atelier/page/submit-review', 'POST', 'submitReview'],
      'approve' => ['/atelier/page/approve', 'POST', 'approve'],
      'reject' => ['/atelier/page/reject', 'POST', 'reject'],
      'archive' => ['/atelier/page/archive', 'POST', 'archive'],
      'restore' => ['/atelier/page/restore', 'POST', 'restore'],
      'transition' => ['/atelier/page/transition', 'POST', 'runTransition'],
      'manifest' => ['/atelier/page/manifest', 'GET', 'manifest'],
      'list' => ['/atelier/page/list', 'GET', 'list'],
      'schema' => ['/atelier/page/{node}/schema', 'GET', 'pageSchema'],
      'converge' => ['/atelier/page/{node}/converge', 'POST', 'converge'],
    ];
    foreach ($pages as $name => [$path, $method, $action]) {
      $route = $provider->getRouteByName("aincient_studio_content.page_$name");
      $this->assertSame($path, $route->getPath(), $name);
      $this->assertSame([$method], $route->getMethods(), $name);
      $this->assertSame('use aincient studio content', $route->getRequirement('_permission'), $name);
      $this->assertSame('content', $route->getRequirement('_aincient_studio_enabled'), $name);
      $this->assertSame('\\' . PageController::class . "::$action", $route->getDefault('_controller'), $name);
    }

    // Diverge carries its own governance permission on top, so the subscriber's
    // exact match cannot stamp it — the routing file stamps it by hand.
    $diverge = $provider->getRouteByName('aincient_studio_content.page_diverge');
    $this->assertSame('/atelier/page/{node}/diverge', $diverge->getPath());
    $this->assertSame('diverge aincient page layout', $diverge->getRequirement('_permission'));
    $this->assertSame('content', $diverge->getRequirement('_aincient_studio_enabled'));

    $blocks = [
      'save' => ['/atelier/block/save', 'POST', 'save'],
      'publish' => ['/atelier/block/publish', 'POST', 'publish'],
      'submit_review' => ['/atelier/block/submit-review', 'POST', 'submitReview'],
      'approve' => ['/atelier/block/approve', 'POST', 'approve'],
      'reject' => ['/atelier/block/reject', 'POST', 'reject'],
      'archive' => ['/atelier/block/archive', 'POST', 'archive'],
      'restore' => ['/atelier/block/restore', 'POST', 'restore'],
      'transition' => ['/atelier/block/transition', 'POST', 'runTransition'],
      'schema' => ['/atelier/block/{media}/schema', 'GET', 'blockSchema'],
    ];
    foreach ($blocks as $name => [$path, $method, $action]) {
      $route = $provider->getRouteByName("aincient_studio_content.block_$name");
      $this->assertSame($path, $route->getPath(), $name);
      $this->assertSame([$method], $route->getMethods(), $name);
      $this->assertSame('use aincient studio content', $route->getRequirement('_permission'), $name);
      $this->assertSame('content', $route->getRequirement('_aincient_studio_enabled'), $name);
      $this->assertSame('\\' . BlockController::class . "::$action", $route->getDefault('_controller'), $name);
    }

    // The old names are gone — nothing resolves a route by them (the console
    // fetches by path), so a stale name would be a silent duplicate.
    foreach (['aincient_pages.page_save', 'aincient_pages.block_save', 'aincient_pages.page_manifest'] as $old) {
      $this->assertCount(0, $provider->getRoutesByNames([$old]), "$old is no longer a core route.");
    }
  }

  /**
   * What stays core: the editor lock is SHARED with Checks (`+` permission, so
   * the subscriber stamps no studio and switching Content off leaves the
   * Checks handover whole); the reference picker and the media endpoints
   * carry Content's permission but are core's — every rail's <ReferenceField>
   * and the Presence cards reach them — so they are stamped `content` by the
   * subscriber yet live in `aincient_pages`.
   */
  public function testSharedRoutesStayCore(): void {
    $this->container->get('router.builder')->rebuild();
    $provider = $this->container->get('router.route_provider');

    foreach (['acquire', 'release', 'status'] as $action) {
      $route = $provider->getRouteByName("aincient_pages.page_lock_$action");
      $this->assertSame("/atelier/page/lock/$action", $route->getPath(), $action);
      $this->assertSame('use aincient studio content+use aincient studio checks', $route->getRequirement('_permission'), $action);
      $this->assertNull($route->getRequirement('_aincient_studio_enabled'), "The lock is shared, $action is nobody's.");
    }

    foreach (['aincient_pages.reference_search', 'aincient_pages.reference_resolve', 'aincient_pages.media_upload', 'aincient_pages.media_url'] as $name) {
      $route = $provider->getRouteByName($name);
      $this->assertStringStartsWith('\\Drupal\\aincient_pages\\Controller\\', (string) $route->getDefault('_controller'), $name);
      $this->assertSame('content', $route->getRequirement('_aincient_studio_enabled'), "$name is stamped content, owned by core.");
    }
  }

  /**
   * The pages agent's composing verb is discovered under this module, and
   * nowhere else; the two shared verbs it also calls stay core's.
   */
  public function testPreviewPageIsTheStudiosAndTheSharedVerbsAreNot(): void {
    $definitions = $this->container->get('plugin.manager.aincient.capabilities')->getDefinitions();
    $this->assertArrayHasKey('aincient_studio_content:preview_page', $definitions);
    $this->assertSame('aincient_studio_content', $definitions['aincient_studio_content:preview_page']['provider']);
    $this->assertArrayNotHasKey('aincient_pages:preview_page', $definitions);

    $this->assertSame('aincient_pages', $definitions['aincient_pages:list_pages']['provider']);
    $this->assertSame('aincient_pages', $definitions['aincient_pages:find_reference']['provider']);

    // Switching Content off refuses its verb and nothing else.
    $this->config('aincient_chat.settings')->set('disabled_studios', ['content'])->save();
    $gates = $this->container->get('aincient_core.capability_gates');
    $this->assertNotNull($gates->refusal('aincient_studio_content:preview_page'));
    $this->assertNull($gates->refusal('aincient_pages:list_pages'));
    $this->assertNull($gates->refusal('aincient_pages:find_reference'));
  }

}
