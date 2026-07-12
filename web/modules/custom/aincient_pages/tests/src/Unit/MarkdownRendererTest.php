<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_pages\Unit;

use Drupal\aincient_pages\MarkdownRenderer;
use Drupal\Tests\UnitTestCase;

/**
 * The `markdown` section's source -> sanitised HTML conversion.
 *
 * @group aincient
 * @coversDefaultClass \Drupal\aincient_pages\MarkdownRenderer
 */
final class MarkdownRendererTest extends UnitTestCase {

  private MarkdownRenderer $renderer;

  protected function setUp(): void {
    parent::setUp();
    $this->renderer = new MarkdownRenderer();
  }

  /**
   * Empty / whitespace-only source converts to the empty string (no band noise).
   *
   * @covers ::toSafeHtml
   */
  public function testEmptyInputIsEmpty(): void {
    $this->assertSame('', $this->renderer->toSafeHtml(''));
    $this->assertSame('', $this->renderer->toSafeHtml("  \n\t "));
  }

  /**
   * CommonMark basics render to the expected block + inline HTML.
   *
   * @covers ::toSafeHtml
   */
  public function testRendersMarkdownToHtml(): void {
    $html = $this->renderer->toSafeHtml("# Title\n\nSome **bold** and a [link](https://example.com).");
    $this->assertStringContainsString('<h1>Title</h1>', $html);
    $this->assertStringContainsString('<strong>bold</strong>', $html);
    $this->assertStringContainsString('href="https://example.com"', $html);
  }

  /**
   * A list renders as a real <ul>/<li> structure.
   *
   * @covers ::toSafeHtml
   */
  public function testRendersLists(): void {
    $html = $this->renderer->toSafeHtml("- one\n- two");
    $this->assertStringContainsString('<ul>', $html);
    $this->assertSame(2, substr_count($html, '<li>'));
  }

  /**
   * Raw HTML embedded in the source is NOT rendered as live markup — a <script>
   * can never reach the page (html_input=escape + Xss::filterAdmin).
   *
   * @covers ::toSafeHtml
   */
  public function testStripsEmbeddedScript(): void {
    $html = $this->renderer->toSafeHtml("Hello\n\n<script>alert('xss')</script>");
    $this->assertStringNotContainsString('<script>', $html);
    $this->assertStringNotContainsString('</script>', $html);
  }

  /**
   * A javascript: link is dropped, not emitted as a clickable href.
   *
   * @covers ::toSafeHtml
   */
  public function testStripsUnsafeLink(): void {
    $html = $this->renderer->toSafeHtml('[click](javascript:alert(1))');
    $this->assertStringNotContainsString('javascript:', $html);
  }

  /**
   * Inline conversion renders the inline marks (link/bold/italic/code) — and
   * nothing else.
   *
   * @covers ::toInlineHtml
   */
  public function testInlineRendersInlineMarks(): void {
    $html = $this->renderer->toInlineHtml('A [link](https://example.com), **bold**, *em*, `code`.');
    $this->assertStringContainsString('<a href="https://example.com">link</a>', $html);
    $this->assertStringContainsString('<strong>bold</strong>', $html);
    $this->assertStringContainsString('<em>em</em>', $html);
    $this->assertStringContainsString('<code>code</code>', $html);
  }

  /**
   * Empty / whitespace-only inline source is the empty string.
   *
   * @covers ::toInlineHtml
   */
  public function testInlineEmptyIsEmpty(): void {
    $this->assertSame('', $this->renderer->toInlineHtml(''));
    $this->assertSame('', $this->renderer->toInlineHtml("  \n "));
  }

  /**
   * Inline mode does NOT parse block syntax — headings, lists, and blockquotes
   * stay literal text so a section's layout is never disturbed. (The leading
   * marker survives as text; no block element is produced.)
   *
   * @covers ::toInlineHtml
   */
  public function testInlineLeavesBlockSyntaxLiteral(): void {
    $html = $this->renderer->toInlineHtml("# Heading\n- item\n> quote");
    $this->assertStringNotContainsString('<h1', $html);
    $this->assertStringNotContainsString('<ul', $html);
    $this->assertStringNotContainsString('<li', $html);
    $this->assertStringNotContainsString('<blockquote', $html);
    $this->assertStringContainsString('# Heading', $html);
  }

  /**
   * A bare ampersand in inline source is escaped exactly ONCE (the bug behind
   * the literal "&amp;": store raw, render escaped once → browser shows "&").
   *
   * @covers ::toInlineHtml
   */
  public function testInlineEscapesAmpersandOnce(): void {
    $this->assertSame('AI Ethics &amp; Policy', $this->renderer->toInlineHtml('AI Ethics & Policy'));
  }

  /**
   * Images and unsafe links are dropped from inline output (scope: text marks
   * only; defence in depth atop the inline-only parser).
   *
   * @covers ::toInlineHtml
   */
  public function testInlineDropsImagesAndUnsafeLinks(): void {
    $this->assertSame('', $this->renderer->toInlineHtml('![alt](https://e.com/i.png)'));
    $unsafe = $this->renderer->toInlineHtml('[x](javascript:alert(1))');
    $this->assertStringNotContainsString('javascript:', $unsafe);
  }

  /**
   * With $breaks, source newlines become <br> (the legacy `body|nl2br`), and no
   * stray trailing <br> is appended.
   *
   * @covers ::toInlineHtml
   */
  public function testInlineBreaks(): void {
    $html = $this->renderer->toInlineHtml("one\ntwo", TRUE);
    $this->assertStringContainsString('one<br>', $html);
    $this->assertStringContainsString('two', $html);
    $this->assertStringEndsWith('two', $html);
  }

}
