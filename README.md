![EPUB Library](assets/banner.svg)

# EPUB Library

A small, self-hosted PHP site that turns a folder of EPUB files into a private library for your e-reader **and** your browser. No database, no Composer, no build step — just PHP and a folder of books.

- **E-readers with a limited browser** (e.g. Tolino) get a deliberately plain, server-rendered page: no JavaScript, no webfonts, no external requests.
- **Desktop and mobile browsers** get a proper reading-room UI with covers, live search, sorting, dark mode, and a **Rescan shelf** button.

> **No books are included in this repository.** The `books/` and `cache/` directories are gitignored, and no personal library metadata is hard-coded anywhere in the project. Add your own EPUBs and run the scanner.

## Live instance

A running deployment is available on the local network at:

**http://10.0.0.2:1080/ebooks**

This directory is that deployment: the NAS serves it directly, so PHP changes take effect on the next request. `books/` and `cache/` live next to the code and are gitignored.

## Highlights

- Automatic EPUB metadata extraction: title, author, cover
- Two interfaces selected by User-Agent — no client-side feature detection needed
- Live search by title or author, with accent-insensitive matching
- Sort by title, author, newest, or size
- One-click incremental rescan from the desktop UI
- 400 px thumbnail covers keep the shelf and e-ink pages fast
- Cover fallback for books without artwork
- Stale cover and thumbnail files are pruned automatically on each scan
- No-JavaScript download fallback for desktop visitors
- Resumable downloads with HTTP Range, ETag, and conditional requests
- Download endpoint with path-traversal protection and safe filenames
- Responsive layout, light/dark theme, reduced-motion support
- Zero runtime dependencies and zero third-party requests — works fully offline

## Requirements

- PHP **8.0+**
- PHP extensions:
  - `zip` (`ZipArchive`) — required by the scanner
  - `dom` / `libxml` (`DOMDocument`, `DOMXPath`) — required by the scanner
  - `gd` — optional; generates the 400 px thumbnail covers (full-size covers are used when it is missing)
  - `posix` — optional; used only to show the PHP user in permission errors
