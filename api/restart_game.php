<?php
require_once __DIR__ . '/_cors.php';
header('Content-Type: application/json; charset=utf-8');

function fail($msg, $code = 400)
{
    http_response_code($code);
    echo json_encode(["ok" => false, "error" => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function load_state_locked($path, &$fp)
{
    $fp = fopen($path, 'c+');
    if (!$fp)
        fail("Oda açılamadı.", 500);
    if (!flock($fp, LOCK_EX))
        fail("Kilit alınamadı.", 500);

    $contents = stream_get_contents($fp);
    $state = $contents ? json_decode($contents, true) : null;
    if (!$state)
        $state = [];
    return $state;
}

function save_state_unlock($fp, $path, $state)
{
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
}

$data = json_decode(file_get_contents("php://input"), true) ?: [];
$room = mb_strtoupper(trim($data["room"] ?? ""), 'UTF-8');
$token = trim($data["token"] ?? "");

if ($room === "" || $token === "")
    fail("Parametre eksik.");

$path = __DIR__ . "/_rooms/{$room}.json";
if (!file_exists($path))
    fail("Oda yok.");

$fp = null;
$state = load_state_locked($path, $fp);

// Only "white" player can restart
if (($state["players"]["w"]["token"] ?? "") !== $token) {
    save_state_unlock($fp, $path, $state);
    fail("Sadece oyunu kuran (Beyaz) oyunu tekrar başlatabilir.", 403);
}

// Reset Game State
$state["fen"] = "start";
$state["pgn"] = "";
$state["over"] = false;
$state["result"] = "";
$state["winner"] = "";
$state["reason"] = "";
$state["history"] = [];
$state["future"] = [];
$state["streak"] = [
    "w" => ["sig" => "", "n" => 0],
    "b" => ["sig" => "", "n" => 0],
];

// Increment sequence
$state["seq"] = intval($state["seq"] ?? 0) + 1;

// Add system message
$state["chat"][] = [
    "seq" => $state["seq"],
    "time" => date("Y-m-d H:i:s"),
    "name" => "Sistem",
    "color" => "",
    "text" => "Oyun tekrar başlatıldı!"
];

save_state_unlock($fp, $path, $state);

echo json_encode(["ok" => true], JSON_UNESCAPED_UNICODE);
