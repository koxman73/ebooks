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

$filename = basename($f);
$fallback = preg_replace('/[^A-Za-z0-9._ -]/', '_', $filename) ?: 'book.epub';

header('Content-Type: application/epub+zip');
header('Content-Disposition: attachment; filename="' . $fallback . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
header('Content-Length: ' . filesize($real));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=0, must-revalidate');

readfile($real);
