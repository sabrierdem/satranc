<?php
require_once __DIR__ . '/_cors.php';
require_once __DIR__ . '/_util.php';
header('Content-Type: application/json; charset=utf-8');

function fail($msg, $code = 400)
{
    http_response_code($code);
    echo json_encode(["ok" => false, "error" => $msg]);
    exit;
}

$raw = file_get_contents("php://input");
$data = json_decode($raw, true);

$room = mb_strtoupper(trim($data["room"] ?? ""), 'UTF-8');
$token = trim($data["token"] ?? "");

if ($room === "" || $token === "")
    fail("Parametre eksik.");

$path = cz_room_path($room);
if (!$path)
  fail("Geçersiz oda kodu.");
if (!file_exists($path))
    fail("Oda yok.");

$fp = fopen($path, 'c+');
if (!$fp)
    fail("Oda açılamadı.", 500);
if (!flock($fp, LOCK_EX))
    fail("Kilit alınamadı.", 500);
$contents = stream_get_contents($fp);
$state = $contents ? json_decode($contents, true) : null;
if (!$state) {
    flock($fp, LOCK_UN);
    fclose($fp);
    fail("Oda verisi bozuk.", 500);
}

// Only allow White (creator usually) to swap? Or anyone?
// User said "white player can choose black". This implies White initiates.
// Let's allow if requester is White OR Black.
$isWhite = (($state["players"]["w"]["token"] ?? "") === $token);
$isBlack = (($state["players"]["b"]["token"] ?? "") === $token);

if (!$isWhite && !$isBlack) {
    flock($fp, LOCK_UN);
    fclose($fp);
    fail("Sadece oyuncular taraf değiştirebilir.");
}

// Swap seats
$temp = $state["players"]["w"];
$state["players"]["w"] = $state["players"]["b"];
$state["players"]["b"] = $temp;

// Update active_users roles
if (isset($state["active_users"])) {
    // Update White's token role (which is now in 'w' slot but might be old black token? No wait.)
    // We swapped the objects.
    // The token that is now in 'w' slot should have role "Beyaz".
    $newWToken = $state["players"]["w"]["token"];
    $newBToken = $state["players"]["b"]["token"];

    if ($newWToken && isset($state["active_users"][$newWToken])) {
        $state["active_users"][$newWToken]["role"] = "Beyaz";
    }
    if ($newBToken && isset($state["active_users"][$newBToken])) {
        $state["active_users"][$newBToken]["role"] = "Siyah";
    }
}

// System Message
$state["seq"] = ($state["seq"] ?? 0) + 1;
$state["chat"][] = [
    "seq" => $state["seq"],
    "time" => date("Y-m-d H:i:s"),
    "name" => "Sistem",
    "color" => "",
    "text" => "Taraflar değiştirildi."
];
cz_cap_chat($state);

ftruncate($fp, 0);
rewind($fp);
fwrite($fp, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
fflush($fp);
flock($fp, LOCK_UN);
fclose($fp);

echo json_encode(["ok" => true]);
