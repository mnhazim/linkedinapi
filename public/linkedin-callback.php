<?php
// Step 2 OAuth: LinkedIn redirect balik ke sini dengan ?code=. Terus publish post.
require __DIR__ . '/../bootstrap.php';

$attendee = $_SESSION['attendee'] ?? null;
$state    = $_SESSION['oauth_state'] ?? null;
unset($_SESSION['oauth_state']);

try {
    if (isset($_GET['error'])) {
        // Contoh: user tekan "Cancel" kat skrin izin LinkedIn
        throw new RuntimeException('LinkedIn: ' . ($_GET['error_description'] ?? $_GET['error']));
    }
    if (!$attendee || !$state || !hash_equals($state, $_GET['state'] ?? '')) {
        throw new RuntimeException('Session tamat atau state tak sah. Sila cuba semula.');
    }

    $linkedin = new LinkedIn($config['linkedin']);

    $token     = $linkedin->exchangeCode($_GET['code'] ?? '')['access_token'];
    $user      = $linkedin->getUserInfo($token);
    $authorUrn = 'urn:li:person:' . $user['sub'];

    $imageUrn = $linkedin->uploadImage($token, $authorUrn, CARD_DIR . '/' . $attendee['card']);
    $postUrn  = $linkedin->createPost(
        $token,
        $authorUrn,
        $_SESSION['post_text'] ?? '',
        $imageUrn,
        $attendee['name'] . ' attending ' . $config['event']['name']
    );

    $postUrl = 'https://www.linkedin.com/feed/update/' . $postUrn . '/';

    render('Berjaya share', '
<h1>Dah share ke LinkedIn! ✅</h1>
<p class="ok">Post kau dah live, ' . e($user['name'] ?? $attendee['name']) . '.</p>
<a class="btn" href="' . e($postUrl) . '" target="_blank" rel="noopener">Tengok post</a>');
} catch (Throwable $ex) {
    error_log('[linkedin] ' . $ex->getMessage());

    render('Gagal share', '
<h1>Tak berjaya share 😕</h1>
<p class="err">' . e($ex->getMessage()) . '</p>
<a class="btn" href="success.php">Cuba semula</a>');
}
