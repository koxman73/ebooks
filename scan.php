<?php
declare(strict_types=1);

$start = microtime(true);

$ROOT   = __DIR__;
$BOOKS  = $ROOT . '/books';
$CACHE  = $ROOT . '/cache';
$COVERS = $CACHE . '/covers';
$INDEX  = $CACHE . '/index.json';

if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
}

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

if (!class_exists('ZipArchive')) {
    fail('ZipArchive not available — enable the PHP zip extension.');
}
if (!class_exists('DOMDocument') || !class_exists('DOMXPath')) {
    fail('DOM extension not available — enable the PHP dom extension.');
}

$books = [];

foreach (new DirectoryIterator($BOOKS) as $fi) {
    if (!$fi->isFile()) continue;

    $file = $fi->getFilename();
    if ($file === '' || $file[0] === '.' || !preg_match('/\.epub$/i', $file)) continue;

    $size  = $fi->getSize();
    $mtime = $fi->getMTime();

    $entry = [
        'file'   => $file,
        'title'  => pathinfo($file, PATHINFO_FILENAME),
        'author' => null,
        'size'   => $size,
        'mtime'  => $mtime,
        'cover'  => null,
    ];

    $zip = new ZipArchive();
    if ($zip->open($fi->getPathname()) === true) {
        $container = $zip->getFromName('META-INF/container.xml');

        if ($container !== false) {
            $dom = new DOMDocument();
            if (@$dom->loadXML($container, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
                $xp = new DOMXPath($dom);
                $opfPath = trim($xp->evaluate(
                    "string(//*[local-name()='rootfile']/@full-path)"
                ));

                if ($opfPath !== '') {
                    $opfPath = resolve_path('', $opfPath);
                    $opfXml  = $zip->getFromName($opfPath);

                    if ($opfXml !== false
                        && @$dom->loadXML($opfXml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
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

                        if ($coverHref !== '') {
                            $coverHref = rawurldecode($coverHref);
                            $coverHref = preg_replace('/[?#].*$/', '', $coverHref) ?? $coverHref;

                            $base = dirname($opfPath);
                            $imgPath = resolve_path($base, $coverHref);
                            $imgData = $zip->getFromName($imgPath);

                            if ($imgData !== false && $imgData !== '') {
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

                                if ($ext !== '') {
                                    $coverName = sha1($file . $mtime) . '.' . $ext;
                                    $coverPath = $COVERS . '/' . $coverName;

                                    if (@file_put_contents($coverPath, $imgData) !== false) {
                                        $entry['cover'] = 'cache/covers/' . $coverName;
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        $zip->close();
    }

    $books[$file] = $entry;
}

ksort($books, SORT_NATURAL | SORT_FLAG_CASE);

/* ---- remove cover files that no longer belong to a book ---- */
$keep = [];
foreach ($books as $b) {
    if (!empty($b['cover'])) {
        $keep[basename($b['cover'])] = true;
    }
}
foreach (new DirectoryIterator($COVERS) as $cf) {
    if (!$cf->isFile()) continue;
    $name = $cf->getFilename();
    if (!preg_match('/\.(jpe?g|png|gif|webp)$/i', $name)) continue;
    if (!isset($keep[$name])) {
        @unlink($cf->getPathname());
    }
}

$json = json_encode(
    $books,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);

if ($json === false || @file_put_contents($INDEX, $json . "\n", LOCK_EX) === false) {
    fail("Cannot write index: $INDEX (check permissions)");
}

$count   = count($books);
$elapsed = number_format(microtime(true) - $start, 2);

echo 'Indexed ' . $count . ' EPUB ' . ($count === 1 ? 'file' : 'files') . " in {$elapsed}s\n";
