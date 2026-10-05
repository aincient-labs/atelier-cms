<?php

declare(strict_types=1);

namespace Drupal\aincient_pages;

use Drupal\aincient_pages\Catalog\ComponentCatalogInterface;
use Drupal\Component\Plugin\Discovery\DiscoveryInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Says what a page/block draft revision changed, in the operator's words.
 *
 * Both stores funnel every schema write through one `editRevision()`; this
 * service diffs the base revision's resolved schema against the freshly
 * written one and returns the revision log message — a headline first line
 * plus, for two or three changes, a bulleted body (the revision rail shows the
 * first line truncated and renders the rest as prose). The style copies
 * {@see \Drupal\aincient_pages\EventSubscriber\BrandConfigSubscriber::summarise}:
 *
 * - Operator vocabulary only — component names and image-slot titles come from
 *   the SDC definitions, meta/teaser/post labels mirror the studio's fields. A
 *   value with no human name is not itemised.
 * - 1 change names it; 2–3 list them under a headline; more collapses to a
 *   count per area ("4 sections, SEO and teaser changed").
 * - A translation names its language; a first revision of a page/translation is
 *   labelled, never itemised (brand's "initial brand" rule).
 * - '' means nothing itemisable changed — the caller keeps its own message.
 *
 * Best-effort by contract: {@see revisionLog} never throws, so a summariser
 * bug costs a message, never a draft.
 */
final class PageSchemaSummariser {

  /**
   * Meta keys → the studio's SEO field labels.
   */
  private const META_LABELS = [
    'description' => 'Meta description',
    'canonical_url' => 'Canonical URL',
    'og_title' => 'Open Graph title',
    'og_description' => 'Open Graph description',
    'og_image' => 'Open Graph image',
  ];

  /**
   * Teaser keys → the studio's "Teaser card" field labels.
   */
  private const TEASER_LABELS = [
    'title' => 'Teaser title',
    'description' => 'Teaser description',
    'image' => 'Teaser image',
  ];

  /**
   * Blog-post fields → the studio's post editor labels. `value` = whether the
   * new value is short enough to quote (the Markdown body never is).
   */
  private const POST_LABELS = [
    'category' => ['Category', TRUE],
    'lead' => ['Lead', TRUE],
    'author' => ['Author', TRUE],
    'author_bio' => ['Author bio', TRUE],
    'date' => ['Date', TRUE],
    'cover' => ['Cover image', TRUE],
    'body_md' => ['Body', FALSE],
  ];

  /**
   * Longest quoted value before it is cut with an ellipsis.
   */
  private const QUOTE_MAX = 60;

  public function __construct(
    private readonly ComponentCatalogInterface $catalog,
    private readonly DiscoveryInterface $components,
    private readonly LanguageManagerInterface $languageManager,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Capture the base schema BEFORE a write, without ever throwing.
   *
   * @param callable(): (array|null) $read
   *   Returns the base revision's resolved schema, or NULL when there is none
   *   (a brand-new translation — its first revision).
   *
   * @return array{schema: array|null}|null
   *   The captured base, or NULL when reading it failed (the caller then falls
   *   back to its constant message).
   */
  public function before(callable $read): ?array {
    try {
      return ['schema' => $read()];
    }
    catch (\Throwable $e) {
      $this->logger->warning('Could not read the base schema for a revision message: @msg', ['@msg' => $e->getMessage()]);
      return NULL;
    }
  }

  /**
   * The revision log message for a schema write — never throws.
   *
   * @param array{schema: array|null}|null $before
   *   What {@see before} captured.
   * @param callable(): array $after
   *   Returns the resolved schema as written.
   * @param string|null $langcode
   *   The translation written, or NULL for the source language.
   * @param string $fallback
   *   The caller's constant message — used when nothing itemisable changed or
   *   the summary could not be computed.
   * @param array|null $origin
   *   Who staged which field ({@see originLine}), appended as the last line.
   */
  public function revisionLog(?array $before, callable $after, ?string $langcode, string $fallback, ?array $origin = NULL): string {
    if ($before === NULL) {
      return $fallback;
    }
    try {
      $written = $after();
      $summary = $this->summarise($before['schema'] ?? NULL, $written, $langcode);
      $line = $origin !== NULL ? $this->originLine($origin, $written) : '';
    }
    catch (\Throwable $e) {
      $this->logger->warning('Could not summarise a page revision: @msg', ['@msg' => $e->getMessage()]);
      return $fallback;
    }
    $message = $summary !== '' ? $summary : $fallback;
    return $line !== '' ? $message . "\n" . $line : $message;
  }

  /**
   * Who changed what, as one line: "Atelier: Meta description · You: Page title".
   *
   * `$origin` is the studio's session origin map grouped by source
   * (DECISIONS 0453) — `{agent: [path…], user: [path…]}`, paths as
   * `chat-ui/src/page-fields.ts` names them. Each path is labelled in the same
   * operator vocabulary as the summary; an unknown path is dropped, so a
   * malformed body costs a label, never the save.
   *
   * @param array $origin
   *   The `origin` body fragment, untrusted.
   * @param array $schema
   *   The schema as written (names a section by its slot id).
   */
  public function originLine(array $origin, array $schema): string {
    $parts = [];
    foreach (['agent' => 'Atelier', 'user' => 'You'] as $source => $who) {
      $paths = is_array($origin[$source] ?? NULL) ? array_slice($origin[$source], 0, 100) : [];
      $labels = [];
      foreach ($paths as $path) {
        $label = is_string($path) ? $this->pathLabel($path, $schema) : NULL;
        if ($label !== NULL) {
          $labels[$label] = TRUE;
        }
      }
      if ($labels !== []) {
        $parts[] = $who . ': ' . implode(', ', array_keys($labels));
      }
    }
    return implode(' · ', $parts);
  }

  /**
   * One field path → its operator label, or NULL when it names nothing known.
   */
  private function pathLabel(string $path, array $schema): ?string {
    $segments = explode('.', $path);
    $head = $segments[0];
    if ($path === 'title') {
      return 'Page title';
    }
    if ($head === 'meta' && count($segments) === 2) {
      return self::META_LABELS[$segments[1]] ?? NULL;
    }
    if ($head === 'teaser' && count($segments) === 2) {
      return self::TEASER_LABELS[$segments[1]] ?? NULL;
    }
    if ($path === 'sections') {
      return 'Section order';
    }
    if ($head === 'sections' && isset($segments[1])) {
      foreach ($this->keyed(is_array($schema['sections'] ?? NULL) ? $schema['sections'] : []) as $key => [$pos, $section]) {
        if ($key === 'id:' . $segments[1] || $key === 'pos:' . ltrim($segments[1], '#')) {
          return $this->sectionName($pos, $section);
        }
      }
      return 'A removed section';
    }
    return count($segments) === 1 ? (self::POST_LABELS[$head][0] ?? NULL) : NULL;
  }

  /**
   * A human description of what changed between two resolved schemas.
   *
   * @param array|null $old
   *   The base revision's schema, or NULL for a first revision.
   * @param array $new
   *   The written schema.
   * @param string|null $langcode
   *   The translation affected (NULL = the source language).
   *
   * @return string
   *   Headline (+ bulleted body for 2–3 changes), or '' for no change.
   */
  public function summarise(?array $old, array $new, ?string $langcode = NULL): string {
    $language = $this->languageName($langcode);
    if ($old === NULL) {
      return $language !== NULL ? $language . ' translation created' : 'First version';
    }

    $items = array_merge(
      $this->titleItems($old, $new),
      $this->sectionItems($old['sections'] ?? [], $new['sections'] ?? []),
      $this->postItems($old, $new),
      $this->mapItems('meta', self::META_LABELS, $old['meta'] ?? [], $new['meta'] ?? []),
      $this->mapItems('teaser', self::TEASER_LABELS, $old['teaser'] ?? [], $new['teaser'] ?? []),
    );
    if ($items === []) {
      return '';
    }

    $prefix = $language !== NULL ? $language . ': ' : '';
    if (count($items) === 1) {
      return $prefix . $items[0]['line'];
    }
    $headline = $prefix . $this->headline($items);
    if (count($items) > 3) {
      return $headline;
    }
    return $headline . "\n" . implode("\n", array_map(static fn (array $i): string => '- ' . $i['line'], $items));
  }

  /**
   * The headline: one phrase per area, a count once an area has several items.
   */
  private function headline(array $items): string {
    $areas = [];
    foreach ($items as $item) {
      $areas[$item['area']][] = $item;
    }
    $phrases = [];
    foreach ($areas as $area => $group) {
      if (count($group) === 1) {
        $phrases[] = $group[0]['subject'];
        continue;
      }
      $phrases[] = match ($area) {
        'sections' => count($group) . ' sections',
        'images' => count($group) . ' images',
        'post' => count($group) . ' post fields',
        'meta' => 'SEO',
        'teaser' => 'teaser',
        default => $group[0]['subject'],
      };
    }
    $last = array_pop($phrases);
    $list = $phrases ? implode(', ', $phrases) . ' and ' . $last : $last;
    return self::ucfirst($list) . ' changed';
  }

  /**
   * The page title (the node/media label).
   */
  private function titleItems(array $old, array $new): array {
    $was = (string) ($old['title'] ?? '');
    $now = (string) ($new['title'] ?? '');
    if ($was === $now) {
      return [];
    }
    return [$this->item('title', 'title', 'Page title → ' . self::quote($now))];
  }

  /**
   * Sections added / removed / reordered / edited, plus image token swaps.
   */
  private function sectionItems(array $old, array $new): array {
    $oldByKey = $this->keyed($old);
    $newByKey = $this->keyed($new);
    $items = [];

    foreach ($oldByKey as $key => [$pos, $section]) {
      if (!isset($newByKey[$key])) {
        $items[] = $this->item('sections', $this->sectionName($pos, $section), $this->sectionName($pos, $section) . ' removed');
      }
    }
    foreach ($newByKey as $key => [$pos, $section]) {
      if (!isset($oldByKey[$key])) {
        $items[] = $this->item('sections', $this->sectionName($pos, $section), $this->sectionName($pos, $section) . ' added');
      }
    }

    // Reordered: the sections both sides share, in a different relative order.
    $common = array_values(array_intersect(array_keys($oldByKey), array_keys($newByKey)));
    $newOrder = array_values(array_intersect(array_keys($newByKey), array_keys($oldByKey)));
    if ($common !== $newOrder) {
      $items[] = $this->item('sections', 'section order', 'Sections reordered');
    }

    $counts = array_count_values(array_map(static fn (array $s): string => (string) ($s['component'] ?? ''), $new));
    foreach ($newByKey as $key => [$pos, $section]) {
      if (!isset($oldByKey[$key])) {
        continue;
      }
      $was = $oldByKey[$key][1];
      if ($was === $section) {
        continue;
      }
      $name = (string) ($section['component'] ?? '');
      if ($name !== (string) ($was['component'] ?? '')) {
        $items[] = $this->item('sections', $this->sectionName($pos, $section), $this->sectionName($pos, $section) . ' replaced');
        continue;
      }
      $oldProps = is_array($was['props'] ?? NULL) ? $was['props'] : [];
      $newProps = is_array($section['props'] ?? NULL) ? $section['props'] : [];
      foreach ($this->imageProps($name) as $prop) {
        $before = (string) ($oldProps[$prop] ?? '');
        $after = (string) ($newProps[$prop] ?? '');
        unset($oldProps[$prop], $newProps[$prop]);
        if ($before === $after) {
          continue;
        }
        $label = $this->imageLabel($name, $prop);
        if (($counts[$name] ?? 0) > 1) {
          $label .= ' (section ' . ($pos + 1) . ')';
        }
        $items[] = $this->item('images', $label, self::swap($label, $before, $after));
      }
      if ($oldProps !== $newProps) {
        $items[] = $this->item('sections', $this->sectionName($pos, $section), $this->sectionName($pos, $section) . ' edited');
      }
    }
    return $items;
  }

  /**
   * The blog post's flat fields (a recipe page carries no sections).
   */
  private function postItems(array $old, array $new): array {
    $items = [];
    foreach (self::POST_LABELS as $key => [$label, $quotable]) {
      $was = (string) ($old[$key] ?? '');
      $now = (string) ($new[$key] ?? '');
      if ($was === $now) {
        continue;
      }
      if ($key === 'cover') {
        $items[] = $this->item('images', $label, self::swap($label, $was, $now));
        continue;
      }
      $line = match (TRUE) {
        $now === '' => $label . ' cleared',
        $quotable => $label . ' → ' . self::quote($now),
        default => $label . ' edited',
      };
      $items[] = $this->item('post', $label, $line);
    }
    return $items;
  }

  /**
   * A flat key → value block (meta, teaser) with known labels.
   */
  private function mapItems(string $area, array $labels, mixed $old, mixed $new): array {
    $old = is_array($old) ? $old : [];
    $new = is_array($new) ? $new : [];
    $items = [];
    foreach ($labels as $key => $label) {
      $was = (string) ($old[$key] ?? '');
      $now = (string) ($new[$key] ?? '');
      if ($was === $now) {
        continue;
      }
      $line = str_ends_with($key, 'image')
        ? self::swap($label, $was, $now)
        : ($now === '' ? $label . ' cleared' : $label . ' → ' . self::quote($now));
      $items[] = $this->item($area, $area === 'meta' ? 'SEO' : 'teaser', $line);
    }
    return $items;
  }

  /**
   * Sections keyed by their stable slot id (falls back to position).
   *
   * @return array<string, array{0: int, 1: array}>
   */
  private function keyed(array $sections): array {
    $out = [];
    foreach (array_values($sections) as $pos => $section) {
      if (!is_array($section)) {
        continue;
      }
      $id = $section['id'] ?? NULL;
      $key = is_string($id) && $id !== '' ? 'id:' . $id : 'pos:' . $pos;
      $out[$key] = [$pos, $section];
    }
    return $out;
  }

  /**
   * "Section 3 (Features)" / "Section 3 (Features, "Why teams switch")".
   */
  private function sectionName(int $pos, array $section): string {
    $label = $this->componentLabel((string) ($section['component'] ?? ''));
    $heading = trim((string) ($section['props']['heading'] ?? ''));
    $detail = $heading !== '' ? $label . ', ' . self::quote($heading) : $label;
    return 'Section ' . ($pos + 1) . ' (' . $detail . ')';
  }

  /**
   * The component's human name from its SDC definition ("CTA Band").
   */
  private function componentLabel(string $name): string {
    $definition = $this->definition($name);
    $label = is_array($definition) ? trim((string) ($definition['name'] ?? '')) : '';
    if ($label !== '') {
      return $label;
    }
    // A virtual placeable (the global `block`) has no SDC of its own.
    return $name === 'block' ? 'Global block' : self::ucfirst(str_replace(['-', '_'], ' ', $name));
  }

  /**
   * The image slot's title from its SDC definition ("Hero image").
   */
  private function imageLabel(string $name, string $prop): string {
    $definition = $this->definition($name);
    $title = is_array($definition) ? trim((string) ($definition['slots'][$prop]['title'] ?? '')) : '';
    return $title !== '' ? $title : $this->componentLabel($name) . ' image';
  }

  /**
   * The component's media-token image props (its declared `image_props`).
   *
   * @return string[]
   */
  private function imageProps(string $name): array {
    $def = $this->catalog->discovered()->def($name);
    $props = is_array($def['image_props'] ?? NULL) ? array_keys($def['image_props']) : [];
    return array_map('strval', $props);
  }

  /**
   * The SDC definition for a placeable, or NULL (virtual / unknown).
   */
  private function definition(string $name): ?array {
    $pluginId = $this->catalog->discovered()->pluginId($name);
    if ($pluginId === NULL) {
      return NULL;
    }
    $definition = $this->components->getDefinition($pluginId, FALSE);
    return is_array($definition) ? $definition : NULL;
  }

  /**
   * The language's own name, or NULL for the source language.
   */
  private function languageName(?string $langcode): ?string {
    if ($langcode === NULL || $langcode === '') {
      return NULL;
    }
    return $this->languageManager->getLanguage($langcode)?->getName() ?? strtoupper($langcode);
  }

  /**
   * One itemised change.
   *
   * @return array{area: string, subject: string, line: string}
   */
  private function item(string $area, string $subject, string $line): array {
    return ['area' => $area, 'subject' => $subject, 'line' => $line];
  }

  /**
   * "Hero image → media:42 (was media:17)" / "… removed".
   */
  private static function swap(string $label, string $was, string $now): string {
    if ($now === '') {
      return $label . ' removed';
    }
    return $label . ' → ' . $now . ($was !== '' ? ' (was ' . $was . ')' : '');
  }

  /**
   * A short, single-line quoted value.
   */
  private static function quote(string $value): string {
    $value = trim((string) preg_replace('/\s+/u', ' ', $value));
    if (mb_strlen($value) > self::QUOTE_MAX) {
      $value = rtrim(mb_substr($value, 0, self::QUOTE_MAX - 1)) . '…';
    }
    return '"' . $value . '"';
  }

  private static function ucfirst(string $value): string {
    return mb_strtoupper(mb_substr($value, 0, 1)) . mb_substr($value, 1);
  }

}
