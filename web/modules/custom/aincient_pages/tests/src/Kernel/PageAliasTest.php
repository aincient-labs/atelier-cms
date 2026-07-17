<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_pages\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\NodeType;
use Drupal\pathauto\Entity\PathautoPattern;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the flat page-alias namespace and its reserved-word guard.
 *
 * The alias pattern is a single root segment ('/[node:title]', no '/pages/'
 * prefix), so a page alias sits one word away from shadowing an app namespace.
 * Pathauto reserves live routes on its own; aincient_pages_pathauto_is_alias_
 * reserved() adds the un-routed machine namespaces ('aincient', 'atelier').
 *
 * @group aincient
 */
#[RunTestsInSeparateProcesses]
final class PageAliasTest extends KernelTestBase {

  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'token',
    'path',
    'path_alias',
    'node',
    'workflows',
    'content_moderation',
    'pathauto',
    'aincient_pages',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('path_alias');
    $this->installConfig(['system', 'pathauto']);

    NodeType::create(['type' => 'aincient_page', 'name' => 'AIncient page'])->save();

    // The flat alias pattern the distribution ships (config/sync +
    // config/optional): a single root segment, no '/pages/' prefix.
    PathautoPattern::create([
      'id' => 'aincient_page',
      'label' => 'AIncient page',
      'type' => 'canonical_entities:node',
      'pattern' => '/[node:title]',
      'selection_criteria' => [],
    ])->save();
  }

  /**
   * The alias for an ordinary title is flat — the '/pages/' prefix is gone.
   */
  public function testFlatAliasHasNoPrefix(): void {
    $alias = $this->aliasFor('About Us');
    $this->assertSame('/about-us', $alias);
  }

  /**
   * Titles landing on a reserved machine namespace are suffixed, not left to
   * shadow it. 'aincient' is not a live route, so pathauto only skips it because
   * of aincient_pages_pathauto_is_alias_reserved().
   *
   * @dataProvider reservedTitles
   */
  public function testReservedNamespaceIsSuffixed(string $title, string $segment): void {
    $alias = $this->aliasFor($title);
    $this->assertNotSame('/' . $segment, $alias);
    $this->assertStringStartsWith('/' . $segment . '-', $alias);
  }

  public static function reservedTitles(): array {
    return [
      'machine namespace' => ['Aincient', 'aincient'],
      'console base' => ['Atelier', 'atelier'],
    ];
  }

  /**
   * A hand-typed alias on a reserved namespace is rejected on validation — the
   * vector pathauto's hook can't see (manual form/REST entry).
   */
  public function testManualReservedAliasIsRejected(): void {
    $node = \Drupal::entityTypeManager()->getStorage('node')->create([
      'type' => 'aincient_page',
      'title' => 'Legit Title',
      'status' => 1,
      'path' => ['alias' => '/aincient/secret'],
    ]);
    $violations = $node->validate();
    $messages = array_map(static fn ($v) => (string) $v->getMessage(), iterator_to_array($violations));
    $reserved = array_filter($messages, static fn ($m) => str_contains($m, 'reserved'));
    $this->assertNotEmpty($reserved, 'A /aincient/… alias must raise a reserved-namespace violation.');
  }

  /**
   * A pathauto-suffixed alias ('/aincient-0') is NOT the reserved word itself,
   * so the constraint must let it through — it never rejects pathauto output.
   */
  public function testSuffixedAliasPassesValidation(): void {
    $node = \Drupal::entityTypeManager()->getStorage('node')->create([
      'type' => 'aincient_page',
      'title' => 'Legit Title',
      'status' => 1,
      'path' => ['alias' => '/aincient-0'],
    ]);
    $messages = array_map(
      static fn ($v) => (string) $v->getMessage(),
      iterator_to_array($node->validate())
    );
    $this->assertEmpty(array_filter($messages, static fn ($m) => str_contains($m, 'reserved')));
  }

  /**
   * Generate the alias a node with $title would receive (no save required).
   */
  private function aliasFor(string $title): string {
    $node = \Drupal::entityTypeManager()->getStorage('node')->create([
      'type' => 'aincient_page',
      'title' => $title,
      'status' => 1,
    ]);
    $node->save();
    return \Drupal::service('pathauto.generator')->createEntityAlias($node, 'return');
  }

}
