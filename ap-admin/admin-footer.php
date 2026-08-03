<?php

/**
 * Admin page footer + progressive-enhancement scripts.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

$ap_footer_version = defined('AP_VERSION') ? (string) AP_VERSION : '';
$ap_show_donation = class_exists('AP_Hall_Of_Fame', false)
    && AP_Hall_Of_Fame::showDonationButton();
$ap_donation_url = class_exists('AP_Hall_Of_Fame', false)
    ? AP_Hall_Of_Fame::DONATION_URL
    : 'https://agorapress.extrovertednerd.com/donate';
$ap_hof_footer_url = class_exists('AP_Admin', false)
    ? AP_Admin::url('options-hall-of-fame.php')
    : 'options-hall-of-fame.php';

?>
        </main>
    </div>
    <footer class="ap-admin-footer" role="contentinfo">
        <p>
            Thank you for creating with <strong>AgoraPress</strong>
            <?php if ($ap_footer_version !== '') : ?>
                <span class="ap-footer-version">v<?php echo ap_esc_html($ap_footer_version); ?></span>
            <?php endif; ?>
            — free forever, no telemetry by default.
            <?php if (class_exists('AP_Admin', false) && AP_Admin::currentUserCan('manage_options')) : ?>
                <span class="ap-footer-sep" aria-hidden="true">·</span>
                <a class="ap-footer-hof" href="<?php echo ap_esc_url($ap_hof_footer_url); ?>">Hall of Fame</a>
            <?php endif; ?>
            <?php if ($ap_show_donation) : ?>
                <span class="ap-footer-sep" aria-hidden="true">·</span>
                <a class="ap-footer-donate" href="<?php echo ap_esc_url($ap_donation_url); ?>"
                    target="_blank" rel="noopener noreferrer">
                    Donate
                    <span class="screen-reader-text">(opens in a new tab)</span>
                </a>
            <?php endif; ?>
        </p>
    </footer>
</div>
<script>
(function () {
    // Mobile admin menu toggle (collapsible sidebar).
    var body = document.body;
    var toggle = document.getElementById('ap-menu-toggle');
    var menu = document.getElementById('ap-admin-menu');
    var backdrop = document.getElementById('ap-admin-menu-backdrop');

    function setMenuOpen(open) {
        if (!body || !toggle) {
            return;
        }
        body.classList.toggle('ap-menu-open', open);
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        toggle.setAttribute('aria-label', open ? 'Close admin menu' : 'Open admin menu');
        if (backdrop) {
            if (open) {
                backdrop.removeAttribute('hidden');
            } else {
                backdrop.setAttribute('hidden', '');
            }
        }
        if (open && menu) {
            var first = menu.querySelector('a');
            if (first && typeof first.focus === 'function') {
                first.focus();
            }
        }
    }

    if (toggle) {
        toggle.addEventListener('click', function () {
            setMenuOpen(!body.classList.contains('ap-menu-open'));
        });
    }
    if (backdrop) {
        backdrop.addEventListener('click', function () {
            setMenuOpen(false);
        });
    }
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && body.classList.contains('ap-menu-open')) {
            setMenuOpen(false);
            if (toggle) {
                toggle.focus();
            }
        }
    });
    // Close drawer after navigating on small screens.
    if (menu) {
        menu.addEventListener('click', function (e) {
            var t = e.target;
            if (t && t.tagName === 'A' && window.matchMedia('(max-width: 782px)').matches) {
                setMenuOpen(false);
            }
        });
    }

    // Bulk select-all for posts list (post[]) and media library (media[]).
    document.querySelectorAll('#cb-select-all').forEach(function (all) {
        all.addEventListener('change', function () {
            var form = all.closest('form') || document;
            form.querySelectorAll('input[name="post[]"], input[name="media[]"]').forEach(function (cb) {
                cb.checked = all.checked;
            });
        });
    });

    // Media library drag-and-drop onto the upload panel.
    var zone = document.getElementById('ap-media-dropzone');
    var input = document.getElementById('ap-media-file-input');
    if (zone && input) {
        ['dragenter', 'dragover'].forEach(function (ev) {
            zone.addEventListener(ev, function (e) {
                e.preventDefault();
                e.stopPropagation();
                zone.classList.add('is-dragover');
            });
        });
        ['dragleave', 'drop'].forEach(function (ev) {
            zone.addEventListener(ev, function (e) {
                e.preventDefault();
                e.stopPropagation();
                zone.classList.remove('is-dragover');
            });
        });
        zone.addEventListener('drop', function (e) {
            var files = e.dataTransfer && e.dataTransfer.files;
            if (!files || !files.length) {
                return;
            }
            try {
                var dt = new DataTransfer();
                Array.prototype.forEach.call(files, function (f) { dt.items.add(f); });
                input.files = dt.files;
            } catch (err) {
                // Older browsers: user can still use the file picker.
            }
            zone.classList.add('has-files');
        });
        input.addEventListener('change', function () {
            if (input.files && input.files.length) {
                zone.classList.add('has-files');
            }
        });
    }
})();
</script>
</body>
</html>
