<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_core\Traits;

use Drupal\aincient_core\Inference\MessageMapper;
use Drupal\aincient_core\Usage\ModelPricing;
use Drupal\aincient_core\Inference\ResultUnpacker;
use Drupal\aincient_core\Usage\UsageRecorder;
use Drupal\aincient_core\Usage\UsageLog;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\Insert;
use Drupal\Core\Session\AccountProxyInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * A recorder that throws its rows away, for unit tests about something else.
 *
 * THIS IS A STUB, AND IT SAYS SO. It used to be defensible as a production
 * state: the recorder took a nullable `ai_metering` QuotaManager, so "no backing
 * store" was what a site without that module really looked like. That is no
 * longer true — {@see UsageLog} is Atelier's own, the table ships with
 * `aincient_core`, and the recorder takes the writer as a HARD dependency. A
 * recorder with nowhere to write is now a test fixture and nothing else, so this
 * is named and documented as one rather than dressed up as a configuration
 * somebody could be running.
 *
 * WHY THE SEAM TESTS STILL WANT IT. Every inference seam takes a
 * {@see UsageRecorder}, but the tests that use this trait pin the option
 * dialect, the result union and the status vocabulary — none of them asserts
 * anything about a recorded row, and none of them should: the row is
 * UsageRecorderTest's subject and the wired path is UsageMeteringTest's. What
 * this fixture has to guarantee is only that the seam under test survives
 * meeting a recorder, which means the write must go nowhere and must not throw.
 * {@see ModelPricing} arrives over an empty rate table for the same reason —
 * it is real, but nothing here reads what it answers.
 */
trait UnmeteredInferenceTrait {

  /**
   * A recorder whose rows go nowhere.
   */
  private function unmeteredRecorder(): UsageRecorder {
    return new UsageRecorder(
      new ResultUnpacker(new MessageMapper()),
      new ModelPricing($this->emptyRateTable()),
      $this->createMock(AccountProxyInterface::class),
      new NullLogger(),
      $this->discardingUsageLog(),
    );
  }

  /**
   * A real {@see UsageLog} over a database that accepts and forgets.
   *
   * The writer is `final`, so it cannot be mocked and is not worth un-finalling
   * for a fixture: assembled over doubles it stays the class production runs,
   * which means a change to its constructor breaks these tests loudly instead of
   * letting a stale double keep them green.
   */
  private function discardingUsageLog(): UsageLog {
    $insert = $this->createMock(Insert::class);
    $insert->method('fields')->willReturnSelf();
    $insert->method('execute')->willReturn(NULL);

    $database = $this->createMock(Connection::class);
    $database->method('insert')->willReturn($insert);

    // Symfony's dispatcher returns the event it dispatched; a mock that answered
    // NULL would be a TypeError inside the recorder's try/catch, i.e. a fixture
    // that silently converted every seam test into a swallowed-warning test.
    $dispatcher = $this->createMock(EventDispatcherInterface::class);
    $dispatcher->method('dispatch')->willReturnArgument(0);

    return new UsageLog(
      $database,
      $this->createMock(TimeInterface::class),
      $this->createMock(CacheTagsInvalidatorInterface::class),
      $dispatcher,
    );
  }

  /**
   * A config factory answering "no rates", built without UnitTestCase helpers.
   *
   * `getConfigFactoryStub()` lives on UnitTestCase and several users of this
   * trait extend plain PHPUnit TestCase, so the double is assembled by hand.
   */
  private function emptyRateTable(): ConfigFactoryInterface {
    $config = $this->createMock(Config::class);
    $config->method('get')->willReturn([]);
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->willReturn($config);
    return $factory;
  }

}
