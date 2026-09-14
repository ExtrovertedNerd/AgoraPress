# AgoraPress Visual Editor

This is the **visual editor contract** for AgoraPress **`0.3.10-beta`** (schema
`AP_DB_VERSION` **13**). The core content editor is a **lightweight classic
visual WYSIWYG**. Full block / Gutenberg / FSE editors remain a **non-goal for
core** (see [vision-compliance.md](vision-compliance.md)) — they are **not in core**.

ACP compose screens: [admin.md](admin.md). Forum reply surface: [forums.md](forums.md).
Theme gold-plating (`--ap-editor-*`): [themes.md](themes.md#editor-contrast).

## What ships

| Piece | Path | Role |
|-------|------|------|
| `AP_Editor` | `ap-includes/class-ap-editor.php` | Toolbar + visual surface + textarea |
| CSS | `ap-includes/css/ap-editor.css` | Toolbar, surface, emoji picker; inherits page `color-scheme` |
| Spoiler CSS | `ap-includes/css/ap-spoiler.css` | Native `<details class="ap-spoiler">` (editor + published) |
| JS | `ap-includes/js/ap-editor.js` | Vanilla progressive enhancement |

- **Architecture:** `classic` (always). See `AP_Editor::architecture()`,
  `AP_Editor::isBlockEditor()` (always `false`), `AP_Editor::isLightweight()`,
  `AP_Editor::isVisual()` (always `true`).
- **Editing surface:** a `contenteditable` div that shows **formatted HTML as you
  type** (bold looks bold, headings look like headings). A hidden `<textarea>`
  holds the value submitted with the form.
- **Visual | Text modes:** toolbar switcher toggles between the WYSIWYG surface
  and raw source (Text) for long crypto addresses, embeds, and fine-grained
  markup. Visual syncs HTML into the textarea. Text can also insert spoiler
  BBCode (`[spoiler]…[/spoiler]`); `AP_Content_Format` converts that on display
  and when the editor reopens.
- **Storage:** HTML (whitelist-sanitized on display via `AP_Content_Format`).
  Legacy Markdown / BBCode (including spoilers) converts when opened in the
  editor and when published via `ap_the_content`.
- **Emoji picker:** Unicode characters only (no image sprites, no remote CDN).
- **No jQuery.** Soft budgets: JS ≤ 48 KiB, CSS ≤ 24 KiB.
- **No block tree** and no third-party editor runtimes (TinyMCE, Quill, …).

Where core actually renders it:

| Surface | Path |
|---------|------|
| ACP post / page compose | `ap-admin/includes/class-ap-admin-post-edit.php` (`AP_Editor::render`) |
| ACP comment edit | `ap-admin/comment.php` (`ap_editor()`, context `comment`) |
| Agora blog comments | `ap-content/themes/agora/comments.php` (`ap_editor()`, context `comment`; loaded by `ap_comments_template()` from `single.php`) |
| Core comments fallback | `ap-includes/theme-compat/comments.php` (`ap_editor()`, context `comment`) |
| Agora forum new topic / reply | `forum-view.php`, `topic.php` (context `forum`) |

## Contrast contract

`AP_Editor` (toolbar + visual surface + textarea) must stay readable on a
**dark page** even when the active theme is **not** Agora and does **not**
define `--ap-*` tokens. Core stylesheet: `ap-includes/css/ap-editor.css`.
Optional theme tokens: [themes.md](themes.md#editor-contrast).

| Contract | As built |
|----------|----------|
| Inherit | `.ap-editor` sets `color-scheme: inherit`. Toolbar, surface, and textarea do the same. |
| Dark hosts | Honors `color-scheme: dark` on `html` / `body` (custom themes), `html.agora-mode-dark` / `body.agora-mode-dark` (Agora), and `[data-ap-color-mode=dark]` (ACP sets this on `<html>`). Dark chrome does **not** depend only on Agora classes or `--ap-*` tokens. |
| Pairing | Toolbar / chrome: `Canvas` / `CanvasText` (via `--ap-editor-bg` / `--ap-editor-fg` when set). Surface + textarea: `Field` / `FieldText` (via `--ap-editor-surface` / `--ap-editor-fg`). Border: `--ap-editor-border`, else `currentColor` at low opacity. |
| Buttons | `currentColor` on a transparent background. Isolation then locks wrapper-qualified toolbar buttons to chrome foreground so a theme `button { color: inherit }` cannot bleach letter labels (`B`, `I`, `Link`) onto a light toolbar. |
| Emoji | Unicode glyph only. No third-party icon font. |

**Optional theme tokens** (unset = page `--ap-*`, then system colors). Themes
**may** gold-plate these. They are **not** required when the page sets
`color-scheme: dark` and uses light text.

| Token | Role |
|-------|------|
| `--ap-editor-bg` | Toolbar / chrome background |
| `--ap-editor-fg` | Toolbar / chrome / surface text |
| `--ap-editor-surface` | Visual surface + textarea background |
| `--ap-editor-border` | Chrome border |
| `--ap-on-accent` | Active Visual \| Text chip text (fallback `#fff`) |

Agora’s six schemes still win when present (`body.agora-theme` maps
`--ap-editor-*` onto scheme tokens). Do **not** add site-specific theme CSS to
core. Generic examples only (`example.com`). Do not name private hosts, persona mailboxes,
live fleet inventory, or add-on skins here.

**Not in core**

- An ACP `admin.css` dark-mode overhaul (separate surface)
- A toolbar redesign or a new emoji set
- Per-theme patches in core for named custom skins

If a custom theme is still unusable after this contract, that is a follow-on
theme CSS pass.

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
fallback so forms that render after `ap_head()` still get CSS/JS once. Spoiler
CSS (`ap-spoiler.css`) is printed with those editor assets so the visual
surface matches published spoilers.

## Spoilers

Toolbar **Spoiler** is a core `AP_Editor` button (`id` `spoiler`, command
`visual-spoiler`). It ships on every compose surface that uses the widget:
post, page, comment, and forum. There is no per-context hide.

| Mode | Button does |
|------|-------------|
| Visual | Wraps the selection in native `<details class="ap-spoiler">`. Empty selection still inserts a spoiler so the author can type inside. Summary label is **Spoiler**. |
| Text | Wraps the textarea selection with `[spoiler]` … `[/spoiler]` (`data-ap-editor-wrap-open` / `wrap-close`). Empty selection inserts the pair and leaves the caret between the tags. |

Works with JavaScript off: published markup is native `<details>` /
`<summary>` (Enter / Space on the summary). There is **no**
`ap-includes/js/ap-spoiler.js`.

### Stored markup

One conversion path: `AP_Content_Format` (BBCode → HTML). **Not** an
`AP_Shortcode` tag — `AP_Shortcode::doShortcode()` must not wrap the same
block again.

Authors may store either form:

| Stored | Typical origin |
|--------|----------------|
| Native HTML `<details class="ap-spoiler">` | Visual button, or Text while editing HTML source |
| BBCode `[spoiler]…[/spoiler]` | Text button, legacy posts, or typed markup |

Accepted BBCode (format **or** reopen in the editor via
`AP_Editor::valueToHtml()`):

```text
[spoiler]hidden[/spoiler]
[spoiler=Label]hidden[/spoiler]
[spoiler title="Label"]hidden[/spoiler]
```

Single-quoted `title='Label'` is accepted too. Default summary label:
**Spoiler**. Empty / whitespace-only body: render **nothing** (no empty
`<details>`). Nested spoilers: innermost first, then one extra pass (one
nesting level).

Output (kses keeps `<details>`, `<summary>`, `class`, and `open`, plus
allow-listed inner markup):

```html
<details class="ap-spoiler">
  <summary class="ap-spoiler__summary">Label</summary>
  <div class="ap-spoiler__body">…</div>
</details>
```

A custom label is Text markup (`[spoiler=Label]` / `[spoiler title="Label"]`)
or an edited `<summary>` in HTML source. Visual does **not** prompt for a
label; it always inserts **Spoiler**.

Generic examples only (`example.com`). Do not name private hosts, persona
mailboxes, or live fleet inventory here.

### Closed vs open (core CSS)

Stylesheet: `ap-includes/css/ap-spoiler.css`. `.ap-spoiler` uses
`color-scheme: inherit`. Closed body is unreadable (`display: none` on
`.ap-spoiler:not([open]) > .ap-spoiler__body`, not hover-only). Open body
follows the page `color-scheme` (no forced light/dark paint). Native
disclosure marker; `:focus-visible` ring on the summary. No images, icon
fonts, or background images.

### Snippets

Inner spoiler text must not leak into excerpts, feeds, Open Graph, search
snippets, or forum last-post blurbs. Shared helper:
`AP_Content_Format::stripSpoilers()` / `ap_strip_spoilers()`. Default
placeholder **`[Spoiler]`**; pass `''` to drop the block.

### Not this

- A per-forum “everything in this room is spoilered” flag
- Hover-only reveal
- A Visual prompt for a custom summary label
- An `AP_Shortcode` handler named `spoiler`
- Guest-only / JavaScript-required disclosure

## Display pipeline

Published posts/pages run `ap_the_content` → `AP_Content_Format::format()` (mode
`auto`) → `AP_Shortcode::doShortcode()`. That means:

1. Visual HTML is kses-sanitized and shown correctly (including stored
   `<details class="ap-spoiler">`).
2. Older Markdown / BBCode posts still convert to HTML (no raw `**` / `[b]`
   characters on the front-end).
3. `[spoiler]`, `[spoiler=Label]`, and `[spoiler title="Label"]` become native
   `<details class="ap-spoiler">` in the format step — not a shortcode.
4. Shortcode handler output is not re-escaped.

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
- Hover-only spoilers, a per-forum spoiler flag, or a second spoiler shortcode  

Plugins may ship alternative editors, but must not rebrand `AP_Editor` as a
block editor. Prefer a separate package and opt-in UI.

## Related docs

| Need | Doc |
|------|-----|
| ACP post / page / comment screens | [admin.md](admin.md) |
| Forum reply / topic compose | [forums.md](forums.md) |
| Agora scheme tokens / `--ap-editor-*` gold-plate | [themes.md](themes.md#editor-contrast) |
| `ap_format_content` and editor filters | [hooks.md](hooks.md) |
| Gutenberg non-goal | [vision-compliance.md](vision-compliance.md) |
| Block/FSE themes out of scope | [compatibility.md](compatibility.md) |
| Comment ownership caps | [roles.md](roles.md) |
| Sanitize on save, escape on display | [security.md](security.md) |
| `php ap-cli post` (no visual editor) | [cli.md](cli.md) |
| Custom editors stay out of `AP_Editor` | [plugins.md](plugins.md) |  
