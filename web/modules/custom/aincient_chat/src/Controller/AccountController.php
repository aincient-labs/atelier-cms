<?php

declare(strict_types=1);

namespace Drupal\aincient_chat\Controller;

use Drupal\aincient_chat\Account\ViewerCard;
use Drupal\Component\Utility\Bytes;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Datetime\TimeZoneFormHelper;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Password\PasswordInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Utility\Token;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\ConstraintViolationListInterface;

/**
 * Self-service "My Account" for the console (DECISIONS 0157, Tier 2).
 *
 * A thin JSON API that lets the operator edit their own email, password,
 * timezone and avatar from the React account pane — so they never have to visit
 * Drupal's brand-themed-but-still-Drupal /user/N/edit form. The security is NOT
 * reimplemented in JS: it lives here, server-side, and leans on Drupal's own
 * machinery —
 *  - current-password re-auth for the protected fields (email/password) via the
 *    core `password` checker + the User entity's ProtectedUserFieldConstraint
 *    (we verify, then set `_skipProtectedUserFieldConstraint`, exactly as
 *    \Drupal\user\AccountForm does after a valid current-password);
 *  - email format/uniqueness, timezone allowed-values and any password-policy
 *    constraints via `$user->validate()` (typed-data validation);
 *  - the avatar upload validated against the `user_picture` field's own limits.
 *
 * The target is ALWAYS the signed-in user (no admin-edit-other-user surface —
 * that's Tier 3, deferred to brand-themed Drupal /admin/people). The route
 * permission (`use aincient operator console`) plus same-origin cookie auth is
 * the gate; there is no cross-account id in any path or body.
 */
final class AccountController implements ContainerInjectionInterface {

