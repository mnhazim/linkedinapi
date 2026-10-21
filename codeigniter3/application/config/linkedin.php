<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
| -------------------------------------------------------------------------
| LinkedIn share (Linkedin_share library)
| -------------------------------------------------------------------------
| Credentials come from https://www.linkedin.com/developers/apps -> your app -> Auth.
| Keep the real client_secret out of git (e.g. read it from an environment variable).
*/

$config['client_id']     = 'YOUR_CLIENT_ID';
$config['client_secret'] = getenv('LINKEDIN_CLIENT_SECRET') ?: 'YOUR_CLIENT_SECRET';

// Must match one of the "Authorized redirect URLs" in the app's Auth tab EXACTLY
// (scheme, host, port, path, trailing slash).
$config['redirect_uri']  = 'https://example.com/linkedin_example/callback';

// openid + profile -> member id (sub) for the post author
// w_member_social  -> permission to post on the member's behalf
$config['scopes']        = array('openid', 'profile', 'w_member_social');

// LinkedIn-Version header for /rest/* calls, format YYYYMM.
// LinkedIn supports each version for roughly 12 months only. When API calls start
// failing with a "version ... is not active" style error, bump this to a recent month.
// Plan to bump it about once a year.
$config['api_version']   = '202609';

// Default post visibility: 'PUBLIC' or 'CONNECTIONS'. Can be overridden per post.
$config['visibility']    = 'PUBLIC';

// cURL timeouts in seconds.
$config['connect_timeout'] = 10;
$config['timeout']         = 30;

// CI session key used to store the OAuth state between get_auth_url() and handle_callback().
$config['session_key']   = 'linkedin_oauth_state';
