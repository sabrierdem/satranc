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

function save_state_unlock($fp, $state)
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

$path = cz_room_path($room);
if (!$path)
  fail("Geçersiz oda kodu.");
if (!file_exists($path))
  fail("Oda yok.");

$fp = null;
$state = load_state_locked($path, $fp);

// init
$state["seq"] = intval($state["seq"] ?? 0);
if (!isset($state["chat"]) || !is_array($state["chat"]))
  $state["chat"] = [];
if (!isset($state["history"]) || !is_array($state["history"]))
  $state["history"] = [];
if (!isset($state["future"]) || !is_array($state["future"]))
  $state["future"] = [];
if (!isset($state["streak"]) || !is_array($state["streak"])) {
  $state["streak"] = [
    "w" => ["sig" => "", "n" => 0],
    "b" => ["sig" => "", "n" => 0],
  ];
}

$who = "";
if (($state["players"]["w"]["token"] ?? "") === $token)
  $who = "w";
if (($state["players"]["b"]["token"] ?? "") === $token)
  $who = "b";
if ($who === "") {
  save_state_unlock($fp, $state);
  fail("Undo yetkiniz yok.", 403);
}

if (count($state["history"]) < 1) {
  save_state_unlock($fp, $state);
  fail("Geri alınacak hamle yok.");
}

// current -> future
$state["future"][] = [
  "fen" => $state["fen"] ?? "start",
  "pgn" => $state["pgn"] ?? "",
  "over" => !empty($state["over"]),
  "result" => $state["result"] ?? "",
  "winner" => $state["winner"] ?? "",
  "reason" => $state["reason"] ?? "",
  "streak" => $state["streak"] ?? null
];

// pop previous
$prev = array_pop($state["history"]);
$state["fen"] = $prev["fen"] ?? "start";
$state["pgn"] = $prev["pgn"] ?? "";
$state["over"] = !empty($prev["over"]);
$state["result"] = $prev["result"] ?? "";
$state["winner"] = $prev["winner"] ?? "";
$state["reason"] = $prev["reason"] ?? "";
if (isset($prev["streak"]) && is_array($prev["streak"]))
  $state["streak"] = $prev["streak"];

// notify
$state["seq"]++;
$playerName = ($state["players"][$who]["name"] ?? "") ?: ($who === "w" ? "Beyaz" : "Siyah");
$state["chat"][] = [
  "seq" => $state["seq"],
  "time" => date("Y-m-d H:i:s"),
  "name" => "Sistem",
  "color" => "",
  "text" => "{$playerName} undo yaptı."
];

cz_cap_chat($state);
save_state_unlock($fp, $state);

echo json_encode([
  "ok" => true,
  "seq" => $state["seq"],
  "fen" => $state["fen"],
  "pgn" => $state["pgn"],
  "over" => !empty($state["over"]),
  "result" => $state["result"] ?? "",
  "winner" => $state["winner"] ?? "",
  "reason" => $state["reason"] ?? ""
], JSON_UNESCAPED_UNICODE);
