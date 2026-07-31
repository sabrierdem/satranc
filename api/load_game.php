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

// Cryptographically-random room code (no ambiguous I/O/1/0).
function genRoomCode()
{
    $chars = "ABCDEFGHJKLMNPQRSTUVWXYZ23456789";
    $code = "";
    for ($i = 0; $i < 6; $i++)
        $code .= $chars[random_int(0, strlen($chars) - 1)];
    return $code;
}

$input = json_decode(file_get_contents("php://input"), true) ?: [];
$filename = $input["file"] ?? "";

if (!$filename)
    fail("Dosya adı gerekli.");

$srcPath = __DIR__ . "/_saved_games/" . basename($filename);
if (!file_exists($srcPath))
    fail("Kayıtlı oyun dosyası bulunamadı.");

// Load content
$content = file_get_contents($srcPath);
$state = json_decode($content, true);

if (!$state)
    fail("Dosya bozuk.");

// Fresh player tokens so new players can claim the seats; old tokens are void.
$state["players"] = [
    "w" => ["name" => "", "token" => bin2hex(random_bytes(16)), "joined" => 0],
    "b" => ["name" => "", "token" => bin2hex(random_bytes(16)), "joined" => 0]
];
// Reset volatile presence/typing; keep move history, fen, pgn, chat as-is.
$state["active_users"] = [];
unset($state["typing"]);

// Create a new room under a unique code, retrying on collision and never
// clobbering an existing room (exclusive create).
$roomsDir = __DIR__ . "/_rooms";
if (!is_dir($roomsDir))
    @mkdir($roomsDir, 0755, true);

$fp = false;
$newRoom = "";
for ($i = 0; $i < 12; $i++) {
    $newRoom = genRoomCode();
    $destPath = $roomsDir . "/{$newRoom}.json";
    $fp = @fopen($destPath, 'x');
    if ($fp !== false)
        break;
}
if ($fp === false)
    fail("Yeni oda oluşturulamadı.", 500);

if (fwrite($fp, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) === false) {
    fclose($fp);
    @unlink($destPath);
    fail("Yeni oda oluşturulamadı.", 500);
}
fclose($fp);

echo json_encode([
    "ok" => true,
    "room" => $newRoom,
    "fen" => $state["fen"] ?? "start",
    "pgn" => $state["pgn"] ?? ""
], JSON_UNESCAPED_UNICODE);
