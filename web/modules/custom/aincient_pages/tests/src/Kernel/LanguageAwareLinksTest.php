<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_pages\Kernel;

use Drupal\aincient_pages\Controller\PageSpikeController;
use Drupal\aincient_pages\PageStore;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\pathauto\Entity\PathautoPattern;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * A raw page-schema href renders in the page's OWN language (M3, finding A).
 *
 * Link props hold either a reference token or a raw string the author typed. A
 * raw INTERNAL path (`/book`) was stored once, in one language, and shipped
 * verbatim into every translation — so the German page's CTA sent a German
 * reader to the English page. It is now re-rendered through Drupal's URL
 * generation in the render language, which applies both the negotiated `/de`
 * prefix and the translation's own alias (PageStore::syncTranslationAlias).
 *
 * External / mailto: / protocol-relative / fragment hrefs are NOT ours to
 * rewrite, and a monolingual site must stay byte-identical — both are asserted.
 *
 * @group aincient
 * @covers \Drupal\aincient_pages\LanguageAwareHref
 * @covers \Drupal\aincient_pages\EntityEmbedResolver::resolveLinks
 */
#[RunTestsInSeparateProcesses]
final class LanguageAwareLinksTest extends KernelTestBase {

  use EditorialWorkflowTestTrait;
  use UserCreationTrait;

  /**
   * The shipped `pathauto.pattern.aincient_page` trips the strict checker on a
   * pre-existing ctools schema gap (`selection_criteria.*.uuid`), which only
   * shows up in a test that enables pathauto AND installs our optional config.
   * Unrelated to what is under test here.
   */
  protected $strictConfigSchema = FALSE;

