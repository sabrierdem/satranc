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
$text = trim($data["text"] ?? "");

if ($room === "" || $token === "")
  fail("Parametre eksik.");
if ($text === "")
  fail("Mesaj boş.");

// Throttle chat per client (20 messages / 10s) to prevent flooding.
if (!cz_rate_limit("chat", 20, 10))
  fail("Çok hızlı mesaj gönderiyorsunuz. Lütfen bekleyin.", 429);

$path = cz_room_path($room);
if (!$path)
  fail("Geçersiz oda kodu.");
if (!file_exists($path))
  fail("Oda yok.");

// Read + modify + write under an exclusive lock so concurrent chat/moves
// cannot clobber each other.
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

// token -> oyuncu adı/renk çöz; only members (players or known spectators) may post
$name = "İzleyici";
$color = "";
if (($state["players"]["w"]["token"] ?? "") === $token) {
  $name = $state["players"]["w"]["name"] ?: "Beyaz";
  $color = "w";
} else if (($state["players"]["b"]["token"] ?? "") === $token) {
  $name = $state["players"]["b"]["name"] ?: "Siyah";
  $color = "b";
} else if (isset($state["active_users"][$token])) {
  $name = $state["active_users"][$token]["name"] ?: "İzleyici";
  $color = "";
} else {
  flock($fp, LOCK_UN);
  fclose($fp);
  fail("Yetkisiz.", 403);
}

$state["seq"] = intval($state["seq"] ?? 0) + 1;
$state["chat"][] = [
  "seq" => $state["seq"],
  "time" => date("Y-m-d H:i:s"),
  "name" => $name,
  "color" => $color,
  "text" => mb_substr($text, 0, 500)
];
cz_cap_chat($state);

ftruncate($fp, 0);
rewind($fp);
fwrite($fp, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
fflush($fp);
flock($fp, LOCK_UN);
fclose($fp);

echo json_encode(["ok" => true], JSON_UNESCAPED_UNICODE);
