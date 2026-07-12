<?php

declare(strict_types=1);

namespace Drupal\aincient_pages\Controller;

use Drupal\aincient_pages\BrandRevisioner;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lists the brand revision history with a one-click restore per entry.
 *
 * The newest revision mirrors the live brand, so it's marked "current" and has
 * no restore link; every earlier snapshot can be restored.
 */
final class BrandHistoryController extends ControllerBase {

  public function __construct(
    private readonly BrandRevisioner $revisioner,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('aincient_pages.brand_revisioner'),
      $container->get('date.formatter'),
    );
  }

  public function list(): array {
    $rows = [];
    $first = TRUE;
    foreach ($this->revisioner->recent() as $revision) {
      $isCurrent = $first;
      $first = FALSE;

      $when = $this->dateFormatter->format((int) $revision->get('created')->value, 'short');
      $owner = $revision->getOwner();

      $ops = [];
      if (!$isCurrent) {
        $ops['restore'] = [
          'title' => $this->t('Restore'),
          'url' => Url::fromRoute('aincient_pages.brand_restore', ['revision' => $revision->id()]),
        ];
      }

      $rows[] = [
        $isCurrent ? $this->t('@when (current)', ['@when' => $when]) : $when,
        $owner ? $owner->getDisplayName() : $this->t('System'),
        (string) $revision->get('summary')->value ?: $this->t('(no change recorded)'),
        ['data' => $ops ? ['#type' => 'operations', '#links' => $ops] : ['#markup' => '—']],
      ];
    }

    return [
      '#type' => 'table',
      '#header' => [
        $this->t('When'),
        $this->t('Changed by'),
        $this->t('What changed'),
        $this->t('Operations'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No brand changes recorded yet.'),
      '#caption' => $this->t('Every brand change — from the editor, from chat, or on config import — is snapshotted here. Restore any past look in one click; the current brand is kept, so a restore is always reversible.'),
    ];
  }

}
