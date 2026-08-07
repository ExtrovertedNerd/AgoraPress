<?php

/**
 * Admin create / edit / profile form logic for users.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

/**
 * User edit form save + render (shared by user-new, user-edit, profile).
 */
class AP_Admin_User_Edit
{
    /**
     * Save a user from a form submission bag.
     *
     * Modes:
     * - create (user_ID empty)
     * - update (user_ID set, typically requires edit_users)
     * - profile (user_ID = current user; role not changed)
     *
     * @param array<string, mixed> $input Typically $_POST.
     * @param string               $mode  create|update|profile
     * @param array<string, mixed> $files Optional $_FILES bag for avatar upload.
     *
     * @return array{
     *   ok: bool,
     *   id: int,
     *   message_key: string,
     *   errors: list<string>,
     *   user: ?AP_User
     * }
     */
    public static function save(
        array $input,
        int $actorId,
        string $mode = 'update',
        ?AP_DB $db = null,
        array $files = []
    ): array {
        $db = $db ?? (function_exists('ap_db') ? ap_db() : null);
        if ($db === null) {
            return [
                'ok' => false,
                'id' => 0,
                'message_key' => 'error',
                'errors' => ['No database connection.'],
                'user' => null,
            ];
        }

        $mode = in_array($mode, ['create', 'update', 'profile'], true) ? $mode : 'update';
        $id = (int) ($input['user_ID'] ?? $input['user_id'] ?? $input['ID'] ?? 0);
        if ($mode === 'profile') {
            $id = $actorId;
        }
        $isNew = $mode === 'create' || $id < 1;

        $nonceAction = $isNew
            ? 'create-user'
            : ($mode === 'profile' ? 'update-profile-' . $id : 'update-user-' . $id);
        $nonce = (string) ($input['_ap_nonce'] ?? '');
        if (!ap_check_nonce($nonce, $nonceAction, $actorId > 0 ? $actorId : null)) {
            return [
                'ok' => false,
                'id' => $id,
                'message_key' => 'nonce',
                'errors' => ['Security check failed. Please reload and try again.'],
                'user' => $id > 0 ? AP_User::getById($id, $db) : null,
            ];
        }

        // Capability gates (prefer actor id so unit tests without a session work).
        if ($isNew) {
            if (!self::actorCan($actorId, 'create_users', $db)) {
                return [
                    'ok' => false,
                    'id' => 0,
                    'message_key' => 'error',
                    'errors' => ['You do not have permission to create users.'],
                    'user' => null,
                ];
            }
        } elseif ($mode === 'profile') {
            if ($actorId < 1 || $actorId !== $id) {
                return [
                    'ok' => false,
                    'id' => $id,
                    'message_key' => 'error',
                    'errors' => ['You can only edit your own profile here.'],
                    'user' => AP_User::getById($id, $db),
                ];
            }
        } else {
            $canEditOthers = self::actorCan($actorId, 'edit_users', $db);
            $isSelf = $actorId > 0 && $actorId === $id;
            if (!$canEditOthers && !$isSelf) {
                return [
                    'ok' => false,
                    'id' => $id,
                    'message_key' => 'error',
                    'errors' => ['You do not have permission to edit this user.'],
                    'user' => AP_User::getById($id, $db),
                ];
            }
        }

        $data = self::collectFields($input, $isNew, $mode, $actorId, $db);

        $passErrors = self::passwordErrors($data, $isNew);
        unset($data['_password_mismatch']);
        if ($passErrors !== []) {
            return [
                'ok' => false,
                'id' => $id,
                'message_key' => 'error',
                'errors' => $passErrors,
                'user' => $id > 0 ? AP_User::getById($id, $db) : null,
            ];
        }

        if ($isNew) {
            $result = AP_User::create($data, $db);
            if (!$result['ok']) {
                return [
                    'ok' => false,
                    'id' => 0,
                    'message_key' => 'error',
                    'errors' => $result['errors'],
                    'user' => null,
                ];
            }

            return [
                'ok' => true,
                'id' => $result['id'],
                'message_key' => 'user_created',
                'errors' => [],
                'user' => $result['user'],
            ];
        }

        // Guard: last admin demotion.
        if (
            isset($data['role'])
            && $data['role'] !== 'administrator'
            && AP_User::isLastAdministrator($id, $db)
        ) {
            return [
                'ok' => false,
                'id' => $id,
                'message_key' => 'error',
                'errors' => ['Cannot demote the last administrator.'],
                'user' => AP_User::getById($id, $db),
            ];
        }

        $result = AP_User::update($id, $data, $db);
        if (!$result['ok']) {
            return [
                'ok' => false,
                'id' => $id,
                'message_key' => 'error',
                'errors' => $result['errors'],
                'user' => $result['user'],
            ];
        }

        $avatarErrors = self::processAvatar($id, $input, $files, $db);
        if ($avatarErrors !== []) {
            return [
                'ok' => false,
                'id' => $id,
                'message_key' => 'error',
                'errors' => $avatarErrors,
                'user' => AP_User::getById($id, $db),
            ];
        }

        // Admin color mode preference (profile only).
        if ($mode === 'profile' && array_key_exists('ap_admin_color_mode', $input)) {
            AP_Admin::setColorMode($id, (string) $input['ap_admin_color_mode'], $db);
        }

        return [
            'ok' => true,
            'id' => $id,
            'message_key' => $mode === 'profile' ? 'profile_updated' : 'user_updated',
            'errors' => [],
            'user' => AP_User::getById($id, $db),
        ];
    }

