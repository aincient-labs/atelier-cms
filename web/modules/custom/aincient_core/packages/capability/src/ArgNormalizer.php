<?php

declare(strict_types=1);

namespace Aincient\Capability;

/**
 * Normalises model-supplied tool arguments and maps their declared types.
 *
 * Pure functions, no state: the one place a capability's model args are cleaned
 * before the capability sees them (DECISIONS 0197), and the one table that maps
 * a capability's declared data type to the JSON-schema type the model is shown.
 * Called by the FlowDrop capability node
 * ({@see \Drupal\aincient_flows\Plugin\FlowDropNodeProcessor\CapabilityTool}),
 * which is the single boundary every model-issued capability call crosses.
 */
final class ArgNormalizer {

  /**
   * Normalise one model-supplied tool argument.
   *
   * LLMs habitually HTML-escape text they think is destined for a web page, so a
   * value like `Ember & Oak` arrives as `Ember &amp; Oak` (and `O'Brien` as
   * `O&#39;Brien`). Our capabilities store these as PLAIN TEXT and escape at
   * OUTPUT (Twig / the SDC templates), so persisting the entity double-encodes
   * it — surfacing a literal `&amp;` in the studio field and on the rendered
   * site. This is a cross-cutting LLM behaviour, so we strip it once, at the
   * single boundary every capability's model args cross, rather than per field.
   *
   * JSON-aware: a `*_json` arg is decoded on its LEAF string values only (parse →
   * decode → re-encode), so an entity inside a value (e.g. a `&quot;`) can never
   * corrupt the JSON envelope. Non-JSON strings are decoded directly. Non-strings
   * pass through untouched. Idempotent + cheap: a bare `&` is not a valid entity,
   * so entity-free text is returned unchanged (and the `&` fast-path skips the
   * work entirely).
   */
  public static function normalizeArg(mixed $value): mixed {
    // A structured (array/object) arg — the model sent native JSON for an array
    // param. Decode HTML entities in its leaf strings, exactly as the JSON-string
    // path below does; keys are field names and are left as-is.
    if (is_array($value)) {
      return self::decodeEntitiesDeep($value);
    }
    if (!is_string($value) || !str_contains($value, '&')) {
      return $value;
    }
    // A JSON object/array arg: decode the leaves, never the raw envelope.
    $trimmed = ltrim($value);
    if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
      $decoded = json_decode($value, TRUE);
      if (is_array($decoded)) {
        return (string) json_encode(
          self::decodeEntitiesDeep($decoded),
          JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
      }
    }
    return self::decodeEntities($value);
  }

  /**
   * Recursively decode HTML entities in the leaf string values of a parsed
   * structure (array keys are left as-is — they are field names, not content).
   */
  public static function decodeEntitiesDeep(mixed $value): mixed {
    if (is_array($value)) {
      return array_map(self::decodeEntitiesDeep(...), $value);
    }
    return is_string($value) ? self::decodeEntities($value) : $value;
  }

  /**
   * Decode HTML entities in a single string (named + numeric, single + double
   * quotes, HTML5 set), UTF-8. A no-op for entity-free text.
   */
  public static function decodeEntities(string $value): string {
    return html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
  }

  /**
   * Map a capability's declared (Drupal typed-data) data type to a JSON-schema
   * type for the tool's `input_schema` properties.
   */
  public static function toJsonType(string $dataType): string {
    return match ($dataType) {
      'boolean' => 'boolean',
      'integer' => 'integer',
      'float', 'decimal' => 'number',
      'list' => 'array',
      // A structured map — in practice a graph-wired input (e.g. preview_page's
      // `page_state`, fed the prompt's variables bag), which the resolver's
      // strict type check would refuse as a `string`.
      'map' => 'object',
      default => 'string',
    };
  }

}
