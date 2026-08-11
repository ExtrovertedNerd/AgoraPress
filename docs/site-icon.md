# Site Icon (favicon pack)

Admins set one **site icon** under **Settings → General**. Core stores an attachment
ID, generates a standard favicon pack (pixel sizes + optional ICO), and prints
`<link rel="icon">` / `apple-touch-icon` tags on the front end via `ap_head`.
Browsers pick the best asset; core does **not** sniff user agents.

## Admin (how operators add an icon)

| Step | What happens |
|------|----------------|
| Settings → General → Site Icon | Upload a new image, choose from the Media Library, or remove |
| Capability | `manage_options` |
| Save | Nonce-protected; option `site_icon` = attachment ID (`0` = none) |
| Preview | Admin UI shows the current icon when set |

Form helpers live in `AP_Admin_Media` (`renderSiteIconField`, `resolveSiteIconFromRequest`).
General settings persist through `AP_Options::updateGeneralSettings()` / the Settings API
sanitizer for `site_icon`. On change, `AP_Options::applySiteIconChange()` runs:

1. **Cleanup** previous attachment’s site-icon derivatives (if the ID changed).
2. **Generate** the favicon pack for the new attachment (if ID &gt; 0).

Re-saving the same attachment ID regenerates sizes in place.

## Data model

| Piece | Detail |
|-------|--------|
| Option | `site_icon` — integer attachment ID; `0` / unset = no managed icon |
| Helper | `AP_Options::siteIcon(?AP_DB $db = null): int` |
| Attachment meta | Under `_ap_attachment_metadata` JSON key `site_icon` (`AP_Media::SITE_ICON_META_KEY`) |
| Storage | Derivative files next to the original upload under `ap-content/uploads/` |

Site-icon sizes are **not** stored in intermediate `sizes` (thumbnail / medium / large),
so thumbnail regeneration does not wipe the favicon pack.

## Generated pack (images)

`AP_Media::generateSiteIconSizes($attachmentId)` (also via
`AP_Options::ensureSiteIconDerivatives()`). Pixel list:
`AP_Media::SITE_ICON_SIZES` = `[32, 180, 192, 512]`.

| Size | Format | Role |
|------|--------|------|
| 32 | PNG (square crop) | Classic favicon |
| 180 | PNG | Apple touch icon |
| 192 | PNG | PWA / high-DPI tab |
| 512 | PNG | Large / maskable-style consumers |
| `ico` | `.ico` when possible | Multi-size ICO; falls back to 32px PNG if ICO cannot be written |

Requires **GD or Imagick**. Source must be a **raster** image (SVG is rejected).
Filenames look like `{original}-site_icon-32.png` and `{original}-site_icon.ico`.

### Public helpers

```php
$id = AP_Options::siteIcon(); // 0 when unset

$sizes = AP_Media::getSiteIconSizes($id);     // map: "32"|"180"|"192"|"512"|"ico" → meta
$url   = AP_Media::getSiteIconUrl($id, 32);   // public URL; empty if missing
$path  = AP_Media::getSiteIconPath($id, 180); // absolute path if file exists
$tags  = AP_Media::getSiteIconMetaTags();     // list of <link> HTML strings
```

Cleanup (remove option or replace attachment):

```php
AP_Media::cleanupSiteIconDerivatives($oldAttachmentId);
// Original media file and normal intermediate sizes are left alone.
```

## Front-end use (link tags)

Bootstrap registers `AP_Media::printSiteIconTags` on `ap_head` (priority 2) via
`AP_Media::registerSiteIconTags()`.

When `site_icon` &gt; 0 and derivatives (or the original) are available, head output
includes roughly:

```html
<link rel="icon" href="…-site_icon.ico" sizes="any" type="image/x-icon">
<link rel="icon" href="…-site_icon-32.png" sizes="32x32" type="image/png">
<link rel="icon" href="…-site_icon-192.png" sizes="192x192" type="image/png">
<link rel="icon" href="…-site_icon-512.png" sizes="512x512" type="image/png">
<link rel="apple-touch-icon" href="…-site_icon-180.png" sizes="180x180">
```

If no derivatives exist yet, a single `rel="icon"` to the original attachment URL is
emitted as a last resort.

### Passive root `favicon.ico`

When `site_icon` is **0**, core emits **no** icon link tags and never invents
`<link rel="icon" href="/favicon.ico">`. Operators may still place a static
`favicon.ico` at the web root; rewrite rules leave existing files alone, so browsers
request it as the usual passive default.

### Filter for plugins / themes

```php
ap_add_filter('ap_site_icon_meta_tags', function (array $tags, int $attachmentId, $db): array {
    // Append, reorder, or replace tag strings. Only runs when site_icon > 0.
    return $tags;
}, 10, 3);
```

Themes should keep calling `ap_head()` (Agora does). Do not hardcode competing
favicon markup unless you intentionally replace core output via the filter or by
removing the action.

## Source map

| Area | Files |
|------|--------|
| Generation / URLs / head tags | `ap-includes/class-ap-media.php` |
| Option + change hook | `ap-includes/class-ap-options.php` (`siteIcon`, `applySiteIconChange`) |
| Settings registration | `ap-includes/class-ap-settings.php` (`site_icon` on group `general`) |
| Admin field / upload | `ap-admin/includes/class-ap-admin-media.php`, `ap-admin/options-general.php` |
| Head registration | `ap-includes/bootstrap.php` → `AP_Media::registerSiteIconTags()` |
| Default option | Installer seeds `site_icon` = `0` |

## Related

- Media library overview: [README.md](../README.md) / `AP_Media`  
- Settings API patterns: [plugins.md](plugins.md)  
- Template head lifecycle: [themes.md](themes.md), [hooks.md](hooks.md)  
