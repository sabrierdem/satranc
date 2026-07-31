<?php
header('Content-Type: application/json; charset=utf-8');
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

function fail($msg, $code = 400)
{
  http_response_code($code);
  echo json_encode(["ok" => false, "error" => $msg]);
  exit;
}
function loadRoom($path)
{
  if (!file_exists($path))
    return null;
  $j = json_decode(file_get_contents($path), true);
  return $j ?: null;
}
function saveRoom($path, $state)
{
  file_put_contents($path, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
}

$raw = file_get_contents("php://input");
$data = json_decode($raw, true);
$name = trim($data["name"] ?? "Anonim");
$room = mb_strtoupper(trim($data["room"] ?? ""), 'UTF-8');
if ($room === "")
  fail("Oda kodu eksik.");

$path = __DIR__ . "/_rooms/{$room}.json";
$fp = fopen($path, 'c+');
if (!$fp)
  fail("Oda açılamadı.");
if (!flock($fp, LOCK_EX))
  fail("Oda kilitli.");

$raw = stream_get_contents($fp);
$state = $raw ? json_decode($raw, true) : null;

if (!$state) {
  flock($fp, LOCK_UN);
  fclose($fp);
  fail("Oda bulunamadı veya veri bozuk.");
}

// Ensure active_users array exists
if (!isset($state["active_users"]))
  $state["active_users"] = [];

// --- SESSION RESUMPTION ---
$tokenIn = trim($data["token"] ?? "");
if ($tokenIn && isset($state["active_users"][$tokenIn])) {
  // User exists. Resume session.
  $u = &$state["active_users"][$tokenIn];

  // If user was away for > 5 seconds, announce return (avoids spam on quick refresh)
  if ((time() - $u["last_seen"]) > 5) {
    $state["seq"] = ($state["seq"] ?? 0) + 1;
    $state["chat"][] = [
      "seq" => $state["seq"],
      "time" => date("Y-m-d H:i:s"),
      "name" => "Sistem",
      "color" => "",
      "text" => "{$u['name']} ({$u['role']}) tekrar bağlandı."
    ];
  }

  $u["last_seen"] = time();
  $role = $u["role"];
  $color = ($role === "Beyaz") ? "w" : (($role === "Siyah") ? "b" : "spectator");

  // Spectator Upgrade Logic
  if (($mode === "player") && ($color === "spectator")) {
    // Try White
    if (empty($state["players"]["w"]["name"])) {
      $state["players"]["w"]["name"] = $name ?: ($u["name"] ?? "Anonim");
      $state["players"]["w"]["token"] = $tokenIn;
      $u["role"] = "Beyaz";
      if ($name)
        $u["name"] = $name;
      $color = "w";

      $state["seq"] = ($state["seq"] ?? 0) + 1;
      $state["chat"][] = [
        "seq" => $state["seq"],
        "time" => date("Y-m-d H:i:s"),
        "name" => "Sistem",
        "color" => "",
        "text" => "{$u['name']} (Beyaz) olarak oyuna dahil oldu."
      ];
    }
    // Try Black
    else if (empty($state["players"]["b"]["name"])) {
      $state["players"]["b"]["name"] = $name ?: ($u["name"] ?? "Anonim");
      $state["players"]["b"]["token"] = $tokenIn;
      $u["role"] = "Siyah";
      if ($name)
        $u["name"] = $name;
      $color = "b";

      $state["seq"] = ($state["seq"] ?? 0) + 1;
      $state["chat"][] = [
        "seq" => $state["seq"],
        "time" => date("Y-m-d H:i:s"),
        "name" => "Sistem",
        "color" => "",
        "text" => "{$u['name']} (Siyah) olarak oyuna dahil oldu."
      ];
    }
  }

  // Rewrite file atomically
  ftruncate($fp, 0);
  rewind($fp);
  fwrite($fp, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
  flock($fp, LOCK_UN);
  fclose($fp);

  echo json_encode([
    "ok" => true,
    "token" => $tokenIn,
    "room" => $room,
    "color" => $color,
    "seq" => intval($state["seq"] ?? 0),
    "fen" => ($state["fen"] ?? "start") === "start" ? "rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1" : $state["fen"],
    "pgn" => $state["pgn"] ?? ""
  ]);
  exit;
}

// --- NEW JOIN ---
$color = "spectator";
$token = null;
$mode = $data["mode"] ?? "player";

if ($mode === "spectator") {
  $color = "spectator";
  $token = bin2hex(random_bytes(16));
} else if (empty($state["players"]["w"]["name"])) {
  $state["players"]["w"]["name"] = $name;
  $color = "w";
  $token = $state["players"]["w"]["token"];
} else if (empty($state["players"]["b"]["name"])) {
  $state["players"]["b"]["name"] = $name;
  $color = "b";
  $token = $state["players"]["b"]["token"];
} else {
  $color = "spectator";
  $token = bin2hex(random_bytes(16));
}

$roleLabel = ($color === "w") ? "Beyaz" : (($color === "b") ? "Siyah" : "İzleyici");

$state["active_users"][$token] = [
  "name" => $name,
  "role" => $roleLabel,
  "last_seen" => time()
];

$state["seq"] = ($state["seq"] ?? 0) + 1;
$state["chat"][] = [
  "seq" => $state["seq"],
  "time" => date("Y-m-d H:i:s"),
  "name" => "Sistem",
  "color" => "",
  "text" => "{$name} ({$roleLabel}) odaya katıldı."
];

// Rewrite file atomically
ftruncate($fp, 0);
rewind($fp);
fwrite($fp, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
flock($fp, LOCK_UN);
fclose($fp);

echo json_encode([
  "ok" => true,
  "room" => $room,
  "token" => $token,
  "color" => $color,
  "fen" => ($state["fen"] ?? "start") === "start" ? "rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1" : $state["fen"],
  "pgn" => $state["pgn"] ?? "",
  "seq" => $state["seq"] ?? 0
], JSON_UNESCAPED_UNICODE);
