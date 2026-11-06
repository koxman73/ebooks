<?php
declare(strict_types=1);

$ROOT = __DIR__;

$f    = $_GET['f'] ?? '';
$kind = $_GET['s'] ?? 'full';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

if (!is_string($f) || !preg_match('/^[a-f0-9]{40}(?:-t[0-9]+)?\.(?:jpe?g|png|gif|webp)$/i', $f)) {
    http_response_code(400);
    exit;
}

if ($kind !== 'thumb' && $kind !== 'full') {
    http_response_code(400);
    exit;
}

$dir  = $kind === 'thumb' ? 'thumbs' : 'covers';
$base = realpath($ROOT . '/cache/' . $dir);
$real = realpath($ROOT . '/cache/' . $dir . '/' . $f);

if ($base === false
    || $real === false
    || !str_starts_with($real, $base . DIRECTORY_SEPARATOR)
    || !is_file($real)
    || !is_readable($real)) {
    http_response_code(404);
    exit;
}

$ext   = strtolower(pathinfo($real, PATHINFO_EXTENSION));
$types = [
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'gif'  => 'image/gif',
    'webp' => 'image/webp',
];

if (!isset($types[$ext])) {
    http_response_code(404);
    exit;
}

$size = filesize($real);
$mtime = filemtime($real);
if ($size === false || $mtime === false) {
    http_response_code(404);
    exit;
}

$etag = sprintf('"%x-%x"', $mtime, $size);
$lastModified = gmdate('D, d M Y H:i:s', $mtime) . ' GMT';

/* Cover filenames are content hashes, so the bytes behind a URL never change. */
header('Cache-Control: public, max-age=31536000, immutable');
header('ETag: ' . $etag);
header('Last-Modified: ' . $lastModified);

$ifNoneMatch = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
if ($ifNoneMatch !== '') {
    foreach (explode(',', $ifNoneMatch) as $candidate) {
        $candidate = trim($candidate);
        if ($candidate === '*') {
            http_response_code(304);
            exit;
        }
        if (str_starts_with($candidate, 'W/')) {
            $candidate = substr($candidate, 2);
        }
        if ($candidate === $etag) {
            http_response_code(304);
            exit;
        }
    }
}

header('Content-Type: ' . $types[$ext]);
header('Content-Length: ' . $size);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
    exit;
}

readfile($real);
