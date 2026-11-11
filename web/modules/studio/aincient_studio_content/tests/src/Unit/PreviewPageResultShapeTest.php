<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_studio_content\Unit;

use Drupal\aincient_studio_content\Plugin\AiCapability\PreviewPage;
use Drupal\Tests\UnitTestCase;

/**
 * The preview_page verdict: status + summary for applied, partial, refused.
 *
 * Bug B3: the agent told the owner a page had a new section order while the
 * move had been refused — the result led with "Previewing N edits" and the
 * refusal sat in a trailing aside. The verdict now leads with the refusal and
 * names the order the page actually has. The end-to-end shape (dry-run against
 * a wired draft) is covered in the kernel PreviewPageTest.
 *
 * @group aincient
 */
final class PreviewPageResultShapeTest extends UnitTestCase {

  private const SECTIONS = [
    ['id' => 'a1', 'component' => 'hero'],
    ['id' => 'c3', 'component' => 'faq'],
  ];

  public function testStatus(): void {
    $this->assertSame('applied', PreviewPage::status(FALSE, 2, 0));
    $this->assertSame('partially_applied', PreviewPage::status(FALSE, 1, 1));
    $this->assertSame('refused', PreviewPage::status(FALSE, 0, 2));
    // Nothing verifiable is never reported as applied.
    $this->assertSame('unverified', PreviewPage::status(TRUE, 2, 0));
    $this->assertSame('unverified', PreviewPage::status(TRUE, 1, 1));
    $this->assertSame('refused', PreviewPage::status(TRUE, 0, 1));
  }

  public function testAppliedSummaryNamesTheOrder(): void {
    $summary = PreviewPage::summary('applied', 1, 1, [], [], self::SECTIONS);
    $this->assertStringStartsWith('Applied 1 page edit', $summary);
    $this->assertStringContainsString('Sections now, in order: hero [a1], faq [c3].', $summary);
    $this->assertStringNotContainsString('REFUSED', $summary);
  }

  public function testPartialSummaryLeadsWithTheRefusal(): void {
    $refused = [['op' => 'reorder', 'reason' => 'order must list every section index exactly once']];
    $summary = PreviewPage::summary('partially_applied', 2, 1, $refused, [], self::SECTIONS);
    $this->assertStringStartsWith('PARTIALLY applied — 1 of 2 page edits applied; 1 REFUSED', $summary);
    $this->assertStringContainsString('reorder: order must list every section index exactly once', $summary);
    $this->assertStringContainsString('hero [a1], faq [c3]', $summary);
  }

  public function testRefusedSummarySaysNothingChanged(): void {
    $refused = [['op' => 'remove_section', 'reason' => 'no section with id "zz"']];
    $summary = PreviewPage::summary('refused', 1, 0, $refused, [], self::SECTIONS);
    $this->assertStringStartsWith('REFUSED — the page edit was not applied, so the page did NOT change', $summary);
    $this->assertStringContainsString('remove_section: no section with id "zz".', $summary);
    $plural = PreviewPage::summary('refused', 2, 0, $refused, [], self::SECTIONS);
    $this->assertStringStartsWith('REFUSED — none of the 2 page edits were applied', $plural);
    $this->assertStringContainsString('Sections now, in order: hero [a1], faq [c3].', $summary);
  }

  public function testUnverifiedSummaryClaimsNoApply(): void {
    $summary = PreviewPage::summary('unverified', 1, 1, [], [], NULL);
    $this->assertStringContainsString('not verified', $summary);
    $this->assertStringNotContainsString('Sections now', $summary);
  }

  public function testSectionListLabelsAndSkipsJunk(): void {
    $list = PreviewPage::sectionList([
      ['id' => 'a1', 'component' => 'hero', 'props' => ['heading' => 'Welcome']],
      'junk',
      ['id' => 'b2', 'component' => 'cta', 'props' => []],
    ]);
    $this->assertSame([
      ['id' => 'a1', 'component' => 'hero', 'label' => 'Welcome'],
      ['id' => 'b2', 'component' => 'cta'],
    ], $list);
    $this->assertSame([], PreviewPage::sectionList(NULL));
  }

}
