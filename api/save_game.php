<?php
header('Content-Type: application/json; charset=utf-8');

function fail($msg, $code = 400)
{
    http_response_code($code);
    echo json_encode(["ok" => false, "error" => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

$input = json_decode(file_get_contents("php://input"), true) ?: [];
$room = strtoupper(trim($input["room"] ?? ""));
$name = trim($input["name"] ?? "Oyun");

// Basic sanitation for filename
$name = preg_replace('/[^a-zA-Z0-9_\- çğıöşüÇĞIÖŞÜ]/u', '_', $name);
if (!$name)
    $name = "Adsız_Oyun";

if (!$room)
    fail("Oda kodu gerekli.");

$sourcePath = __DIR__ . "/_rooms/{$room}.json";
if (!file_exists($sourcePath))
    fail("Oda bulunamadı veya süresi dolmuş.");

// Generate saved filename: YYYYMMDD_HHMMSS_{name}.json
$timestamp = date("Ymd_His");
$destFilename = "{$timestamp}_{$name}.json";
$destPath = __DIR__ . "/_saved_games/{$destFilename}";

$destDir = __DIR__ . "/_saved_games";
if (!is_dir($destDir))
    mkdir($destDir, 0777, true);
if (!is_writable($destDir))
    fail("Sunucu hatası: _saved_games klasörüne yazılamıyor.", 500);

if (!@copy($sourcePath, $destPath)) {
    $err = error_get_last();
    fail("Dosya kopyalama hatası: " . ($err['message'] ?? 'Bilinmeyen'), 500);
}

// Optionally, we can mark the room as saved inside the file, but not strictly necessary for file system storage.
// Just return success.
echo json_encode(["ok" => true, "file" => $destFilename], JSON_UNESCAPED_UNICODE);
