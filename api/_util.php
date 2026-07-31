<?php
// Shared helpers for the room API.

// Room codes are always A-Z0-9, 3-20 chars (matches create_room's own rule).
// Validating everywhere blocks path traversal via ../ in $room.
function cz_valid_room($room)
{
    return is_string($room) && preg_match('/^[A-Z0-9]{3,20}$/', $room) === 1;
}

// Safe absolute path for a room state file. Returns null for invalid codes.
function cz_room_path($room)
{
    if (!cz_valid_room($room))
        return null;
    return __DIR__ . "/_rooms/{$room}.json";
}

// Trim chat to the last $max messages so a long game cannot grow the room file
// (and every 1s poll's read+write) without bound.
function cz_cap_chat(&$state, $max = 200)
{
    if (isset($state["chat"]) && is_array($state["chat"]) && count($state["chat"]) > $max) {
        $state["chat"] = array_slice($state["chat"], -$max);
    }
}

// Best-effort client IP, preferring Cloudflare's real-client header.
function cz_client_ip()
{
    foreach (["HTTP_CF_CONNECTING_IP", "HTTP_X_FORWARDED_FOR", "REMOTE_ADDR"] as $h) {
        if (!empty($_SERVER[$h])) {
            $ip = trim(explode(",", $_SERVER[$h])[0]);
            if ($ip !== "")
                return $ip;
        }
    }
    return "0.0.0.0";
}

// Simple fixed-window rate limiter backed by a small JSON file per IP+bucket.
// Returns true if the call is allowed, false if the limit is exceeded.
function cz_rate_limit($bucket, $max, $windowSeconds)
{
    $dir = __DIR__ . "/_rate";
    if (!is_dir($dir))
        @mkdir($dir, 0755, true);
    if (!is_dir($dir) || !is_writable($dir))
        return true; // fail open: never block real users on a storage hiccup

    $key = preg_replace('/[^a-zA-Z0-9_.:-]/', '_', $bucket . "_" . cz_client_ip());
    $path = $dir . "/" . substr(hash("sha256", $key), 0, 40) . ".json";

    $fp = @fopen($path, "c+");
    if (!$fp)
        return true;
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        return true;
    }

    $now = time();
    $raw = stream_get_contents($fp);
    $rec = $raw ? json_decode($raw, true) : null;
    if (!is_array($rec) || ($now - intval($rec["start"] ?? 0)) >= $windowSeconds) {
        $rec = ["start" => $now, "count" => 0];
    }
    $rec["count"] = intval($rec["count"] ?? 0) + 1;
    $allowed = $rec["count"] <= $max;

    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($rec));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    // Opportunistic cleanup of stale rate files (1% of calls).
    if (random_int(1, 100) === 1) {
        foreach (glob($dir . "/*.json") ?: [] as $f) {
            if (is_file($f) && ($now - filemtime($f)) > 3600)
                @unlink($f);
        }
    }
    return $allowed;
}
