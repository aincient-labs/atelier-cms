<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_core\Unit;

use Drupal\aincient_core\CapabilitySet;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests the three product verbs → the chips AND the system-prompt block.
 *
 * The point of the class under test is that ONE table renders both surfaces, so
 * they are tested together: a chip and a model can only contradict each other if
 * the words come from two places, and that contradiction (a lit "Draw" over an
 * agent that says it cannot draw) is the bug this replaced, one level up.
 *
 * Every combination is exercised, because "generated prose beats a hand-written
 * variant per state" is only true if every state is actually generated.
 *
 * @covers \Drupal\aincient_core\CapabilitySet
 * @group aincient
 */
final class CapabilitySetTest extends UnitTestCase {

  private const SETUP_URL = '/admin/config/aincient/models';

  /**
   * The eight capability states, as [write, describe, draw].
   *
   * @return iterable<string, array{bool, bool, bool}>
   */
  public static function states(): iterable {
    foreach ([FALSE, TRUE] as $write) {
      foreach ([FALSE, TRUE] as $describe) {
        foreach ([FALSE, TRUE] as $draw) {
          $name = sprintf(
            'write:%s describe:%s draw:%s',
            $write ? 'on' : 'off',
            $describe ? 'on' : 'off',
            $draw ? 'on' : 'off',
          );
          yield $name => [$write, $describe, $draw];
        }
      }
    }
  }

  /**
   * Three chips, always the same three, in the same order — in every state.
   *
   * The row is a learnable part of the console: it must not appear, reorder or
   * grow depending on how the site happens to be configured.
   */
  #[DataProvider('states')]
  public function testChipsAreTheSameThreeVerbsInEveryState(bool $write, bool $describe, bool $draw): void {
    $chips = (new CapabilitySet($write, $describe, $draw))->chips(self::SETUP_URL);

    $this->assertSame(['write', 'describe', 'draw'], array_column($chips, 'id'));
    $this->assertSame(['Write', 'Describe', 'Draw'], array_column($chips, 'label'));
    foreach ($chips as $chip) {
      $this->assertNotSame('', $chip['means'], 'Every chip explains itself, lit or not.');
    }
  }

  /**
   * Exactly two states per chip: available (no hint, no link) or needs-setup
   * (both). There is deliberately no third, hedged state.
   */
  #[DataProvider('states')]
  public function testEveryChipIsEitherAvailableOrNeedsSetup(bool $write, bool $describe, bool $draw): void {
    $expected = ['write' => $write, 'describe' => $describe, 'draw' => $draw];
    foreach ((new CapabilitySet($write, $describe, $draw))->chips(self::SETUP_URL) as $chip) {
      $this->assertSame($expected[$chip['id']], $chip['available']);
      if ($chip['available']) {
        $this->assertSame('', $chip['hint'], 'A kept promise needs no explanation.');
        $this->assertSame('', $chip['setupUrl'], 'Nothing to go and fix.');
        continue;
      }
      $this->assertNotSame('', $chip['hint'], 'A dimmed chip must say what is missing.');
      $this->assertSame(self::SETUP_URL, $chip['setupUrl'], 'And where to fix it.');
    }
  }

  /**
   * The room's reason rides along on every chip, in every state.
   *
   * A chip dims for two independent reasons and the console picks between them:
   * the install cannot (`hint`), or the room's agent has no tool for it
   * (`unusedHint`). The second is a fact about the ROOM, so it does not depend on
   * install state and must be there even on a chip that is lit — which is exactly
   * when an unused verb is the only thing left that can dim it.
   */
  #[DataProvider('states')]
  public function testEveryChipCarriesTheRoomReasonWhateverTheInstallCanDo(bool $write, bool $describe, bool $draw): void {
    foreach ((new CapabilitySet($write, $describe, $draw))->chips(self::SETUP_URL) as $chip) {
      $this->assertNotSame('', $chip['unusedHint'], 'A room without the tool still has something to say.');
    }
  }

