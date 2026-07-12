<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_audit\Unit;

use Drupal\aincient_audit\Check\CheckInterface;
use Drupal\aincient_audit\Check\SeoMetaCheck;
use Drupal\aincient_audit\MetaTagReader;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;

/**
 * The SEO check's finding logic — the `seo` default policy (DECISIONS 0129).
 *
 * Locks the exact branching that MUST stay byte-identical across the
 * AuditEngine → checks extraction: title/description length thresholds,
 * missing detection, canonical + Open Graph presence, and the override-vs-
 * default provenance suffix. Deterministic — the reader is doubled, so no
 * metatag/render stack is involved.
 *
 * @group aincient
 * @coversDefaultClass \Drupal\aincient_audit\Check\SeoMetaCheck
 */
final class SeoMetaCheckTest extends UnitTestCase {

  /**
   * Build the check over a reader that returns fixed tags + overrides.
   *
   * @param array<string,string> $tags
   * @param array<string,true> $overrides
   */
  private function check(array $tags, array $overrides = []): SeoMetaCheck {
    $reader = $this->createMock(MetaTagReader::class);
    $reader->method('tags')->willReturn($tags);
    $reader->method('overrides')->willReturn($overrides);
    return new SeoMetaCheck($reader);
  }

  /**
   * Index a finding list by id → the finding (ids are unique within SEO).
   *
   * @return array<string, array{id:string,severity:string,title:string,detail:string,location:string}>
   */
  private function byId(array $findings): array {
    $out = [];
    foreach ($findings as $f) {
      $out[$f['id']] = $f;
    }
    return $out;
  }

  public function testIdentity(): void {
    $check = $this->check([]);
    $this->assertSame('seo', $check->id());
    $this->assertSame('SEO & meta tags', $check->label());
  }

  /**
   * A fully-populated, in-range page → every finding PASSes.
   *
   * @covers ::evaluate
   */
  public function testAllPass(): void {
    $f = $this->byId($this->check([
      'title' => 'A perfectly reasonable page title of the right length',
      'description' => str_repeat('x', 100),
      'canonical' => 'https://example.com/page',
      'og:title' => 'OG title',
      'og:description' => 'OG description',
      'og:image' => 'https://example.com/og.png',
    ])->evaluate($this->node()));

    foreach (['seo.title', 'seo.description', 'seo.canonical', 'seo.og_title', 'seo.og_description', 'seo.og_image'] as $id) {
      $this->assertSame(CheckInterface::PASS, $f[$id]['severity'], "$id should pass");
    }
  }

  /**
   * Title thresholds: missing → FAIL, <30 → WARN, >60 → WARN, in-range → PASS.
   *
   * @covers ::evaluate
   * @dataProvider titleCases
   */
  public function testTitleThresholds(string $title, string $severity, string $titleText): void {
    $f = $this->byId($this->check(['title' => $title])->evaluate($this->node()));
    $this->assertSame($severity, $f['seo.title']['severity']);
    $this->assertSame($titleText, $f['seo.title']['title']);
  }

  public static function titleCases(): array {
    return [
      'missing' => ['', CheckInterface::FAIL, 'Page title is missing'],
      'short' => ['Too short', CheckInterface::WARN, 'Page title is short'],
      'long' => [str_repeat('a', 61), CheckInterface::WARN, 'Page title is long'],
      'ok' => [str_repeat('a', 45), CheckInterface::PASS, 'Page title looks good'],
    ];
  }

  /**
   * Description thresholds mirror the title, at 50/160 bounds.
   *
   * @covers ::evaluate
   * @dataProvider descriptionCases
   */
  public function testDescriptionThresholds(string $desc, string $severity, string $titleText): void {
    $f = $this->byId($this->check(['title' => str_repeat('a', 45), 'description' => $desc])->evaluate($this->node()));
    $this->assertSame($severity, $f['seo.description']['severity']);
    $this->assertSame($titleText, $f['seo.description']['title']);
  }

  public static function descriptionCases(): array {
    return [
      'missing' => ['', CheckInterface::FAIL, 'Meta description is missing'],
      'short' => [str_repeat('x', 20), CheckInterface::WARN, 'Meta description is short'],
      'long' => [str_repeat('x', 161), CheckInterface::WARN, 'Meta description is long'],
      'ok' => [str_repeat('x', 100), CheckInterface::PASS, 'Meta description looks good'],
    ];
  }

  /**
   * The provenance suffix distinguishes a per-page override from a site default
   * — only on the description WARN branches (missing/FAIL carries none).
   *
   * @covers ::evaluate
   */
  public function testDescriptionProvenance(): void {
    $short = ['title' => str_repeat('a', 45), 'description' => str_repeat('x', 20)];

    $inherited = $this->byId($this->check($short, [])->evaluate($this->node()));
    $this->assertStringContainsString('inherited from the site default', $inherited['seo.description']['detail']);

    $overridden = $this->byId($this->check($short, ['description' => TRUE])->evaluate($this->node()));
    $this->assertStringContainsString('(set on this page)', $overridden['seo.description']['detail']);
  }

  /**
   * Canonical + each Open Graph tag: absent → WARN, present → PASS.
   *
   * @covers ::evaluate
   */
  public function testCanonicalAndOpenGraphPresence(): void {
    $absent = $this->byId($this->check(['title' => str_repeat('a', 45), 'description' => str_repeat('x', 100)])->evaluate($this->node()));
    $this->assertSame(CheckInterface::WARN, $absent['seo.canonical']['severity']);
    $this->assertSame(CheckInterface::WARN, $absent['seo.og_title']['severity']);
    $this->assertSame(CheckInterface::WARN, $absent['seo.og_description']['severity']);
    $this->assertSame(CheckInterface::WARN, $absent['seo.og_image']['severity']);
  }

  private function node(): NodeInterface {
    return $this->createMock(NodeInterface::class);
  }

}
