<?php
// Cross-origin access for the GitHub Pages build of the frontend.
// The app is same-origin when served from satrancevi.com.tr, so this only
// matters for the Pages mirror at sabrierdem.github.io.

$allowedOrigins = [
    "https://sabrierdem.github.io",
];

$origin = $_SERVER["HTTP_ORIGIN"] ?? "";

if ($origin !== "" && in_array($origin, $allowedOrigins, true)) {
    header("Access-Control-Allow-Origin: {$origin}");
    header("Vary: Origin");
    header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type");
    header("Access-Control-Max-Age: 86400");
}

// Preflight: answer and stop before the endpoint does any work.
if (($_SERVER["REQUEST_METHOD"] ?? "") === "OPTIONS") {
    http_response_code(204);
    exit;
}
