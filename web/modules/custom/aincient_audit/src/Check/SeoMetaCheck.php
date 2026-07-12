<?php

declare(strict_types=1);

namespace Drupal\aincient_audit\Check;

use Drupal\aincient_audit\MetaTagReader;
use Drupal\node\NodeInterface;

/**
 * The SEO / meta-tag check — title, description, canonical, Open Graph.
 *
 * The `seo` default policy's logic (DECISIONS 0129). Reads the node's effective
 * meta tags via {@see MetaTagReader} and flags length/presence issues, marking
 * whether each value is a per-page override or a site-wide default so we don't
 * tell a user to "fix" a deliberate global default. Finding ids (`seo.title`,
 * `seo.description`, `seo.og_*`, …) are keyed on by the Checks UI — do not change.
 */
final class SeoMetaCheck implements CheckInterface {

  use FindingTrait;

  /**
   * Built-in threshold defaults (Phase 3, DECISIONS 0134). A policy's
   * `parameters` override these by key (`title_min`, …); when a key is absent
   * the default applies, so an un-tuned policy — and any direct caller passing
   * no params — is byte-identical to the pre-Phase-3 output.
   */
  private const TITLE_MIN = 30;
  private const TITLE_MAX = 60;
  private const DESCRIPTION_MIN = 50;
  private const DESCRIPTION_MAX = 160;

  /**
   * The declarative remediation descriptor per SEO finding (Phase 2, DECISIONS
   * 0133) — the SINGLE source the repair agent AND the manual editors read (it
   * replaces the old hardcoded finding→field maps that lived in both the agent
   * prompt and the Checks studio). Every SEO finding is the `meta` dimension and
   * edits one field: the page title (routed to `draft.title`) or a `field_metatag`
   * key (routed to `draft.meta[field]`). `og_image` is a raw URL by design (it is
   * consumed off-site by unfurlers — memory `page-schema-images-are-media-tokens`).
   * Canonical key order — action, field, input, label, constraints, aiFixable —
   * is what {@see \Drupal\aincient_audit\PolicyEvaluator} passes through unchanged.
   *
   * @var array<string, array<string, mixed>>
   */
  private const REMEDIATION = [
    'seo.title' => ['action' => 'edit_field', 'field' => 'title', 'input' => 'text', 'label' => 'Page title', 'constraints' => ['min' => 30, 'max' => 60], 'aiFixable' => TRUE],
    'seo.description' => ['action' => 'edit_field', 'field' => 'description', 'input' => 'textarea', 'label' => 'Meta description', 'constraints' => ['min' => 50, 'max' => 160], 'aiFixable' => TRUE],
    'seo.canonical' => ['action' => 'edit_field', 'field' => 'canonical_url', 'input' => 'url', 'label' => 'Canonical URL', 'aiFixable' => TRUE],
    'seo.og_title' => ['action' => 'edit_field', 'field' => 'og_title', 'input' => 'text', 'label' => 'Open Graph title', 'aiFixable' => TRUE],
    'seo.og_description' => ['action' => 'edit_field', 'field' => 'og_description', 'input' => 'textarea', 'label' => 'Open Graph description', 'aiFixable' => TRUE],
    'seo.og_image' => ['action' => 'edit_field', 'field' => 'og_image', 'input' => 'url', 'label' => 'Open Graph image', 'aiFixable' => TRUE],
  ];

  public function __construct(
    private readonly MetaTagReader $meta,
  ) {}

  /**
   * A `meta`-dimension finding carrying its declarative remediation (Phase 2).
   * `pass` findings carry no remediation (nothing to fix); everything else picks
   * the descriptor for its id from {@see self::REMEDIATION}.
   *
   * When `$constraints` is given (title/description, whose bounds are tunable —
   * Phase 3) it OVERWRITES the descriptor's `constraints` in place, so the fix
   * UI's shown bounds always match the resolved thresholds the finding was
   * judged against — and key order is preserved (byte-identical when the bounds
   * equal the defaults).
   *
   * @param array{min: int, max: int}|null $constraints
   *   The resolved bounds to stamp onto the remediation, or NULL to keep the
   *   descriptor's own.
   */
  private function metaFinding(string $id, string $severity, string $title, string $detail, string $location, ?array $constraints = NULL): array {
    $remediation = $severity === self::PASS ? NULL : (self::REMEDIATION[$id] ?? NULL);
    if ($remediation !== NULL && $constraints !== NULL) {
      $remediation['constraints'] = $constraints;
    }
    return $this->finding($id, $severity, $title, $detail, $location, 'meta', $remediation);
  }

