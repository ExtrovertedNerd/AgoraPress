# AgoraPress Visual Editor

This is the **visual editor contract** for AgoraPress **`0.3.8-beta`** (schema
`AP_DB_VERSION` **12**). The core content editor is a **lightweight classic
visual WYSIWYG**. Full block / Gutenberg / FSE editors remain a **non-goal for
core** (see [vision-compliance.md](vision-compliance.md)) — they are **not in core**.

ACP compose screens: [admin.md](admin.md). Forum reply surface: [forums.md](forums.md).
Theme field tokens: [themes.md](themes.md).

## What ships

| Piece | Path | Role |
|-------|------|------|
| `AP_Editor` | `ap-includes/class-ap-editor.php` | Toolbar + visual surface + textarea |
| CSS | `ap-includes/css/ap-editor.css` | Toolbar, surface, emoji picker styles |
| JS | `ap-includes/js/ap-editor.js` | Vanilla progressive enhancement |

- **Architecture:** `classic` (always). See `AP_Editor::architecture()`,
  `AP_Editor::isBlockEditor()` (always `false`), `AP_Editor::isLightweight()`,
  `AP_Editor::isVisual()` (always `true`).
- **Editing surface:** a `contenteditable` div that shows **formatted HTML as you
  type** (bold looks bold, headings look like headings). A hidden `<textarea>`
  holds the HTML submitted with the form.
- **Visual | Text modes:** toolbar switcher toggles between the WYSIWYG surface
  and raw HTML source (Text) for long crypto addresses, embeds, and fine-grained
  markup. Both modes store the same HTML.
- **Storage:** HTML (whitelist-sanitized on display via `AP_Content_Format`).
  Legacy Markdown / BBCode content is converted when opened in the editor and
  when published via `ap_the_content`.
- **Emoji picker:** Unicode characters only (no image sprites, no remote CDN).
- **No jQuery.** Soft budgets: JS ≤ 48 KiB, CSS ≤ 24 KiB.
- **No block tree** and no third-party editor runtimes (TinyMCE, Quill, …).

Where core actually renders it:

| Surface | Path |
|---------|------|
| ACP post / page compose | `ap-admin/includes/class-ap-admin-post-edit.php` (`AP_Editor::render`) |
| ACP comment edit | `ap-admin/comment.php` (`ap_editor()`, context `comment`) |
| Agora blog comments | `ap-content/themes/agora/single.php` |
| Agora forum new topic / reply | `forum-view.php`, `topic.php` (context `forum`) |

## Usage

```php
// Render (returns HTML string).
echo ap_editor([
    'id'    => 'post_content',
    'name'  => 'post_content',
    'value' => $content,
    'mode'  => AP_Editor::modeForContext('post'), // visual
    'rows'  => 14,
    'label' => 'Content',
]);

// Forum reply example (same visual editor).
echo ap_editor([
    'id'   => 'reply_body',
    'name' => 'reply_body',
    'mode' => AP_Editor::modeForContext('forum'), // visual
]);
```

Helpers: `ap_editor()`, `ap_the_editor()`, `ap_enqueue_editor()`,
`ap_print_editor_assets()`.

Convert stored content for display or the surface:

```php
$html = AP_Editor::valueToHtml($raw); // auto: Markdown/BBCode/HTML → kses HTML
```

## Progressive enhancement

With JavaScript disabled the plain textarea still submits (pre-filled with
formatted HTML). With JS enabled the visual surface is shown and toolbar buttons
apply formatting via the browser’s editing API (`document.execCommand` +
selection helpers). The **Visual / Text** switcher (`AP_Editor.setMode`) flips
between contenteditable and monospace HTML source without losing content.
Assets are enqueued via `AP_Assets` when available, with an idempotent print
fallback so forms that render after `ap_head()` still get CSS/JS once.

## Display pipeline

Published posts/pages run `ap_the_content` → `AP_Content_Format::format()` (mode
`auto`) → `AP_Shortcode::doShortcode()`. That means:

1. Visual HTML is kses-sanitized and shown correctly.
2. Older Markdown / BBCode posts still convert to HTML (no raw `**` / `[b]`
   characters on the front-end).
3. Shortcode handler output is not re-escaped.

Forum posts continue to store `post_content_filtered` and expose `content_html`
for the Agora topic template.

## Filters

| Filter | Purpose |
|--------|---------|
| `ap_editor_buttons` | Adjust toolbar button definitions |
| `ap_editor_emojis` | Adjust the emoji catalog |
| `ap_editor_mode` | Override default mode for a context slug |

## Explicit non-goals

- Gutenberg / block editor canvas in core  
- Block-serialized post content (`<!-- wp:... -->`) as the core format  
- Heavy third-party editor runtimes (TinyMCE, Quill, ProseMirror, Lexical, …)  

Plugins may ship alternative editors, but must not rebrand `AP_Editor` as a
block editor. Prefer a separate package and opt-in UI.

## Related docs

| Need | Doc |
|------|-----|
| ACP post / page / comment screens | [admin.md](admin.md) |
| Forum reply / topic compose | [forums.md](forums.md) |
| Agora scheme tokens for fields | [themes.md](themes.md) |
| `ap_format_content` and editor filters | [hooks.md](hooks.md) |
| Gutenberg non-goal | [vision-compliance.md](vision-compliance.md) |
| Block/FSE themes out of scope | [compatibility.md](compatibility.md) |
| Comment ownership caps | [roles.md](roles.md) |
| Sanitize on save, escape on display | [security.md](security.md) |
| `php ap-cli post` (no visual editor) | [cli.md](cli.md) |
| Custom editors stay out of `AP_Editor` | [plugins.md](plugins.md) |  