    /**
     * Render the create/edit/profile form.
     *
     * @param array{
     *   first_name?: string,
     *   last_name?: string,
     *   nickname?: string,
     *   description?: string,
     *   location?: string,
     *   signature?: string,
     *   role?: string,
     *   user_pass?: string
     * } $extra Prefill overrides (e.g. after failed validation).
     */
    public static function renderForm(
        ?AP_User $user,
        string $mode,
        int $actorId,
        array $extra = [],
        ?AP_DB $db = null
    ): string {
        $db = $db ?? (function_exists('ap_db') ? ap_db() : null);
        $isNew = $user === null || $user->ID < 1 || $mode === 'create';
        $id = $isNew ? 0 : $user->ID;
        $mode = in_array($mode, ['create', 'update', 'profile'], true) ? $mode : ($isNew ? 'create' : 'update');

        $nonceAction = $isNew
            ? 'create-user'
            : ($mode === 'profile' ? 'update-profile-' . $id : 'update-user-' . $id);

        $actionUrl = match ($mode) {
            'create' => AP_Admin::url('user-new.php'),
            'profile' => AP_Admin::url('profile.php'),
            default => AP_Admin::url('user-edit.php', ['user_id' => $id]),
        };

        $login = $isNew
            ? (string) ($extra['user_login'] ?? '')
            : $user->user_login;
        $email = (string) ($extra['user_email'] ?? ($isNew ? '' : $user->user_email));
        $url = (string) ($extra['user_url'] ?? ($isNew ? '' : $user->user_url));
        $display = (string) ($extra['display_name'] ?? ($isNew ? '' : $user->display_name));

        $meta = $isNew
            ? [
                'first_name' => '',
                'last_name' => '',
                'nickname' => '',
                'description' => '',
                'location' => '',
                'signature' => '',
            ]
            : AP_User::getProfileMeta($id, $db);
        foreach (['first_name', 'last_name', 'nickname', 'description', 'location', 'signature'] as $k) {
            if (array_key_exists($k, $extra)) {
                $meta[$k] = (string) $extra[$k];
            }
        }

        $role = (string) ($extra['role'] ?? '');
        if ($role === '' && !$isNew && class_exists('AP_Roles', false) && $db !== null) {
            $role = AP_Roles::getUserRole($id, $db);
        }
        if ($role === '' && $isNew) {
            if (function_exists('ap_get_option') && $db !== null) {
                $role = (string) ap_get_option('default_role', 'subscriber', $db);
            } else {
                $role = 'subscriber';
            }
        }

        $canPromote = $mode !== 'profile' && self::actorCan($actorId, 'promote_users', $db);
        // Self-edit via profile never changes role; edit_users without promote_users also cannot.

        $html = '<form method="post" action="' . ap_esc_url($actionUrl)
            . '" class="ap-user-form" autocomplete="off" enctype="multipart/form-data">';
        $html .= ap_nonce_field($nonceAction, '_ap_nonce', false, $actorId > 0 ? $actorId : null);
        $html .= '<input type="hidden" name="user_ID" value="' . (int) $id . '" />';
        $html .= '<input type="hidden" name="ap_user_mode" value="' . ap_esc_attr($mode) . '" />';

        $html .= '<div class="ap-edit-main">';
        $html .= '<fieldset class="ap-fieldset">';
        $html .= '<legend>Account</legend>';

        // Username.
        $html .= '<div class="ap-field">';
        $html .= '<label for="user_login">Username'
            . ($isNew ? ' <span class="required">*</span>' : '') . '</label>';
        if ($isNew) {
            $html .= '<input type="text" name="user_login" id="user_login" class="regular-text" required '
                . 'maxlength="60" value="' . ap_esc_attr($login) . '" autocomplete="off" />';
            $html .= '<p class="description">Usernames cannot be changed later.</p>';
        } else {
            $html .= '<input type="text" id="user_login" class="regular-text" readonly disabled '
                . 'value="' . ap_esc_attr($login) . '" />';
            $html .= '<input type="hidden" name="user_login" value="' . ap_esc_attr($login) . '" />';
        }
        $html .= '</div>';

        // Email.
        $html .= '<div class="ap-field">';
        $html .= '<label for="user_email">Email <span class="required">*</span></label>';
        $html .= '<input type="email" name="user_email" id="user_email" class="regular-text" required '
            . 'maxlength="100" value="' . ap_esc_attr($email) . '" autocomplete="email" />';
        $html .= '</div>';

        // Website.
        $html .= '<div class="ap-field">';
        $html .= '<label for="user_url">Website</label>';
        $html .= '<input type="url" name="user_url" id="user_url" class="regular-text" '
            . 'maxlength="100" value="' . ap_esc_attr($url) . '" placeholder="https://" />';
        $html .= '</div>';

        $html .= '</fieldset>';

        $html .= '<fieldset class="ap-fieldset">';
        $html .= '<legend>Name</legend>';

        $html .= '<div class="ap-field">';
        $html .= '<label for="first_name">First Name</label>';
        $html .= '<input type="text" name="first_name" id="first_name" class="regular-text" '
            . 'value="' . ap_esc_attr($meta['first_name']) . '" />';
        $html .= '</div>';

        $html .= '<div class="ap-field">';
        $html .= '<label for="last_name">Last Name</label>';
        $html .= '<input type="text" name="last_name" id="last_name" class="regular-text" '
            . 'value="' . ap_esc_attr($meta['last_name']) . '" />';
        $html .= '</div>';

        $html .= '<div class="ap-field">';
        $html .= '<label for="nickname">Nickname</label>';
        $html .= '<input type="text" name="nickname" id="nickname" class="regular-text" '
            . 'value="' . ap_esc_attr($meta['nickname']) . '" />';
        $html .= '</div>';

        $html .= '<div class="ap-field">';
        $html .= '<label for="display_name">Display Name</label>';
        $html .= '<input type="text" name="display_name" id="display_name" class="regular-text" '
            . 'value="' . ap_esc_attr($display) . '" />';
        $html .= '<p class="description">How the name appears publicly (author archives, comments).</p>';
        $html .= '</div>';

        $html .= '</fieldset>';

        $html .= '<fieldset class="ap-fieldset">';
        $html .= '<legend>About</legend>';
        $html .= '<div class="ap-field">';
        $html .= '<label for="description">Biographical Info</label>';
        $html .= '<textarea name="description" id="description" rows="5" class="large-text">'
            . ap_esc_textarea($meta['description'] ?? '') . '</textarea>';
        $html .= '</div>';
        $html .= '<div class="ap-field">';
        $html .= '<label for="location">Location</label>';
        $html .= '<input type="text" name="location" id="location" class="regular-text" '
            . 'maxlength="120" value="' . ap_esc_attr($meta['location'] ?? '') . '" '
            . 'placeholder="City, region" autocomplete="address-level2" />';
        $html .= '<p class="description">Optional. Shown on forum posts in the author panel when set.</p>';
        $html .= '</div>';
        $sigMax = class_exists('AP_User', false) ? (int) AP_User::SIGNATURE_MAX_LENGTH : 500;
        $html .= '<div class="ap-field">';
        $html .= '<label for="signature">Forum signature</label>';
        $html .= '<textarea name="signature" id="signature" rows="3" class="large-text" maxlength="'
            . $sigMax . '">'
            . ap_esc_textarea($meta['signature'] ?? '') . '</textarea>';
        $html .= '<p class="description">Optional. Shown under your forum posts when signatures are '
            . 'enabled (Settings → Forums). Max ' . $sigMax . ' characters. '
            . 'Supports the same light markup as forum posts.</p>';
        $html .= '</div>';
        $html .= '</fieldset>';

        // Avatar (existing users only — local upload + Gravatar fallback).
        if (!$isNew && class_exists('AP_Avatar', false)) {
            $html .= self::renderAvatarFieldset($id, $user, $db);
        }

        // Admin appearance (color mode) — profile only; self preference.
        if ($mode === 'profile' && $id > 0) {
            $html .= self::renderColorModeFieldset($id, $extra, $db);
        }

        // Password.
        $html .= '<fieldset class="ap-fieldset">';
        $html .= '<legend>' . ($isNew ? 'Password' : 'Account Management') . '</legend>';
        $html .= '<div class="ap-field">';
        $html .= '<label for="pass1">' . ($isNew ? 'Password <span class="required">*</span>' : 'New Password')
            . '</label>';
        $html .= '<input type="password" name="pass1" id="pass1" class="regular-text" '
            . 'autocomplete="new-password"' . ($isNew ? ' required' : '') . ' />';
        if (!$isNew) {
            $html .= '<p class="description">Leave blank to keep the current password.</p>';
        } else {
            $html .= '<p class="description">Minimum 8 characters. Use a strong unique password.</p>';
        }
        $html .= '</div>';
        $html .= '<div class="ap-field">';
        $html .= '<label for="pass2">Confirm Password' . ($isNew ? ' <span class="required">*</span>' : '')
            . '</label>';
        $html .= '<input type="password" name="pass2" id="pass2" class="regular-text" '
            . 'autocomplete="new-password"' . ($isNew ? ' required' : '') . ' />';
        $html .= '</div>';
        $html .= '</fieldset>';

        // Role.
        if ($canPromote && class_exists('AP_Roles', false) && $db !== null) {
            $html .= '<fieldset class="ap-fieldset">';
            $html .= '<legend>Role</legend>';
            $html .= '<div class="ap-field">';
            $html .= '<label for="role">Role</label>';
            $html .= '<select name="role" id="role">';
            foreach (AP_Roles::getRoleNames($db) as $slug => $label) {
                $sel = $slug === $role ? ' selected' : '';
                $html .= '<option value="' . ap_esc_attr($slug) . '"' . $sel . '>'
                    . ap_esc_html($label) . '</option>';
            }
            $html .= '</select>';
            $html .= '</div>';
            $html .= '</fieldset>';
        } elseif (!$isNew && $role !== '') {
            $roleNames = class_exists('AP_Roles', false) && $db !== null
                ? AP_Roles::getRoleNames($db)
                : [];
            $roleLabel = $roleNames[$role] ?? $role;
            $html .= '<fieldset class="ap-fieldset">';
            $html .= '<legend>Role</legend>';
            $html .= '<div class="ap-field"><p><strong>' . ap_esc_html($roleLabel) . '</strong></p></div>';
            $html .= '</fieldset>';
        }

        $submitLabel = match ($mode) {
            'create' => 'Add New User',
            'profile' => 'Update Profile',
            default => 'Update User',
        };

        $html .= '<p class="submit">';
        $html .= '<button type="submit" name="save_user" value="1" class="button button-primary">'
            . ap_esc_html($submitLabel) . '</button>';
        if ($mode === 'update') {
            $listUrl = AP_Admin::url('users.php');
            $html .= ' <a class="button" href="' . ap_esc_url($listUrl) . '">Cancel</a>';
        }
        $html .= '</p>';

        $html .= '</div></form>';

        return $html;
    }

