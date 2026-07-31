<?php
require_once __DIR__ . '/_cors.php';
require_once __DIR__ . '/_util.php';
header('Content-Type: application/json; charset=utf-8');

function fail($msg, $code = 400)
{
    http_response_code($code);
    echo json_encode(["ok" => false, "error" => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

$input = json_decode(file_get_contents("php://input"), true) ?: [];
$room = strtoupper(trim($input["room"] ?? ""));
$name = mb_substr(trim($input["name"] ?? "Oyun"), 0, 40);

// Basic sanitation for filename
$name = preg_replace('/[^a-zA-Z0-9_\- çğıöşüÇĞIÖŞÜ]/u', '_', $name);
if (!$name)
    $name = "Adsız_Oyun";

if (!$room)
    fail("Oda kodu gerekli.");

$sourcePath = cz_room_path($room);
if (!$sourcePath)
    fail("Geçersiz oda kodu.");
if (!file_exists($sourcePath))
    fail("Oda bulunamadı veya süresi dolmuş.");

$state = json_decode(file_get_contents($sourcePath), true);
if (!is_array($state))
    fail("Oda verisi okunamadı.", 500);

// Strip session secrets and volatile presence before persisting: a saved game
// must never carry the players' auth tokens (it is loadable by anyone).
if (isset($state["players"]["w"]))
    $state["players"]["w"]["token"] = "";
if (isset($state["players"]["b"]))
    $state["players"]["b"]["token"] = "";
unset($state["active_users"], $state["typing"]);

$destDir = __DIR__ . "/_saved_games";
if (!is_dir($destDir))
    @mkdir($destDir, 0755, true);
if (!is_writable($destDir))
    fail("Sunucu hatası: oyun kaydedilemedi.", 500);

// Filename: YYYYMMDD_HHMMSS_{name}_{rand}.json. The random suffix prevents
// guessing/enumeration by URL and avoids same-second collisions.
$timestamp = date("Ymd_His");
$rand = substr(bin2hex(random_bytes(4)), 0, 6);
$destFilename = "{$timestamp}_{$name}_{$rand}.json";
$destPath = $destDir . "/" . $destFilename;

if (file_put_contents($destPath, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX) === false) {
    error_log("save_game: write failed for {$destPath}");
    fail("Oyun kaydedilemedi.", 500);
}

echo json_encode(["ok" => true, "file" => $destFilename], JSON_UNESCAPED_UNICODE);