  public function __construct(
    private readonly AccountInterface $currentUser,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly PasswordInterface $passwordChecker,
    private readonly FileSystemInterface $fileSystem,
    private readonly Token $token,
    private readonly ViewerCard $viewerCard,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('current_user'),
      $container->get('entity_type.manager'),
      $container->get('password'),
      $container->get('file_system'),
      $container->get('token'),
      $container->get('aincient_chat.viewer_card'),
    );
  }

  /**
   * GET /aincient/account — the editable snapshot + option lists for the pane.
   */
  public function get(): JsonResponse {
    $account = $this->loadAccount();
    return new JsonResponse([
      // The earned display name (study 02, Plate 15's "Name · optional" field);
      // '' when the owner never offered one.
      'name' => $this->viewerCard->displayName($account),
      'mail' => $account->getEmail(),
      'timezone' => $account->getTimeZone(),
      'avatarUrl' => $this->viewerCard->avatarUrl($account),
      // Region-grouped { region: { zone: label } | label } for the <select>.
      'timezones' => $this->timezoneOptions(),
      'avatarExtensions' => $this->avatarExtensions($account),
      // Whether editing the protected fields (email/password) demands the
      // current password. False for an admin (who may reset without it), so the
      // pane hides the field — mirroring AccountForm.
      'requiresCurrentPassword' => !$this->currentUser->hasPermission('administer users'),
      'viewer' => $this->viewerCard->build($account),
    ]);
  }

  /**
   * POST /aincient/account — save name / email / password / timezone.
   *
   * Body: { name?, mail?, timezone?, currentPass?, newPass? }. Returns
   * { ok: true, name, mail, timezone, viewer } on success, or
   * { errors: { field: message } } with a 422 when validation fails (per-field
   * so the pane can attach each message to its input).
   */
  public function save(Request $request): JsonResponse {
    $data = $this->body($request);
    $account = $this->loadAccount();

    // The earned display name (Plate 15): no re-auth needed — a name is
    // presentation, not credentials. Written AFTER validation passes so a
    // rejected email/password never half-applies the save.
    $name = isset($data['name']) && is_string($data['name']) ? $data['name'] : NULL;

    $mail = isset($data['mail']) && is_string($data['mail']) ? trim($data['mail']) : NULL;
    $newPass = isset($data['newPass']) && is_string($data['newPass']) && $data['newPass'] !== ''
      ? $data['newPass']
      : NULL;
    $timezone = isset($data['timezone']) && is_string($data['timezone']) ? $data['timezone'] : NULL;

    $changingMail = $mail !== NULL && $mail !== (string) $account->getEmail();
    $changingPass = $newPass !== NULL;

    // Protected-field gate: changing email or password requires the current
    // password (unless the user may administer users). Verify server-side, then
    // waive the entity constraint exactly as AccountForm does — never trust the
    // client to have re-authed.
    if ($changingMail || $changingPass) {
      if ($this->currentUser->hasPermission('administer users')) {
        $account->_skipProtectedUserFieldConstraint = TRUE;
      }
      else {
        $current = isset($data['currentPass']) && is_string($data['currentPass']) ? $data['currentPass'] : '';
        if ($current === '' || !$this->passwordChecker->check($current, $account->getPassword())) {
          return new JsonResponse([
            'errors' => ['currentPass' => 'Your current password is missing or incorrect.'],
          ], 422);
        }
        $account->_skipProtectedUserFieldConstraint = TRUE;
      }
    }

    if ($changingMail) {
      $account->setEmail($mail);
    }
    if ($changingPass) {
      $account->setPassword($newPass);
    }
    if ($timezone !== NULL) {
      $account->set('timezone', $timezone);
    }

    // Typed-data validation: email format/uniqueness, timezone allowed value,
    // any password-policy constraints. Map each violation to its top-level field
    // so the pane can render it inline; a violation drops the whole save (no
    // partial writes).
    $errors = $this->violationsToErrors($account->validate());
    if ($errors !== []) {
      return new JsonResponse(['errors' => $errors], 422);
    }

    $account->save();

    // Sanitized maître-d' style in ViewerCard ('' clears; a paste accident
    // stores nothing rather than echoing back forever).
    if ($name !== NULL) {
      $this->viewerCard->setDisplayName($account, $name);
    }

    return new JsonResponse([
      'ok' => TRUE,
      'name' => $this->viewerCard->displayName($account),
      'mail' => $account->getEmail(),
      'timezone' => $account->getTimeZone(),
      'viewer' => $this->viewerCard->build($account),
    ]);
  }

  /**
   * POST /aincient/account/avatar — set the user picture from a multipart upload.
   *
   * Validated against the `user_picture` field's own extension/size limits, so
   * the pane inherits the same constraints as Drupal's profile form.
   */
  public function uploadAvatar(Request $request): JsonResponse {
    $account = $this->loadAccount();
    if (!$account->hasField('user_picture')) {
      return new JsonResponse(['error' => 'Avatars are not enabled on this site.'], 404);
    }
    $upload = $request->files->get('file');
    if ($upload === NULL) {
      return new JsonResponse(['error' => 'Expected a multipart "file" upload.'], 400);
    }
    try {
      $file = $this->saveAvatarFile($account, $upload);
    }
    catch (\RuntimeException $e) {
      return new JsonResponse(['errors' => ['avatar' => $e->getMessage()]], 422);
    }
    $account->set('user_picture', ['target_id' => $file->id()]);
    // Reuse the field's constraints (e.g. image resolution) via typed-data.
    $errors = $this->violationsToErrors($account->validate());
    if ($errors !== []) {
      $file->delete();
      return new JsonResponse(['errors' => $errors], 422);
    }
    $account->save();
    return new JsonResponse([
      'ok' => TRUE,
      'avatarUrl' => $this->viewerCard->avatarUrl($account),
      'viewer' => $this->viewerCard->build($account),
    ]);
  }

  /**
   * DELETE /aincient/account/avatar — clear the user picture.
   */
  public function deleteAvatar(): JsonResponse {
    $account = $this->loadAccount();
    if ($account->hasField('user_picture') && !$account->get('user_picture')->isEmpty()) {
      $account->set('user_picture', NULL);
      $account->save();
    }
    return new JsonResponse([
      'ok' => TRUE,
      'avatarUrl' => NULL,
      'viewer' => $this->viewerCard->build($account),
    ]);
  }

  /**
   * Loads the signed-in user as a full, editable entity (the session proxy
   * carries no fields).
   */
  private function loadAccount(): UserInterface {
    /** @var \Drupal\user\UserInterface $account */
    $account = $this->entityTypeManager->getStorage('user')->load($this->currentUser->id());
    return $account;
  }

  /**
   * Region-grouped timezone options for the pane's <select>.
   *
   * @return array<string, string|array<string, string>>
   */
  private function timezoneOptions(): array {
    $out = [];
    foreach (TimeZoneFormHelper::getOptionsListByRegion() as $region => $value) {
      if (is_array($value)) {
        $out[$region] = array_map(static fn($label) => (string) $label, $value);
      }
      else {
        $out[$region] = (string) $value;
      }
    }
    return $out;
  }

  /**
   * The allowed avatar extensions, from the field's own settings.
   */
  private function avatarExtensions(UserInterface $account): string {
    if (!$account->hasField('user_picture')) {
      return '';
    }
    $settings = $account->get('user_picture')->getSettings();
    return (string) ($settings['file_extensions'] ?? 'png gif jpg jpeg');
  }

  /**
   * Validates + persists an uploaded avatar, mirroring MediaRepository's manual
   * approach (extension + size against the field settings, land in the field's
   * configured directory). Returns the saved File entity.
   */
  private function saveAvatarFile(UserInterface $account, $upload) {
    if (!$upload->isValid()) {
      throw new \RuntimeException('The upload did not complete.');
    }
    $settings = $account->get('user_picture')->getSettings();
    $extensions = preg_split('/\s+/', trim((string) ($settings['file_extensions'] ?? 'png gif jpg jpeg webp')));
    $ext = strtolower((string) $upload->getClientOriginalExtension());
    if ($ext === '' || !in_array($ext, $extensions, TRUE)) {
      throw new \RuntimeException(sprintf('Unsupported file type “.%s” — allowed: %s.', $ext, implode(', ', $extensions)));
    }
    $maxBytes = $this->maxBytes($settings['max_filesize'] ?? NULL);
    if ($maxBytes > 0 && $upload->getSize() > $maxBytes) {
      throw new \RuntimeException('The image is larger than the allowed maximum.');
    }
    if (@getimagesize($upload->getRealPath()) === FALSE) {
      throw new \RuntimeException('That file is not a valid image.');
    }

    $scheme = (string) ($settings['uri_scheme'] ?? 'public');
    $directory = $scheme . '://' . $this->token->replace((string) ($settings['file_directory'] ?? ''));
    $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    $basename = basename($upload->getClientOriginalName());
    $destination = $this->fileSystem->createFilename($basename, $directory);
    $uri = $this->fileSystem->move($upload->getRealPath(), $destination, FileExists::Rename);

    $file = $this->entityTypeManager->getStorage('file')->create([
      'uri' => $uri,
      'status' => 1,
    ]);
    $file->save();
    return $file;
  }

  /**
   * A field's `max_filesize` setting ("2 MB", "500 KB", …) in bytes; 0 if unset.
   */
  private function maxBytes(?string $setting): int {
    if ($setting === NULL || trim($setting) === '') {
      return 0;
    }
    return (int) Bytes::toNumber($setting);
  }

  /**
   * Reduces a constraint-violation list to a { field: message } map, keyed by
   * the violated property's top-level field name.
   *
   * @return array<string, string>
   */
  private function violationsToErrors(ConstraintViolationListInterface $violations): array {
    $errors = [];
    foreach ($violations as $violation) {
      $path = $violation->getPropertyPath();
      // "mail.0.value" → "mail"; empty path → a generic key.
      $field = $path === '' ? 'form' : explode('.', $path)[0];
      // First message per field wins (the most specific / user-facing one).
      if (!isset($errors[$field])) {
        $errors[$field] = strip_tags((string) $violation->getMessage());
      }
    }
    return $errors;
  }

  /**
   * Decodes the JSON request body to an array (empty on malformed input).
   *
   * @return array<string, mixed>
   */
  private function body(Request $request): array {
    $decoded = json_decode((string) $request->getContent(), TRUE);
    return is_array($decoded) ? $decoded : [];
  }

}
