# Zero Shits

Native AgoraPress theme for **[Zero Shits to Give](https://www.0shits.com/)** (`0shits.com`).

Irreverent bathroom-humor skin: porcelain tile background, caution-tape ticker, CSS “no pooping” 💩 ban marks (red circle + slash), stall-graffiti sidebar defaults, and pop-culture taglines (parody nods to South Park, Shrek, LotR, Star Wars, GoT, Idiocracy, Monty Python, etc.).

## Features

- **One shell width** for blog, pages, and forums (`--zs-shell`, max `72rem`)
- **Responsive** from narrow phones to 4K
- **Forum templates** (`forum.php`, `forum-view.php`, `topic.php`, `forum-search.php`)
- **No external image assets required** — ban mark is pure CSS over emoji
- **Modular sidebars** (Primary + Footer); witty defaults when empty

## Activate

1. Copy `ap-content/themes/zeroshits/` onto the site (or deploy from this repo).
2. Appearance → Themes → activate **Zero Shits**.
3. Optional: Appearance → Menus (Primary / Footer).

## Customize

- Colors / shell width: CSS variables in `style.css` (`:root`)
- Taglines: arrays in `header.php` and `footer.php`
- Sidebar defaults: `sidebar.php` (when no widgets assigned)

## License

GPL-2.0-or-later (same as AgoraPress). Pop-culture phrases are parody / fair-use flavor text, not brand endorsements.
