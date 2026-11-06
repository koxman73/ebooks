<?php
declare(strict_types=1);

$start = microtime(true);

$ROOT   = __DIR__;
$BOOKS  = $ROOT . '/books';
$CACHE  = $ROOT . '/cache';
$COVERS = $CACHE . '/covers';
$THUMBS = $CACHE . '/thumbs';
$INDEX  = $CACHE . '/index.json';
$LOCK   = $CACHE . '/.scan.lock';

const THUMB_WIDTH = 400;

if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
}

@set_time_limit(0);
@ini_set('memory_limit', '512M');

function fail(string $message, int $status = 500): void {
    if (PHP_SAPI !== 'cli') {
        http_response_code($status);
    }
    echo $message . "\n";
    exit(1);
}

function normalize(string $p): string {
    return str_replace('\\', '/', $p);
}

function resolve_path(string $base, string $path): string {
    $full = ($base === '' || $base === '.') ? $path : $base . '/' . $path;
    $parts = [];
    foreach (explode('/', normalize($full)) as $part) {
        if ($part === '' || $part === '.') continue;
        if ($part === '..') {
            array_pop($parts);
            continue;
        }
        $parts[] = $part;
    }
    return implode('/', $parts);
}

function warn(array &$warnings, string $file, string $message): void {
    $warnings[] = $file . ': ' . $message;
}

function thumb_extension(string $srcExt): string {
    if ($srcExt === 'png' || $srcExt === 'gif') return 'png';
    if ($srcExt === 'webp' && function_exists('imagewebp')) return 'webp';
    return 'jpg';
}

/**
 * Downscale cover data for the shelf and e-reader thumbnail.
 * Returns false when GD is unavailable or the image cannot be processed.
 */
function make_thumbnail(string $data, string $srcExt, string $dest, int $maxWidth): bool {
    if (!function_exists('imagecreatefromstring')) return false;

    $info = @getimagesizefromstring($data);
    if (!is_array($info) || $info[0] < 1 || $info[1] < 1) return false;

    /* Refuse pathological images before allocating a GD resource. */
    if (($info[0] * $info[1]) > 20000000) return false;

    $src = @imagecreatefromstring($data);
    if ($src === false) return false;

    $w = imagesx($src);
    $h = imagesy($src);
    if ($w < 1 || $h < 1) {
        return false;
    }

    $nw = min($w, $maxWidth);
    $nh = max(1, (int)round($h * $nw / $w));

    $dst = imagecreatetruecolor($nw, $nh);
    if ($dst === false) {
        return false;
    }

    $hasAlpha = in_array($srcExt, ['png', 'gif', 'webp'], true);
    if ($hasAlpha) {
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        imagefilledrectangle($dst, 0, 0, $nw, $nh, $transparent);
        imagealphablending($dst, true);
    } else {
        $white = imagecolorallocate($dst, 255, 255, 255);
        imagefilledrectangle($dst, 0, 0, $nw, $nh, $white);
    }

    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

    $ok = match (thumb_extension($srcExt)) {
        'png'  => imagepng($dst, $dest, 7),
        'webp' => imagewebp($dst, $dest, 82),
        default => imagejpeg($dst, $dest, 82),
    };

    return (bool)$ok;
}

function prune_dir(string $dir, array $keep): void {
    if (!is_dir($dir)) return;

    foreach (new DirectoryIterator($dir) as $cf) {
        if (!$cf->isFile()) continue;
        $name = $cf->getFilename();
        if (!preg_match('/\.(jpe?g|png|gif|webp)$/i', $name)) continue;
        if (!isset($keep[$name])) {
            @unlink($cf->getPathname());
        }
    }
}

/**
 * Read one EPUB and return its index entry. Broken files are still listed
 * under their filename, but produce a warning instead of failing silently.
 */