  /**
   * {@inheritdoc}
   */
  public function id(): string {
    return 'seo';
  }

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    return 'SEO & meta tags';
  }

  /**
   * {@inheritdoc}
   */
  public function evaluate(NodeInterface $node, array $params = []): array {
    $tags = $this->meta->tags($node);
    $overrides = $this->meta->overrides($node);
    $findings = [];

    // Resolve the tunable thresholds (Phase 3) — a param overrides the default
    // per key; the resolved bounds drive BOTH the finding text and the fix UI's
    // `constraints` so the two never disagree.
    $titleMin = (int) ($params['title_min'] ?? self::TITLE_MIN);
    $titleMax = (int) ($params['title_max'] ?? self::TITLE_MAX);
    $descMin = (int) ($params['description_min'] ?? self::DESCRIPTION_MIN);
    $descMax = (int) ($params['description_max'] ?? self::DESCRIPTION_MAX);
    $titleBounds = ['min' => $titleMin, 'max' => $titleMax];
    $descBounds = ['min' => $descMin, 'max' => $descMax];

    // <title> — search results show ~titleMin–titleMax chars.
    $title = $tags['title'] ?? '';
    $len = mb_strlen($title);
    if ($title === '') {
      $findings[] = $this->metaFinding('seo.title', self::FAIL, 'Page title is missing', 'No <title> resolves for this page — add a meta title.', 'Meta: title', $titleBounds);
    }
    elseif ($len < $titleMin) {
      $findings[] = $this->metaFinding('seo.title', self::WARN, 'Page title is short', sprintf('The title is %d characters; aim for %d–%d.', $len, $titleMin, $titleMax), 'Meta: title', $titleBounds);
    }
    elseif ($len > $titleMax) {
      $findings[] = $this->metaFinding('seo.title', self::WARN, 'Page title is long', sprintf('The title is %d characters; search engines truncate beyond ~%d.', $len, $titleMax), 'Meta: title', $titleBounds);
    }
    else {
      $findings[] = $this->metaFinding('seo.title', self::PASS, 'Page title looks good', sprintf('“%s” (%d chars).', $title, $len), 'Meta: title');
    }

    // Meta description — ~descMin–descMax chars.
    $desc = $tags['description'] ?? '';
    $dlen = mb_strlen($desc);
    if ($desc === '') {
      $findings[] = $this->metaFinding('seo.description', self::FAIL, 'Meta description is missing', 'Add a description so search engines and social cards have a summary.', 'Meta: description', $descBounds);
    }
    elseif ($dlen < $descMin) {
      $findings[] = $this->metaFinding('seo.description', self::WARN, 'Meta description is short', sprintf('The description is %d characters; aim for %d–%d.', $dlen, $descMin, $descMax) . $this->provenance('description', $overrides), 'Meta: description', $descBounds);
    }
    elseif ($dlen > $descMax) {
      $findings[] = $this->metaFinding('seo.description', self::WARN, 'Meta description is long', sprintf('The description is %d characters; it is truncated beyond ~%d.', $dlen, $descMax) . $this->provenance('description', $overrides), 'Meta: description', $descBounds);
    }
    else {
      $findings[] = $this->metaFinding('seo.description', self::PASS, 'Meta description looks good', sprintf('%d characters.', $dlen), 'Meta: description');
    }

    // Canonical URL.
    if (($tags['canonical'] ?? '') === '') {
      $findings[] = $this->metaFinding('seo.canonical', self::WARN, 'No canonical URL', 'A canonical URL avoids duplicate-content ambiguity.', 'Meta: canonical');
    }
    else {
      $findings[] = $this->metaFinding('seo.canonical', self::PASS, 'Canonical URL set', (string) $tags['canonical'], 'Meta: canonical');
    }

    // Open Graph — the social-share card.
    $og = [
      'og:title' => 'Open Graph title',
      'og:description' => 'Open Graph description',
      'og:image' => 'Open Graph image',
    ];
    foreach ($og as $key => $label) {
      $id = 'seo.' . str_replace(':', '_', $key);
      if (($tags[$key] ?? '') === '') {
        $findings[] = $this->metaFinding($id, self::WARN, $label . ' is missing', sprintf('Set %s so shared links render a rich preview card.', $key), 'Meta: ' . $key);
      }
      else {
        $findings[] = $this->metaFinding($id, self::PASS, $label . ' set', (string) $tags[$key], 'Meta: ' . $key);
      }
    }

    return $findings;
  }

  /**
   * A provenance suffix for a finding detail: whether the tag's value is a
   * per-page override or inherited from a site-wide default. Keeps us from
   * telling a user to "fix" something that's a deliberate global default (they'd
   * set a per-page override, which the studio does — this just makes it legible).
   * $tagId is the Metatag plugin id; $overrides is {@see MetaTagReader::overrides}.
   */
  private function provenance(string $tagId, array $overrides): string {
    return isset($overrides[$tagId])
      ? ' (set on this page)'
      : ' (inherited from the site default — fixing sets a per-page override)';
  }

}
