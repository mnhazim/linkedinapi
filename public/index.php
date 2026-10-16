<?php
// Simulasi borang pendaftaran conference. Dalam sistem sebenar, ini halaman register kau sendiri.
require __DIR__ . '/../bootstrap.php';

$event = $config['event'];

render('Daftar ' . e($event['name']), '
<h1>Daftar ' . e($event['name']) . '</h1>
<p class="muted">' . e($event['date']) . ' &middot; ' . e($event['venue']) . '</p>
<form method="post" action="register.php">
  <label>Nama penuh</label>
  <input name="name" required maxlength="60">
  <label>Jawatan</label>
  <input name="title" maxlength="60">
  <label>Syarikat</label>
  <input name="company" maxlength="60">
  <button type="submit">Daftar</button>
</form>');