  /**
   * And it says it in the same product language, about the CHAT, not the site.
   */
  public function testUnusedHintsSpeakAboutTheRoom(): void {
    $hints = array_column((new CapabilitySet(TRUE, TRUE, TRUE))->chips(self::SETUP_URL), 'unusedHint', 'id');

    $this->assertSame('not part of this chat', $hints['write']);
    $this->assertSame('this chat doesn’t read images', $hints['describe']);
    $this->assertSame('this chat doesn’t make images', $hints['draw']);
  }

  /**
   * The needs-setup hints are the product's own words, not model vocabulary.
   */
  public function testHintsSpeakProductLanguage(): void {
    $chips = (new CapabilitySet(FALSE, FALSE, FALSE))->chips(self::SETUP_URL);
    $hints = array_column($chips, 'hint', 'id');

    $this->assertSame('needs a connected model', $hints['write']);
    $this->assertSame('needs a model that can read images', $hints['describe']);
    $this->assertSame('needs an image provider', $hints['draw']);
  }

  /**
   * The URL is passed in, never spelled here — the console's IA has moved once.
   */
  public function testSetupUrlIsWhateverTheCallerResolved(): void {
    $chips = (new CapabilitySet(TRUE, FALSE, FALSE))->chips('/somewhere/else');
    $describe = array_column($chips, 'setupUrl', 'id')['describe'];

    $this->assertSame('/somewhere/else', $describe);
  }

  /**
   * The prompt block carries one clause per verb, in every state — and its
   * clauses agree with the chips, because they are the same row.
   */
  #[DataProvider('states')]
  public function testPromptLineHasOneClausePerVerbAndAgreesWithTheChips(bool $write, bool $describe, bool $draw): void {
    $set = new CapabilitySet($write, $describe, $draw);
    $lines = explode("\n", $set->promptLine());

    // A heading plus exactly three clauses — never a state with more or fewer.
    $this->assertCount(4, $lines);
    $this->assertStringContainsString('CAPABILITIES OF THIS INSTALL', $lines[0]);
    foreach (array_slice($lines, 1) as $clause) {
      $this->assertStringStartsWith('- ', $clause);
    }

    // The chips and the clauses are driven by the same booleans: a "cannot" in
    // the prompt implies a needs-setup chip, and vice versa.
    $clauses = array_values(array_slice($lines, 1));
    foreach ($set->chips(self::SETUP_URL) as $i => $chip) {
      $cannot = str_contains($clauses[$i], 'You cannot');
      $this->assertSame(!$chip['available'], $cannot, $chip['id'] . ': chip and prompt must agree.');
    }
  }

  /**
   * A fully capable install is told what it can do, and nothing it cannot.
   */
  public function testPromptLineForAFullyCapableInstall(): void {
    $line = (new CapabilitySet(TRUE, TRUE, TRUE))->promptLine();

    $this->assertStringNotContainsString('You cannot', $line);
    $this->assertStringContainsString('You can write', $line);
    $this->assertStringContainsString('read an image', $line);
    $this->assertStringContainsString('generate and edit images', $line);
  }

  /**
   * The words-only install — the state that used to lose the room entirely.
   *
   * It keeps its rail, is told plainly that pictures are out, and is told not to
   * offer them: the agent's half of "the chips and the model cannot disagree".
   */
  public function testPromptLineForAWordsOnlyInstall(): void {
    $line = (new CapabilitySet(TRUE, FALSE, FALSE))->promptLine();

    $this->assertStringContainsString('You can write', $line);
    $this->assertStringContainsString('cannot reliably read images', $line);
    $this->assertStringContainsString('cannot generate images', $line);
    $this->assertStringContainsString('Do not offer', $line);
  }

  /**
   * has() answers per verb, and shrugs at anything that is not one.
   */
  public function testHasAnswersPerVerb(): void {
    $set = new CapabilitySet(TRUE, FALSE, TRUE);

    $this->assertTrue($set->has(CapabilitySet::WRITE));
    $this->assertFalse($set->has(CapabilitySet::DESCRIBE));
    $this->assertTrue($set->has(CapabilitySet::DRAW));
    $this->assertFalse($set->has('teleport'));
  }

}
