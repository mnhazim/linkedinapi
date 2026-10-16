<?php

/**
 * Client LinkedIn minimal: OAuth 2.0 + upload image + create post.
 *
 * Flow:
 *   1. getAuthUrl()        -> redirect user ke LinkedIn untuk login & bagi izin
 *   2. exchangeCode()      -> tukar ?code= kepada access token
 *   3. getUserInfo()       -> dapatkan `sub` (member id) untuk author URN
 *   4. uploadImage()       -> initializeUpload + PUT binary, dapat urn:li:image:...
 *   5. createPost()        -> POST /rest/posts dengan image tu
 */
class LinkedIn
{
    private const AUTH_URL  = 'https://www.linkedin.com/oauth/v2/authorization';
    private const TOKEN_URL = 'https://www.linkedin.com/oauth/v2/accessToken';
    private const API_BASE  = 'https://api.linkedin.com';

    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function getAuthUrl(string $state): string
    {
        return self::AUTH_URL . '?' . http_build_query([
            'response_type' => 'code',
            'client_id'     => $this->config['client_id'],
            'redirect_uri'  => $this->config['redirect_uri'],
            'state'         => $state,
            'scope'         => implode(' ', $this->config['scopes']),
        ]);
    }

    /** @return array{access_token:string, expires_in:int} */
    public function exchangeCode(string $code): array
    {
        $res = $this->request('POST', self::TOKEN_URL, [
            'Content-Type: application/x-www-form-urlencoded',
        ], http_build_query([
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => $this->config['redirect_uri'],
            'client_id'     => $this->config['client_id'],
            'client_secret' => $this->config['client_secret'],
        ]));

        $this->assertStatus($res, 200, 'Tukar code kepada token gagal');
        return json_decode($res['body'], true);
    }

    /** OpenID Connect userinfo. `sub` = person id. */
    public function getUserInfo(string $token): array
    {
        $res = $this->request('GET', self::API_BASE . '/v2/userinfo', [
            'Authorization: Bearer ' . $token,
        ]);

        $this->assertStatus($res, 200, 'Gagal dapatkan profil user');
        return json_decode($res['body'], true);
    }

    /** Upload image dan pulangkan URN dia (urn:li:image:...). */
    public function uploadImage(string $token, string $ownerUrn, string $filePath): string
    {
        // Step 1: minta upload URL
        $res = $this->request('POST', self::API_BASE . '/rest/images?action=initializeUpload',
            $this->restHeaders($token),
            json_encode(['initializeUploadRequest' => ['owner' => $ownerUrn]])
        );
        $this->assertStatus($res, 200, 'initializeUpload gagal');

        $value = json_decode($res['body'], true)['value'];

        // Step 2: PUT binary image ke upload URL
        $res = $this->request('PUT', $value['uploadUrl'], [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/octet-stream',
        ], file_get_contents($filePath));

        if ($res['status'] < 200 || $res['status'] >= 300) {
            throw new RuntimeException("Upload image gagal (HTTP {$res['status']}): {$res['body']}");
        }

        return $value['image'];
    }

    /** Publish post dengan satu image. Pulangkan URN post (urn:li:share:...). */
    public function createPost(string $token, string $authorUrn, string $text, string $imageUrn, string $altText = ''): string
    {
        $payload = [
            'author'       => $authorUrn,
            'commentary'   => self::toLittleText($text),
            'visibility'   => 'PUBLIC',
            'distribution' => [
                'feedDistribution'               => 'MAIN_FEED',
                'targetEntities'                 => [],
                'thirdPartyDistributionChannels' => [],
            ],
            'content' => [
                'media' => [
                    'id'      => $imageUrn,
                    'altText' => $altText,
                ],
            ],
            'lifecycleState'            => 'PUBLISHED',
            'isReshareDisabledByAuthor' => false,
        ];

        $res = $this->request('POST', self::API_BASE . '/rest/posts',
            $this->restHeaders($token), json_encode($payload));

        $this->assertStatus($res, 201, 'Create post gagal');

        // Post id dipulangkan dalam header x-restli-id
        return $res['headers']['x-restli-id'] ?? '';
    }

    /**
     * Field `commentary` guna format "little text": aksara khas mesti di-escape,
     * kalau tak post boleh terpotong. #hashtag ditukar jadi template hashtag.
     */
    public static function toLittleText(string $text): string
    {
        $escaped = preg_replace('/([\\\\|{}@\[\]()<>#*_~])/u', '\\\\$1', $text);

        return preg_replace('/\\\\#([\p{L}\p{N}_]+)/u', '{hashtag|\\\\#|$1}', $escaped);
    }

    private function restHeaders(string $token): array
    {
        return [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
            'LinkedIn-Version: ' . $this->config['api_version'],
            'X-Restli-Protocol-Version: 2.0.0',
        ];
    }

    private function assertStatus(array $res, int $expected, string $message): void
    {
        if ($res['status'] !== $expected) {
            throw new RuntimeException("$message (HTTP {$res['status']}): {$res['body']}");
        }
    }

    /** @return array{status:int, headers:array<string,string>, body:string} */
    private function request(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        $responseHeaders = [];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$responseHeaders) {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $responseBody = curl_exec($ch);
        if ($responseBody === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException("cURL error: $error");
        }

        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return ['status' => $status, 'headers' => $responseHeaders, 'body' => $responseBody];
    }
}
