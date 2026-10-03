<?php

declare(strict_types=1);

namespace Drupal\aincient_chat\EventSubscriber;

use Drupal\aincient_chat\Studio\StudioDemoContent;
use Drupal\aincient_chat\Studio\StudioSwitch;
use Drupal\Core\Config\ConfigCrudEvent;
use Drupal\Core\Config\ConfigEvents;
use Drupal\Core\Config\ConfigInstallerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Seeds a studio's demo content the first time its switch flips to ON.
 *
 * Listens to the switch's own config rather than to the writers of it, so
 * every on-ramp — `drush atelier:studio-enable`, the toggle UI when it comes,
 * a hand `config:set` — seeds the same way, and none of them has to know
 * that demo content exists (plans/studio-modules.md "Demo content").
 *
 * Silent during a config sync: an import of `disabled_studios` on an upgrade
 * is the appliance converging on its source, not an operator opening a room,
 * and content must never appear as a side effect of `cim`. Install time is
 * the other first-enable and is handled by the install hook, because a studio
 * that ships on never touches this config at all.
 */
final class StudioDemoSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly StudioDemoContent $demo,
    private readonly ConfigInstallerInterface $configInstaller,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [ConfigEvents::SAVE => 'onSave'];
  }

  public function onSave(ConfigCrudEvent $event): void {
    $config = $event->getConfig();
    if ($config->getName() !== StudioSwitch::CONFIG || !$event->isChanged(StudioSwitch::KEY) || $this->configInstaller->isSyncing()) {
      return;
    }
    $before = $this->ids($config->getOriginal(StudioSwitch::KEY));
    $after = $this->ids($config->get(StudioSwitch::KEY));
    foreach (array_diff($before, $after) as $id) {
      $this->demo->import($id);
    }
  }

  /**
   * @return list<string>
   */
  private function ids(mixed $raw): array {
    return array_values(array_map('strval', is_array($raw) ? $raw : []));
  }

}
