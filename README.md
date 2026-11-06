![EPUB Library](assets/banner.svg)

# EPUB Library

A small, self-hosted PHP site that turns a folder of EPUB files into a private library for your e-reader **and** your browser. No database, no Composer, no build step — just PHP and a folder of books.

- **E-readers with a limited browser** (e.g. Tolino) get a deliberately plain, server-rendered page: no JavaScript, no webfonts, no external requests.
- **Desktop and mobile browsers** get a proper reading-room UI with covers, live search, sorting, dark mode, and a **Rescan shelf** button.

> **No books are included in this repository.** The `books/` and `cache/` directories are gitignored, and no personal library metadata is hard-coded anywhere in the project. Add your own EPUBs and run the scanner.

## Live instance

A running deployment is available on the local network at:

**http://10.0.0.2:1080/ebooks**

That instance is a separate deployment with its own `books/` and `cache/` directories. It does not share files with this repository, and changes made here are not reflected there until deployed.

## Highlights

- Automatic EPUB metadata extraction: title, author, cover
- Two interfaces selected by User-Agent — no client-side feature detection needed
- Live search by title or author, with accent-insensitive matching
- Sort by title, author, newest, or size
- One-click rescan from the desktop UI
- Cover fallback for books without artwork
- Stale cover files are pruned automatically on each scan
- No-JavaScript download fallback for desktop visitors
- Download endpoint with path-traversal protection and safe filenames
- Responsive layout, light/dark theme, reduced-motion support
- Zero runtime dependencies and zero third-party requests — works fully offline

## Requirements

- PHP **8.0+**
- PHP extensions:
  - `zip` (`ZipArchive`) — required by the scanner
  - `dom` / `libxml` (`DOMDocument`, `DOMXPath`) — required by the scanner
  - `posix` — optional; used only to show the PHP user in permission errors