    /**
     * Admin color mode fieldset (profile / personal options).
     *
     * @param array<string, mixed> $extra Prefill overrides.
     */
    public static function renderColorModeFieldset(int $userId, array $extra = [], ?AP_DB $db = null): string
    {
        if ($userId < 1) {
            return '';
        }

        $current = array_key_exists('ap_admin_color_mode', $extra)
            ? AP_Admin::sanitizeColorMode((string) $extra['ap_admin_color_mode'])
            : AP_Admin::getColorMode($userId, $db);
        $labels = AP_Admin::colorModeLabels();

        $html = '<fieldset class="ap-fieldset ap-color-mode-fieldset">';
        $html .= '<legend>Admin Appearance</legend>';
        $html .= '<div class="ap-field">';
        $html .= '<label for="ap_admin_color_mode">Color mode</label>';
        $html .= '<select name="ap_admin_color_mode" id="ap_admin_color_mode" class="regular-text">';
        foreach ($labels as $slug => $label) {
            $sel = $slug === $current ? ' selected' : '';
            $html .= '<option value="' . ap_esc_attr($slug) . '"' . $sel . '>'
                . ap_esc_html($label) . '</option>';
        }
        $html .= '</select>';
        $html .= '<p class="description">Controls the admin control panel theme. '
            . '<strong>System</strong> follows your device setting; '
            . '<strong>Light</strong> and <strong>Dark</strong> force a mode. '
            . 'You can also cycle modes from the sun/moon button in the top bar.</p>';
        $html .= '</div>';
        $html .= '</fieldset>';

        return $html;
    }

