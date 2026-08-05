/**
 * AgoraPress visual WYSIWYG editor (no jQuery).
 *
 * Progressive enhancement: a contenteditable surface shows formatted HTML
 * while editing; a hidden textarea holds the value submitted with the form.
 * Without JavaScript the plain textarea remains usable.
 */
(function () {
    'use strict';

    function closestWrap(el) {
        return el && el.closest ? el.closest('[data-ap-editor-wrap]') : null;
    }

    function getSurface(wrap) {
        if (!wrap) {
            return null;
        }
        return wrap.querySelector('[data-ap-editor-surface]');
    }

    function getTextarea(wrap) {
        if (!wrap) {
            return null;
        }
        return wrap.querySelector('textarea[data-ap-editor]');
    }

    function getToolbar(wrap) {
        if (!wrap) {
            return null;
        }
        return wrap.querySelector('[data-ap-editor-toolbar]');
    }

    function isEmptyHtml(html) {
        if (!html) {
            return true;
        }
        var t = String(html)
            .replace(/&nbsp;/gi, ' ')
            .replace(/<br\s*\/?>/gi, '')
            .replace(/<div><\/div>/gi, '')
            .replace(/<p><\/p>/gi, '')
            .replace(/<[^>]+>/g, '')
            .replace(/\s+/g, '');
        return t === '';
    }

    function normalizeSyncedHtml(html) {
        if (isEmptyHtml(html)) {
            return '';
        }
        return String(html);
    }

    function syncSurfaceToTextarea(wrap) {
        var surface = getSurface(wrap);
        var ta = getTextarea(wrap);
        if (!surface || !ta) {
            return;
        }
        var html = normalizeSyncedHtml(surface.innerHTML);
        if (ta.value !== html) {
            ta.value = html;
            try {
                ta.dispatchEvent(new Event('input', { bubbles: true }));
            } catch (e) { /* older browsers */ }
        }
    }

    function focusSurface(surface) {
        if (!surface || typeof surface.focus !== 'function') {
            return;
        }
        try {
            surface.focus({ preventScroll: true });
        } catch (e) {
            surface.focus();
        }
    }

    function placeCaretAtEnd(el) {
        if (!el || !window.getSelection || !document.createRange) {
            return;
        }
        var range = document.createRange();
        range.selectNodeContents(el);
        range.collapse(false);
        var sel = window.getSelection();
        if (!sel) {
            return;
        }
        sel.removeAllRanges();
        sel.addRange(range);
    }

    function exec(cmd, value) {
        try {
            document.execCommand(cmd, false, value === undefined ? null : value);
            return true;
        } catch (e) {
            return false;
        }
    }

    function promptUrl(defaultUrl) {
        var url = window.prompt('Enter URL', defaultUrl || 'https://');
        if (url === null) {
            return null;
        }
        url = String(url).trim();
        if (url === '' || url === 'https://' || url === 'http://') {
            return null;
        }
        return url;
    }

    function insertHtml(html) {
        if (!html) {
            return;
        }
        // Prefer insertHTML; fall back to paste-like insert.
        if (exec('insertHTML', html)) {
            return;
        }
        var sel = window.getSelection ? window.getSelection() : null;
        if (!sel || sel.rangeCount === 0) {
            return;
        }
        var range = sel.getRangeAt(0);
        range.deleteContents();
        var tmp = document.createElement('div');
        tmp.innerHTML = html;
        var frag = document.createDocumentFragment();
        var node;
        var last = null;
        while ((node = tmp.firstChild)) {
            last = frag.appendChild(node);
        }
        range.insertNode(frag);
        if (last) {
            range.setStartAfter(last);
            range.collapse(true);
            sel.removeAllRanges();
            sel.addRange(range);
        }
    }

    function wrapSelectionWithTag(tagName) {
        var sel = window.getSelection ? window.getSelection() : null;
        if (!sel || sel.rangeCount === 0) {
            return;
        }
        var text = sel.toString();
        if (text === '') {
            insertHtml('<' + tagName + '>\u200b</' + tagName + '>');
            return;
        }
        var escaped = text
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
        insertHtml('<' + tagName + '>' + escaped + '</' + tagName + '>');
    }

    function getEmojiPicker(fromEl) {
        var wrap = closestWrap(fromEl);
        if (!wrap) {
            return null;
        }
        return wrap.querySelector('[data-ap-editor-emoji-picker]');
    }

    function getEmojiToggle(toolbar) {
        if (!toolbar) {
            return null;
        }
        return toolbar.querySelector('[data-ap-editor-cmd="emoji-picker"]');
    }

    function setPickerOpen(picker, open, toggle) {
        if (!picker) {
            return;
        }
        if (open) {
            picker.removeAttribute('hidden');
            picker.setAttribute('data-ap-editor-emoji-open', '1');
        } else {
            picker.setAttribute('hidden', 'hidden');
            picker.removeAttribute('data-ap-editor-emoji-open');
        }
        if (toggle) {
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        }
    }

    function closeAllPickers(except) {
        var pickers = document.querySelectorAll
            ? document.querySelectorAll('[data-ap-editor-emoji-picker]')
            : [];
        for (var i = 0; i < pickers.length; i++) {
            if (except && pickers[i] === except) {
                continue;
            }
            var wrap = closestWrap(pickers[i]);
            setPickerOpen(pickers[i], false, getEmojiToggle(getToolbar(wrap)));
        }
    }

    function toggleEmojiPicker(btn, surface) {
        var wrap = closestWrap(btn);
        var picker = wrap ? wrap.querySelector('[data-ap-editor-emoji-picker]') : null;
        if (!picker) {
            return;
        }
        var isOpen = !picker.hasAttribute('hidden');
        if (isOpen) {
            setPickerOpen(picker, false, btn);
        } else {
            closeAllPickers(picker);
            setPickerOpen(picker, true, btn);
            focusSurface(surface);
        }
    }

    function handleVisualCommand(btn, surface, wrap) {
        var cmd = btn.getAttribute('data-ap-editor-cmd') || '';
        var visual = btn.getAttribute('data-ap-editor-visual') || '';
        var block = btn.getAttribute('data-ap-editor-block') || '';
        var text = btn.getAttribute('data-ap-editor-text') || '';
        var isEmoji = btn.getAttribute('data-ap-emoji') === '1';

        focusSurface(surface);

        switch (cmd) {
            case 'emoji-picker':
                toggleEmojiPicker(btn, surface);
                break;
            case 'visual':
                if (visual) {
                    exec(visual);
                }
                break;
            case 'visual-block':
                if (block) {
                    // Toggle-ish: formatBlock with tag name.
                    exec('formatBlock', block);
                }
                break;
            case 'visual-link': {
                var url = promptUrl('https://');
                if (url === null) {
                    return;
                }
                exec('createLink', url);
                break;
            }
            case 'visual-unlink':
                exec('unlink');
                break;
            case 'visual-code':
                wrapSelectionWithTag('code');
                break;
            case 'visual-img': {
                var imgUrl = promptUrl('https://');
                if (imgUrl === null) {
                    return;
                }
                var safe = String(imgUrl)
                    .replace(/&/g, '&amp;')
                    .replace(/"/g, '&quot;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;');
                // Optional display width so huge images do not blow out the layout.
                var widthHint = '';
                try {
                    var wRaw = window.prompt(
                        'Display width in pixels (optional, leave blank for auto):',
                        ''
                    );
                    if (wRaw !== null && String(wRaw).trim() !== '') {
                        var wNum = parseInt(String(wRaw).trim(), 10);
                        if (wNum > 0 && wNum <= 10000) {
                            widthHint = ' width="' + wNum + '" style="max-width:100%;height:auto"';
                        }
                    }
                } catch (eImg) { /* ignore */ }
                if (widthHint === '') {
                    widthHint = ' style="max-width:100%;height:auto"';
                }
                insertHtml('<img src="' + safe + '" alt="" class="ap-content-image"' + widthHint + '>');
                break;
            }
            case 'insert':
                if (text) {
                    // Emoji / plain text insert at caret.
                    insertHtml(text);
                }
                if (isEmoji) {
                    // Keep picker open for multi-insert.
                }
                break;
            default:
                // Legacy markup cmds ignored in visual mode.
                break;
        }

        syncSurfaceToTextarea(wrap);
    }

    function bindToolbar(toolbar) {
        if (!toolbar || toolbar.getAttribute('data-ap-editor-bound') === '1') {
            return;
        }
        toolbar.setAttribute('data-ap-editor-bound', '1');
        toolbar.addEventListener('click', function (e) {
            var t = e.target;
            if (!t || !t.closest) {
                return;
            }
            // Mode switch is handled separately.
            if (t.closest('[data-ap-editor-set-mode]')) {
                return;
            }
            var btn = t.closest('[data-ap-editor-cmd]');
            if (!btn || !toolbar.contains(btn)) {
                return;
            }
            e.preventDefault();
            var wrap = closestWrap(toolbar);
            if (!wrap || getActiveMode(wrap) === 'html') {
                return;
            }
            var surface = getSurface(wrap);
            if (!surface) {
                return;
            }
            handleVisualCommand(btn, surface, wrap);
        });
    }

    function bindEmojiPicker(picker) {
        if (!picker || picker.getAttribute('data-ap-editor-emoji-bound') === '1') {
            return;
        }
        picker.setAttribute('data-ap-editor-emoji-bound', '1');
        picker.addEventListener('click', function (e) {
            var t = e.target;
            if (!t || !t.closest) {
                return;
            }
            var closeBtn = t.closest('[data-ap-editor-emoji-close]');
            if (closeBtn && picker.contains(closeBtn)) {
                e.preventDefault();
                var wrapClose = closestWrap(picker);
                setPickerOpen(picker, false, getEmojiToggle(getToolbar(wrapClose)));
                return;
            }
            var btn = t.closest('[data-ap-editor-cmd]');
            if (!btn || !picker.contains(btn)) {
                return;
            }
            e.preventDefault();
            var wrap = closestWrap(picker);
            var surface = getSurface(wrap);
            if (!surface) {
                return;
            }
            handleVisualCommand(btn, surface, wrap);
        });
    }

    function syncTextareaToSurface(wrap) {
        var surface = getSurface(wrap);
        var ta = getTextarea(wrap);
        if (!surface || !ta) {
            return;
        }
        surface.innerHTML = ta.value || '';
    }

    function getActiveMode(wrap) {
        if (!wrap) {
            return 'visual';
        }
        var m = wrap.getAttribute('data-ap-editor-ui-mode') || 'visual';
        return m === 'html' ? 'html' : 'visual';
    }

    function updateModeButtons(wrap, mode) {
        var switcher = wrap.querySelector('[data-ap-editor-mode-switch]');
        if (!switcher) {
            return;
        }
        var buttons = switcher.querySelectorAll('[data-ap-editor-set-mode]');
        for (var i = 0; i < buttons.length; i++) {
            var btn = buttons[i];
            var isOn = btn.getAttribute('data-ap-editor-set-mode') === mode;
            if (isOn) {
                btn.classList.add('is-active');
                btn.setAttribute('aria-pressed', 'true');
            } else {
                btn.classList.remove('is-active');
                btn.setAttribute('aria-pressed', 'false');
            }
        }
    }

    /**
     * Switch between Visual (contenteditable) and Text/HTML source (textarea).
     * @param {Element} wrap
     * @param {string} mode 'visual' | 'html'
     */
    function setMode(wrap, mode) {
        if (!wrap) {
            return;
        }
        mode = mode === 'html' ? 'html' : 'visual';
        var ta = getTextarea(wrap);
        var surface = getSurface(wrap);
        if (!ta || !surface) {
            return;
        }

        var prev = getActiveMode(wrap);
        if (prev === mode && wrap.getAttribute('data-ap-editor-enhanced') === '1') {
            updateModeButtons(wrap, mode);
            return;
        }

        if (mode === 'html') {
            // Push visual surface → textarea before showing source.
            if (prev === 'visual' || wrap.classList.contains('ap-editor--visual-active')) {
                syncSurfaceToTextarea(wrap);
            }
            wrap.setAttribute('data-ap-editor-ui-mode', 'html');
            wrap.classList.remove('ap-editor--visual-active');
            wrap.classList.add('ap-editor--html-active');
            surface.setAttribute('hidden', 'hidden');
            surface.removeAttribute('contenteditable');
            surface.setAttribute('aria-hidden', 'true');

            ta.classList.remove('ap-editor__textarea--hidden');
            ta.removeAttribute('aria-hidden');
            ta.tabIndex = 0;
            ta.setAttribute('data-ap-editor-mode', 'html');
            try {
                ta.focus({ preventScroll: true });
            } catch (e) {
                ta.focus();
            }
        } else {
            // Text → visual: load textarea into surface.
            if (prev === 'html' || wrap.classList.contains('ap-editor--html-active')) {
                syncTextareaToSurface(wrap);
            }
            wrap.setAttribute('data-ap-editor-ui-mode', 'visual');
            wrap.classList.remove('ap-editor--html-active');
            wrap.classList.add('ap-editor--visual-active');
            surface.removeAttribute('hidden');
            surface.removeAttribute('aria-hidden');
            surface.setAttribute('contenteditable', 'true');
            surface.setAttribute('role', 'textbox');
            surface.setAttribute('aria-multiline', 'true');

            ta.classList.add('ap-editor__textarea--hidden');
            ta.setAttribute('aria-hidden', 'true');
            ta.tabIndex = -1;
            ta.setAttribute('data-ap-editor-mode', 'visual');
            focusSurface(surface);
            syncSurfaceToTextarea(wrap);
        }

        updateModeButtons(wrap, mode);
    }

    function bindModeSwitch(wrap) {
        if (!wrap || wrap.getAttribute('data-ap-editor-mode-bound') === '1') {
            return;
        }
        var switcher = wrap.querySelector('[data-ap-editor-mode-switch]');
        if (!switcher) {
            return;
        }
        wrap.setAttribute('data-ap-editor-mode-bound', '1');
        switcher.addEventListener('click', function (e) {
            var t = e.target;
            if (!t || !t.closest) {
                return;
            }
            var btn = t.closest('[data-ap-editor-set-mode]');
            if (!btn || !switcher.contains(btn)) {
                return;
            }
            e.preventDefault();
            setMode(wrap, btn.getAttribute('data-ap-editor-set-mode') || 'visual');
        });
    }

    function enhanceWrap(wrap) {
        if (!wrap || wrap.getAttribute('data-ap-editor-enhanced') === '1') {
            return;
        }
        var ta = getTextarea(wrap);
        var surface = getSurface(wrap);
        if (!ta || !surface) {
            return;
        }
        wrap.setAttribute('data-ap-editor-enhanced', '1');
        wrap.classList.add('ap-editor--visual-active');
        wrap.setAttribute('data-ap-editor-ui-mode', 'visual');

        // Show visual surface; keep textarea for submit (visually hidden).
        surface.removeAttribute('hidden');
        surface.setAttribute('contenteditable', 'true');
        surface.setAttribute('role', 'textbox');
        surface.setAttribute('aria-multiline', 'true');
        if (!surface.getAttribute('aria-label')) {
            var label = wrap.querySelector('label.ap-editor__label');
            if (label && label.textContent) {
                surface.setAttribute('aria-label', label.textContent.trim());
            } else {
                surface.setAttribute('aria-label', 'Content');
            }
        }

        // Prefer server-rendered surface HTML; fall back to textarea value.
        if (isEmptyHtml(surface.innerHTML) && ta.value) {
            surface.innerHTML = ta.value;
        }

        ta.classList.add('ap-editor__textarea--hidden');
        ta.setAttribute('aria-hidden', 'true');
        ta.tabIndex = -1;

        var sync = function () {
            if (getActiveMode(wrap) !== 'visual') {
                return;
            }
            syncSurfaceToTextarea(wrap);
        };
        surface.addEventListener('input', sync);
        surface.addEventListener('blur', sync);
        surface.addEventListener('keyup', sync);
        surface.addEventListener('paste', function () {
            // After paste settles, re-sync cleaned HTML.
            window.setTimeout(sync, 0);
        });

        // Placeholder behaviour.
        var placeholder = ta.getAttribute('placeholder') || '';
        if (placeholder) {
            surface.setAttribute('data-placeholder', placeholder);
            var updateEmpty = function () {
                if (isEmptyHtml(surface.innerHTML)) {
                    surface.classList.add('ap-editor__surface--empty');
                } else {
                    surface.classList.remove('ap-editor__surface--empty');
                }
            };
            surface.addEventListener('input', updateEmpty);
            surface.addEventListener('blur', updateEmpty);
            updateEmpty();
        }

        var form = wrap.closest ? wrap.closest('form') : null;
        if (form && form.getAttribute('data-ap-editor-submit-bound') !== '1') {
            form.setAttribute('data-ap-editor-submit-bound', '1');
            form.addEventListener('submit', function () {
                var wraps = form.querySelectorAll('[data-ap-editor-wrap]');
                for (var i = 0; i < wraps.length; i++) {
                    if (getActiveMode(wraps[i]) === 'visual') {
                        syncSurfaceToTextarea(wraps[i]);
                    }
                }
            });
        }

        bindModeSwitch(wrap);
        updateModeButtons(wrap, 'visual');

        // Initial sync so saved HTML matches what is shown.
        sync();
    }

    function init(root) {
        root = root || document;
        var wraps = root.querySelectorAll
            ? root.querySelectorAll('[data-ap-editor-wrap]')
            : [];
        for (var w = 0; w < wraps.length; w++) {
            enhanceWrap(wraps[w]);
        }
        var toolbars = root.querySelectorAll
            ? root.querySelectorAll('[data-ap-editor-toolbar]')
            : [];
        for (var i = 0; i < toolbars.length; i++) {
            bindToolbar(toolbars[i]);
        }
        var pickers = root.querySelectorAll
            ? root.querySelectorAll('[data-ap-editor-emoji-picker]')
            : [];
        for (var j = 0; j < pickers.length; j++) {
            bindEmojiPicker(pickers[j]);
        }
    }

    if (!window.AP_Editor_emojiDocBound) {
        window.AP_Editor_emojiDocBound = true;
        document.addEventListener('click', function (e) {
            var t = e.target;
            if (!t || !t.closest) {
                return;
            }
            if (t.closest('[data-ap-editor-emoji-picker]')
                || t.closest('[data-ap-editor-cmd="emoji-picker"]')) {
                return;
            }
            closeAllPickers(null);
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' || e.keyCode === 27) {
                closeAllPickers(null);
            }
        });
    }

    window.AP_Editor = {
        init: init,
        enhance: enhanceWrap,
        bindToolbar: bindToolbar,
        bindEmojiPicker: bindEmojiPicker,
        closeEmojiPickers: closeAllPickers,
        sync: syncSurfaceToTextarea,
        setMode: setMode,
        getMode: getActiveMode,
        placeCaretAtEnd: placeCaretAtEnd
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            init(document);
        });
    } else {
        init(document);
    }
})();
