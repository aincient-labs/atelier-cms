<?php

declare(strict_types=1);

namespace Drupal\aincient_chat\Controller;

use Drupal\aincient_chat\Form\OneTimeLoginSwitchForm;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ClassResolverInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\user\Controller\UserController;
use Drupal\user\OneTimeAuthentication;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * One-time login links (`/user/reset/{uid}/{timestamp}/{hash}/login`) for a
 * browser that may already be signed in.
 *
 * - Anonymous: core's {@see UserController::resetPassLogin()}, unchanged.
 * - Signed in as the link's user: nothing to do — go on to the destination
 *   (the console by default). The link is not consumed.
 * - Signed in as someone else: a valid link asks to sign out and continue
 *   ({@see OneTimeLoginSwitchForm}); an invalid one says so. The link is only
 *   consumed after the switch is confirmed, so "Stay signed in" keeps it usable.
 */
final class OneTimeLoginController implements ContainerInjectionInterface {

  use StringTranslationTrait;

  public function __construct(
    private readonly AccountInterface $currentUser,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ClassResolverInterface $classResolver,
    private readonly FormBuilderInterface $formBuilder,
    private readonly OneTimeAuthentication $oneTimeAuthentication,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly TimeInterface $time,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('current_user'),
      $container->get('entity_type.manager'),
      $container->get('class_resolver'),
      $container->get('form_builder'),
      $container->get(OneTimeAuthentication::class),
      $container->get('config.factory'),
      $container->get('datetime.time'),
    );
  }

  /**
   * Route controller for `user.reset.login`.
   */
  public function login(string $uid, string $timestamp, string $hash, Request $request): array|RedirectResponse {
    if ($this->currentUser->isAnonymous()) {
      return $this->classResolver
        ->getInstanceFromDefinition(UserController::class)
        ->resetPassLogin($uid, $timestamp, $hash, $request);
    }
    $uid = (int) $uid;
    $timestamp = (int) $timestamp;

    // An explicit ?destination= wins over this target (RedirectResponseSubscriber).
    if ((int) $this->currentUser->id() === $uid) {
      return new RedirectResponse(self::homeUrl($this->currentUser));
    }

    $target = $this->entityTypeManager->getStorage('user')->load($uid);
    if (!$target instanceof UserInterface || !$this->isValid($target, $timestamp, $hash)) {
      return [
        '#title' => $this->t('Sign-in link expired'),
        '#cache' => ['max-age' => 0],
        'message' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $this->t('This sign-in link has expired or has already been used. Open the console from the Atelier manager again to get a new one.'),
        ],
        'back' => [
          '#type' => 'link',
          '#title' => $this->t('Continue as @name', ['@name' => $this->currentUser->getDisplayName()]),
          '#url' => Url::fromUri(self::homeUrl($this->currentUser)),
        ],
      ];
    }

    return $this->formBuilder->getForm(OneTimeLoginSwitchForm::class, $target, $timestamp, $hash);
  }

  /**
   * Where a signed-in user goes: the console when they may use it.
   */
  public static function homeUrl(AccountInterface $account): string {
    $route = $account->hasPermission('use aincient operator console') ? 'aincient_chat.console' : '<front>';
    return Url::fromRoute($route)->setAbsolute()->toString();
  }

  /**
   * The same checks core's determineErrorRedirect() makes, without messages.
   */
  private function isValid(UserInterface $user, int $timestamp, string $hash): bool {
    if (!$user->isActive()) {
      return FALSE;
    }
    $timeout = (int) $this->configFactory->get('user.settings')->get('password_reset_timeout');
    if ($user->getLastLoginTime() && $this->time->getRequestTime() - $timestamp > $timeout) {
      return FALSE;
    }
    return $this->oneTimeAuthentication->verifyHmac($user, $timestamp, $hash, $timeout);
  }

}
