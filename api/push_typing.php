<?php
require_once __DIR__ . '/_cors.php';
header('Content-Type: application/json; charset=utf-8');

function fail($msg, $code = 400)
{
    http_response_code($code);
    echo json_encode(["ok" => false, "error" => $msg]);
    exit;
}

$raw = file_get_contents("php://input");
$data = json_decode($raw, true);

$room = strtoupper(trim($data["room"] ?? ""));
$token = trim($data["token"] ?? "");

if ($room === "" || $token === "")
    fail("Parametre eksik.");

$path = __DIR__ . "/_rooms/{$room}.json";
if (!file_exists($path))
    fail("Oda yok.");

// Lock file to update state safely
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

// Identify user
$who = "";
if (($state["players"]["w"]["token"] ?? "") === $token)
    $who = "w";
else if (($state["players"]["b"]["token"] ?? "") === $token)
    $who = "b";

// Only players can trigger typing for now (spectators ignored to avoid spam)
if ($who !== "") {
    if (!isset($state["typing"]) || !is_array($state["typing"])) {
        $state["typing"] = ["w" => 0, "b" => 0];
    }
    $state["typing"][$who] = time();

    // Save back
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    fflush($fp);
}

flock($fp, LOCK_UN);
fclose($fp);

echo json_encode(["ok" => true]);
