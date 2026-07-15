<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_chat\Functional;

use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The live-site editor pill on a THEMED page (hook_page_bottom).
 *
 * The pill's themed path is the "Open Console" fallback on everything that
 * renders through Drupal's page pipeline (front page, basic pages, listings) —
 * AIncient pages get a deep-linked pill through a different seam (covered by the
 * EditorPill Kernel test). This guards the surface-switching contract from the
 * visitor's side: an operator gets the pill + its stylesheet; a visitor gets
 * neither the markup NOR the asset (no leak to anonymous).
 *
 * @group aincient
 */
#[RunTestsInSeparateProcesses]
class EditorPillThemedTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['node', 'aincient_chat'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   *
   * aincient_chat ships install config that predates its own schema (a known,
   * pre-existing drift, per the sibling metering test) — relax strict schema so
   * it doesn't mask what this test guards.
   */
  protected $strictConfigSchema = FALSE;

  /**
   * Node URL under test.
   */
  private string $nodeUrl;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->drupalCreateContentType(['type' => 'article', 'name' => 'Article']);
    $node = $this->drupalCreateNode(['type' => 'article', 'title' => 'A themed page']);
    $this->nodeUrl = $node->toUrl()->toString();
    // Visitors must be able to view content to reach a themed node page.
    $this->grantPermissions(
      \Drupal\user\Entity\Role::load(\Drupal\Core\Session\AccountInterface::ANONYMOUS_ROLE),
      ['access content'],
    );
  }

  /**
   * A visitor sees no pill and loads none of its assets.
   */
  public function testAnonymousSeesNoPillNorAsset(): void {
    $this->drupalGet($this->nodeUrl);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->elementNotExists('css', '.aincient-editor-pill');
    $this->assertSession()->responseNotContains('editor-pill.css');
  }

  /**
   * An operator gets the "Open Console" pill (same tab) + its stylesheet.
   */
  public function testOperatorGetsOpenConsolePill(): void {
    $this->drupalLogin($this->drupalCreateUser(['use aincient operator console', 'access content']));
    $this->drupalGet($this->nodeUrl);
    $this->assertSession()->statusCodeEquals(200);

    $pill = $this->assertSession()->elementExists('css', 'a.aincient-editor-pill');
    // Fallback target: the console home. No target=_blank (surface-nav rule 3).
    $this->assertStringContainsString('/atelier', $pill->getAttribute('href'));
    $this->assertNull($pill->getAttribute('target'));
    $this->assertStringContainsString('Open Console', $pill->getText());
    $this->assertSession()->responseContains('editor-pill.css');
  }

}
