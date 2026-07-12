<?php

declare(strict_types=1);

namespace Drupal\aincient_audit;

use Drupal\metatag\MetatagManagerInterface;
use Drupal\node\NodeInterface;

/**
 * Resolves a node's effective meta tags — the shared meta-reading seam.
 *
 * Extracted from {@see AuditEngine} so both the SEO policy check
 * ({@see \Drupal\aincient_audit\Check\SeoMetaCheck}) and the `read_meta_tags`
 * capability read the SAME resolved tags — "what tags do I have" and "what the
 * audit evaluates" can never disagree. Reads only ({@see MetatagManagerInterface}),
 * writes nothing, makes no network request.
 *
 * Not `final`: {@see \Drupal\aincient_audit\Check\SeoMetaCheck} depends on this
 * seam and its branch logic is unit-tested against a doubled reader.
 */
class MetaTagReader {

  public function __construct(
    private readonly MetatagManagerInterface $metatagManager,
  ) {}

  /**
   * The effective meta tags for a node, resolved (tokens replaced) → {key: value}.
   *
   * Keys are the rendered tag identities: `title`, `description`, `canonical`,
   * `og:title`, … so the SEO check and the `read_meta_tags` capability agree.
   *
   * @return array<string, string>
   */
  public function tags(NodeInterface $node): array {
    $tags = $this->metatagManager->tagsFromEntityWithDefaults($node);
    $elements = $this->metatagManager->generateRawElements($tags, $node);
    $out = [];
    // generateRawElements returns [$tag_name => $render_element]; the resolved
    // value lives in #value (the <title>) or in #attributes (meta/link).
    foreach ($elements as $element) {
      if (!is_array($element)) {
        continue;
      }
      $tag = (string) ($element['#tag'] ?? '');
      $attrs = is_array($element['#attributes'] ?? NULL) ? $element['#attributes'] : [];
      if ($tag === 'title') {
        $out['title'] = trim((string) ($element['#value'] ?? ''));
      }
      elseif ($tag === 'meta' && isset($attrs['name'])) {
        $out[(string) $attrs['name']] = trim((string) ($attrs['content'] ?? ''));
      }
      elseif ($tag === 'meta' && isset($attrs['property'])) {
        $out[(string) $attrs['property']] = trim((string) ($attrs['content'] ?? ''));
      }
      elseif ($tag === 'link' && (($attrs['rel'] ?? '') === 'canonical')) {
        $out['canonical'] = trim((string) ($attrs['href'] ?? ''));
      }
    }
    return $out;
  }

  /**
   * The tag ids a node OVERRIDES on its own `field_metatag` (raw — not merged
   * with the site defaults), so the SEO check can tell "set on this page" from
   * "inherited from a site default". Decodes Metatag v2 JSON + legacy serialized.
   * Empty when the field is unset/absent. Keys are Metatag plugin ids
   * (`description`, `canonical_url`, `og_title`, …).
   *
   * @return array<string, true>
   */
  public function overrides(NodeInterface $node): array {
    if (!$node->hasField('field_metatag')) {
      return [];
    }
    $raw = (string) $node->get('field_metatag')->value;
    if (trim($raw) === '') {
      return [];
    }
    $data = metatag_data_decode($raw);
    $out = [];
    foreach ($data as $id => $value) {
      if (is_scalar($value) && (string) $value !== '') {
        $out[(string) $id] = TRUE;
      }
    }
    return $out;
  }

}
