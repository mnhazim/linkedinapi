# LinkedIn Share POC (Vanilla PHP)

A simple POC so conference attendees can **publish a social card (image) to LinkedIn** right after they register.

```
Register form ──► generate social card (GD) ──► success page (preview + caption)
                                                    │  "Share ke LinkedIn"
                                                    ▼
                              LinkedIn OAuth (login + permission)
                                                    │  ?code=
                                                    ▼
             linkedin-callback.php: token → userinfo → upload image → create post ✅
```

## Requirements

- PHP 8.1+ with the `curl` and `gd` (FreeType) extensions
- A LinkedIn Developer app

## 1. Set up the LinkedIn app

1. Go to <https://www.linkedin.com/developers/apps> → **Create app** (it must be linked to a LinkedIn Company Page; your conference page works).
2. **Products** tab → request both of these (usually approved instantly):
   - **Share on LinkedIn** → gives the `w_member_social` scope
   - **Sign In with LinkedIn using OpenID Connect** → gives `openid` and `profile`
3. **Auth** tab:
   - Copy the **Client ID** and **Client Secret**
   - Add the **Authorized redirect URL**: `http://localhost:8000/linkedin-callback.php` (plus your production URL later)

## 2. Configure

```bash
cp config.example.php config.php
# edit config.php: client_id, client_secret, redirect_uri, and the event details
```

## 3. Run

```bash
php -S localhost:8000 -t public
```

Open <http://localhost:8000>, register, then click **Share ke LinkedIn**.

## File structure

| File | What it does |
|---|---|
| `lib/LinkedIn.php` | API client: OAuth, `userinfo`, image upload, create post |
| `lib/SocialCard.php` | Generates a 1200×627 PNG card with GD |
| `public/index.php` | Mock registration form |
| `public/register.php` | Saves the attendee to the session and generates the card |
| `public/success.php` | Card preview, caption editor, share button |
| `public/linkedin-auth.php` | Redirects to LinkedIn (with a `state` value for CSRF protection) |
| `public/linkedin-callback.php` | Exchanges the code for a token, uploads the image, publishes the post |

## The LinkedIn API calls involved

1. `GET  https://www.linkedin.com/oauth/v2/authorization` – user login and consent
2. `POST https://www.linkedin.com/oauth/v2/accessToken` – code → access token
3. `GET  https://api.linkedin.com/v2/userinfo` – `sub` → author `urn:li:person:{sub}`
4. `POST https://api.linkedin.com/rest/images?action=initializeUpload` – returns an `uploadUrl` and `urn:li:image:...`
5. `PUT  {uploadUrl}` – uploads the PNG binary
6. `POST https://api.linkedin.com/rest/posts` – creates the post with `content.media.id = urn:li:image:...`

The `/rest/*` endpoints need the `LinkedIn-Version: YYYYMM` header (`api_version` in the config). LinkedIn supports each version for about 12 months. If you get an error that the version is not active, bump it to a newer month.

## Notes for integrating into the real system

- **The session is temporary storage.** In production, save the attendee and card path in the DB, keyed by the registration ID.
- **Tokens are not stored.** This POC uses the token once, for a single post. You don't need to save it unless you want to post again later (it is valid for about 60 days).
- **Caption:** the `commentary` field uses LinkedIn's "little text" format. `LinkedIn::toLittleText()` escapes the special characters and converts `#hashtag` automatically. Without this, a post can get cut off at characters like `(`, `@` or `_`.
- **Card design:** replace `SocialCard.php` with your conference design, for example a background template PNG plus the name text on top.
- Uploaded images take a few seconds to process on LinkedIn's side. The post is still created right away.
- The DejaVu fonts in `assets/fonts/` are free to use (see `LICENSE-DejaVu.txt`).