function read_epub(
    string $path,
    string $file,
    int $size,
    int $mtime,
    string $coversDir,
    string $thumbsDir,
    int $thumbWidth,
    bool $gd,
    array &$warnings
): array {
    $entry = [
        'file'   => $file,
        'title'  => pathinfo($file, PATHINFO_FILENAME),
        'author' => null,
        'size'   => $size,
        'mtime'  => $mtime,
        'cover'  => null,
        'thumb'  => null,
    ];

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        warn($warnings, $file, 'not a readable EPUB/ZIP archive');
        return $entry;
    }

    try {
        $container = $zip->getFromName('META-INF/container.xml');
        if ($container === false) {
            warn($warnings, $file, 'META-INF/container.xml is missing');
            return $entry;
        }

        $dom = new DOMDocument();
        if (!@$dom->loadXML($container, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
            warn($warnings, $file, 'container.xml is not valid XML');
            return $entry;
        }

        $xp = new DOMXPath($dom);
        $opfPath = trim($xp->evaluate("string(//*[local-name()='rootfile']/@full-path)"));
        if ($opfPath === '') {
            warn($warnings, $file, 'container.xml has no rootfile path');
            return $entry;
        }

        $opfPath = resolve_path('', $opfPath);
        $opfXml = $zip->getFromName($opfPath);
        if ($opfXml === false) {
            warn($warnings, $file, 'package file is missing: ' . $opfPath);
            return $entry;
        }

        if (!@$dom->loadXML($opfXml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
            warn($warnings, $file, 'package file is not valid XML: ' . $opfPath);
            return $entry;
        }

        $xp = new DOMXPath($dom);

        $title = trim($xp->evaluate("string(//*[local-name()='title'])"));
        if ($title !== '') $entry['title'] = $title;

        $author = trim($xp->evaluate("string(//*[local-name()='creator'])"));
        if ($author !== '') $entry['author'] = $author;

        /* ---- cover detection ---- */
        $coverId = trim($xp->evaluate(
            "string(//*[local-name()='meta' and @name='cover']/@content)"
        ));

        $coverHref = '';

        if ($coverId !== '') {
            foreach ($xp->query("//*[local-name()='item']") as $item) {
                if ($item->getAttribute('id') === $coverId) {
                    $coverHref = trim($item->getAttribute('href'));
                    break;
                }
            }
        }

        if ($coverHref === '') {
            $coverHref = trim($xp->evaluate(
                "string(//*[local-name()='item' and contains(@properties,'cover-image')]/@href)"
            ));
        }

        if ($coverHref === '') {
            return $entry;
        }

        $coverHref = rawurldecode($coverHref);
        $coverHref = preg_replace('/[?#].*$/', '', $coverHref) ?? $coverHref;

        $base = dirname($opfPath);
        $imgPath = resolve_path($base, $coverHref);
        $imgData = $zip->getFromName($imgPath);

        if ($imgData === false || $imgData === '') {
            warn($warnings, $file, 'cover image is missing: ' . $coverHref);
            return $entry;
        }

        $ext = strtolower(pathinfo($coverHref, PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
            $info = @getimagesizefromstring($imgData);
            $ext = match ($info['mime'] ?? '') {
                'image/jpeg' => 'jpg',
                'image/png'  => 'png',
                'image/gif'  => 'gif',
                'image/webp' => 'webp',
                default      => '',
            };
        }

        if ($ext === '') {
            warn($warnings, $file, 'unsupported cover format: ' . $coverHref);
            return $entry;
        }

        $baseName = sha1($file . $mtime);
        $coverName = $baseName . '.' . $ext;
        $coverPath = $coversDir . '/' . $coverName;

        if (@file_put_contents($coverPath, $imgData) === false) {
            warn($warnings, $file, 'could not write cover image');
            return $entry;
        }

        $entry['cover'] = 'cache/covers/' . $coverName;

        if ($gd) {
            $thumbName = $baseName . '-t' . $thumbWidth . '.' . thumb_extension($ext);
            $thumbPath = $thumbsDir . '/' . $thumbName;

            if (make_thumbnail($imgData, $ext, $thumbPath, $thumbWidth)) {
                $entry['thumb'] = 'cache/thumbs/' . $thumbName;
            } else {
                warn($warnings, $file, 'cover thumbnail could not be generated');
            }
        }

        return $entry;
    } finally {
        $zip->close();
    }
}

if (!is_dir($BOOKS)) {
    fail("Books directory missing: $BOOKS");
}
if (!is_readable($BOOKS)) {
    fail("Books directory not readable: $BOOKS (check permissions)");
}

if (!is_dir($CACHE) && !mkdir($CACHE, 0775, true) && !is_dir($CACHE)) {
    fail("Cannot create cache dir: $CACHE (check permissions)");
}
if (!is_dir($COVERS) && !mkdir($COVERS, 0775, true) && !is_dir($COVERS)) {
    fail("Cannot create covers dir: $COVERS (check permissions)");
}
if (!is_dir($THUMBS) && !mkdir($THUMBS, 0775, true) && !is_dir($THUMBS)) {
    fail("Cannot create thumbs dir: $THUMBS (check permissions)");
}

if (!is_writable($CACHE)) {
    $user = 'unknown';
    if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
        $pw = posix_getpwuid(posix_geteuid());
        if (is_array($pw) && isset($pw['name'])) $user = $pw['name'];
    } else {
        $user = get_current_user();
    }
    fail("Cache dir not writable by PHP user ($user): $CACHE");
}
if (!is_writable($COVERS)) {
    fail("Covers dir not writable: $COVERS (check permissions)");
}
if (!is_writable($THUMBS)) {
    fail("Thumbs dir not writable: $THUMBS (check permissions)");
}

if (!class_exists('ZipArchive')) {
    fail('ZipArchive not available — enable the PHP zip extension.');
}
if (!class_exists('DOMDocument') || !class_exists('DOMXPath')) {
    fail('DOM extension not available — enable the PHP dom extension.');
}

$gd = function_exists('imagecreatefromstring');

/* ---- one scan at a time ---- */
$lockHandle = @fopen($LOCK, 'c');
if ($lockHandle === false) {
    fail("Cannot open scan lock: $LOCK (check permissions)");
}
if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
    fail('Scan already running — try again in a moment.', 409);
}

