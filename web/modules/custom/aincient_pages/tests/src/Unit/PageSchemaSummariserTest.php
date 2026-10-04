<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_pages\Unit;

use Drupal\aincient_pages\Catalog\CatalogCompiler;
use Drupal\aincient_pages\Catalog\ComponentCatalogInterface;
use Drupal\aincient_pages\PageSchemaSummariser;
use Drupal\Component\Plugin\Discovery\DiscoveryInterface;
use Drupal\Core\Language\Language;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Log\LoggerInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * The draft revision message: what changed, in the operator's words.
 *
 * Runs over the REAL shipped .component.yml files (component names + image
 * slot titles come from the SDC definitions), like ComponentCatalogTest.
 *
 * @group aincient
 * @coversDefaultClass \Drupal\aincient_pages\PageSchemaSummariser
 */
#[RunTestsInSeparateProcesses]
final class PageSchemaSummariserTest extends UnitTestCase {

  private LoggerInterface $logger;

  private function summariser(): PageSchemaSummariser {
    $definitions = [];
    foreach (glob(dirname(__DIR__, 3) . '/components/*/*.component.yml') as $file) {
      $name = basename(dirname($file));
      $definitions['aincient_pages:' . $name] = Yaml::parseFile($file) + [
        'provider' => 'aincient_pages',
        'machineName' => $name,
      ];
    }
    $compiled = CatalogCompiler::compile($definitions, NULL);
    $catalog = $this->createMock(ComponentCatalogInterface::class);
    $catalog->method('discovered')->willReturn($compiled);
    $discovery = $this->createMock(DiscoveryInterface::class);
    $discovery->method('getDefinition')->willReturnCallback(static fn (string $id) => $definitions[$id] ?? NULL);
    $languages = $this->createMock(LanguageManagerInterface::class);
    $languages->method('getLanguage')->willReturnCallback(static fn (string $code) => $code === 'de' ? new Language(['id' => 'de', 'name' => 'German']) : NULL);
    $this->logger = $this->createMock(LoggerInterface::class);
    return new PageSchemaSummariser($catalog, $discovery, $languages, $this->logger);
  }

  /**
   * A three-section landing page.
   */
  private function page(): array {
    return [
      'type' => 'landing',
      'title' => 'Atelier',
      'sections' => [
        ['id' => 's1', 'component' => 'hero', 'props' => ['heading' => 'Build by talking', 'image' => 'media:17']],
        ['id' => 's2', 'component' => 'features', 'props' => ['heading' => 'Why teams switch']],
        ['id' => 's3', 'component' => 'cta', 'props' => ['heading' => 'Start now']],
      ],
    ];
  }

  public function testNoOpIsEmpty(): void {
    $this->assertSame('', $this->summariser()->summarise($this->page(), $this->page()));
  }

  public function testFirstRevision(): void {
    $this->assertSame('First version', $this->summariser()->summarise(NULL, $this->page()));
    $this->assertSame('German translation created', $this->summariser()->summarise(NULL, $this->page(), 'de'));
  }

  public function testSectionAddedNamesPositionKindAndHeading(): void {
    $new = $this->page();
    $new['sections'][] = ['id' => 's4', 'component' => 'faq', 'props' => ['heading' => 'Questions']];
    $this->assertSame('Section 4 (FAQ, "Questions") added', $this->summariser()->summarise($this->page(), $new));
  }

  public function testSectionRemoved(): void {
    $new = $this->page();
    unset($new['sections'][1]);
    $this->assertSame('Section 2 (Features, "Why teams switch") removed', $this->summariser()->summarise($this->page(), $new));
  }

  public function testSectionsReordered(): void {
    $new = $this->page();
    [$new['sections'][1], $new['sections'][2]] = [$new['sections'][2], $new['sections'][1]];
    $this->assertSame('Sections reordered', $this->summariser()->summarise($this->page(), $new));
  }

  public function testMediaTokenSwapUsesSlotTitle(): void {
    $new = $this->page();
    $new['sections'][0]['props']['image'] = 'media:42';
    $this->assertSame('Hero image → media:42 (was media:17)', $this->summariser()->summarise($this->page(), $new));
  }

  public function testSectionCopyEdited(): void {
    $new = $this->page();
    $new['sections'][2]['props']['heading'] = 'Start today';
    $this->assertSame('Section 3 (CTA Band, "Start today") edited', $this->summariser()->summarise($this->page(), $new));
  }

  public function testMetaOnly(): void {
    $new = $this->page();
    $new['meta'] = ['description' => 'An AI-first site builder', 'og_image' => 'media:9'];
    $this->assertSame(
      "SEO changed\n- Meta description → \"An AI-first site builder\"\n- Open Graph image → media:9",
      $this->summariser()->summarise($this->page(), $new),
    );
  }

  public function testTwoToThreeChangesGetHeadlineAndBullets(): void {
    $new = $this->page();
    $new['sections'][0]['props']['image'] = 'media:42';
    $new['teaser'] = ['title' => 'Build a site by talking to it'];
    $new['sections'][] = ['id' => 's4', 'component' => 'features', 'props' => []];
    $this->assertSame(
      "Section 4 (Features), Hero image and teaser changed\n"
      . "- Section 4 (Features) added\n"
      . "- Hero image → media:42 (was media:17)\n"
      . "- Teaser title → \"Build a site by talking to it\"",
      $this->summariser()->summarise($this->page(), $new),
    );
  }

  public function testMoreThanThreeCollapsesToCountsPerArea(): void {
    $new = $this->page();
    foreach ([0, 1, 2] as $i) {
      $new['sections'][$i]['props']['heading'] .= '!';
    }
    $new['sections'][] = ['id' => 's4', 'component' => 'faq', 'props' => []];
    $new['meta'] = ['og_title' => 'Atelier'];
    $this->assertSame('4 sections and SEO changed', $this->summariser()->summarise($this->page(), $new));
  }

  public function testTranslationNamesTheLanguage(): void {
    $new = $this->page();
    $new['sections'][1]['props']['heading'] = 'Warum Teams wechseln';
    $this->assertSame(
      'German: Section 2 (Features, "Warum Teams wechseln") edited',
      $this->summariser()->summarise($this->page(), $new, 'de'),
    );
  }

  public function testBlogPostFields(): void {
    $old = ['type' => 'blog', 'title' => 'Post', 'body_md' => 'One', 'cover' => 'media:1'];
    $new = ['type' => 'blog', 'title' => 'Post', 'body_md' => 'Two', 'cover' => 'media:2'];
    $this->assertSame(
      "Cover image and Body changed\n- Cover image → media:2 (was media:1)\n- Body edited",
      $this->summariser()->summarise($old, $new),
    );
  }

  public function testRevisionLogFallsBackAndNeverThrows(): void {
    $summariser = $this->summariser();
    $this->logger->expects($this->once())->method('warning');
    // Empty diff → the caller's constant.
    $this->assertSame('Saved draft.', $summariser->revisionLog(['schema' => $this->page()], fn () => $this->page(), NULL, 'Saved draft.'));
    // A failing read → the constant, logged.
    $this->assertSame('Saved draft.', $summariser->revisionLog(['schema' => $this->page()], static fn () => throw new \RuntimeException('boom'), NULL, 'Saved draft.'));
  }

}
