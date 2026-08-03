<?php

/**
 * Admin page footer.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

?>
        </main>
    </div>
    <footer class="ap-admin-footer">
        <p>Thank you for creating with <strong>AgoraPress</strong> — free forever, no telemetry by default.</p>
    </footer>
</div>
<script>
(function () {
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
