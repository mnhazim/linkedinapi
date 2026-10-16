<?php

session_start();

if (!file_exists(__DIR__ . '/config.php')) {
    exit('config.php tak jumpa. Copy config.example.php ke config.php dan isi credential LinkedIn.');
}

$config = require __DIR__ . '/config.php';

require __DIR__ . '/lib/LinkedIn.php';
require __DIR__ . '/lib/SocialCard.php';

const CARD_DIR = __DIR__ . '/storage/cards';

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function render(string $title, string $body): void
{
    echo <<<HTML
<!doctype html>
<html lang="ms">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$title}</title>
<style>
  body { font-family: system-ui, sans-serif; background: #f3f4f8; margin: 0; padding: 32px 16px; color: #1f2333; }
  .box { max-width: 680px; margin: auto; background: #fff; border-radius: 12px; padding: 28px; box-shadow: 0 4px 20px rgba(0,0,0,.06); }
  h1 { margin-top: 0; font-size: 1.5rem; }
  label { display: block; font-weight: 600; margin: 14px 0 6px; }
  input, textarea { width: 100%; box-sizing: border-box; padding: 10px; border: 1px solid #ccd; border-radius: 8px; font: inherit; }
  textarea { min-height: 130px; }
  button, .btn { display: inline-block; margin-top: 18px; padding: 12px 20px; border: 0; border-radius: 8px; background: #0a66c2; color: #fff; font-weight: 600; font-size: 1rem; cursor: pointer; text-decoration: none; }
  img { max-width: 100%; border-radius: 8px; }
  .ok { background: #e7f6ec; color: #11602e; padding: 12px; border-radius: 8px; }
  .err { background: #fdecec; color: #8a1c1c; padding: 12px; border-radius: 8px; word-break: break-word; }
  .muted { color: #667; font-size: .9rem; }
</style>
</head>
<body><div class="box">{$body}</div></body>
</html>
HTML;
}
