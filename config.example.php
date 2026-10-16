<?php
// Copy file ni jadi config.php dan isi dengan credential app LinkedIn kau.
// https://www.linkedin.com/developers/apps

return [
    'linkedin' => [
        'client_id'     => 'YOUR_CLIENT_ID',
        'client_secret' => 'YOUR_CLIENT_SECRET',
        // Mesti SAMA tepat dengan "Authorized redirect URLs" dalam tab Auth app LinkedIn.
        'redirect_uri'  => 'http://localhost:8000/linkedin-callback.php',
        // openid + profile  -> dapatkan member id (sub) untuk author post
        // w_member_social   -> izin publish post bagi pihak member
        'scopes'        => ['openid', 'profile', 'w_member_social'],
        // Format YYYYMM. LinkedIn sokong setiap versi ~12 bulan, update bila expired.
        'api_version'   => '202509',
    ],

    'event' => [
        'name'     => 'MyConf 2026',
        'date'     => '12-13 Disember 2026',
        'venue'    => 'Kuala Lumpur Convention Centre',
        'url'      => 'https://myconf.example.com',
        'hashtags' => ['MyConf2026', 'TechMalaysia'],
    ],
];
