<?php
// Proses pendaftaran: simpan data (dalam sistem sebenar: DB) dan jana social card.
require __DIR__ . '/../bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || trim($_POST['name'] ?? '') === '') {
    header('Location: index.php');
    exit;
}

$attendee = [
    'name'    => trim($_POST['name']),
    'title'   => trim($_POST['title'] ?? ''),
    'company' => trim($_POST['company'] ?? ''),
];

$cardFile = bin2hex(random_bytes(8)) . '.png';
SocialCard::generate($attendee, $config['event'], CARD_DIR . '/' . $cardFile);

$_SESSION['attendee'] = $attendee + ['card' => $cardFile];

header('Location: success.php');
