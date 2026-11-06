<?php
declare(strict_types=1);

$ROOT  = __DIR__;
$BOOKS = $ROOT . '/books';
$INDEX = $ROOT . '/cache/index.json';

$f = $_GET['f'] ?? '';
if (!is_string($f) || $f === '' || $f !== basename($f)) {
    http_response_code(400);
    exit('Bad request');
}

$idx = json_decode((string)@file_get_contents($INDEX), true);
if (!is_array($idx) || !isset($idx[$f])) {
    http_response_code(404);
    exit('Not found');
}

$booksReal = realpath($BOOKS);
$real      = realpath($BOOKS . '/' . $f);

if ($booksReal === false
    || $real === false
    || !str_starts_with($real, $booksReal . DIRECTORY_SEPARATOR)
    || !is_file($real)
    || !is_readable($real)) {
    http_response_code(403);
    exit('Forbidden');
}

$size  = filesize($real);
$mtime = filemtime($real);
if ($size === false || $mtime === false) {
    http_response_code(403);
    exit('Forbidden');
}

$filename = basename($f);
$fallback = preg_replace('/[^A-Za-z0-9._ -]/', '_', $filename) ?: 'book.epub';

$etag         = sprintf('"%x-%x"', $mtime, $size);
$lastModified = gmdate('D, d M Y H:i:s', $mtime) . ' GMT';

header('Content-Type: application/epub+zip');
header('Content-Disposition: attachment; filename="' . $fallback . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=0, must-revalidate');
header('ETag: ' . $etag);
header('Last-Modified: ' . $lastModified);
header('Accept-Ranges: bytes');

function etag_matches(string $header, string $etag): bool {
    foreach (explode(',', $header) as $candidate) {
        $candidate = trim($candidate);
        if ($candidate === '*') return true;
        if (str_starts_with($candidate, 'W/')) {
            $candidate = substr($candidate, 2);
        }
        if ($candidate === $etag) return true;
    }
    return false;
}

$ifNoneMatch = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
if ($ifNoneMatch !== '') {
    if (etag_matches($ifNoneMatch, $etag)) {
        http_response_code(304);
        exit;
    }
} elseif (isset($_SERVER['HTTP_IF_MODIFIED_SINCE'])) {
    $since = strtotime(trim((string)$_SERVER['HTTP_IF_MODIFIED_SINCE']));
    if ($since !== false && $since >= $mtime) {
        http_response_code(304);
        exit;
    }
}

/* ---- single byte range (resumable downloads and readers) ---- */
$start   = 0;
$end     = $size - 1;
$partial = false;

$range = $_SERVER['HTTP_RANGE'] ?? '';
if ($range !== '' && preg_match('/^bytes=(\d*)-(\d*)$/i', trim($range), $m)) {
    $hasStart = $m[1] !== '';
    $hasEnd   = $m[2] !== '';

    if ($hasStart) {
        $start = (int)$m[1];
        $end   = $hasEnd ? (int)$m[2] : $size - 1;

        if ($start > $end || $start >= $size) {
            http_response_code(416);
            header('Content-Range: bytes */' . $size);
            header('Content-Length: 0');
            exit;
        }

        if ($end >= $size) $end = $size - 1;
        $partial = true;
    } elseif ($hasEnd) {
        $suffix = (int)$m[2];
        if ($suffix < 1) {
            http_response_code(416);
            header('Content-Range: bytes */' . $size);
            header('Content-Length: 0');
            exit;
        }
        $start   = max(0, $size - $suffix);
        $end     = $size - 1;
        $partial = true;
    }
}

if ($partial && isset($_SERVER['HTTP_IF_RANGE'])) {
    $ifRange = trim((string)$_SERVER['HTTP_IF_RANGE']);
    if ($ifRange !== $etag && $ifRange !== $lastModified) {
        $partial = false;
        $start   = 0;
        $end     = $size - 1;
    }
}

$length = $end - $start + 1;

if ($partial) {
    http_response_code(206);
    header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
}

header('Content-Length: ' . $length);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
    exit;
}

while (ob_get_level() > 0) {
    @ob_end_clean();
}

$fp = @fopen($real, 'rb');
if ($fp === false) {
    http_response_code(500);
    exit;
}

if ($start > 0) {
    @fseek($fp, $start);
}

$remaining = $length;
while ($remaining > 0 && !feof($fp)) {
    $chunk = fread($fp, min(8192, $remaining));
    if ($chunk === false || $chunk === '') break;
    echo $chunk;
    $remaining -= strlen($chunk);
}

fclose($fp);
