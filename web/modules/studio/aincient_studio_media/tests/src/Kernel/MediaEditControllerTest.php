<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_studio_media\Kernel;

use Drupal\aincient_studio_media\Controller\MediaEditController;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The Library studio's seams as a studio MODULE (plans/studio-modules.md Phase E).
 *
 * The editing routes moved here from `aincient_pages` with their paths intact;
 * what changed is who gates them: the studio's own derived permission plus the
 * on/off switch, which the route subscriber stamps on every route whose
 * permission is exactly the studio's. The three image verbs moved with them
 * and are discovered under the module's provider — same slugs, so the FlowDrop
 * node types (`aincient_capability:<slug>`) in the image agent are untouched.
 *
 * @group aincient
 */
#[RunTestsInSeparateProcesses]
final class MediaEditControllerTest extends KernelTestBase {

  protected static $modules = [
    'system',
    'user',
    'field', 'filter', 'text', 'node', 'key', 'file', 'image', 'media',
    'workflows', 'content_moderation', 'aincient_core', 'aincient_pages',
    'aincient_chat', 'aincient_studio_media',
  ];

  /**
   * The five routes are the studio's: gated by its derived permission, and the
   * route subscriber stamps the studio-enabled requirement on them — so a
   * switched-off Library studio 403s them even for a permission holder. The
   * paths are the ones the console's media-state fetches, unchanged by the move.
   */
  public function testRoutesAreGatedByTheMediaStudio(): void {
    $this->container->get('router.builder')->rebuild();
    $provider = $this->container->get('router.route_provider');
    $this->assertInstanceOf(RouteProviderInterface::class, $provider);
    $expected = [
      'aincient_studio_media.media_schema' => '/atelier/media/{media}/schema',
      'aincient_studio_media.media_save' => '/atelier/media/{media}',
      'aincient_studio_media.media_replace' => '/atelier/media/{media}/file',
      'aincient_studio_media.media_replace_from' => '/atelier/media/{media}/replace-from',
      'aincient_studio_media.media_delete' => '/atelier/media/{media}/delete',
    ];
    foreach ($expected as $name => $path) {
      $route = $provider->getRouteByName($name);
      $this->assertSame($path, $route->getPath(), $name);
      $this->assertSame('use aincient studio media', $route->getRequirement('_permission'), $name);
      $this->assertSame('media', $route->getRequirement('_aincient_studio_enabled'), $name);
      $this->assertStringStartsWith('\\' . MediaEditController::class . '::', $route->getDefault('_controller'), $name);
    }
    // And the two core endpoints stayed in core — a page keeps uploading and
    // resolving images with this studio off (they carry the Content studio's
    // permission, so the subscriber stamps THAT studio, never this one).
    foreach (['aincient_pages.media_upload', 'aincient_pages.media_url'] as $name) {
      $route = $provider->getRouteByName($name);
      $this->assertSame('use aincient studio content', $route->getRequirement('_permission'), $name);
      $this->assertNotSame('media', $route->getRequirement('_aincient_studio_enabled'), $name);
    }
  }

  /**
   * The image verbs are the studio's capabilities now: same slugs, new provider.
   */
  public function testImageCapabilitiesAreDiscoveredUnderTheStudioProvider(): void {
    $definitions = $this->container->get('plugin.manager.aincient.capabilities')->getDefinitions();
    foreach (['generate_image', 'generate_alt_text', 'propose_media_name'] as $slug) {
      $this->assertArrayHasKey("aincient_studio_media:$slug", $definitions, $slug);
      $this->assertSame('aincient_studio_media', $definitions["aincient_studio_media:$slug"]['provider'], $slug);
      $this->assertArrayNotHasKey("aincient_pages:$slug", $definitions, "$slug must not be defined twice.");
    }
    // The manifest claims exactly those three, prefixed once with the provider.
    $studio = $this->container->get('plugin.manager.aincient.studios')->get('media');
    $this->assertNotNull($studio);
    $this->assertSame(
      ['aincient_studio_media:generate_image', 'aincient_studio_media:generate_alt_text', 'aincient_studio_media:propose_media_name'],
      $studio->capabilities(),
    );
    $this->assertSame(['aincient_image_agent'], $studio->flows());
  }

  /**
   * The controller builds from the container with core's media repository.
   */
  public function testControllerIsBuiltFromCoreServices(): void {
    $this->assertInstanceOf(MediaEditController::class, MediaEditController::create($this->container));
  }

}