  protected static $modules = [
    'system', 'user', 'field', 'text', 'filter', 'link', 'menu_link_content',
    'node', 'language', 'content_translation',
    'workflows', 'content_moderation',
    'token', 'path', 'path_alias', 'pathauto',
    'file',
    'aincient_core', 'aincient_pages',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('menu_link_content');
    $this->installEntitySchema('configurable_language');
    $this->installEntitySchema('content_moderation_state');
    $this->installEntitySchema('path_alias');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'user', 'language', 'content_translation', 'pathauto']);
    $this->installConfig(['workflows', 'content_moderation', 'aincient_pages']);

    $anonymous = Role::load(RoleInterface::ANONYMOUS_ID)
      ?: Role::create(['id' => RoleInterface::ANONYMOUS_ID, 'label' => 'Anonymous']);
    $anonymous->grantPermission('access content')->save();

    ConfigurableLanguage::createFromLangcode('de')->save();

    // URL-prefix negotiation with the default language UNPREFIXED — the shape
    // the distribution ships (and what a bilingual site actually serves).
    $this->config('language.negotiation')
      ->set('url.source', 'path_prefix')
      ->set('url.prefixes', ['en' => '', 'de' => 'de'])
      ->save();

    // language's ServiceProvider only registers `path_processor_language` when
    // the site is ALREADY multilingual at container-build time — which, in a
    // kernel test, was before `de` existed. Without this rebuild the outbound
    // language processor is simply absent and no URL ever gets a prefix.
    $this->container->get('kernel')->rebuildContainer();
    $this->container = \Drupal::getContainer();

    if (NodeType::load('aincient_page') === NULL) {
      NodeType::create(['type' => 'aincient_page', 'name' => 'AIncient page'])->save();
    }
    foreach (['field_page_structure', 'field_page_content'] as $name) {
      if (FieldStorageConfig::loadByName('node', $name)) {
        continue;
      }
      FieldStorageConfig::create([
        'field_name' => $name,
        'entity_type' => 'node',
        'type' => 'string_long',
        'translatable' => TRUE,
      ])->save();
      FieldConfig::create([
        'field_name' => $name,
        'entity_type' => 'node',
        'bundle' => 'aincient_page',
        'label' => $name,
        'translatable' => TRUE,
      ])->save();
    }
    if (!FieldStorageConfig::loadByName('node', 'field_layout_mode')) {
      FieldStorageConfig::create([
        'field_name' => 'field_layout_mode',
        'entity_type' => 'node',
        'type' => 'string',
        'settings' => ['max_length' => 16, 'is_ascii' => TRUE, 'case_sensitive' => FALSE],
        'translatable' => TRUE,
      ])->save();
      FieldConfig::create([
        'field_name' => 'field_layout_mode',
        'entity_type' => 'node',
        'bundle' => 'aincient_page',
        'label' => 'Layout mode',
        'translatable' => TRUE,
      ])->save();
    }

    \Drupal::service('content_translation.manager')->setEnabled('node', 'aincient_page', TRUE);
    $this->setUpEditorialWorkflow(['aincient_page']);

    $this->config('aincient_pages.settings')
      ->set('translation', ['default_mode' => 'symmetric', 'allow_divergence' => TRUE])
      ->save();

    // The flat pattern the distribution ships (see PageAliasTest) — already
    // installed from config/optional when aincient_pages' config went in.
    if (PathautoPattern::load('aincient_page') === NULL) {
      PathautoPattern::create([
        'id' => 'aincient_page',
        'label' => 'AIncient page',
        'type' => 'canonical_entities:node',
        'pattern' => '/[node:title]',
        'selection_criteria' => [],
      ])->save();
    }

    $this->setUpCurrentUser(['uid' => 2], [
      'access content',
      'view any unpublished content',
      'view latest version',
      'edit any aincient_page content',
      'create aincient_page content',
      'use aincient_editorial transition create_new_draft',
      'use aincient_editorial transition submit_for_review',
      'use aincient_editorial transition approve',
      'use aincient_editorial transition reject',
      'use aincient_editorial transition publish',
      'use aincient_editorial transition archive',
      'use aincient_editorial transition restore',
    ]);
  }

  private function store(): PageStore {
    return $this->container->get('aincient_pages.store');
  }

  /** A landing schema with one cta section carrying $props. */
  private function schema(string $title, array $props = []): array {
    return [
      'type' => 'landing',
      'title' => $title,
      'sections' => [
        ['component' => 'cta', 'props' => ['heading' => $title] + $props],
      ],
    ];
  }

  /** The chrome-less HTML the PUBLIC node route renders for a translation. */
  private function renderNode(string $id, string $langcode): string {
    $node = Node::load((int) $id);
    $this->assertNotNull($node);
    if ($langcode !== $node->language()->getId()) {
      $node = $node->getTranslation($langcode);
    }
    $controller = PageSpikeController::create($this->container);
    return (string) $controller->nodeCanonical($node)->getContent();
  }

  /**
   * The German page's CTA points at the GERMAN target — prefix AND the
   * translation's own alias — while the English page keeps the bare alias.
   */
  public function testRawInternalHrefFollowsTheRenderLanguage(): void {
    // Target page: EN /book, DE /de/termin (M2 aliases the translation).
    $target = $this->store()->store($this->schema('Book'));
    $this->store()->publish($target, $this->schema('Book'));
    $this->store()->publish($target, $this->schema('Termin'), 'de');

    // Linking page: a cta whose target is the raw EN path the author typed.
    $props = ['cta_label' => 'Book', 'cta_url' => '/book'];
    $source = $this->store()->store($this->schema('Start', $props));
    $this->store()->publish($source, $this->schema('Start', $props));
    $this->store()->publish($source, $this->schema('Anfang', ['cta_label' => 'Termin'] + $props), 'de');

    $de = $this->renderNode($source, 'de');
    $this->assertStringContainsString('href="/de/termin"', $de);
    $this->assertStringNotContainsString('href="/book"', $de);

    $en = $this->renderNode($source, 'en');
    $this->assertStringContainsString('href="/book"', $en);
    $this->assertStringNotContainsString('href="/de/termin"', $en);
  }

  /**
   * An internal path with NO translated alias still lands on the German
   * translation (the outbound alias processor falls back to the system path).
   */
  public function testUntranslatedTargetStillGetsTheLanguagePrefix(): void {
    $target = $this->store()->store($this->schema('Imprint'));
    $this->store()->publish($target, $this->schema('Imprint'));

    $props = ['cta_label' => 'Imprint', 'cta_url' => '/imprint'];
    $source = $this->store()->store($this->schema('Start', $props));
    $this->store()->publish($source, $this->schema('Start', $props));
    $this->store()->publish($source, $this->schema('Anfang', $props), 'de');

    $de = $this->renderNode($source, 'de');
    $this->assertMatchesRegularExpression('#href="/de/(imprint|node/' . $target . ')"#', $de);
  }

  /**
   * Everything that is not an internal root-relative path is left ALONE —
   * external URLs, mailto:, protocol-relative and pure fragments.
   */
  public function testForeignAndFragmentHrefsAreUntouched(): void {
    $resolver = $this->container->get('aincient_pages.embed_resolver');
    $untouched = [
      'cta_url' => 'https://example.com/x',
      'secondary_url' => 'mailto:hi@example.com',
      'url' => '#top',
    ];
    $this->assertSame($untouched, $resolver->resolveLinks($untouched, 'de'));
    $this->assertSame(['url' => '//cdn.example.com/x'], $resolver->resolveLinks(['url' => '//cdn.example.com/x'], 'de'));
    $this->assertSame(['url' => 'tel:+4930123'], $resolver->resolveLinks(['url' => 'tel:+4930123'], 'de'));
  }

  /**
   * An href already carrying the `de` prefix is never prefixed twice, and a
   * query/fragment suffix rides along.
   */
  public function testPrefixIsNeverDoubledAndSuffixesSurvive(): void {
    $resolver = $this->container->get('aincient_pages.embed_resolver');
    $this->assertSame(
      ['cta_url' => '/de/termin'],
      $resolver->resolveLinks(['cta_url' => '/de/termin'], 'de'),
    );
    $this->assertSame(
      '/de/kontakt?ref=cta#form',
      $resolver->resolveLinks(['cta_url' => '/kontakt?ref=cta#form'], 'de')['cta_url'],
    );
  }

  /**
   * A static asset is served by the web server at its real path only — a
   * prefixed `/de/sites/default/files/…` falls through to Drupal and 404s. So a
   * file under public files, a theme or module tree, or any docroot file, keeps
   * the href the author typed on every translation.
   */
  public function testStaticAssetHrefsAreNeverPrefixed(): void {
    $resolver = $this->container->get('aincient_pages.embed_resolver');
    $assets = [
      'cta_url' => '/sites/default/files/brochure.pdf',
      'secondary_url' => '/themes/custom/site/logo.svg',
      'url' => '/modules/custom/aincient_core/assets/favicon.svg',
    ];
    $this->assertSame($assets, $resolver->resolveLinks($assets, 'de'));
    // A real file living directly in the docroot (not under a known tree).
    $this->assertSame(['url' => '/robots.txt'], $resolver->resolveLinks(['url' => '/robots.txt'], 'de'));
    // …while an unaliased page path next to it still earns the prefix.
    $this->assertSame(['url' => '/de/kontakt'], $resolver->resolveLinks(['url' => '/kontakt'], 'de'));
  }

  /**
   * The rewrite is language-gated: with no langcode in play nothing changes.
   */
  public function testNoLangcodeLeavesHrefsAlone(): void {
    $resolver = $this->container->get('aincient_pages.embed_resolver');
    $this->assertSame(['cta_url' => '/book'], $resolver->resolveLinks(['cta_url' => '/book'], NULL));
  }

}
