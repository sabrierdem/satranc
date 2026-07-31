<?php
require_once __DIR__ . '/_cors.php';
header('Content-Type: application/json; charset=utf-8');

function fail($msg, $code = 400)
{
    http_response_code($code);
    echo json_encode(["ok" => false, "error" => $msg]);
    exit;
}

// Support Beacon API (text/plain or form-data) or JSON
$input = file_get_contents("php://input");
$data = json_decode($input, true);
if (!$data) {
    // try post vars (beacon sends as text/plain sometimes or form, let's generic parse)
    // Actually beacon with Blob/JSON is possible.
    // If standard POST:
    $data = $_POST;
}
if (!$data && $input) {
    // Maybe raw json but content type header missing?
    $data = json_decode($input, true);
}

$room = mb_strtoupper(trim($data["room"] ?? ""), 'UTF-8');
$token = trim($data["token"] ?? "");

if ($room === "" || $token === "") {
    // Silent fail for beacon
    exit;
}

$path = __DIR__ . "/_rooms/{$room}.json";
if (!file_exists($path))
    exit;

$fp = fopen($path, 'c+');
if (flock($fp, LOCK_EX)) {
    $raw = stream_get_contents($fp);
    $state = json_decode($raw, true);

    if ($state && isset($state["active_users"][$token])) {
        $u = $state["active_users"][$token];

        // Announce leave
        $state["seq"] = ($state["seq"] ?? 0) + 1;
        $state["chat"][] = [
            "seq" => $state["seq"],
            "time" => date("Y-m-d H:i:s"),
            "name" => "Sistem",
            "color" => "",
            "text" => "{$u['name']} ({$u['role']}) odadan ayrıldı."
        ];

        // Free seat
        if (($state["players"]["w"]["token"] ?? "") === $token) {
            $state["players"]["w"]["name"] = "";
        } else if (($state["players"]["b"]["token"] ?? "") === $token) {
            $state["players"]["b"]["name"] = "";
        }

        unset($state["active_users"][$token]);

        // Auto-delete logic removed to persist game state on disconnect
        // Cleaning will happen via poll.php garbage collection (24h)
        // or we can implement a softer check (e.g. mark as abandoned)
        // For now, we keep the room file so user can rejoin.


        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }
    flock($fp, LOCK_UN);
}
fclose($fp);

echo json_encode(["ok" => true]);
