<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_pages\Unit;

use Drupal\aincient_pages\PageSchemaCodec;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests the structure / content split + merge (PageSchemaCodec).
 *
 * The codec is the load-bearing value transform of the page-schema layering:
 * STORAGE keeps a structure layer (shared layout) + a content layer (per-language
 * copy), while authoring/rendering speak one merged schema. The invariant that
 * makes the round-trip safe is merge(split($schema)) == $schema (modulo prop key
 * ordering, which carries no meaning).
 *
 * @group aincient
 * @covers \Drupal\aincient_pages\PageSchemaCodec
 */
final class PageSchemaCodecTest extends UnitTestCase {

  /**
   * Structural props land in the structure slot; everything else in content.
   */
  public function testSplitPartitionsStructuralVersusContentProps(): void {
    $schema = [
      'type' => 'landing',
      'title' => 'Lumen',
      'sections' => [
        [
          'id' => 'aaaa1111',
          'component' => 'hero',
          'props' => [
            'variant' => 'split',
            'tone' => 'brand',
            'heading' => 'Hello',
            'cta_label' => 'Go',
            'cta_url' => 'https://example.com',
            'image' => 'https://example.com/x.png',
          ],
        ],
        [
          'id' => 'bbbb2222',
          'component' => 'features',
          'props' => [
            'columns' => 3,
            'heading' => 'Why us',
            'features' => [['icon' => '✶', 'title' => 'Fast', 'body' => 'Quick']],
          ],
        ],
      ],
    ];

    ['structure' => $structure, 'content' => $content] = PageSchemaCodec::split($schema);

    // Structure carries only id/component + the structural knobs (emitted in the
    // canonical STRUCTURAL_PROPS order: tone, variant, columns).
    $this->assertSame('landing', $structure['type']);
    $this->assertSame(
      ['id' => 'aaaa1111', 'component' => 'hero', 'tone' => 'brand', 'variant' => 'split'],
      $structure['slots'][0],
    );
    $this->assertSame(
      ['id' => 'bbbb2222', 'component' => 'features', 'columns' => 3],
      $structure['slots'][1],
    );

    // Content carries the title + every non-structural prop, keyed by slot id.
    $this->assertSame('Lumen', $content['title']);
    $this->assertSame(
      ['heading' => 'Hello', 'cta_label' => 'Go', 'cta_url' => 'https://example.com', 'image' => 'https://example.com/x.png'],
      $content['slots']['aaaa1111'],
    );
    $this->assertArrayHasKey('features', $content['slots']['bbbb2222']);
    // Structural keys never leak into the content overlay.
    $this->assertArrayNotHasKey('variant', $content['slots']['aaaa1111']);
    $this->assertArrayNotHasKey('columns', $content['slots']['bbbb2222']);
  }

  /**
   * A block placement's `ref` is STRUCTURAL (travels in the structure slot, shared
   * across languages), while an embed's `entity` token is CONTENT (per-language).
   */
  public function testSplitPartitionsReferencePlaceables(): void {
    $schema = [
      'type' => 'landing',
      'title' => 'Refs',
      'sections' => [
        ['id' => 'blk00001', 'component' => 'block', 'props' => ['ref' => 'block:7']],
        ['id' => 'emb00002', 'component' => 'embed', 'props' => ['tone' => 'muted', 'heading' => 'See also', 'entity' => 'entity:node:15@teaser']],
      ],
    ];

    ['structure' => $structure, 'content' => $content] = PageSchemaCodec::split($schema);

    // The block ref rides with the structure slot (it's a shared layout decision).
    $this->assertSame(['id' => 'blk00001', 'component' => 'block', 'ref' => 'block:7'], $structure['slots'][0]);
    $this->assertSame([], $content['slots']['blk00001']);

    // The embed's tone is structural; its entity token is content.
    $this->assertSame(['id' => 'emb00002', 'component' => 'embed', 'tone' => 'muted'], $structure['slots'][1]);
    $this->assertSame(
      ['heading' => 'See also', 'entity' => 'entity:node:15@teaser'],
      $content['slots']['emb00002'],
    );
  }

  /**
   * merge(split($schema)) reproduces the schema (order-insensitive).
   *
   * @param array $schema
   *   A representative merged schema.
   */
  #[DataProvider('roundTripSchemas')]
  public function testSplitMergeRoundTrip(array $schema): void {
    ['structure' => $structure, 'content' => $content] = PageSchemaCodec::split($schema);
    $merged = PageSchemaCodec::merge($structure, $content);
    $this->assertSame($this->normalize($schema), $this->normalize($merged));
  }

  /**
   * Representative schemas: a rich landing page and a blog post.
   */
  public static function roundTripSchemas(): array {
    return [
      'landing' => [[
        'type' => 'landing',
        'title' => 'Lumen',
        'sections' => [
          ['id' => 'aaaa1111', 'component' => 'hero', 'props' => ['variant' => 'centered', 'tone' => 'brand', 'heading' => 'Hi', 'cta_url' => '/go']],
          ['id' => 'bbbb2222', 'component' => 'stats', 'props' => ['items' => [['value' => '10k', 'label' => 'Users']]]],
          ['id' => 'cccc3333', 'component' => 'features', 'props' => ['columns' => 2, 'heading' => 'Why', 'features' => [['icon' => '⚡', 'title' => 'A', 'body' => 'B']]]],
        ],
      ]],
      'blog' => [[
        'type' => 'blog',
        'title' => 'A post',
        'category' => 'News',
        'lead' => 'A lead.',
        'author' => 'Ada',
        'body_html' => '<p>Body</p>',
      ]],
      'empty landing' => [[
        'type' => 'landing',
        'title' => 'Blank',
        'sections' => [],
      ]],
      'references' => [[
        'type' => 'landing',
        'title' => 'Refs',
        'sections' => [
          ['id' => 'aaaa1111', 'component' => 'hero', 'props' => ['heading' => 'Hi']],
          ['id' => 'blk00001', 'component' => 'block', 'props' => ['ref' => 'block:9']],
          ['id' => 'emb00002', 'component' => 'embed', 'props' => ['tone' => 'brand', 'entity' => 'entity:node:42']],
        ],
      ]],
    ];
  }

  /**
   * Recursively sort array keys so equality ignores (meaningless) key order.
   */
  private function normalize(array $value): array {
    foreach ($value as &$item) {
      if (is_array($item)) {
        $item = $this->normalize($item);
      }
    }
    ksort($value);
    return $value;
  }

}