- A web server that executes PHP (Apache, nginx + PHP-FPM, Caddy, or PHP's built-in server)
- Write access for the PHP user to `cache/` (including `cache/covers/`, `cache/thumbs/`, and the scan lock file)

## Quick start

1. Put the project in a directory served by PHP, or point your web server's document root at it.
2. Copy your `.epub` files into `books/`. The folder is flat — subdirectories are not scanned.
3. Make sure the cache is writable by the web server user:

   ```sh
   mkdir -p cache/covers cache/thumbs
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

The site works under a path such as `/ebooks/` without configuration: every link, cover URL, and fetch call is relative (`download.php?f=…`, `scan.php`, `cover.php?…`). Make sure the web server routes the whole prefix to this directory and executes PHP for it.

## Project layout

```
.
├── index.php           # Entry point: e-reader UI + desktop UI
├── scan.php            # Scanner: updates cache/index.json, covers, and thumbnails
├── cover.php           # Serves cached covers/thumbnails with long-lived caching
├── download.php        # Streams an indexed EPUB, with HTTP Range support
├── README.md
├── LICENSE
├── .gitattributes
├── .github/
│   └── workflows/
│       └── ci.yml      # php -l on PHP 8.0–8.5
├── assets/
│   ├── banner.svg      # README banner
│   └── favicon.svg     # Browser icon
├── books/              # Your .epub files (gitignored)
└── cache/              # Generated; safe to delete (gitignored)
    ├── index.json      # Book metadata index
    ├── .scan.lock      # Prevents overlapping scans
    ├── covers/         # Extracted cover images
    └── thumbs/         # 400 px thumbnails for the shelf and e-reader
```

## Interfaces

### Desktop and mobile browsers (`index.php`)

- Masthead with a small bookshelf mark, search field, and sort menu.
- Books are shown as cover thumbnails standing on wooden shelf boards.
- Search filters by title and author as you type (120 ms debounce, accent-insensitive).
- Sorting uses a numeric-aware collator, so `Book 2` comes before `Book 10`.
- **Rescan shelf** calls `scan.php` via `fetch()`, shows the scanner's status text, then reloads the page. Scans are incremental; add `?force=1` to the request (or run `php scan.php --force`) for a full rebuild.
- If JavaScript is disabled, a server-rendered list of direct download links is shown instead.
- Light and dark themes follow `prefers-color-scheme`.
- Self-contained: no webfonts, CDNs, or third-party requests.

### E-readers and limited browsers

The server detects Tolino-style browsers when the `User-Agent`:

- contains `Tolino`, or
- contains `WebKit/53` but not `Chrome` (older e-ink WebKit browsers).

Those clients receive a separate, minimal page — a single-column list with a 400 px cover thumbnail, title, author, size/date, and a plain download link. No JavaScript is sent. **There is no rescan button on the e-reader UI**; add books and rescan from a desktop browser or the command line.

### Downloads (`download.php?f=<filename>`)

Only files present in `cache/index.json` are served. The endpoint:

- rejects names that differ from their `basename()`,
- resolves the real path and verifies it stays inside `books/`,
- returns proper `400`, `403`, and `404` responses,
- sends `Content-Type: application/epub+zip` with an attachment filename,
- escapes non-ASCII filenames via RFC 5987 `filename*`,
- supports single HTTP byte ranges (`206 Partial Content`, `416 Range Not Satisfiable`) and `If-Range`,
- sends `ETag` and `Last-Modified`, and answers conditional requests with `304 Not Modified`.

### Covers (`cover.php?s=thumb|full&f=<hash>.<ext>`)

Covers and thumbnails are content-addressed files under `cache/`. `cover.php` validates the requested hash filename, serves the image with the correct `Content-Type`, and adds `ETag`, `Last-Modified`, and a one-year immutable `Cache-Control` header. Full-size covers remain available when thumbnails are missing.

## How scanning works

`scan.php`:

1. Creates `cache/`, `cache/covers/`, and `cache/thumbs/` if needed and verifies that they are writable.
2. Takes a non-blocking lock (`cache/.scan.lock`); a second concurrent scan returns `409` instead of racing.
3. Iterates over files directly inside `books/`, skipping dotfiles and anything not ending in `.epub` (case-insensitive).
4. Reuses entries from the previous index when `size` and `mtime` are unchanged and the cover/thumbnail files still exist. Missing thumbnails are regenerated on the next scan.
5. Opens each changed EPUB as a ZIP and reads `META-INF/container.xml` to find the OPF package file.
6. Reads `<title>` and `<creator>` from the OPF; falls back to the filename for the title.
7. Finds the cover via the OPF `<meta name="cover">` reference, or the manifest item with the `cover-image` property.
8. Extracts the cover to `cache/covers/<sha1(filename + mtime)>.<ext>` and, when GD is available, writes a 400 px thumbnail to `cache/thumbs/<sha1(filename + mtime)>-t400.<ext>`.
9. Writes `cache/index.json` atomically (temporary file, then `rename`) so a crash cannot truncate the live index.
10. Removes cover and thumbnail files that no longer belong to an indexed book.
11. Returns a plain-text summary, including one warning line per EPUB that could not be fully read.

Unchanged files are not reopened, so rescans stay fast as the library grows. Use `php scan.php --force` or `scan.php?force=1` to rebuild every entry from scratch.

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
    "cover": "cache/covers/0123456789abcdef0123456789abcdef01234567.jpg",
    "thumb": "cache/thumbs/0123456789abcdef0123456789abcdef01234567-t400.jpg"
  }
}
```

`author`, `cover`, and `thumb` may be `null`. The file is generated — edit the EPUB metadata, not the JSON.

## Security and privacy

- **There is no authentication.** Run the site on a trusted network, behind a VPN, or put HTTP Basic Auth in front of it.
- **`scan.php` is unauthenticated.** Anyone who can reach it can trigger a rescan. Do not expose it to the public internet unprotected.
- `download.php` only serves files listed in the index and verifies the resolved path stays inside `books/`.
- `cover.php` accepts only 40-character content-hash filenames under `cache/covers/` or `cache/thumbs/` and verifies the resolved path stays inside that directory.
- `download.php` supports single-range requests, but still requires the file to be indexed first.
- JSON embedded in `index.php` is encoded with `JSON_HEX_TAG`, so EPUB metadata cannot break out of the `<script>` block.
- `index.php` sends a restrictive Content-Security-Policy plus `X-Content-Type-Options` and `Referrer-Policy` headers.
- The site makes no third-party requests. Typography uses the system serif and sans stacks, so it works fully offline.
- `books/` and `cache/` are gitignored. Do not commit your library or generated covers to a public repository.
- No telemetry, analytics, or outbound calls.

## Customizing

- **Palette and typography:** the `:root` custom properties at the top of the desktop `<style>` block in `index.php`.
- **E-reader detection:** `is_tolino()` in `index.php`. Keep the dedicated UI plain: it is intentionally free of JavaScript and external assets.
- **Paths:** `books/`, `cache/`, `cache/covers/`, and `cache/thumbs/` are resolved relative to `__DIR__` in `scan.php`, `cover.php`, and `download.php`.
- **Thumbnails:** change `THUMB_WIDTH` in `scan.php`. The width is part of the thumbnail filename (`-t400`), so the next scan generates new files and prunes the old ones. `gd` must be enabled.
- **Cover cache lifetime:** the immutable one-year `Cache-Control` header is set in `cover.php`; cover filenames are content hashes, so changing a book produces a new URL.
- **Shelf look:** the wooden board is the `.book::after` rule; cover proportions use `aspect-ratio: 2/3`.

## Troubleshooting

| Symptom | Fix |
| --- | --- |
| "Your shelf is empty" | Add EPUBs to `books/` and press **Rescan shelf**. |
| `Books directory missing` | Ensure `books/` exists next to `scan.php`. |
| `Cache dir not writable` | Give the PHP user write access to `cache/`. |
| `Scan already running` | A scan (browser or cron) holds `cache/.scan.lock`; wait for it to finish and retry. |
| `ZipArchive not available` | Install/enable the PHP `zip` extension. |
| `DOM extension not available` | Install/enable the PHP `dom` extension. |
| No covers shown | The EPUB may lack a cover reference; check the file, then rescan. |
| No thumbnails / slow shelf | Enable the PHP `gd` extension, then run `php scan.php --force`. Full-size covers are used as a fallback. |
| `… not a readable EPUB/ZIP archive` warning | The file is corrupt, incomplete, or DRM-protected; remove or repair it and rescan. |
| Blank page on desktop | Confirm the server executes PHP and that `cache/index.json` is readable. |
| Wrong UI on an e-reader | Detection is User-Agent based; adjust `is_tolino()`. |

## Ideas / roadmap

Good next steps if the project grows:

- [ ] **OPDS 1.2 catalog** — native browsing in KOReader, Thorium, Moon+ Reader, and other reading apps
- [ ] Optional login or access token for the whole library, including `scan.php`
- [ ] Dockerfile and Compose example for one-command deployment
- [ ] Additional formats (PDF, MOBI, AZW3) with a format filter
- [x] Incremental scanning (a filesystem watcher is not planned; the scanner stays request-driven)
- [x] Atomic index writes and a lock against overlapping scans
- [ ] Metadata editing and custom covers from the UI
- [ ] Series, tags, and collections
- [ ] Reading progress stored locally in the browser
- [x] HTTP Range support for resumable downloads
- [ ] Multi-language UI and RTL support
- [ ] PWA/offline mode for the desktop UI (requires serving over HTTPS)
- [x] GitHub Actions running `php -l` on PHP 8.0–8.5
- [ ] PHPStan and coding-standard checks

## Contributing

Contributions are welcome. Keep the project dependency-free and friendly to low-powered devices:

1. Fork the repository and create a feature branch.
2. Do not commit books, covers, thumbnails, or `cache/index.json`.
3. Run `php -l` on any changed PHP file; CI repeats this check on PHP 8.0–8.5.
4. Keep the e-reader UI JavaScript-free.
5. Describe the change and the device/browser you tested with.

## License

MIT — see [LICENSE](LICENSE).
