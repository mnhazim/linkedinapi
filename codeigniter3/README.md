# Linkedin_share for CodeIgniter 3

A drop-in CodeIgniter 3 library that signs a member in with LinkedIn and publishes an image post on their behalf. It also includes an optional GD library that generates an "I'm attending" social card PNG. You don't need Composer or any other dependency.

- **PHP:** 7.2 or newer, with the `curl` and `json` extensions. `gd` with FreeType is needed only for `Social_card`. `mbstring` is optional.
- **CodeIgniter:** 3.1.x. This was tested on 3.1.13.

## 1. Install

Copy the folder contents into your app:

```
application/libraries/Linkedin_share.php                         (required)
application/libraries/Social_card.php                            (optional, card generator)
application/third_party/linkedin_share/Linkedin_share_exception.php  (required)
application/third_party/linkedin_share/fonts/*.ttf               (only for Social_card)
application/config/linkedin.php                                  (required, edit it)
application/controllers/Linkedin_example.php                     (example, delete in prod)
```

## 2. Configure: `application/config/linkedin.php`

| Key | Meaning |
|---|---|
| `client_id`, `client_secret` | Come from the LinkedIn app's **Auth** tab. Keep the secret out of git. The template reads it from the `LINKEDIN_CLIENT_SECRET` env var. |
| `redirect_uri` | Your callback URL. It must match an **Authorized redirect URL** exactly. |
| `scopes` | `openid profile w_member_social` |
| `api_version` | The `LinkedIn-Version` header (`YYYYMM`). LinkedIn supports each version for about 12 months only. Bump it roughly once a year, or whenever calls fail with a "version not active" error. |
| `visibility` | The default visibility for posts: `PUBLIC` (the default) or `CONNECTIONS`. You can override it for each post. |
| `connect_timeout`, `timeout` | cURL timeouts in seconds. The defaults are 10 and 30. |
| `session_key` | The CI session key that holds the OAuth state. |

Values passed to `$this->load->library('linkedin_share', $params)` override the file.

## 3. LinkedIn developer app

1. Go to <https://www.linkedin.com/developers/apps> and click **Create app**. The app must be linked to a LinkedIn Company Page.
2. On the **Products** tab, add both of these. They are usually approved instantly.
   - **Share on LinkedIn** gives you `w_member_social`.
   - **Sign In with LinkedIn using OpenID Connect** gives you `openid` and `profile`.
3. On the **Auth** tab, copy the Client ID and Client Secret. Then add your **Authorized redirect URL**, for example `https://yourapp.com/linkedin_example/callback`, plus a local dev URL if you need one.

## 4. Usage

```php
$this->load->library(array('session', 'linkedin_share'));

// 1. Redirect to LinkedIn (a random state is generated and stored in the CI session)
redirect($this->linkedin_share->get_auth_url());

// 2. In the callback action
try {
    $token  = $this->linkedin_share->handle_callback();          // verifies + consumes state
    $result = $this->linkedin_share->share_image(
        $token['access_token'],
        "I'm attending MyConf 2026! #MyConf2026",                 // plain text
        '/path/to/card.png',
        array('alt_text' => 'Attending MyConf 2026', 'visibility' => 'PUBLIC')
    );
    // $result['post_url'], $result['post_urn']
} catch (Linkedin_share_exception $e) {
    log_message('error', $e->getErrorType().': '.$e->getMessage());
}
```

See `controllers/Linkedin_example.php` for the full flow: a card preview, a caption form, the auth redirect, the callback, and serving the card image.

### Methods (`Linkedin_share`)

| Method | Returns |
|---|---|
| `get_auth_url($state = NULL)` | The authorization URL. If `$state` is NULL, it generates one and stores it in the CI session. |
| `handle_callback($query = NULL, $expected_state = NULL)` | The token array (`access_token`, `expires_in`, `expires_at`, `scope`, ...). It checks the state, then `?error=`, then exchanges `?code=`. |
| `exchange_code($code)` | The token array. |
| `get_userinfo($token)` | OpenID userinfo (`sub`, `name`, `picture`, ...). |
| `get_person_urn($token)` | `urn:li:person:{sub}` |
| `upload_image($token, $owner_urn, $file_path)` | `urn:li:image:...` Accepts JPG, PNG or GIF. |
| `create_post($token, $author_urn, $text, $image_urn = NULL, $options = array())` | The post URN. The options are `alt_text`, `visibility` and `raw`. |
| `share_image($token, $text, $image_path, $options = array())` | `post_urn`, `post_url`, `image_urn`, `author_urn`, `user` |
| `Linkedin_share::post_url($post_urn)` | `https://www.linkedin.com/feed/update/{urn}/` |
| `Linkedin_share::to_little_text($text)` | Plain text converted to LinkedIn "little text". |
| `config($key = NULL)` | The effective config. |

