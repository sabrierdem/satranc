<?php
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
$fen = trim($data["fen"] ?? "");
$pgn = trim($data["pgn"] ?? "");
$mv = $data["move"] ?? null;
$status = $data["status"] ?? [];

if ($room === "" || $token === "")
  fail("Parametre eksik.");
if (!$mv || empty($mv["from"]) || empty($mv["to"]))
  fail("Hamle eksik.");
if ($fen === "")
  fail("FEN eksik.");
// PGN boş olabilir (başlangıçta), sorun değil.

$path = __DIR__ . "/_rooms/{$room}.json";
if (!file_exists($path))
  fail("Oda yok.");

$fp = null;
$state = load_state_locked($path, $fp);

// ---- initialize defaults (backward compatible) ----
$state["seq"] = intval($state["seq"] ?? 0);
$state["fen"] = $state["fen"] ?? "start";
$state["pgn"] = $state["pgn"] ?? "";
if (!isset($state["chat"]) || !is_array($state["chat"]))
  $state["chat"] = [];
if (!isset($state["history"]) || !is_array($state["history"]))
  $state["history"] = [];
if (!isset($state["future"]) || !is_array($state["future"]))
  $state["future"] = [];
if (!isset($state["over"]))
  $state["over"] = false;
$state["result"] = $state["result"] ?? "";
$state["winner"] = $state["winner"] ?? "";
$state["reason"] = $state["reason"] ?? "";
if (!isset($state["streak"]) || !is_array($state["streak"])) {
  $state["streak"] = [
    "w" => ["sig" => "", "n" => 0],
    "b" => ["sig" => "", "n" => 0],
  ];
}

// ---- auth: identify player ----
$who = "";
if (($state["players"]["w"]["token"] ?? "") === $token)
  $who = "w";
if (($state["players"]["b"]["token"] ?? "") === $token)
  $who = "b";
if ($who === "") {
  save_state_unlock($fp, $path, $state);
  fail("Yetkisiz.", 403);
}

// ---- if game over, no more moves ----
if (!empty($state["over"])) {
  save_state_unlock($fp, $path, $state);
  fail("Oyun bitti. Yeni hamle yapılamaz.", 409);
}

// ---- save snapshot for undo (includes over/result/streak) ----
$state["history"][] = [
  "fen" => $state["fen"] ?? "start",
  "pgn" => $state["pgn"] ?? "",
  "over" => !empty($state["over"]),
  "result" => $state["result"] ?? "",
  "winner" => $state["winner"] ?? "",
  "reason" => $state["reason"] ?? "",
  "streak" => $state["streak"] ?? null
];

// new move breaks redo chain
$state["future"] = [];

// ---- apply new fen/pgn from client (authoritative feed) ----
$state["fen"] = $fen;
$state["pgn"] = $pgn;

// ---- streak: same player repeats identical move 3 times in a row => draw (custom rule) ----
$sig = strtoupper($mv["from"] . "-" . $mv["to"] . "-" . ($mv["promotion"] ?? ""));
$prevSig = $state["streak"][$who]["sig"] ?? "";
$prevN = intval($state["streak"][$who]["n"] ?? 0);

if ($sig === $prevSig) {
  $state["streak"][$who]["n"] = $prevN + 1;
} else {
  $state["streak"][$who]["sig"] = $sig;
  $state["streak"][$who]["n"] = 1;
}
// other side streak unchanged (as requested: "bir oyuncu tarafından ardışık")

// ---- read status flags (computed on client with chess.js) ----
$isCheck = !empty($status["check"]);
$isMate = !empty($status["checkmate"]);
$isStale = !empty($status["stalemate"]);

// ---- decide outcomes ----
if (intval($state["streak"][$who]["n"] ?? 0) >= 3) {
  $state["over"] = true;
  $state["result"] = "draw";
  $state["winner"] = "";
  $state["reason"] = "Aynı hamlenin aynı oyuncu tarafından 3 kez ardışık tekrarı";
}

if (empty($state["over"])) {
  if ($isMate) {
    $state["over"] = true;
    $state["winner"] = $who;
    $state["result"] = ($who === "w") ? "white" : "black";
    $state["reason"] = "Şah-mat";
  } else if ($isStale && !$isCheck) {
    $state["over"] = true;
    $state["winner"] = "";
    $state["result"] = "draw";
    $state["reason"] = "Pat (stalemate)";
  }
}

// ---- bump seq & broadcast events via chat ----
$state["seq"] = intval($state["seq"] ?? 0) + 1;

// Optional: add a move marker message (comment out if you don't want it)
// $state["chat"][] = ["seq"=>$state["seq"],"time"=>date("Y-m-d H:i:s"),"name"=>"Sistem","color"=>"","text"=>"Hamle: {$sig}"];

if ($isCheck && empty($state["over"])) {
  // warn both sides
  $state["seq"]++;
  $state["chat"][] = [
    "seq" => $state["seq"],
    "time" => date("Y-m-d H:i:s"),
    "name" => "Sistem",
    "color" => "",
    "text" => "Şah!"
  ];
}

if (!empty($state["over"])) {
  $state["seq"]++;
  if (($state["result"] ?? "") === "draw") {
    $msg = "Oyun berabere bitti. (" . ($state["reason"] ?? "—") . ")";
  } else {
    $w = ($state["winner"] ?? "") === "w" ? "Beyaz" : "Siyah";
    $msg = "Oyun bitti. Kazanan: {$w}. (" . ($state["reason"] ?? "—") . ")";
  }
  $state["chat"][] = [
    "seq" => $state["seq"],
    "time" => date("Y-m-d H:i:s"),
    "name" => "Sistem",
    "color" => "",
    "text" => $msg
  ];
}

save_state_unlock($fp, $path, $state);

echo json_encode([
  "ok" => true,
  "seq" => $state["seq"],
  "over" => !empty($state["over"]),
  "result" => $state["result"] ?? "",
  "winner" => $state["winner"] ?? "",
  "reason" => $state["reason"] ?? ""
], JSON_UNESCAPED_UNICODE);
