# AgoraPress Classic Editor

The core content editor is **lightweight and classic**. Full block / Gutenberg
editors are a **non-goal for core** (see FEATURES.md and the product vision).

## What ships

| Piece | Path | Role |
|-------|------|------|
| `AP_Editor` | `ap-includes/class-ap-editor.php` | Toolbar + textarea renderer |
| CSS | `ap-includes/css/ap-editor.css` | Toolbar + emoji picker styles |
| JS | `ap-includes/js/ap-editor.js` | Vanilla progressive enhancement |

- **Architecture:** `classic` (always). See `AP_Editor::architecture()`,
  `AP_Editor::isBlockEditor()` (always `false`), `AP_Editor::isLightweight()`.
- **Markup modes:** `markdown` (posts/pages/comments), `bbcode` (forums),
  `html` (optional). There is **no** `blocks` mode.
- **Control:** a normal `<textarea>` with a Quicktags-style formatting toolbar.
  Buttons wrap or prefix the selection; they do **not** build a block tree.
- **Emoji picker:** Unicode characters only (no image sprites, no remote CDN).
- **No jQuery** in the editor script. Soft budgets: JS ≤ 32 KiB, CSS ≤ 16 KiB.

## Usage

```php
// Render (returns HTML string).
echo ap_editor([
    'id'    => 'post_content',
    'name'  => 'post_content',
    'value' => $content,
    'mode'  => AP_Editor::modeForContext('post'), // markdown
    'rows'  => 14,
    'label' => 'Content',
]);

// Forum reply example.
echo ap_editor([
    'id'   => 'reply_body',
    'name' => 'reply_body',
    'mode' => AP_Editor::modeForContext('forum'), // bbcode
]);
```

Helpers: `ap_editor()`, `ap_the_editor()`, `ap_enqueue_editor()`,
`ap_print_editor_assets()`.

## Progressive enhancement

With JavaScript disabled the plain textarea still submits. Assets are enqueued
via `AP_Assets` when available, with an idempotent print fallback so forms that
render after `ap_head()` still get CSS/JS once.

## Filters

| Filter | Purpose |
|--------|---------|
| `ap_editor_buttons` | Adjust toolbar button definitions per mode |
| `ap_editor_emojis` | Adjust the emoji catalog |
| `ap_editor_mode` | Override default mode for a context slug |

## Explicit non-goals

- Gutenberg / block editor canvas in core  
- `contenteditable` rich-text surfaces as the primary control  
- Heavy third-party editor runtimes (TinyMCE, Quill, ProseMirror, Lexical, …)  
- Block-serialized post content (`<!-- wp:... -->`) as the core format  

Plugins may ship alternative editors, but must not rebrand `AP_Editor` as a
block editor. Prefer a separate package and opt-in UI.

## Related

- [Hooks](hooks.md) — `ap_format_content` and editor filters  
- [Vision compliance](vision-compliance.md) — Gutenberg non-goal  
- [Compatibility](compatibility.md) — block/FSE themes out of scope  
