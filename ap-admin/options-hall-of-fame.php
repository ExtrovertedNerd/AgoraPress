<?php

/**
 * Settings — Hall of Fame & Project (voluntary domain registration).
 *
 * Fully opt-in. No anonymous installer pings. Join runs a file-proof
 * handshake (domain + challenge only) so the project can confirm control
 * of the site. Membership can be withdrawn.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

require_once __DIR__ . '/admin-bootstrap.php';

AP_Admin::requireCapability('manage_options');

AP_Admin::consumeQueryNotice();

$userId = (int) ap_get_current_user_id();
$db = ap_db();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string) ($_POST['ap_hof_action'] ?? '');
    $result = match ($action) {
        AP_Hall_Of_Fame::ACTION_JOIN => AP_Hall_Of_Fame::join($userId, $_POST, $db),
        AP_Hall_Of_Fame::ACTION_LEAVE => AP_Hall_Of_Fame::leave($userId, $_POST, $db),
        default => [
            'ok' => false,
            'message_key' => 'error',
            'errors' => ['Unknown action.'],
        ],
    };

    if ($result['ok']) {
        AP_Admin::redirect(AP_Admin::url('options-hall-of-fame.php', [
            'message' => (string) ($result['message_key'] ?? 'updated'),
        ]));
    }

    foreach ($result['errors'] as $err) {
        AP_Admin::addNotice((string) $err, 'error');
    }
    if (($result['message_key'] ?? '') === 'nonce') {
        AP_Admin::addNotice('Security check failed. Please reload and try again.', 'error');
    }
}

$status = AP_Hall_Of_Fame::getStatus($db);
$domain = $status['joined']
    ? (string) $status['domain']
    : AP_Hall_Of_Fame::resolveDomain($db);
$endpoint = AP_Hall_Of_Fame::registrationEndpoint();
$publicPage = AP_Hall_Of_Fame::PUBLIC_PAGE_URL;
$donateUrl = AP_Hall_Of_Fame::DONATION_URL;

$ap_admin_title = 'Hall of Fame';
$ap_admin_screen = 'options-hall-of-fame';
require __DIR__ . '/admin-header.php';
?>
<div class="ap-page-header">
    <h1>Hall of Fame &amp; Project</h1>
</div>

<p>
    AgoraPress is free and open source. It never phones home by default. The Hall of Fame is the only optional way to count installs: you may voluntarily register your domain so it can appear in a public counter and random rotation on the project site. You can withdraw at any time. Nothing is sent during install or ordinary browsing.
</p>

<section class="ap-metabox ap-hof-status" aria-labelledby="ap-hof-status-title">
    <h2 id="ap-hof-status-title" class="ap-metabox-title">Registration status</h2>
    <div class="ap-metabox-body">
        <?php if ($status['joined']) : ?>
            <p class="ap-hof-badge ap-hof-badge--joined">
                <strong>Joined</strong>
                <?php if ($domain !== '') : ?>
                    — domain <code><?php echo ap_esc_html($domain); ?></code>
                <?php endif; ?>
                <?php if ($status['joined_at'] !== '') : ?>
                    <span class="ap-muted">since <?php echo ap_esc_html($status['joined_at']); ?></span>
                <?php endif; ?>
            </p>
            <p class="ap-help">
                Only your domain was registered. No user accounts, email addresses, or
                environment data are transmitted. To leave, use the button below.
            </p>
            <form method="post" action="" class="ap-form ap-form--inline">
                <input type="hidden" name="ap_hof_action" value="<?php echo ap_esc_attr(AP_Hall_Of_Fame::ACTION_LEAVE); ?>">
                <?php echo ap_nonce_field(AP_Hall_Of_Fame::NONCE_LEAVE, '_ap_nonce', false, $userId); ?>
                <button type="submit" class="button">Leave Hall of Fame</button>
            </form>
        <?php else : ?>
            <p class="ap-hof-badge ap-hof-badge--out">
                <strong>Not registered</strong>
                <?php if ($domain !== '') : ?>
                    — would register <code><?php echo ap_esc_html($domain); ?></code>
                <?php else : ?>
                    — set a Site URL under
                    <a href="<?php echo ap_esc_url(AP_Admin::url('options-general.php')); ?>">General settings</a>
                    first.
                <?php endif; ?>
            </p>
            <p class="ap-help">
                Clicking Join starts a handshake: the project asks this site to write a
                short-lived proof file containing a unique code, fetches that file to
                confirm you control the domain, then this site deletes the file.
                Only the domain and handshake data are sent — no telemetry, no anonymous
                pings, no automatic install counting.
            </p>
            <form method="post" action="" class="ap-form">
                <input type="hidden" name="ap_hof_action" value="<?php echo ap_esc_attr(AP_Hall_Of_Fame::ACTION_JOIN); ?>">
                <?php echo ap_nonce_field(AP_Hall_Of_Fame::NONCE_JOIN, '_ap_nonce', false, $userId); ?>
                <p class="ap-field">
                    <label for="ap-hof-domain">Domain</label>
                    <input type="text" name="domain" id="ap-hof-domain" class="regular-text"
                        value="<?php echo ap_esc_attr($domain); ?>"
                        placeholder="example.com" autocomplete="off">
                    <span class="ap-help">Public hostname only (no path). Derived from Site URL when left as-is.</span>
                </p>
                <p class="ap-card-actions">
                    <button type="submit" class="button button-primary" <?php echo $domain === '' ? 'disabled' : ''; ?>>
                        Join the Hall of Fame
                    </button>
                    <a class="button" href="<?php echo ap_esc_url($publicPage); ?>" target="_blank" rel="noopener noreferrer">
                        View public Hall of Fame
                        <span class="screen-reader-text">(opens in a new tab)</span>
                    </a>
                </p>
            </form>
        <?php endif; ?>
    </div>
</section>

<section class="ap-metabox" aria-labelledby="ap-hof-privacy-title">
    <h2 id="ap-hof-privacy-title" class="ap-metabox-title">Privacy</h2>
    <div class="ap-metabox-body">
        <ul class="ap-list">
            <li><strong>No telemetry</strong> — core never ships phone-home collectors or a telemetry flag.</li>
            <li><strong>No installer pings</strong> — the web and CLI installers never contact the project site.</li>
            <li><strong>Voluntary only</strong> — registration happens only when an administrator clicks Join.</li>
            <li><strong>Domain only</strong> — the registration payload is domain + handshake (and a withdrawal token on leave).</li>
            <li><strong>Domain control</strong> — a one-time proof file on this site must match before the project lists the domain.</li>
            <li><strong>Withdrawable</strong> — Leave clears local membership and asks the project API to remove the domain.</li>
        </ul>
        <p class="ap-muted ap-help">
            Registration endpoint:
            <code><?php echo ap_esc_html($endpoint); ?></code>
        </p>
    </div>
</section>

<section class="ap-metabox" aria-labelledby="ap-hof-donation-title">
    <h2 id="ap-hof-donation-title" class="ap-metabox-title">Donation link</h2>
    <div class="ap-metabox-body">
        <p>
            A subtle tip/donation link always appears in the admin footer.
            It is permanent and non-optional — the only price for the free CMS —
            and never blocks features or creates a paywall.
        </p>
        <p class="ap-card-actions">
            <a class="button" href="<?php echo ap_esc_url($donateUrl); ?>" target="_blank" rel="noopener noreferrer">
                Open donation page
                <span class="screen-reader-text">(opens in a new tab)</span>
            </a>
        </p>
    </div>
</section>
<?php
require __DIR__ . '/admin-footer.php';
