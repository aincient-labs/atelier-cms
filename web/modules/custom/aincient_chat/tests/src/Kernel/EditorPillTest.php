<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_chat\Kernel;

use Drupal\Core\Session\AnonymousUserSession;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The live-site editor pill's deep link (hook_aincient_pages_shell_bottom).
 *
 * The pill on an AIncient page's canonical view is contributed to the bespoke
 * chrome-less shell (aincient_pages) — hook_page_bottom never fires there. This
 * guards the surface-switching win: an operator gets a deep link straight to the
 * page in the Content studio (translation-aware, matching the console URL codec);
 * a visitor gets nothing.
 *
 * @group aincient
 * @covers ::aincient_chat_aincient_pages_shell_bottom
 */
#[RunTestsInSeparateProcesses]
final class EditorPillTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'text',
    'node',
    'language',
    'content_translation',
    'key',
    'ai',
    'aincient_core',
    'workflows',
    'content_moderation',
    'aincient_pages',
    'aincient_chat',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('configurable_language');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['language', 'content_translation']);

    ConfigurableLanguage::createFromLangcode('de')->save();

    // Burn uid 1: it is the all-permissions superuser, which would defeat the
    // permission-gate assertions below (a non-operator would still "pass" any
    // hasPermission check). Every user the tests create is uid ≥ 2.
    $this->createUser();

    NodeType::create(['type' => 'aincient_page', 'name' => 'AIncient page'])->save();
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    \Drupal::service('content_translation.manager')->setEnabled('node', 'aincient_page', TRUE);
  }

  /**
   * Render the hook's contribution to HTML for the current user.
   */
  private function pillHtml(Node $node): string {
    $build = aincient_chat_aincient_pages_shell_bottom($node);
    if ($build === []) {
      return '';
    }
    return (string) \Drupal::service('renderer')->renderInIsolation($build);
  }

  /**
   * An operator on an AIncient page gets the Content-studio deep link + its CSS.
   */
  public function testOperatorGetsDeepLinkPill(): void {
    $this->setCurrentUser($this->createUser(['use aincient operator console']));
    $node = Node::create(['type' => 'aincient_page', 'title' => 'Home']);
    $node->save();

    $html = $this->pillHtml($node);
    $this->assertStringContainsString('class="aincient-editor-pill"', $html);
    $this->assertStringContainsString('href="/atelier/content/node/' . $node->id() . '"', $html);
    $this->assertStringContainsString('Edit in Console', $html);
    // The bespoke shell can't #attach a library, so the pill links its own CSS.
    $this->assertStringContainsString('editor-pill.css', $html);
  }

  /**
   * A non-source translation appends its langcode (source omits it).
   */
  public function testTranslationDeepLinkCarriesLangcode(): void {
    $this->setCurrentUser($this->createUser(['use aincient operator console']));
    $node = Node::create(['type' => 'aincient_page', 'title' => 'Home', 'langcode' => 'en']);
    $node->save();
    $node->addTranslation('de', ['title' => 'Startseite'])->save();

    $source = Node::load($node->id());
    $this->assertStringContainsString('href="/atelier/content/node/' . $node->id() . '"', $this->pillHtml($source));

    $de = Node::load($node->id())->getTranslation('de');
    $this->assertStringContainsString('href="/atelier/content/node/' . $node->id() . '/de"', $this->pillHtml($de));
  }

  /**
   * A visitor (no console permission) gets no pill — and no assets.
   */
  public function testAnonymousGetsNoPill(): void {
    $this->setCurrentUser(new AnonymousUserSession());
    $node = Node::create(['type' => 'aincient_page', 'title' => 'Home']);
    $node->save();
    $this->assertSame([], aincient_chat_aincient_pages_shell_bottom($node));
  }

  /**
   * A logged-in non-operator likewise gets nothing.
   */
  public function testNonOperatorGetsNoPill(): void {
    $this->setCurrentUser($this->createUser([]));
    $node = Node::create(['type' => 'aincient_page', 'title' => 'Home']);
    $node->save();
    $this->assertSame([], aincient_chat_aincient_pages_shell_bottom($node));
  }

}
