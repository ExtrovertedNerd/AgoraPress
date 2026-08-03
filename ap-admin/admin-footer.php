<?php

/**
 * Admin page footer + progressive-enhancement scripts.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

$ap_footer_version = defined('AP_VERSION') ? (string) AP_VERSION : '';
// Permanent non-optional admin-footer tip link (constitution: free CMS price).
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
            <span class="ap-footer-sep" aria-hidden="true">·</span>
            <a class="ap-footer-donate" href="<?php echo ap_esc_url($ap_donation_url); ?>"
                target="_blank" rel="noopener noreferrer">
                Donate
                <span class="screen-reader-text">(opens in a new tab)</span>
            </a>
        </p>
    </footer>
</div>
<script>
(function () {
    // ----- Color mode (auto → light → dark → auto) -----
    var COLOR_KEY = 'ap_admin_color_mode';
    var COLOR_ORDER = ['auto', 'light', 'dark'];
    var COLOR_LABELS = { auto: 'System', light: 'Light', dark: 'Dark' };

    function readColorMode() {
        var el = document.documentElement;
        var attr = el.getAttribute('data-ap-color-mode');
        if (attr === 'light' || attr === 'dark' || attr === 'auto') {
            return attr;
        }
        try {
            var stored = localStorage.getItem(COLOR_KEY);
            if (stored === 'light' || stored === 'dark' || stored === 'auto') {
                return stored;
            }
        } catch (e) {}
        var pref = el.getAttribute('data-ap-color-mode-pref');
        if (pref === 'light' || pref === 'dark' || pref === 'auto') {
            return pref;
        }
        return 'auto';
    }

    function applyColorMode(mode) {
        if (COLOR_ORDER.indexOf(mode) === -1) {
            mode = 'auto';
        }
        document.documentElement.setAttribute('data-ap-color-mode', mode);
        try {
            localStorage.setItem(COLOR_KEY, mode);
        } catch (e) {}
        var meta = document.querySelector('meta[name="color-scheme"]');
        if (meta) {
            meta.setAttribute('content', mode === 'auto' ? 'light dark' : mode);
        }
        var btn = document.getElementById('ap-color-mode-toggle');
        if (btn) {
            var label = COLOR_LABELS[mode] || 'System';
            btn.setAttribute('data-ap-color-mode-current', mode);
            btn.setAttribute('aria-label', 'Color mode: ' + label + '. Click to change.');
            btn.setAttribute('title', 'Color mode: ' + label + ' (click to cycle System / Light / Dark)');
        }
        // Keep profile select in sync when present.
        var select = document.getElementById('ap_admin_color_mode');
        if (select && select.value !== mode) {
            select.value = mode;
        }
    }

    function nextColorMode(mode) {
        var i = COLOR_ORDER.indexOf(mode);
        if (i < 0) {
            return 'light';
        }
        return COLOR_ORDER[(i + 1) % COLOR_ORDER.length];
    }

    applyColorMode(readColorMode());

    var colorToggle = document.getElementById('ap-color-mode-toggle');
    if (colorToggle) {
        colorToggle.addEventListener('click', function () {
            applyColorMode(nextColorMode(readColorMode()));
        });
    }

    // Profile form: updating the select also applies immediately for preview.
    var colorSelect = document.getElementById('ap_admin_color_mode');
    if (colorSelect) {
        colorSelect.addEventListener('change', function () {
            applyColorMode(colorSelect.value);
        });
    }

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