- A web server that executes PHP (Apache, nginx + PHP-FPM, Caddy, or PHP's built-in server)
- Write access for the PHP user to `cache/` (and `cache/covers/`)

## Quick start

1. Put the project in a directory served by PHP, or point your web server's document root at it.
2. Copy your `.epub` files into `books/`. The folder is flat — subdirectories are not scanned.
3. Make sure the cache is writable by the web server user:

   ```sh
   mkdir -p cache/covers
   chmod -R 775 cache
   # if needed, give ownership to the PHP user, e.g. www-data
   ```

4. Run the first scan:

   ```sh
   php scan.php
   ```

   You can also open `scan.php` in a browser. On success it prints something like `Indexed 12 EPUB files in 0.18s`.

5. Open `index.php`.

For local testing:

```sh
php -S 0.0.0.0:8080
# then open http://localhost:8080/index.php
```

### Serving from a subdirectory

The site works under a path such as `/ebooks/` without configuration: every link, cover URL, and fetch call is relative (`download.php?f=…`, `scan.php`, `cache/covers/…`). Make sure the web server routes the whole prefix to this directory and executes PHP for it.

## Project layout

```
.
├── index.php           # Entry point: e-reader UI + desktop UI
├── scan.php            # Scanner: rebuilds cache/index.json and covers
├── download.php        # Streams an indexed EPUB as an attachment
├── README.md
├── assets/
│   ├── banner.svg      # README banner
│   └── favicon.svg     # Browser icon
├── books/              # Your .epub files (gitignored)
└── cache/              # Generated; safe to delete (gitignored)
    ├── index.json      # Book metadata index
    └── covers/         # Extracted cover images
```

## Interfaces

### Desktop and mobile browsers (`index.php`)

- Masthead with a small bookshelf mark, search field, and sort menu.
- Books are shown as covers standing on wooden shelf boards.
- Search filters by title and author as you type (120 ms debounce, accent-insensitive).
- Sorting uses a numeric-aware collator, so `Book 2` comes before `Book 10`.
- **Rescan shelf** calls `scan.php` via `fetch()`, shows the scanner's status text, then reloads the page.
- If JavaScript is disabled, a server-rendered list of direct download links is shown instead.
- Light and dark themes follow `prefers-color-scheme`.
- Self-contained: no webfonts, CDNs, or third-party requests.

### E-readers and limited browsers

The server detects Tolino-style browsers when the `User-Agent`:

- contains `Tolino`, or
- contains `WebKit/53` but not `Chrome` (older e-ink WebKit browsers).

Those clients receive a separate, minimal page — a single-column list with cover thumbnail, title, author, size/date, and a plain download link. No JavaScript is sent. **There is no rescan button on the e-reader UI**; add books and rescan from a desktop browser or the command line.

### Downloads (`download.php?f=<filename>`)

Only files present in `cache/index.json` are served. The endpoint:

- rejects names that differ from their `basename()`,
- resolves the real path and verifies it stays inside `books/`,
- returns proper `400`, `403`, and `404` responses,
- sends `Content-Type: application/epub+zip` with an attachment filename,
- escapes non-ASCII filenames via RFC 5987 `filename*`.

## How scanning works

`scan.php`:

1. Creates `cache/` and `cache/covers/` if needed and verifies that the cache is writable.
2. Iterates over files directly inside `books/`, skipping dotfiles and anything not ending in `.epub` (case-insensitive).
3. Opens each EPUB as a ZIP and reads `META-INF/container.xml` to find the OPF package file.
4. Reads `<title>` and `<creator>` from the OPF; falls back to the filename for the title.
5. Finds the cover via the OPF `<meta name="cover">` reference, or the manifest item with the `cover-image` property.
6. Extracts the cover and stores it as `cache/covers/<sha1(filename + mtime)>.<ext>`.
7. Removes cover files that no longer belong to an indexed book.
8. Writes the index and returns a short plain-text summary.

The scanner can run over HTTP or from the CLI. `index.php` only reads `cache/index.json`; it never scans the folder itself.

### Index format

`cache/index.json` maps each filename to its metadata. A generic entry looks like this:

```json
{
  "example-book.epub": {
    "file": "example-book.epub",
    "title": "Example Book",
    "author": "Example Author",
    "size": 123456,
    "mtime": 1700000000,
    "cover": "cache/covers/0123456789abcdef0123456789abcdef01234567.jpg"
  }
}
```

`author` and `cover` may be `null`. The file is generated — edit the EPUB metadata, not the JSON.

## Security and privacy

- **There is no authentication.** Run the site on a trusted network, behind a VPN, or put HTTP Basic Auth in front of it.
- **`scan.php` is unauthenticated.** Anyone who can reach it can trigger a rescan. Do not expose it to the public internet unprotected.
- `download.php` only serves files listed in the index and verifies the resolved path stays inside `books/`.
- JSON embedded in `index.php` is encoded with `JSON_HEX_TAG`, so EPUB metadata cannot break out of the `<script>` block.
- `index.php` sends a restrictive Content-Security-Policy plus `X-Content-Type-Options` and `Referrer-Policy` headers.
- The site makes no third-party requests. Typography uses the system serif and sans stacks, so it works fully offline.
- `books/` and `cache/` are gitignored. Do not commit your library or generated covers to a public repository.
- No telemetry, analytics, or outbound calls.

## Customizing

- **Palette and typography:** the `:root` custom properties at the top of the desktop `<style>` block in `index.php`.
- **E-reader detection:** `is_tolino()` in `index.php`. Keep the dedicated UI plain: it is intentionally free of JavaScript and external assets.
- **Paths:** `books/`, `cache/`, and `cache/covers/` are resolved relative to `__DIR__` in `scan.php` and `download.php`.
- **Shelf look:** the wooden board is the `.book::after` rule; cover proportions use `aspect-ratio: 2/3`.

## Troubleshooting

| Symptom | Fix |
| --- | --- |
| "Your shelf is empty" | Add EPUBs to `books/` and press **Rescan shelf**. |
| `Books directory missing` | Ensure `books/` exists next to `scan.php`. |
| `Cache dir not writable` | Give the PHP user write access to `cache/`. |
| `ZipArchive not available` | Install/enable the PHP `zip` extension. |
| `DOM extension not available` | Install/enable the PHP `dom` extension. |
| No covers shown | The EPUB may lack a cover reference; check the file, then rescan. |
| Blank page on desktop | Confirm the server executes PHP and that `cache/index.json` is readable. |
| Wrong UI on an e-reader | Detection is User-Agent based; adjust `is_tolino()`. |

## Ideas / roadmap

Good next steps if the project grows:

- [ ] **OPDS 1.2 catalog** — native browsing in KOReader, Thorium, Moon+ Reader, and other reading apps
- [ ] Optional login or access token for the whole library, including `scan.php`
- [ ] Dockerfile and Compose example for one-command deployment
- [ ] Additional formats (PDF, MOBI, AZW3) with a format filter
- [ ] Incremental scanning or a filesystem watcher
- [ ] Metadata editing and custom covers from the UI
- [ ] Series, tags, and collections
- [ ] Reading progress stored locally in the browser
- [ ] HTTP Range support for resumable downloads
- [ ] Multi-language UI and RTL support
- [ ] PWA/offline mode for the desktop UI
- [ ] PHPStan, coding-standard checks, and GitHub Actions
- [ ] A proper open-source license

## Contributing

Contributions are welcome. Keep the project dependency-free and friendly to low-powered devices:

1. Fork the repository and create a feature branch.
2. Do not commit books, covers, or `cache/index.json`.
3. Run `php -l` on any changed PHP file.
4. Keep the e-reader UI JavaScript-free.
5. Describe the change and the device/browser you tested with.

## License

No license file is included yet. Choose one (MIT and Apache-2.0 are common for small PHP projects) before distributing or accepting contributions.
