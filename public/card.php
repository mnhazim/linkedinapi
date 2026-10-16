<?php
// Serve social card milik session semasa (folder storage/ tak public).
require __DIR__ . '/../bootstrap.php';

$file = $_SESSION['attendee']['card'] ?? null;
if (!$file || !is_file(CARD_DIR . '/' . $file)) {
    http_response_code(404);
    exit;
}

header('Content-Type: image/png');
readfile(CARD_DIR . '/' . $file);
