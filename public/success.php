<?php
// Halaman "terima kasih" selepas register: preview social card + butang share ke LinkedIn.
require __DIR__ . '/../bootstrap.php';

$attendee = $_SESSION['attendee'] ?? null;
if (!$attendee) {
    header('Location: index.php');
    exit;
}

$event    = $config['event'];
$hashtags = implode(' ', array_map(fn ($h) => '#' . $h, $event['hashtags']));

$defaultText = "Saya akan hadir ke {$event['name']} pada {$event['date']} di {$event['venue']}! 🎉\n\n"
    . "Jom join sekali: {$event['url']}\n\n{$hashtags}";

render('Pendaftaran berjaya', '
<h1>Terima kasih, ' . e($attendee['name']) . '! 🎉</h1>
<p>Pendaftaran kau berjaya. Ini social card kau:</p>
<img src="card.php?v=' . e($attendee['card']) . '" alt="Social card">
<form method="post" action="linkedin-auth.php">
  <label>Caption post LinkedIn</label>
  <textarea name="text" maxlength="3000">' . e($defaultText) . '</textarea>
  <button type="submit">Share ke LinkedIn</button>
</form>
<p class="muted">Kau akan diminta login LinkedIn dan bagi izin untuk post bagi pihak kau.</p>');
