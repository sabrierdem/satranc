<?php
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
        // Format: YYYYMMDD_HHMMSS_{name}.json
        $parts = explode("_", $name, 3);
        $displayName = $name;
        if (count($parts) >= 3) {
            $displayName = $parts[2];
            $displayName = str_replace(".json", "", $displayName);
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