$force = false;
if (PHP_SAPI === 'cli') {
    $force = in_array('--force', $argv ?? [], true);
} else {
    $raw = $_GET['force'] ?? '';
    $force = is_string($raw) && in_array(strtolower($raw), ['1', 'true', 'yes', 'on'], true);
}

$previous = [];
if (!$force && is_file($INDEX)) {
    $decoded = json_decode((string)@file_get_contents($INDEX), true);
    if (is_array($decoded)) $previous = $decoded;
}

$books    = [];
$warnings = [];
$reused   = 0;

foreach (new DirectoryIterator($BOOKS) as $fi) {
    if (!$fi->isFile()) continue;

    $file = $fi->getFilename();
    if ($file === '' || $file[0] === '.' || !preg_match('/\.epub$/i', $file)) continue;

    $size  = $fi->getSize();
    $mtime = $fi->getMTime();

    if (!$force && isset($previous[$file]) && is_array($previous[$file])) {
        $old = $previous[$file];

        $sameFile = (int)($old['size'] ?? -1) === (int)$size
            && (int)($old['mtime'] ?? -1) === (int)$mtime;

        $oldCover = isset($old['cover']) && is_string($old['cover']) ? $old['cover'] : null;
        $oldThumb = isset($old['thumb']) && is_string($old['thumb']) ? $old['thumb'] : null;

        $coverOk = $oldCover === null || is_file($ROOT . '/' . $oldCover);
        $thumbOk = $oldThumb === null || is_file($ROOT . '/' . $oldThumb);
        $thumbMissing = $gd && $oldCover !== null && $oldThumb === null;

        if ($sameFile && $coverOk && $thumbOk && !$thumbMissing) {
            $old['file']   = $file;
            $old['size']   = $size;
            $old['mtime']  = $mtime;
            $old['title']  = (isset($old['title']) && $old['title'] !== '')
                ? $old['title']
                : pathinfo($file, PATHINFO_FILENAME);
            $old['author'] = $old['author'] ?? null;
            $old['cover']  = $oldCover;
            $old['thumb']  = $oldThumb;

            $books[$file] = $old;
            $reused++;
            continue;
        }
    }

    $books[$file] = read_epub(
        $fi->getPathname(),
        $file,
        $size,
        $mtime,
        $COVERS,
        $THUMBS,
        THUMB_WIDTH,
        $gd,
        $warnings
    );
}

ksort($books, SORT_NATURAL | SORT_FLAG_CASE);

/* ---- write the index atomically before pruning old cover files ---- */
$json = json_encode(
    $books,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);

if ($json === false) {
    fail('Cannot encode the index as JSON.');
}

$tmp = $INDEX . '.tmp.' . getmypid();
if (@file_put_contents($tmp, $json . "\n", LOCK_EX) === false) {
    @unlink($tmp);
    fail("Cannot write index: $INDEX (check permissions)");
}
@chmod($tmp, 0664);

if (!@rename($tmp, $INDEX)) {
    /* Some network filesystems do not allow rename over an existing file. */
    if (@file_put_contents($INDEX, $json . "\n", LOCK_EX) === false) {
        @unlink($tmp);
        fail("Cannot replace index: $INDEX (check permissions)");
    }
    @unlink($tmp);
}

$keepCovers = [];
$keepThumbs = [];
foreach ($books as $b) {
    if (!empty($b['cover'])) $keepCovers[basename((string)$b['cover'])] = true;
    if (!empty($b['thumb'])) $keepThumbs[basename((string)$b['thumb'])] = true;
}

prune_dir($COVERS, $keepCovers);
if ($gd) {
    prune_dir($THUMBS, $keepThumbs);
}

$count   = count($books);
$elapsed = number_format(microtime(true) - $start, 2);

$summary = 'Indexed ' . $count . ' EPUB ' . ($count === 1 ? 'file' : 'files')
    . " in {$elapsed}s";
if ($reused > 0) {
    $summary .= ' · ' . $reused . ' unchanged';
}
echo $summary . "\n";

if (!$gd && $count > 0) {
    echo "Note: GD extension unavailable — thumbnail covers were not generated.\n";
}

if ($warnings) {
    $n = count($warnings);
    echo $n . ' warning' . ($n === 1 ? '' : 's') . ":\n";
    foreach ($warnings as $warning) {
        echo '  - ' . $warning . "\n";
    }
}

flock($lockHandle, LOCK_UN);
fclose($lockHandle);
