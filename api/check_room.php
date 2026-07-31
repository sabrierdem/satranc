<?php
require_once __DIR__ . '/_cors.php';
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

$room = mb_strtoupper(trim($_GET["room"] ?? ""), 'UTF-8');
if ($room === "") {
    echo json_encode(["ok" => true, "exists" => false]);
    exit;
}

$path = __DIR__ . "/_rooms/{$room}.json";
$exists = file_exists($path);

// Optional: Check if player slots are full if we wanted to give more info
// But primarily we just need to know if it *exists* to prevent overwriting/creating duplicate intent.
// Actually, create_room handles collision by retrying, but the UI constraint is "Don't let user click Create if they typed an existing room code".

echo json_encode([
    "ok" => true,
    "exists" => $exists
]);