    /**
     * Avatar fieldset HTML for profile / edit screens.
     */
    public static function renderAvatarFieldset(int $userId, ?AP_User $user, ?AP_DB $db = null): string
    {
        if ($userId < 1 || !class_exists('AP_Avatar', false)) {
            return '';
        }

        $html = '<fieldset class="ap-fieldset ap-avatar-fieldset">';
        $html .= '<legend>Avatar</legend>';
        $html .= '<div class="ap-field ap-avatar-preview">';
        $html .= '<span class="ap-avatar-current" aria-hidden="false">';
        $html .= AP_Avatar::getHtml(
            $user ?? $userId,
            96,
            [
                'class' => 'avatar avatar-96 photo ap-avatar-img',
                'force_display' => true,
                'alt' => $user instanceof AP_User
                    ? ($user->display_name !== '' ? $user->display_name : $user->user_login)
                    : 'Avatar',
            ],
            $db
        );
        $html .= '</span>';
        $localId = AP_Avatar::getLocalAttachmentId($userId, $db);
        if ($localId > 0) {
            $html .= '<p class="description">Custom uploaded avatar.</p>';
        } else {
            $html .= '<p class="description">No custom avatar — showing Gravatar for this email '
                . '(or the site default) when avatars are enabled.</p>';
        }
        $html .= '</div>';

        $html .= '<div class="ap-field">';
        $html .= '<label for="ap_avatar">Upload new avatar</label>';
        $html .= '<input type="file" name="ap_avatar" id="ap_avatar" accept="image/jpeg,image/png,image/gif,image/webp" />';
        $html .= '<p class="description">JPG, PNG, GIF, or WebP. Max '
            . self::formatAvatarMaxSize() . '. Replaces any previous custom avatar.</p>';
        $html .= '</div>';

        if ($localId > 0) {
            $html .= '<div class="ap-field">';
            $html .= '<label class="ap-checkbox-label">';
            $html .= '<input type="checkbox" name="remove_avatar" id="remove_avatar" value="1" /> ';
            $html .= 'Remove custom avatar</label>';
            $html .= '<p class="description">Falls back to Gravatar / site default after save.</p>';
            $html .= '</div>';
        }

        $html .= '</fieldset>';

        return $html;
    }

