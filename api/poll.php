<?php
require_once __DIR__ . '/_cors.php';
header('Content-Type: application/json; charset=utf-8');
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

function fail($msg, $code = 400)
{
  http_response_code($code);
  echo json_encode(["ok" => false, "error" => $msg], JSON_UNESCAPED_UNICODE);
  exit;
}

$room = mb_strtoupper(trim($_GET["room"] ?? ""), 'UTF-8');
$since = intval($_GET["since"] ?? 0);
$token = trim($_GET["token"] ?? "");

if ($room === "" || $token === "")
  fail("Parametre eksik.");

$path = __DIR__ . "/_rooms/{$room}.json";
$fp = fopen($path, 'c+');
if (!$fp)
  fail("Oda açılamadı.", 500);

// For poll, we try to lock. If filtered/heavy, we could use SH first, but for safety EX is better.
if (!flock($fp, LOCK_EX))
  fail("Oda kilitli.", 500);

$raw = stream_get_contents($fp);
$state = $raw ? json_decode($raw, true) : null;

if (!$state) {
  flock($fp, LOCK_UN);
  fclose($fp);
  fail("Oda verisi bozuk.", 500);
}

// auth: allow players and spectators who have any token
$ok = !empty($token);
if (!$ok) {
  flock($fp, LOCK_UN);
  fclose($fp);
  fail("Yetkisiz.", 403);
}

$seq = intval($state["seq"] ?? 0);

// REPAIR: Check if seq is desynchronized (less than last chat seq)
$changed = false;
if (isset($state["chat"]) && is_array($state["chat"]) && count($state["chat"]) > 0) {
  $lastMsg = end($state["chat"]);
  $lastSeq = intval($lastMsg["seq"] ?? 0);
  if ($lastSeq > $seq) {
    // Repair needed
    $state["seq"] = $lastSeq;
    $seq = $lastSeq;
    $changed = true;
  }
}

// --- PRESENCE SYSTEM START ---
$now = time();

// Ensure active_users exists
if (!isset($state["active_users"]))
  $state["active_users"] = [];

// 1. Update Heartbeat (for current token)
if ($token) {
  if (isset($state["active_users"][$token])) {
    // Only update if > 3s to reduce writes, but since we are here and locked, cheap enough to just set in memory.
    // We only set $changed=true if we really want to commit to disk.
    if (($now - $state["active_users"][$token]["last_seen"]) > 3) {
      $state["active_users"][$token]["last_seen"] = $now;
      $changed = true;
    }
  } else {
    // Recover if player
    $wToken = $state["players"]["w"]["token"] ?? "";
    $bToken = $state["players"]["b"]["token"] ?? "";
    $recoveredName = "";
    $recoveredRole = "";

    if ($token === $wToken && $wToken) {
      $recoveredName = $state["players"]["w"]["name"];
      $recoveredRole = "Beyaz";
    } else if ($token === $bToken && $bToken) {
      $recoveredName = $state["players"]["b"]["name"];
      $recoveredRole = "Siyah";
    }

    if ($recoveredName) {
      $state["active_users"][$token] = [
        "name" => $recoveredName,
        "role" => $recoveredRole,
        "last_seen" => $now
      ];
      $changed = true;

      // Announce unexpected recovery (silent join fix)
      $state["seq"] = ($state["seq"] ?? 0) + 1;
      $state["chat"][] = [
        "seq" => $state["seq"],
        "time" => date("Y-m-d H:i:s"),
        "name" => "Sistem",
        "color" => "",
        "text" => "{$recoveredName} ({$recoveredRole}) tekrar bağlandı."
      ];
    }
  }
}

// 2. Check Timeouts
$timeout = 15; // seconds (faster disconnect detection)
foreach ($state["active_users"] as $t => $u) {
  if (($now - $u["last_seen"]) > $timeout) {
    // User timed out - Remove and Announce
    $state["seq"] = ($state["seq"] ?? 0) + 1;
    $state["chat"][] = [
      "seq" => $state["seq"],
      "time" => date("Y-m-d H:i:s"),
      "name" => "Sistem",
      "color" => "",
      "text" => "{$u['name']} ({$u['role']}) odadan ayrıldı."
    ];

    // Free up the seat if they were a player
    if (($state["players"]["w"]["token"] ?? "x") === $t) {
      $state["players"]["w"]["name"] = "";
    } else if (($state["players"]["b"]["token"] ?? "x") === $t) {
      $state["players"]["b"]["name"] = "";
    }

    unset($state["active_users"][$t]);
    $changed = true;
  }
}

// 4. Auto-Delete if empty
if (empty($state["active_users"])) {
  // If we delete the file, we must close handle first?
  // Or just unlink path.
  // unlink works on path.
  // But we hold lock.
  // Unlink while locked is usually okay on POSIX, file stays until closed.
  // But safer to unlock, close, then unlink.
  if ($changed) {
    // If we were going to write changes, but everyone is gone, just delete.
  }

  flock($fp, LOCK_UN);
  fclose($fp);
  @unlink($path);
  echo json_encode(["ok" => false, "error" => "Oda kapatıldı (kimse kalmadı)."]);
  exit;
}

// 3. Save if needed
if ($changed) {
  ftruncate($fp, 0);
  rewind($fp);
  fwrite($fp, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
  // Update local seq
  $seq = intval($state["seq"] ?? 0);
}

flock($fp, LOCK_UN);
fclose($fp);
// --- PRESENCE SYSTEM END ---

// Refresh $fen, $pgn, $chat from state (in case we modified it or just loaded)
// (Actually we used $state vars so we are good, but $seq might have changed)
$fen = $state["fen"] ?? "start";
$pgn = $state["pgn"] ?? "";

$chat = $state["chat"] ?? [];
$outChat = [];
if (is_array($chat)) {
  foreach ($chat as $m) {
    $mseq = intval($m["seq"] ?? 0);
    if ($mseq > $since)
      $outChat[] = $m;
  }
}
// typing status: check last 3 seconds
$typingColors = [];
if (isset($state["typing"]) && is_array($state["typing"])) {
  $now = time();
  foreach ($state["typing"] as $c => $ts) {
    if (($now - $ts) <= 3)
      $typingColors[] = $c;
  }
}

// Determine current user's color for frontend sync
$myColor = "spectator";
if ($token && ($state["players"]["w"]["token"] ?? "") === $token)
  $myColor = "w";
else if ($token && ($state["players"]["b"]["token"] ?? "") === $token)
  $myColor = "b";

echo json_encode([
  "ok" => true,
  "seq" => $seq,
  "fen" => $fen,
  "pgn" => $pgn,
  "chat" => $outChat,
  "over" => !empty($state["over"]),
  "result" => $state["result"] ?? "",
  "winner" => $state["winner"] ?? "",
  "reason" => $state["reason"] ?? "",
  "typing_colors" => $typingColors,
  "players" => [
    "w" => $state["players"]["w"]["name"] ?? "Beyaz",
    "b" => $state["players"]["b"]["name"] ?? "Siyah"
  ],
  "my_color" => $myColor
], JSON_UNESCAPED_UNICODE);
