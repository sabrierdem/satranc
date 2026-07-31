<?php
require_once __DIR__ . '/_cors.php';
require_once __DIR__ . '/_util.php';
header('Content-Type: application/json; charset=utf-8');

$dir = __DIR__ . "/_saved_games";
if (!is_dir($dir)) {
    echo json_encode(["ok" => true, "files" => []]);
    exit;
}

$files = glob($dir . "/*.json");
$result = [];

foreach ($files as $f) {
    if (is_file($f)) {
        $name = basename($f);
        $time = filemtime($f);
        // Extract display name if possible
        // Format: YYYYMMDD_HHMMSS_{name}_{rand}.json  (older files have no _{rand})
        $parts = explode("_", $name, 3);
        $displayName = $name;
        if (count($parts) >= 3) {
            $displayName = str_replace(".json", "", $parts[2]);
            // drop the 6-hex random suffix added by save_game
            $displayName = preg_replace('/_[0-9a-f]{6}$/', '', $displayName);
        }

        $result[] = [
            "file" => $name,
            "name" => $displayName,
            "date" => $time,
            "date_fmt" => date("d.m.Y H:i", $time)
        ];
    }
}

// Sort by date desc
usort($result, function ($a, $b) {
    return $b["date"] - $a["date"];
});

echo json_encode(["ok" => true, "files" => $result], JSON_UNESCAPED_UNICODE);
