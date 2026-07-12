<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_pages\Unit;

use Drupal\aincient_pages\SchemaLinter;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The structural "ally" for the page agent — advisory lint of section props.
 *
 * @group aincient
 * @coversDefaultClass \Drupal\aincient_pages\SchemaLinter
 */
#[RunTestsInSeparateProcesses]
final class SchemaLinterTest extends UnitTestCase {

  /**
   * The reported bug: quote rows under `testimonials` (the component name) with
   * `title` for the role. The lint must catch BOTH and point at the right prop.
   */
  public function testCatchesMisplacedTestimonialData(): void {
    $issues = SchemaLinter::lint('testimonials', [
      'heading' => 'Loved by cooks',
      'testimonials' => [
        ['quote' => 'Great!', 'author' => 'Sam', 'title' => 'Chef'],
      ],
    ]);
    $joined = implode("\n", $issues);
    // Unknown top-level prop, redirected to the real one.
    $this->assertStringContainsString('has no prop "testimonials"', $joined);
    $this->assertStringContainsString('Did you mean "quotes"', $joined);
    // The misplaced rows still aren't validated as `quotes` (they're ignored),
    // so the linter flags that quotes is the empty content prop is suppressed
    // because we redirected — assert we didn't ALSO double-nag.
    $this->assertStringNotContainsString('has no "quotes" yet', $joined);
  }

  /**
   * A correctly-shaped section produces no advisories.
   */
  public function testCleanSectionHasNoIssues(): void {
    $issues = SchemaLinter::lint('testimonials', [
      'tone' => 'muted',
      'heading' => 'Loved by cooks',
      'quotes' => [
        ['quote' => 'Great!', 'author' => 'Sam', 'role' => 'Chef'],
      ],
    ]);
    $this->assertSame([], $issues);
  }

  /**
   * A bad row field is flagged with the allowed field list.
   */
  public function testFlagsUnknownRowField(): void {
    $issues = SchemaLinter::lint('testimonials', [
      'quotes' => [
        ['quote' => 'Great!', 'author' => 'Sam', 'title' => 'Chef'],
      ],
    ]);
    $joined = implode("\n", $issues);
    $this->assertStringContainsString('rows take {quote,author,role,avatar}', $joined);
    $this->assertStringContainsString('title', $joined);
  }

  /**
   * A new section missing its content array renders empty — flagged for a full
   * section (add_section), suppressed for a partial edit (update_section).
   */
  public function testEmptyContentArrayFlaggedOnlyForFullSection(): void {
    $full = SchemaLinter::lint('testimonials', ['heading' => 'Loved by cooks'], TRUE);
    $this->assertStringContainsString('renders empty', implode("\n", $full));

    $partial = SchemaLinter::lint('testimonials', ['heading' => 'Loved by cooks'], FALSE);
    $this->assertSame([], $partial);
  }

  /**
   * An unknown component isn't this layer's job (the allow-list handles it).
   */
  public function testUnknownComponentIsSilent(): void {
    $this->assertSame([], SchemaLinter::lint('nope', ['foo' => 'bar']));
  }

}