    /**
     * Process avatar upload / remove after a successful user update.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $files
     *
     * @return list<string> Errors (empty on success / no-op).
     */
    private static function processAvatar(int $userId, array $input, array $files, AP_DB $db): array
    {
        if ($userId < 1 || !class_exists('AP_Avatar', false)) {
            return [];
        }

        $remove = !empty($input['remove_avatar']);
        $file = $files['ap_avatar'] ?? null;
        $hasUpload = is_array($file)
            && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE
            && (string) ($file['tmp_name'] ?? '') !== '';

        if ($remove && !$hasUpload) {
            AP_Avatar::deleteLocal($userId, true, $db);

            return [];
        }

        if (!$hasUpload) {
            return [];
        }

        /** @var array<string, mixed> $file */
        $result = AP_Avatar::upload($userId, $file, $db);
        if (!$result['ok']) {
            return [$result['error'] !== '' ? $result['error'] : 'Could not upload avatar.'];
        }

        return [];
    }

    private static function formatAvatarMaxSize(): string
    {
        $bytes = class_exists('AP_Avatar', false) ? AP_Avatar::MAX_UPLOAD_BYTES : 2097152;
        if ($bytes >= 1048576) {
            return (string) round($bytes / 1048576, 1) . ' MB';
        }

        return (string) (int) round($bytes / 1024) . ' KB';
    }

