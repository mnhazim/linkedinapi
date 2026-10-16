<?php
// Step 1 OAuth: simpan caption + state dalam session, redirect ke LinkedIn.
require __DIR__ . '/../bootstrap.php';

if (empty($_SESSION['attendee']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$_SESSION['post_text']   = trim($_POST['text'] ?? '');
$_SESSION['oauth_state'] = bin2hex(random_bytes(16)); // lindung daripada CSRF

$linkedin = new LinkedIn($config['linkedin']);
header('Location: ' . $linkedin->getAuthUrl($_SESSION['oauth_state']));
