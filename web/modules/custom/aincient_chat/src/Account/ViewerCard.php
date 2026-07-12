<?php

declare(strict_types=1);

namespace Drupal\aincient_chat\Account;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\user\UserDataInterface;

/**
 * Shapes a signed-in user into the console's read-only identity card.
 *
 * A read-only snapshot (earned display name, email, avatar, AIncient roles,
 * joined date) that lets the operator see who they are without leaving for
 * Drupal's /user/N page (DECISIONS 0157). Shared by the shell
 * (ConsoleController injects it into `window.aincientChat.viewer`) and the
 * self-service account pane (which returns a refreshed card after a save, so
 * the flyout updates without a reload).
 *
 * Names are EARNED, never scraped (study 02, Plate 14): `name` is the display
 * name a human deliberately entered (account pane / onboarding "What should we
 * call you?"), stored in user.data — the machine username never appears in
 * chrome, and the email's local part is never promoted to a name. With no
 * earned name, `name` is '' and the email becomes the card's single primary
 * line. No status pill (the signed-in user is by definition active) and no
 * tenure arithmetic — just "since Jul 2".
 *
 * The full User entity is loaded because a session's AccountInterface proxy
 * carries no email/created/picture.
 */
final class ViewerCard {

  /**
   * The user.data module bucket + key holding the earned display name.
   */
  public const NAME_MODULE = 'aincient_chat';
  public const NAME_KEY = 'display_name';

  /**
   * Longest earned name we store — a chrome budget, not an identity limit.
   */
  private const NAME_MAX = 60;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
    private readonly UserDataInterface $userData,
  ) {}

  /**
   * The identity card for the given account.
   *
   * @return array{name: string, email: ?string, avatarUrl: ?string, initial: string, roles: list<string>, since: string}
   */
  public function build(AccountInterface $account): array {
    /** @var \Drupal\user\UserInterface $user */
    $user = $this->entityTypeManager->getStorage('user')->load($account->id());
    $name = $this->displayName($account);
    $email = (string) $user->getEmail();
    $created = (int) $user->getCreatedTime();
    return [
      'name' => $name,
      'email' => $user->getEmail(),
      'avatarUrl' => $this->avatarUrl($user),
      // The avatar-fallback initial comes from whatever leads the card: the
      // earned name, else the email — never the machine username.
      'initial' => mb_strtoupper(mb_substr(trim($name !== '' ? $name : $email), 0, 1)),
      'roles' => $this->roleLabels($user),
      // "since Jul 2" — the year only when it isn't this one.
      'since' => $this->dateFormatter->format(
        $created,
        'custom',
        date('Y', $created) === date('Y') ? 'M j' : 'M j, Y',
      ),
    ];
  }

  /**
   * The user's EARNED display name, or '' when none was ever offered.
   */
  public function displayName(AccountInterface $account): string {
    $raw = $this->userData->get(self::NAME_MODULE, (int) $account->id(), self::NAME_KEY);
    return is_string($raw) ? trim($raw) : '';
  }

  /**
   * Store (or clear, with '') the earned display name after sanitizing.
   *
   * @return string
   *   The name as stored ('' when cleared or rejected by sanitation).
   */
  public function setDisplayName(AccountInterface $account, string $name): string {
    $clean = self::sanitizeName($name);
    if ($clean === '') {
      $this->userData->delete(self::NAME_MODULE, (int) $account->id(), self::NAME_KEY);
    }
    else {
      $this->userData->set(self::NAME_MODULE, (int) $account->id(), self::NAME_KEY, $clean);
    }
    return $clean;
  }

  /**
   * Maître-d' sanitation (study 02): trim it, cap the length, and if it looks
   * like a paste accident (an email, a URL, markup) fall back to NO name
   * rather than echoing it back forever. Shared by the account pane and the
   * onboarding "What should we call you?" field.
   */
  public static function sanitizeName(string $name): string {
    $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
    if ($name === '' || mb_strlen($name) > self::NAME_MAX) {
      return '';
    }
    if (preg_match('~[@<>]|://~', $name)) {
      return '';
    }
    return $name;
  }

  /**
   * Absolute URL of the user's picture (if the user_picture field is set), else
   * NULL so the front-end falls back to the initial chip.
   */
  public function avatarUrl(AccountInterface $account): ?string {
    if (!$account instanceof FieldableEntityInterface
      || !$account->hasField('user_picture')
      || $account->get('user_picture')->isEmpty()) {
      return NULL;
    }
    $file = $account->get('user_picture')->entity;
    if ($file === NULL) {
      return NULL;
    }
    return $this->fileUrlGenerator->generateAbsoluteString($file->getFileUri());
  }

  /**
   * The user's roles as human labels, restricted to what's meaningful in
   * AIncient. `getRoles(TRUE)` drops the locked authenticated/anonymous roles,
   * so the card surfaces only the assigned roles (Administrator, Content
   * editor, Content reviewer) — never "Authenticated user" noise.
   *
   * @return list<string>
   */
  public function roleLabels(AccountInterface $account): array {
    $ids = $account->getRoles(TRUE);
    if ($ids === []) {
      return [];
    }
    $roles = $this->entityTypeManager->getStorage('user_role')->loadMultiple($ids);
    // Owner words (study 02, Plate 14): the site's administrator is its
    // "Owner" in the chrome — "Administrator" is Drupal speaking, not us.
    return array_values(array_map(
      static fn($role) => $role->id() === 'administrator' ? 'Owner' : (string) $role->label(),
      $roles,
    ));
  }

}
