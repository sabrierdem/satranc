<?php
require_once __DIR__ . '/_cors.php';
header('Content-Type: application/json; charset=utf-8');

function fail($msg, $code = 400)
{
  http_response_code($code);
  echo json_encode(["ok" => false, "error" => $msg], JSON_UNESCAPED_UNICODE);
  exit;
}

function rand_token($len = 32)
{
  // 32 bytes hex => 64 chars (if needed). We'll keep it simple:
  return bin2hex(random_bytes(intval($len / 2)));
}

function rand_room_code($len = 6)
{
  $alphabet = "ABCDEFGHJKLMNPQRSTUVWXYZ23456789"; // I,O,1,0 yok
  $out = "";
  for ($i = 0; $i < $len; $i++) {
    $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
  }
  return $out;
}

$data = json_decode(file_get_contents("php://input"), true) ?: [];
$name = trim($data["name"] ?? "");
if ($name === "")
  $name = "Anonim";

$roomsDir = __DIR__ . "/_rooms";
if (!is_dir($roomsDir)) {
  if (!mkdir($roomsDir, 0755, true))
    fail("Rooms klasörü oluşturulamadı.", 500);
}

// unique room code
$requestedRoom = mb_strtoupper(trim($data["room"] ?? ""), 'UTF-8');
$room = "";
$path = "";

if ($requestedRoom !== "") {
  // Validate custom room code
  if (!preg_match("/^[A-Z0-9]+$/", $requestedRoom)) {
    fail("Oda kodu sadece harf ve rakam içerebilir.");
  }
  if (strlen($requestedRoom) < 3 || strlen($requestedRoom) > 20) {
    fail("Oda kodu 3-20 karakter arasında olmalıdır.");
  }

  // Check if room exists
  $path = $roomsDir . "/{$requestedRoom}.json";

  // If it exists, check if it's "dead" (empty users and old) to recycle it
  $canRecycle = false;
  if (file_exists($path)) {
    $oldState = json_decode(file_get_contents($path), true);
    // If broken JSON, or no active users, or last updated > 10 mins ago
    $lastMod = filemtime($path);
    $isStale = (time() - $lastMod) > 600; // 10 mins stale

    if (!$oldState || empty($oldState["active_users"]) || $isStale) {
      $canRecycle = true;
      // Delete old file to allow fresh creation below
      @unlink($path);
    }
  }

  if (file_exists($path) && !$canRecycle) {
    fail("Bu oda adı ('{$requestedRoom}') şu an aktif kullanımda. Başka bir kod deneyin.");
  }

  $room = $requestedRoom;
} else {
  // Generate random
  for ($i = 0; $i < 10; $i++) {
    $room = rand_room_code(6);
    $path = $roomsDir . "/{$room}.json";
    if (!file_exists($path))
      break;
    $room = "";
  }
}

if ($room === "")
  fail("Oda kodu üretilemedi veya geçersiz.");

// creator is White by default
$wToken = rand_token(32);
$bToken = rand_token(32);

// initialize state
$state = [
  "room" => $room,
  "created_at" => time(),
  "seq" => 2,
  "fen" => "start",
  "pgn" => "",
  "history" => [],
  "future" => [],
  "over" => false,
  "result" => "",
  "winner" => "",
  "reason" => "",
  "streak" => [
    "w" => ["sig" => "", "n" => 0],
    "b" => ["sig" => "", "n" => 0],
  ],
  "players" => [
    "w" => ["name" => $name, "token" => $wToken],
    "b" => ["name" => "", "token" => $bToken],
  ],
  "active_users" => [
    $wToken => ["name" => $name, "role" => "Beyaz", "last_seen" => time()]
  ],
  "chat" => [
    ["seq" => 1, "time" => date("Y-m-d H:i:s"), "name" => "Sistem", "color" => "", "text" => "Oda oluşturuldu. Beyaz oyuna başlar."],
    ["seq" => 2, "time" => date("Y-m-d H:i:s"), "name" => "Sistem", "color" => "", "text" => "{$name} (Beyaz) odaya katıldı."]
  ],
];

if (file_put_contents($path, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX) === false) {
  fail("Oda kaydedilemedi.", 500);
}

// Aggressive Garbage Collector:
// Delete rooms older than 60 minutes to strictly prevent stale data issues as requested
try {
  $files = glob($roomsDir . "/*.json");
  $now = time();
  $expire = 3600; // 60 mins aggressive cleanup
  foreach ($files as $f) {
    if (is_file($f)) {
      // If file older than 60 mins, delete
      if ($now - filemtime($f) > $expire) {
        @unlink($f);
      }
    }
  }
} catch (Exception $e) {
}

echo json_encode([
  "ok" => true,
  "room" => $room,
  "token" => $wToken,
  "color" => "w",
  "fen" => "start",
  "pgn" => "",
], JSON_UNESCAPED_UNICODE);