    /**
     * Collect and validate form fields into a data bag for AP_User::create/update.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private static function collectFields(
        array $input,
        bool $isNew,
        string $mode,
        int $actorId,
        AP_DB $db
    ): array {
        $data = [
            'user_email' => (string) ($input['user_email'] ?? ''),
            'user_url' => (string) ($input['user_url'] ?? ''),
            'display_name' => (string) ($input['display_name'] ?? ''),
            'first_name' => (string) ($input['first_name'] ?? ''),
            'last_name' => (string) ($input['last_name'] ?? ''),
            'nickname' => (string) ($input['nickname'] ?? ''),
            'description' => (string) ($input['description'] ?? ''),
            'location' => (string) ($input['location'] ?? ''),
            'signature' => (string) ($input['signature'] ?? ''),
        ];

        if ($isNew) {
            $data['user_login'] = (string) ($input['user_login'] ?? '');
        }

        $pass1 = (string) ($input['pass1'] ?? $input['password'] ?? '');
        $pass2 = (string) ($input['pass2'] ?? $input['password_confirm'] ?? '');
        if ($isNew || $pass1 !== '' || $pass2 !== '') {
            if ($pass1 !== $pass2) {
                // Surface via a synthetic field; save() relies on AP_User for length.
                // We attach a mismatch by setting an invalid short password marker only when empty.
                // Better: throw through errors by using a private validation — handled below in save via custom.
            }
            if ($pass1 !== $pass2) {
                // Put empty password so create fails "required", then we replace — actually use a flag.
                $data['password'] = $pass1;
                $data['_password_mismatch'] = true;
            } else {
                $data['password'] = $pass1;
            }
        }

        $canPromote = $mode !== 'profile' && self::actorCan($actorId, 'promote_users', $db);
        if ($canPromote && array_key_exists('role', $input)) {
            $data['role'] = trim((string) $input['role']);
        }

        return $data;
    }

    /**
     * Whether an actor may use a capability (falls back to current user).
     */
    private static function actorCan(int $actorId, string $cap, ?AP_DB $db): bool
    {
        if ($actorId > 0 && function_exists('ap_user_can')) {
            return ap_user_can($actorId, $cap, null, $db);
        }
        if (function_exists('ap_current_user_can')) {
            return ap_current_user_can($cap, null, $db);
        }

        return true;
    }

    /**
     * Validate password confirmation before create/update.
     *
     * @param array<string, mixed> $data
     *
     * @return list<string>
     */
    public static function passwordErrors(array $data, bool $isNew): array
    {
        $errors = [];
        if (!empty($data['_password_mismatch'])) {
            $errors[] = 'Passwords do not match.';

            return $errors;
        }
        $pass = (string) ($data['password'] ?? $data['user_pass'] ?? '');
        if ($isNew && $pass === '') {
            $errors[] = 'Password is required.';
        }
        if ($pass !== '' && strlen($pass) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        }

        return $errors;
    }
}
