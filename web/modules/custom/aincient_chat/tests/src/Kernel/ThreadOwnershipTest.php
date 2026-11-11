<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_chat\Kernel;

use Drupal\aincient_chat\Chat\FlowDropDispatcher;
use Drupal\aincient_chat\Chat\SessionThreadStore;
use Drupal\aincient_chat\Event\ChatEventType;
use Drupal\aincient_core\Routing\CsrfHeaderRouteSubscriber;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Console threads and their interrupts answer only to their owner (0511).
 *
 * Also pins which routes the CSRF header requirement covers, since the route
 * subscriber decides it from the route's shape rather than a per-route flag.
 */
#[RunTestsInSeparateProcesses]
final class ThreadOwnershipTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'text', 'options', 'filter', 'key',
    'flowdrop_ui_components', 'flowdrop', 'flowdrop_node_category',
    'flowdrop_node_type', 'flowdrop_node_processor', 'flowdrop_workflow',
    'flowdrop_orchestration', 'flowdrop_runtime', 'flowdrop_pipeline',
    'flowdrop_job', 'flowdrop_session', 'flowdrop_interrupt', 'flowdrop_memory',
    'aincient_core', 'aincient_chat',
  ];

  private const OWNER = 2;

  private const OTHER = 3;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('flowdrop_workflow');
    $this->installEntitySchema('flowdrop_node_type');
    $this->installEntitySchema('flowdrop_pipeline');
    $this->installEntitySchema('flowdrop_job');
    $this->installEntitySchema('flowdrop_session');
    $this->installEntitySchema('flowdrop_session_message');
    $this->installEntitySchema('flowdrop_interrupt');
    $this->installConfig(['system', 'flowdrop_node_processor', 'flowdrop_job', 'flowdrop_pipeline']);
    // uid 1 is the superuser; the two people under test are ordinary accounts.
    $this->createUser([], NULL, FALSE, ['uid' => 1]);
    $this->createUser([], NULL, FALSE, ['uid' => self::OWNER]);
    $this->createUser([], NULL, FALSE, ['uid' => self::OTHER]);
  }

  private function store(): SessionThreadStore {
    return $this->container->get('aincient_chat.thread_store');
  }

  /**
   * The owner's console session for a thread.
   */
  private function session(string $threadId, int $uid): int {
    $session = $this->container->get('entity_type.manager')->getStorage('flowdrop_session')->create([
      'name' => 'console:' . $threadId,
      'uid' => $uid,
    ]);
    $session->save();
    return (int) $session->id();
  }

  /**
   * A pending interrupt raised in a session; returns its uuid.
   */
  private function interrupt(int $sessionId): string {
    $interrupt = $this->container->get('entity_type.manager')->getStorage('flowdrop_interrupt')->create([
      'pipeline_id' => 'pipe-1',
      'session_id' => $sessionId,
      'node_id' => 'gate',
      'type' => 'confirmation',
      'status' => 'pending',
      'message' => 'Publish the page?',
      'uid' => self::OWNER,
    ]);
    $interrupt->save();
    return $interrupt->uuid();
  }

  /**
   * Resumes as the given user and returns the [type, payload] frames.
   *
   * @return list<array{0: \Drupal\aincient_chat\Event\ChatEventType, 1: array}>
   */
  private function resumeAs(int $uid, string $uuid, string $threadId): array {
    $this->setCurrentUser($this->container->get('entity_type.manager')->getStorage('user')->load($uid));
    $dispatcher = $this->container->get('aincient_chat.flow_dispatcher');
    $this->assertInstanceOf(FlowDropDispatcher::class, $dispatcher);
    $frames = [];
    foreach ($dispatcher->resume($uuid, 'yes', $threadId) as $event) {
      $frames[] = [$event->type, $event->data];
    }
    return $frames;
  }

  private function interruptStatus(string $uuid): string {
    $interrupt = $this->container->get('entity.repository')->loadEntityByUuid('flowdrop_interrupt', $uuid);
    return (string) $interrupt->get('status')->value;
  }

  /**
   * A thread id taken by another person is reported as such; your own is not.
   */
  public function testBelongsToSomeoneElse(): void {
    $this->session('thr_a', self::OWNER);

    $this->assertTrue($this->store()->belongsToSomeoneElse('thr_a', self::OTHER));
    $this->assertFalse($this->store()->belongsToSomeoneElse('thr_a', self::OWNER));
    $this->assertFalse($this->store()->belongsToSomeoneElse('thr_new', self::OTHER));
    $this->assertFalse($this->store()->belongsToSomeoneElse('', self::OTHER));
  }

  /**
   * Someone else's interrupt is refused, and stays pending for its owner.
   */
  public function testOtherUserCannotAnswerAnInterrupt(): void {
    $uuid = $this->interrupt($this->session('thr_a', self::OWNER));

    // Even naming the owner's real thread id does not help.
    $frames = $this->resumeAs(self::OTHER, $uuid, 'thr_a');

    $this->assertSame(ChatEventType::ERROR, $frames[0][0] ?? NULL);
    $this->assertStringContainsString('expired or was already answered', (string) ($frames[0][1]['message'] ?? ''));
    $this->assertCount(1, $frames, 'Nothing after the refusal: no "Recording your choice" status.');
    $this->assertSame('pending', $this->interruptStatus($uuid));
  }

  /**
   * The owner naming the wrong thread is refused too: the body's thread_id
   * must be the one the interrupt was raised in.
   */
  public function testThreadIdMustMatchTheInterrupt(): void {
    $uuid = $this->interrupt($this->session('thr_a', self::OWNER));
    $this->session('thr_b', self::OWNER);

    $frames = $this->resumeAs(self::OWNER, $uuid, 'thr_b');

    $this->assertSame(ChatEventType::ERROR, $frames[0][0] ?? NULL);
    $this->assertSame('pending', $this->interruptStatus($uuid));
  }

  /**
   * Which routes need X-CSRF-Token: Atelier writes yes, reads and forms no.
   */
  public function testCsrfHeaderCoversAtelierWriteRoutes(): void {
    $routes = $this->container->get('router.route_provider');
    $requires = static fn(string $name): bool => $routes->getRouteByName($name)->hasRequirement(CsrfHeaderRouteSubscriber::REQUIREMENT);

    $this->assertTrue($requires('aincient_chat.chat'));
    $this->assertTrue($requires('aincient_chat.interrupt_resolve'));
    $this->assertTrue($requires('aincient_chat.account_save'));
    $this->assertFalse($requires('aincient_chat.threads'), 'A GET-only route is untouched.');
    $this->assertFalse($requires('system.csrftoken'), 'Core routes are untouched.');

    // Nothing of ours that accepts an unsafe method is left out, except forms.
    foreach ($routes->getAllRoutes() as $name => $route) {
      if (!str_starts_with($name, 'aincient') || array_intersect(['POST', 'PUT', 'PATCH', 'DELETE'], $route->getMethods()) === []) {
        continue;
      }
      if ($route->hasDefault('_form') || $route->hasDefault('_entity_form') || $route->hasRequirement('_csrf_token')) {
        continue;
      }
      $this->assertTrue($route->hasRequirement(CsrfHeaderRouteSubscriber::REQUIREMENT), "$name requires X-CSRF-Token");
    }
  }

}
