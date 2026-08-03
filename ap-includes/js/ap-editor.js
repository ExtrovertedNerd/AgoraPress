/**
 * AgoraPress classic editor toolbar (no jQuery).
 *
 * Progressive enhancement: buttons insert Markdown / BBCode / HTML around the
 * textarea selection. Safe when JS is disabled (plain textarea remains).
 */
(function () {
    'use strict';

    function getTextarea(toolbar) {
        var id = toolbar.getAttribute('data-ap-editor-for') || '';
        if (id) {
            var byId = document.getElementById(id);
            if (byId && byId.tagName === 'TEXTAREA') {
                return byId;
            }
        }
        var wrap = toolbar.closest('[data-ap-editor-wrap]');
        if (wrap) {
            return wrap.querySelector('textarea[data-ap-editor]');
        }
        return null;
    }

    function insertAtCursor(ta, before, selected, after) {
        var start = typeof ta.selectionStart === 'number' ? ta.selectionStart : ta.value.length;
        var end = typeof ta.selectionEnd === 'number' ? ta.selectionEnd : start;
        var val = ta.value;
        var mid = selected !== null && selected !== undefined
            ? selected
            : val.substring(start, end);
        var next = val.substring(0, start) + before + mid + after + val.substring(end);
        ta.value = next;
        var cursor = start + before.length + mid.length + after.length;
        var selStart = start + before.length;
        var selEnd = selStart + mid.length;
        ta.focus();
        if (typeof ta.setSelectionRange === 'function') {
            if (mid.length === 0) {
                ta.setSelectionRange(selStart, selStart);
            } else {
                ta.setSelectionRange(selStart, selEnd);
            }
        }
        // Notify listeners (autosave hooks, etc.).
        try {
            ta.dispatchEvent(new Event('input', { bubbles: true }));
        } catch (e) {
            /* older browsers */
        }
        return cursor;
    }

    function selectedText(ta) {
        var start = typeof ta.selectionStart === 'number' ? ta.selectionStart : 0;
        var end = typeof ta.selectionEnd === 'number' ? ta.selectionEnd : 0;
        return ta.value.substring(start, end);
    }

    function expandToFullLines(ta) {
        var start = typeof ta.selectionStart === 'number' ? ta.selectionStart : 0;
        var end = typeof ta.selectionEnd === 'number' ? ta.selectionEnd : 0;
        var val = ta.value;
        while (start > 0 && val.charAt(start - 1) !== '\n') {
            start--;
        }
        while (end < val.length && val.charAt(end) !== '\n') {
            end++;
        }
        return { start: start, end: end, text: val.substring(start, end) };
    }

    function replaceRange(ta, start, end, replacement) {
        var val = ta.value;
        ta.value = val.substring(0, start) + replacement + val.substring(end);
        ta.focus();
        if (typeof ta.setSelectionRange === 'function') {
            ta.setSelectionRange(start, start + replacement.length);
        }
        try {
            ta.dispatchEvent(new Event('input', { bubbles: true }));
        } catch (e) { /* noop */ }
    }

    function prefixLines(ta, prefix, placeholder) {
        var sel = selectedText(ta);
        if (sel === '') {
            insertAtCursor(ta, prefix, placeholder || '', '');
            return;
        }
        var range = expandToFullLines(ta);
        var lines = range.text.split(/\r?\n/);
        var out = lines.map(function (line) {
            if (line === '') {
                return line;
            }
            // Avoid double-prefix when re-clicked.
            if (line.indexOf(prefix) === 0) {
                return line;
            }
            return prefix + line;
        }).join('\n');
        replaceRange(ta, range.start, range.end, out);
    }

    function bbcodeList(ta, ordered) {
        var sel = selectedText(ta);
        var placeholder = 'list item';
        var lines;
        if (sel === '') {
            lines = [placeholder];
        } else {
            lines = sel.split(/\r?\n/).filter(function (l) { return l.trim() !== ''; });
            if (lines.length === 0) {
                lines = [placeholder];
            }
        }
        var body = lines.map(function (l) { return '[*]' + l.replace(/^[-*+\d.]+\s+/, ''); }).join('\n');
        var open = ordered ? '[list=1]\n' : '[list]\n';
        var close = '\n[/list]';
        insertAtCursor(ta, open, body, close);
    }

    function htmlList(ta, ordered) {
        var sel = selectedText(ta);
        var placeholder = 'list item';
        var lines;
        if (sel === '') {
            lines = [placeholder];
        } else {
            lines = sel.split(/\r?\n/).filter(function (l) { return l.trim() !== ''; });
            if (lines.length === 0) {
                lines = [placeholder];
            }
        }
        var tag = ordered ? 'ol' : 'ul';
        var body = lines.map(function (l) {
            return '  <li>' + l.replace(/^[-*+\d.]+\s+/, '') + '</li>';
        }).join('\n');
        insertAtCursor(ta, '<' + tag + '>\n', body, '\n</' + tag + '>');
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

    function getEditorWrap(el) {
        return el && el.closest ? el.closest('[data-ap-editor-wrap]') : null;
    }

    function getEmojiPicker(fromEl) {
        var wrap = getEditorWrap(fromEl);
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
            var wrap = getEditorWrap(pickers[i]);
            var toolbar = wrap
                ? wrap.querySelector('[data-ap-editor-toolbar]')
                : null;
            setPickerOpen(pickers[i], false, getEmojiToggle(toolbar));
        }
    }

    function toggleEmojiPicker(btn, ta) {
        var wrap = getEditorWrap(btn);
        var picker = wrap
            ? wrap.querySelector('[data-ap-editor-emoji-picker]')
            : null;
        if (!picker) {
            return;
        }
        var isOpen = !picker.hasAttribute('hidden');
        if (isOpen) {
            setPickerOpen(picker, false, btn);
        } else {
            closeAllPickers(picker);
            setPickerOpen(picker, true, btn);
            if (ta && typeof ta.focus === 'function') {
                // Keep focus ready for insert without scrolling away.
                try { ta.focus({ preventScroll: true }); } catch (e) { ta.focus(); }
            }
        }
    }

    function handleCommand(btn, ta) {
        var cmd = btn.getAttribute('data-ap-editor-cmd') || '';
        var before = btn.getAttribute('data-ap-editor-before') || '';
        var after = btn.getAttribute('data-ap-editor-after') || '';
        var prefix = btn.getAttribute('data-ap-editor-prefix') || '';
        var placeholder = btn.getAttribute('data-ap-editor-placeholder') || '';
        var text = btn.getAttribute('data-ap-editor-text') || '';
        var template = btn.getAttribute('data-ap-editor-template') || '';
        var ordered = btn.getAttribute('data-ap-editor-ordered') === '1';
        var sel = selectedText(ta);
        var isEmoji = btn.getAttribute('data-ap-emoji') === '1';

        switch (cmd) {
            case 'emoji-picker':
                toggleEmojiPicker(btn, ta);
                break;
            case 'wrap':
                insertAtCursor(ta, before, sel !== '' ? sel : placeholder, after);
                break;
            case 'prefix-line':
            case 'prefix-lines':
                prefixLines(ta, prefix, placeholder);
                break;
            case 'insert':
                insertAtCursor(ta, text, '', '');
                if (isEmoji) {
                    // Keep picker open so users can insert multiple emojis;
                    // only close when they click outside or press Escape.
                }
                break;
            case 'link': {
                var url = promptUrl('https://');
                if (url === null) {
                    return;
                }
                var b = before.replace(/%url%/g, url);
                var a = after.replace(/%url%/g, url);
                insertAtCursor(ta, b, sel !== '' ? sel : (placeholder || url), a);
                break;
            }
            case 'md-link': {
                var mdUrl = promptUrl('https://');
                if (mdUrl === null) {
                    return;
                }
                var label = sel !== '' ? sel : (placeholder || 'link text');
                insertAtCursor(ta, '[', label, '](' + mdUrl + ')');
                break;
            }
            case 'img': {
                var imgUrl = promptUrl('https://');
                if (imgUrl === null) {
                    return;
                }
                var tpl = template || '[img]%url%[/img]';
                insertAtCursor(ta, tpl.replace(/%url%/g, imgUrl), '', '');
                break;
            }
            case 'bbcode-list':
                bbcodeList(ta, ordered);
                break;
            case 'html-list':
                htmlList(ta, ordered);
                break;
            default:
                break;
        }
    }

    function bindToolbar(toolbar) {
        if (toolbar.getAttribute('data-ap-editor-bound') === '1') {
            return;
        }
        toolbar.setAttribute('data-ap-editor-bound', '1');
        toolbar.addEventListener('click', function (e) {
            var t = e.target;
            if (!t || !t.closest) {
                return;
            }
            var btn = t.closest('[data-ap-editor-cmd]');
            if (!btn || !toolbar.contains(btn)) {
                return;
            }
            e.preventDefault();
            var ta = getTextarea(toolbar);
            if (!ta) {
                return;
            }
            handleCommand(btn, ta);
        });
    }

    function bindEmojiPicker(picker) {
        if (picker.getAttribute('data-ap-editor-emoji-bound') === '1') {
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
                var wrap = getEditorWrap(picker);
                var toolbar = wrap
                    ? wrap.querySelector('[data-ap-editor-toolbar]')
                    : null;
                setPickerOpen(picker, false, getEmojiToggle(toolbar));
                return;
            }
            var btn = t.closest('[data-ap-editor-cmd]');
            if (!btn || !picker.contains(btn)) {
                return;
            }
            e.preventDefault();
            var forId = picker.getAttribute('data-ap-editor-for') || '';
            var ta = forId ? document.getElementById(forId) : null;
            if (!ta) {
                var wrap2 = getEditorWrap(picker);
                ta = wrap2
                    ? wrap2.querySelector('textarea[data-ap-editor]')
                    : null;
            }
            if (!ta) {
                return;
            }
            handleCommand(btn, ta);
        });
    }

    function init(root) {
        root = root || document;
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

    // Close pickers on outside click / Escape (once).
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

    // Public API for dynamic forms.
    window.AP_Editor = {
        init: init,
        bindToolbar: bindToolbar,
        bindEmojiPicker: bindEmojiPicker,
        closeEmojiPickers: closeAllPickers
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            init(document);
        });
    } else {
        init(document);
    }
})();
