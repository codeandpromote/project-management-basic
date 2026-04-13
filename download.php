<?php
// ============================================================
//  HRMS · Secure File Download
//  Serves files from the uploads/ directory through PHP,
//  bypassing web-server restrictions (Hostinger/Nginx).
// ============================================================
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
requireLogin();

$path = $_GET['path'] ?? '';

// Strip dangerous sequences — prevent directory traversal
$path = str_replace(['..', '\\', "\0"], '', $path);
$path = ltrim($path, '/');

if ($path === '') {
    http_response_code(400);
    exit('Invalid file path.');
}

$uploadBase = realpath(__DIR__ . '/uploads');
$fullPath   = __DIR__ . '/uploads/' . $path;
$realFile   = realpath($fullPath);

// Must be inside uploads/ and must be a regular file
if (!$realFile || strpos($realFile, $uploadBase) !== 0 || !is_file($realFile)) {
    http_response_code(404);
    exit('File not found.');
}

$ext = strtolower(pathinfo($realFile, PATHINFO_EXTENSION));

$mimeTypes = [
    'pdf'  => 'application/pdf',
    'doc'  => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'xls'  => 'application/vnd.ms-excel',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'png'  => 'image/png',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'gif'  => 'image/gif',
    'zip'  => 'application/zip',
    'txt'  => 'text/plain',
];

$mime = $mimeTypes[$ext] ?? 'application/octet-stream';

// Inline for images/PDF so browser can preview; force-download for others
$disposition = in_array($ext, ['pdf','png','jpg','jpeg','gif'], true)
    ? 'inline'
    : 'attachment';

header('Content-Type: ' . $mime);
header('Content-Disposition: ' . $disposition . '; filename="' . basename($realFile) . '"');
header('Content-Length: ' . filesize($realFile));
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');
readfile($realFile);
exit;
