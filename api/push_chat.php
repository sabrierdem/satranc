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

$room = mb_strtoupper(trim($data["room"] ?? ""), 'UTF-8');
$token = trim($data["token"] ?? "");
$text = trim($data["text"] ?? "");

if ($room === "" || $token === "")
  fail("Parametre eksik.");
if ($text === "")
  fail("Mesaj boş.");

$path = __DIR__ . "/_rooms/{$room}.json";
if (!file_exists($path))
  fail("Oda yok.");

$state = json_decode(file_get_contents($path), true);
if (!$state)
  fail("Oda verisi bozuk.", 500);

// token -> oyuncu adı/renk çöz
$name = "Anonim";
$color = "";
if (($state["players"]["w"]["token"] ?? "") === $token) {
  $name = $state["players"]["w"]["name"] ?: "Beyaz";
  $color = "w";
} else if (($state["players"]["b"]["token"] ?? "") === $token) {
  $name = $state["players"]["b"]["name"] ?: "Siyah";
  $color = "b";
} else {
  $name = "İzleyici";
  $color = "";
}

$state["seq"] = intval($state["seq"] ?? 0) + 1;
$state["chat"][] = [
  "seq" => $state["seq"],
  "time" => date("Y-m-d H:i:s"),
  "name" => $name,
  "color" => $color,
  "text" => mb_substr($text, 0, 500)
];

file_put_contents($path, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
echo json_encode(["ok" => true], JSON_UNESCAPED_UNICODE);
