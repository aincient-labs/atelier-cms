<?php

declare(strict_types=1);

namespace Drupal\aincient_pages\Form;

use Drupal\aincient_pages\BrandRevisioner;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Confirm + perform a restore of the brand to a past revision.
 */
final class BrandRestoreForm extends ConfirmFormBase {

  private ?int $revisionId = NULL;

  public function __construct(
    private readonly BrandRevisioner $revisioner,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('aincient_pages.brand_revisioner'),
      $container->get('entity_type.manager'),
      $container->get('date.formatter'),
    );
  }

  public function getFormId(): string {
    return 'aincient_pages_brand_restore';
  }

  public function buildForm(array $form, FormStateInterface $form_state, $revision = NULL): array {
    $this->revisionId = $revision !== NULL ? (int) $revision : NULL;
    return parent::buildForm($form, $form_state);
  }

  public function getQuestion(): \Stringable|string {
    $revision = $this->revisionId
      ? $this->entityTypeManager->getStorage(BrandRevisioner::ENTITY_TYPE)->load($this->revisionId)
      : NULL;
    if (!$revision) {
      return $this->t('Restore this brand revision?');
    }
    $when = $this->dateFormatter->format((int) $revision->get('created')->value, 'short');
    return $this->t('Restore the brand to its state from @when?', ['@when' => $when]);
  }

  public function getDescription(): \Stringable|string {
    return $this->t('This replaces the current brand (colours, typography, logo, fonts, footer note) with the saved snapshot and applies it to every page immediately. The current brand is kept in history, so you can switch back.');
  }

  public function getConfirmText(): \Stringable|string {
    return $this->t('Restore');
  }

  public function getCancelUrl(): Url {
    return Url::fromRoute('aincient_pages.brand_history');
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    if ($this->revisionId !== NULL && $this->revisioner->restore($this->revisionId)) {
      $this->messenger()->addStatus($this->t('Brand restored. The new look applies to every page immediately.'));
    }
    else {
      $this->messenger()->addError($this->t('That brand revision could not be restored.'));
    }
    $form_state->setRedirect('aincient_pages.brand_history');
  }

}
