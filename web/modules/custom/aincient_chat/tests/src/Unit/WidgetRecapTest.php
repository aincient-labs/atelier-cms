<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_chat\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\aincient_chat\Chat\ContextPolicy;
use Drupal\aincient_chat\Chat\WidgetRecap;

/**
 * A turn's cards become one line the model can resolve references against.
 *
 * DECISIONS 0464: names and ids, never the payload.
 *
 * @coversDefaultClass \Drupal\aincient_chat\Chat\WidgetRecap
 * @group aincient_chat
 */
final class WidgetRecapTest extends UnitTestCase {

  /**
   * A list_pages table lists rows in order, by title, with their node ids.
   */
  public function testPagesTableListsTitlesAndIds(): void {
    $line = WidgetRecap::line([[
      'widget' => 'data_table',
      'payload' => [
        'columns' => [['key' => 'title', 'label' => 'Page'], ['key' => 'state', 'label' => 'State']],
        'rows' => [
          ['id' => 1, 'cells' => ['title' => 'Home', 'state' => 'Published']],
          ['id' => 4, 'cells' => ['title' => 'About us', 'state' => 'Draft']],
        ],
      ],
      'summary' => 'You have 2 pages — pick one to open it in the studio.',
    ]]);
    $this->assertSame('[shown to the user: a table of 2 rows — 1. Home (id 1) · 2. About us (id 4)]', $line);
  }

  /**
   * Long tables stop at twenty rows and say how many more there were.
   */
  public function testLongTableIsCut(): void {
    $rows = [];
    for ($i = 1; $i <= 23; $i++) {
      $rows[] = ['id' => $i, 'cells' => ['title' => 'Page ' . $i]];
    }
    $line = WidgetRecap::line([['widget' => 'data_table', 'payload' => ['columns' => [['key' => 'title']], 'rows' => $rows]]]);
    $this->assertStringContainsString('a table of 23 rows', $line);
    $this->assertStringContainsString('20. Page 20 (id 20) · +3 more]', $line);
    $this->assertStringNotContainsString('Page 21', $line);
  }

  /**
   * The studio map names rooms the way the user sees them, with status lines.
   */
  public function testStudioTourNamesRooms(): void {
    $line = WidgetRecap::line([[
      'widget' => 'studio_tour',
      'payload' => ['rooms' => [['key' => 'content', 'status' => '3 pages so far'], ['key' => 'globals', 'status' => '']]],
    ]]);
    $this->assertSame('[shown to the user: room cards — Pages (3 pages so far), Globals]', $line);
  }

  /**
   * A brand preview names the tokens and fonts it staged.
   */
  public function testBrandPreviewNamesTokensAndFonts(): void {
    $line = WidgetRecap::line([[
      'widget' => 'brand_preview',
      'payload' => ['commands' => [
        ['verb' => 'set_tokens', 'args' => ['tokens' => ['--brand-primary' => '#1F3D3D', '--neutral-surface' => '#FAF6EF']]],
        ['verb' => 'set_fonts', 'args' => ['fonts' => ['Lora']]],
      ]],
    ]]);
    $this->assertSame('[shown to the user: a brand preview — 2 tokens (--brand-primary, --neutral-surface); fonts Lora]', $line);
  }

  /**
   * An unknown card falls back to its own summary; several cards share a line.
   */
  public function testUnknownCardUsesSummaryAndCardsJoin(): void {
    $line = WidgetRecap::line([
      ['widget' => 'page_preview', 'payload' => ['x' => 1], 'summary' => "Previewing   the\nhero [draft]"],
      ['widget' => 'weather_card', 'payload' => []],
    ]);
    $this->assertSame('[shown to the user: page_preview card: Previewing the hero (draft) | weather_card card]', $line);
  }

  /**
   * No cards, no line.
   */
  public function testNoWidgetsIsEmpty(): void {
    $this->assertSame('', WidgetRecap::line([]));
    $this->assertSame('', WidgetRecap::line([['widget' => '', 'payload' => []]]));
  }

  /**
   * The policy the agents read names the exact prefix the recap writes.
   */
  public function testContextPolicyNamesThePrefix(): void {
    $this->assertStringContainsString(rtrim(WidgetRecap::PREFIX), str_replace("\n  ", ' ', ContextPolicy::TEXT));
  }

}