### Methods (`Social_card`, optional)

| Method | Returns |
|---|---|
| `generate(array $attendee, array $event, $output_path)` | The path of a 1200x627 PNG. `$attendee` takes `name` (required), `title` and `company`. `$event` takes `name`, `date` and `venue`. You can pass `font_dir` as a load param. |

### OAuth state: how it is stored

By default, the library stores the state in the **CI session** through `get_instance()`. Load the `session` library before you call `get_auth_url()` or `handle_callback()`, otherwise you get a `config` error. The state is single use: `handle_callback()` removes it whether verification passes or fails, so refreshing the callback page cannot post twice.

If you keep state somewhere else, such as a DB row for the registration, call `get_auth_url($your_state)` and then `handle_callback(NULL, $your_state)`. The library then never touches the session.

Also turn on `$config['csrf_protection'] = TRUE` in `config.php` and build the share form with `form_open()`. Without it, another site could POST a caption to your auth action. A member who has already authorized your app is sent straight back by LinkedIn without a consent screen, so that post would publish.

### Post text ("little text")

`commentary` uses LinkedIn's little-text format. By default, `create_post()` converts plain text for you:

- The characters `\ | { } @ [ ] ( ) < > # * _ ~` are backslash-escaped. Unescaped, they can make LinkedIn silently cut the post off.
- `#word` at the start of the text or after whitespace becomes a real hashtag (`{hashtag|\#|word}`). The word can contain letters, digits and Unicode marks. A `#` anywhere else (`C#`, `page#top`) stays a literal `#`.
- URLs are kept as text with their reserved characters escaped, and LinkedIn auto-links them. Plain URLs like `https://myconf.example.com` are known to work. URLs that contain `_ ( ) #` have not been tested on a live post.
- `@` mentions are not supported and are posted as plain text. If you build little text yourself, pass `'raw' => TRUE`.
- The limit is 3000 characters of input text.

## 5. Error handling

Every failure throws **`Linkedin_share_exception`**, which extends `RuntimeException`. The library never returns `false` or an error array.

| `getErrorType()` | When |
|---|---|
| `config` | A missing `client_id`, `redirect_uri` or `api_version`, no CI session loaded, or no curl extension. |
| `state` | The state is missing or doesn't match, or the session expired or was replayed. |
| `oauth_denied` | The user pressed Cancel, or LinkedIn returned `?error=`. |
| `transport` | A cURL failure: DNS, TLS or timeout. |
| `http` | LinkedIn returned an unexpected status. Use `getHttpStatus()`, `getResponseBody()` and `getResponseJson()`. |
| `response` | A 2xx response that is missing expected fields, or invalid JSON. |
| `input` | Empty or too-long text, invalid UTF-8, a bad visibility value, or an image that is unreadable or not an image. |
| `card` | A `Social_card` failure: no GD or FreeType, a missing font, or an unwritable directory. |

Messages never contain the client secret or the access token, so they are safe to log. They can contain LinkedIn's error text, so show users a friendly message instead, as the example controller does.

Common LinkedIn errors:
- `401 Invalid access token`: the token expired. Tokens last about 60 days.
- `426` or "version not active": bump `api_version`.
- `403` on `/rest/posts`: the token lacks `w_member_social`. Check the app's Products.
- `redirect_uri does not match`: fix the Auth tab or the config so the two match exactly.

## 6. Testing: there is no sandbox

LinkedIn has **no sandbox**. Every post is real and immediately visible. When you test:

- Use a dedicated test LinkedIn account, not your personal one.
- Set `'visibility' => 'CONNECTIONS'` in the config or the post options while you develop.
- Delete test posts manually afterwards.

## Notes

- **Tokens:** the example uses the token once and drops it. If you store it so you can post later, treat it like a password. It lasts about 60 days, and the `w_member_social`-only flow gets no refresh token.
- **Image processing:** LinkedIn processes an uploaded image asynchronously. The post is accepted right away, and the image appears within seconds.
- **Card storage:** the example writes cards to `application/cache/linkedin_cards/`, which must be writable. Clean old files up with a cron job, or store the path in your registrations table instead of the session.
- **PHP 8.2+ with CodeIgniter 3.1.13:** CI's own core emits "dynamic property" deprecation notices. This library's files don't. In `ENVIRONMENT = development`, either lower `error_reporting` to `E_ALL & ~E_DEPRECATED` or run PHP 7.x or 8.1.
- The DejaVu fonts are free to redistribute. See `fonts/LICENSE-DejaVu.txt`.
