<?php
header('Content-Type: application/json; charset=utf-8');

function fail($msg, $code = 400)
{
    http_response_code($code);
    echo json_encode(["ok" => false, "error" => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

// Helper to generate random room (copied from create_room.php context)
function genRoomCode()
{
    $chars = "ABCDEFGHJKLMNPQRSTUVWXYZ23456789";
    $code = "";
    for ($i = 0; $i < 6; $i++)
        $code .= $chars[rand(0, strlen($chars) - 1)];
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

// Create new room
$newRoom = genRoomCode();
$destPath = __DIR__ . "/_rooms/{$newRoom}.json";

// We need to reset some session-specific things in the state, like players' tokens
// Because this is a "Load", the old tokens are invalid.
// We must allow new players to join.
// Reset players:
$state["players"] = [
    "w" => ["name" => "", "token" => "", "joined" => 0],
    "b" => ["name" => "", "token" => "", "joined" => 0]
];
// Keep move history, fen, pgn, etc.
// Important: If the game was "over", it remains "over".
// If the user wants to continue playing, we might need to manually unset "over" flag?
// User requirement: "Load saved games". Usually implies viewing.
// But if they want to continue, maybe they can match settings.
// For now, we restore it AS IS. If it was Over, it loads as Over.

if (!file_put_contents($destPath, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT))) {
    fail("Yeni oda oluşturulamadı.", 500);
}

echo json_encode([
    "ok" => true,
    "room" => $newRoom,
    "fen" => $state["fen"] ?? "start",
    "pgn" => $state["pgn"] ?? ""
], JSON_UNESCAPED_UNICODE);
