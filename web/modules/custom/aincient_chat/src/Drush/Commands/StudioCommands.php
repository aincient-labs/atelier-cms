<?php

declare(strict_types=1);

namespace Drupal\aincient_chat\Drush\Commands;

use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\aincient_chat\Studio\StudioInterface;
use Drupal\aincient_chat\Studio\StudioManager;
use Drupal\aincient_chat\Studio\StudioSwitch;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Drush commands for the console studios.
 *
 * The manifest is a studio's index (plans/studio-modules.md "The manifest"),
 * so "what does this studio ship, and is it on" is answerable from the shell
 * without reading code. The on/off commands write the same switch the console
 * reads ({@see StudioSwitch}); switching off is never an uninstall (DECISIONS
 * 0430), so neither command touches a module.
 */
final class StudioCommands extends DrushCommands {

  private const FIELDS = [
    'id' => 'ID',
    'label' => 'Label',
    'provider' => 'Provider',
    'enabled' => 'Enabled',
    'weight' => 'Weight',
    'open' => 'Open',
    'ui' => 'UI',
    'flows' => 'Flows',
    'capabilities' => 'Capabilities',
    'demo' => 'Demo',
  ];

  public function __construct(
    private readonly StudioManager $studios,
    private readonly StudioSwitch $switch,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('plugin.manager.aincient.studios'),
      $container->get('aincient_chat.studio_switch'),
    );
  }

  /**
   * Show every studio (or one) with what its manifest declares and its switch.
   */
  #[CLI\Command(name: 'atelier:studio-info')]
  #[CLI\Argument(name: 'id', description: 'A studio id; omit to list every studio.')]
  #[CLI\FieldLabels(labels: self::FIELDS)]
  #[CLI\DefaultTableFields(fields: ['id', 'label', 'provider', 'enabled', 'weight', 'open', 'ui', 'flows', 'capabilities', 'demo'])]
  #[CLI\Usage(name: 'drush atelier:studio-info content --format=yaml', description: 'One studio, as YAML.')]
  public function info(?string $id = NULL): RowsOfFields {
    $studios = $id === NULL ? $this->studios->studios() : [$id => $this->require($id)];
    return new RowsOfFields(array_values(array_map($this->row(...), $studios)));
  }

  /**
   * Switch a studio on.
   */
  #[CLI\Command(name: 'atelier:studio-enable')]
  #[CLI\Argument(name: 'id', description: 'The studio id.')]
  public function enable(string $id): void {
    $this->toggle($id, TRUE);
  }

  /**
   * Switch a studio off. Its data and module stay; it leaves the console.
   */
  #[CLI\Command(name: 'atelier:studio-disable')]
  #[CLI\Argument(name: 'id', description: 'The studio id.')]
  public function disable(string $id): void {
    $this->toggle($id, FALSE);
  }

  private function toggle(string $id, bool $enabled): void {
    // Refused rather than recorded: the switch would accept any string, and a
    // typo'd id sitting in `disabled_studios` switches nothing off.
    $this->require($id);
    $this->switch->setEnabled($id, $enabled);
    $this->logger()->success(dt('Studio @id is now @state.', [
      '@id' => $id,
      '@state' => $this->switch->isEnabled($id) ? 'ON' : 'OFF',
    ]));
  }

  private function require(string $id): StudioInterface {
    $studio = $this->studios->get($id);
    if ($studio === NULL) {
      throw new \InvalidArgumentException(sprintf('Unknown studio "%s". Known: %s.', $id, implode(', ', $this->studios->keys())));
    }
    return $studio;
  }

  /**
   * @return array<string, string|int>
   */
  private function row(StudioInterface $studio): array {
    return [
      'id' => $studio->id(),
      'label' => $studio->label(),
      'provider' => (string) ($studio->getPluginDefinition()['provider'] ?? ''),
      'enabled' => $this->switch->isEnabled($studio->id()) ? 'yes' : 'no',
      'weight' => $studio->weight(),
      'open' => $studio->isOpen() ? 'yes' : '',
      'ui' => $studio->uiEntry() ?? '',
      'flows' => implode(', ', $studio->flows()),
      'capabilities' => implode(', ', $studio->capabilities()),
      'demo' => $studio->demoPath() ?? '',
    ];
  }

}
