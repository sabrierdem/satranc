<?php
require_once __DIR__ . '/_cors.php';
require_once __DIR__ . '/_util.php';
require_once __DIR__ . '/_chess.php';
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
$pgn = trim($data["pgn"] ?? "");
$mv = $data["move"] ?? null;

if ($room === "" || $token === "")
  fail("Parametre eksik.");
if (!cz_valid_room($room))
  fail("Geçersiz oda kodu.");
if (!$mv || empty($mv["from"]) || empty($mv["to"]))
  fail("Hamle eksik.");

$path = cz_room_path($room);
if (!$path || !file_exists($path))
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
  flock($fp, LOCK_UN);
  fclose($fp);
  fail("Yetkisiz.", 403);
}

// ---- if game over, no more moves ----
if (!empty($state["over"])) {
  flock($fp, LOCK_UN);
  fclose($fp);
  fail("Oyun bitti. Yeni hamle yapılamaz.", 409);
}

// ---- AUTHORITATIVE VALIDATION: parse the stored position server-side ----
$pos = cz_fen_parse($state["fen"]);
if (!$pos) {
  flock($fp, LOCK_UN);
  fclose($fp);
  fail("Sunucu pozisyonu okunamadı.", 500);
}

// It must actually be this player's turn according to the stored FEN.
if ($pos["side"] !== $who) {
  flock($fp, LOCK_UN);
  fclose($fp);
  fail("Sıra sizde değil.", 409);
}

// The move must be legal in the stored position. The client's claimed
// fen/checkmate/stalemate flags are ignored -- the server computes them.
$from = strtolower(trim($mv["from"]));
$to = strtolower(trim($mv["to"]));
$promo = strtolower(trim($mv["promotion"] ?? ""));
$applied = cz_apply_move($pos, $from, $to, $promo);
if ($applied === null) {
  flock($fp, LOCK_UN);
  fclose($fp);
  fail("Geçersiz hamle.", 422);
}
list($newPos, $meta) = $applied;
$newFen = cz_fen_export($newPos);

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

// ---- store authoritative fen; keep client pgn only for move-list display ----
$state["fen"] = $newFen;
$state["pgn"] = mb_substr($pgn, 0, 20000);

// ---- streak: same player repeats identical move 3 times in a row => draw (custom rule) ----
$sig = strtoupper($from . "-" . $to . "-" . $promo);
$prevSig = $state["streak"][$who]["sig"] ?? "";
$prevN = intval($state["streak"][$who]["n"] ?? 0);

if ($sig === $prevSig) {
  $state["streak"][$who]["n"] = $prevN + 1;
} else {
  $state["streak"][$who]["sig"] = $sig;
  $state["streak"][$who]["n"] = 1;
}

// ---- outcomes (all computed server-side) ----
$isCheck = !empty($meta["check"]);
$isMate = !empty($meta["checkmate"]);
$isStale = !empty($meta["stalemate"]);

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
  } else if ($isStale) {
    $state["over"] = true;
    $state["winner"] = "";
    $state["result"] = "draw";
    $state["reason"] = "Pat (stalemate)";
  }
}

// ---- bump seq & broadcast events via chat ----
$state["seq"] = intval($state["seq"] ?? 0) + 1;

if ($isCheck && empty($state["over"])) {
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

cz_cap_chat($state);
save_state_unlock($fp, $path, $state);

echo json_encode([
  "ok" => true,
  "seq" => $state["seq"],
  "fen" => $newFen,
  "over" => !empty($state["over"]),
  "result" => $state["result"] ?? "",
  "winner" => $state["winner"] ?? "",
  "reason" => $state["reason"] ?? ""
], JSON_UNESCAPED_UNICODE);
