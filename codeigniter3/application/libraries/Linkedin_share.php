<?php
defined('BASEPATH') OR exit('No direct script access allowed');

require_once APPPATH.'third_party/linkedin_share/Linkedin_share_exception.php';

/**
 * Linkedin_share - CodeIgniter 3 library: "Sign in + share an image post to LinkedIn".
 *
 * Flow:
 *   1. get_auth_url()      -> redirect the member to LinkedIn (state stored in CI session)
 *   2. handle_callback()   -> verify state, exchange ?code= for an access token
 *   3. share_image()       -> userinfo -> upload image -> create post (one call)
 *
 * Lower-level calls are public too: exchange_code(), get_userinfo(), get_person_urn(),
 * upload_image(), create_post(), to_little_text(), post_url().
 *
 * Every failure throws Linkedin_share_exception (see its getErrorType()).
 *
 * Requires PHP 7.2+ with the curl and json extensions.
 *
 * Load:  $this->load->library('linkedin_share');           // reads config/linkedin.php
 *        $this->load->library('linkedin_share', $params);  // $params override the config file
 */
class Linkedin_share
{
	const AUTH_URL  = 'https://www.linkedin.com/oauth/v2/authorization';
	const TOKEN_URL = 'https://www.linkedin.com/oauth/v2/accessToken';
	const API_BASE  = 'https://api.linkedin.com';

	/** LinkedIn's documented limit for post commentary. */
	const MAX_COMMENTARY = 3000;

	/** @var array */
	protected $config = array(
		'client_id'       => '',
		'client_secret'   => '',
		'redirect_uri'    => '',
		'scopes'          => array('openid', 'profile', 'w_member_social'),
		'api_version'     => '',
		'visibility'      => 'PUBLIC',
		'connect_timeout' => 10,
		'timeout'         => 30,
		'session_key'     => 'linkedin_oauth_state',
	);

	/** @var object|null CodeIgniter super object, when running inside CI */
	protected $CI;

	/**
	 * @param array $params Optional overrides for config/linkedin.php
	 */
	public function __construct($params = array())
	{
		if (function_exists('get_instance'))
		{
			$this->CI =& get_instance();
		}

		// CI only auto-loads config/<library_name>.php (linkedin_share.php). We use
		// config/linkedin.php, so load it ourselves when present.
		if ($this->CI !== NULL && isset($this->CI->config)
			&& $this->CI->config->load('linkedin', TRUE, TRUE))
		{
			$file_config = $this->CI->config->item('linkedin');
			if (is_array($file_config))
			{
				$this->config = array_merge($this->config, $file_config);
			}
		}

		if (is_array($params))
		{
			$this->config = array_merge($this->config, $params);
		}
	}

	// ------------------------------------------------------------------------
	// OAuth
	// ------------------------------------------------------------------------

	/**
	 * Build the LinkedIn authorization URL.
	 *
	 * @param string|null $state NULL = generate one and store it in the CI session
	 *                           (session library must be loaded). Pass your own if you
	 *                           store state elsewhere, then pass it to handle_callback().
	 * @return string
	 */
	public function get_auth_url($state = NULL)
	{
		$this->require_config(array('client_id', 'redirect_uri'));

		if ($state === NULL)
		{
			$state = bin2hex(random_bytes(16));
			$this->session()->set_userdata($this->config['session_key'], $state);
		}

		return self::AUTH_URL.'?'.http_build_query(array(
			'response_type' => 'code',
			'client_id'     => $this->config['client_id'],
			'redirect_uri'  => $this->config['redirect_uri'],
			'state'         => (string) $state,
			'scope'         => implode(' ', (array) $this->config['scopes']),
		), '', '&', PHP_QUERY_RFC3986);
	}

	/**
	 * Handle the redirect back from LinkedIn: verify state, then exchange the code.
	 * The stored state is consumed (single use) whether or not verification passes.
	 *
	 * @param array|null  $query          Query params; NULL = $this->input->get()
	 * @param string|null $expected_state NULL = read from CI session (set by get_auth_url())
	 * @return array Token response: access_token, expires_in, expires_at, scope, ...
	 */
	public function handle_callback($query = NULL, $expected_state = NULL)
	{
		if ($query === NULL)
		{
			$query = ($this->CI !== NULL && isset($this->CI->input)) ? $this->CI->input->get() : $_GET;
		}
		$query = is_array($query) ? $query : array();

		if ($expected_state === NULL)
		{
			$session = $this->session();
			$expected_state = $session->userdata($this->config['session_key']);
			$session->unset_userdata($this->config['session_key']);
		}

		$given_state = isset($query['state']) ? $query['state'] : NULL;
		if ( ! is_string($expected_state) || $expected_state === ''
			|| ! is_string($given_state) || ! hash_equals($expected_state, $given_state))
		{
			throw new Linkedin_share_exception(
				'OAuth state is missing or does not match (session expired, or the link was reused). Please try again.',
				Linkedin_share_exception::STATE
			);
		}

		if (isset($query['error']))
		{
			$desc = isset($query['error_description']) && is_string($query['error_description'])
				? $query['error_description'] : (string) $query['error'];
			throw new Linkedin_share_exception('LinkedIn authorization failed: '.$desc, Linkedin_share_exception::OAUTH_DENIED);
		}

		if ( ! isset($query['code']) || ! is_string($query['code']) || $query['code'] === '')
		{
			throw new Linkedin_share_exception('LinkedIn callback has no authorization code.', Linkedin_share_exception::INPUT);
		}

		return $this->exchange_code($query['code']);
	}

	/**
	 * Exchange an authorization code for an access token (valid ~60 days).
	 *
	 * @param string $code
	 * @return array access_token, expires_in, expires_at (unix time), scope, id_token, ...
	 */
	public function exchange_code($code)
	{
		$this->require_config(array('client_id', 'client_secret', 'redirect_uri'));

		$res = $this->request('POST', self::TOKEN_URL, array(
			'Content-Type: application/x-www-form-urlencoded',
		), http_build_query(array(
			'grant_type'    => 'authorization_code',
			'code'          => (string) $code,
			'redirect_uri'  => $this->config['redirect_uri'],
			'client_id'     => $this->config['client_id'],
			'client_secret' => $this->config['client_secret'],
		)));

		$this->assert_status($res, array(200), 'Exchanging the authorization code failed');
		$data = $this->decode_json($res, array('access_token'));

		$data['expires_at'] = isset($data['expires_in']) ? time() + (int) $data['expires_in'] : NULL;
		return $data;
	}

	// ------------------------------------------------------------------------
	// API
	// ------------------------------------------------------------------------

	/**
	 * OpenID Connect userinfo (needs openid + profile scopes).
	 *
	 * @param string $token
	 * @return array sub, name, given_name, family_name, picture, ...
	 */
	public function get_userinfo($token)
	{
		$res = $this->request('GET', self::API_BASE.'/v2/userinfo', array(
			'Authorization: Bearer '.$token,
		));

		$this->assert_status($res, array(200), 'Fetching the LinkedIn profile failed');
		return $this->decode_json($res, array('sub'));
	}

	/**
	 * @param string $token
	 * @return string urn:li:person:{sub}
	 */
	public function get_person_urn($token)
	{
		$user = $this->get_userinfo($token);
		return 'urn:li:person:'.$user['sub'];
	}

	/**
	 * Upload a JPG/PNG/GIF via the Images API (initializeUpload + PUT).
	 *
	 * @param string $token
	 * @param string $owner_urn  urn:li:person:... (must be the post author)
	 * @param string $file_path
	 * @return string urn:li:image:...
	 */
	public function upload_image($token, $owner_urn, $file_path)
	{
		$this->require_config(array('api_version'));

		if ( ! is_file($file_path) || ! is_readable($file_path))
		{
			throw new Linkedin_share_exception('Image file not found or not readable: '.$file_path, Linkedin_share_exception::INPUT);
		}
		$info = @getimagesize($file_path);
		if ($info === FALSE || ! in_array($info[2], array(IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF), TRUE))
		{
			throw new Linkedin_share_exception('Image must be a JPG, PNG or GIF: '.$file_path, Linkedin_share_exception::INPUT);
		}
		$binary = file_get_contents($file_path);
		if ($binary === FALSE || $binary === '')
		{
			throw new Linkedin_share_exception('Could not read image file: '.$file_path, Linkedin_share_exception::INPUT);
		}

		// Step 1: ask for an upload URL
		$res = $this->request('POST', self::API_BASE.'/rest/images?action=initializeUpload',
			$this->rest_headers($token),
			json_encode(array('initializeUploadRequest' => array('owner' => $owner_urn)))
		);
		$this->assert_status($res, array(200), 'Images API initializeUpload failed');
		$data = $this->decode_json($res, array('value'));

		if (empty($data['value']['uploadUrl']) || empty($data['value']['image']))
		{
			throw new Linkedin_share_exception('initializeUpload response has no uploadUrl/image.',
				Linkedin_share_exception::RESPONSE, $res['status'], $res['body']);
		}

		// Step 2: PUT the binary to the upload URL
		$res = $this->request('PUT', $data['value']['uploadUrl'], array(
			'Authorization: Bearer '.$token,
			'Content-Type: application/octet-stream',
		), $binary);
		$this->assert_status($res, array(200, 201), 'Image binary upload failed');

		return $data['value']['image'];
	}

	/**
	 * Create a post (optionally with one image).
	 *
	 * @param string      $token
	 * @param string      $author_urn urn:li:person:...
	 * @param string      $text       Plain text. Escaped to "little text" unless options['raw'] = TRUE.
	 * @param string|null $image_urn  urn:li:image:... or NULL for a text-only post
	 * @param array       $options    alt_text (string), visibility ('PUBLIC'|'CONNECTIONS'),
	 *                                raw (bool: $text is already little-text formatted)
	 * @return string Post URN (urn:li:share:... or urn:li:ugcPost:...)
	 */
	public function create_post($token, $author_urn, $text, $image_urn = NULL, $options = array())
	{
		$this->require_config(array('api_version'));

		$visibility = isset($options['visibility']) ? $options['visibility'] : $this->config['visibility'];
		$visibility = strtoupper((string) $visibility);
		if ( ! in_array($visibility, array('PUBLIC', 'CONNECTIONS'), TRUE))
		{
			throw new Linkedin_share_exception('visibility must be PUBLIC or CONNECTIONS, got: '.$visibility, Linkedin_share_exception::INPUT);
		}

		$text = (string) $text;
		$length = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
		if ($length > self::MAX_COMMENTARY)
		{
			throw new Linkedin_share_exception('Post text is longer than '.self::MAX_COMMENTARY.' characters.', Linkedin_share_exception::INPUT);
		}
		if (trim($text) === '' && $image_urn === NULL)
		{
			throw new Linkedin_share_exception('Post text is empty.', Linkedin_share_exception::INPUT);
		}

		$payload = array(
			'author'       => $author_urn,
			'commentary'   => empty($options['raw']) ? self::to_little_text($text) : $text,
			'visibility'   => $visibility,
			'distribution' => array(
				'feedDistribution'               => 'MAIN_FEED',
				'targetEntities'                 => array(),
				'thirdPartyDistributionChannels' => array(),
			),
			'lifecycleState'            => 'PUBLISHED',
			'isReshareDisabledByAuthor' => FALSE,
		);
		if ($image_urn !== NULL)
		{
			$payload['content'] = array('media' => array(
				'id'      => $image_urn,
				'altText' => isset($options['alt_text']) ? (string) $options['alt_text'] : '',
			));
		}

		$json = json_encode($payload);
		if ($json === FALSE)
		{
			throw new Linkedin_share_exception('Could not encode post payload: '.json_last_error_msg(), Linkedin_share_exception::INPUT);
		}

		$res = $this->request('POST', self::API_BASE.'/rest/posts', $this->rest_headers($token), $json);
		$this->assert_status($res, array(201), 'Creating the LinkedIn post failed');

		if (empty($res['headers']['x-restli-id']))
		{
			throw new Linkedin_share_exception('Post created but LinkedIn returned no x-restli-id header.',
				Linkedin_share_exception::RESPONSE, $res['status'], $res['body']);
		}
		return $res['headers']['x-restli-id'];
	}

	/**
	 * Convenience: userinfo -> upload image -> create post.
	 *
	 * @param string $token
	 * @param string $text
	 * @param string $image_path
	 * @param array  $options See create_post()
	 * @return array post_urn, post_url, image_urn, author_urn, user (userinfo array)
	 */
	public function share_image($token, $text, $image_path, $options = array())
	{
		$user       = $this->get_userinfo($token);
		$author_urn = 'urn:li:person:'.$user['sub'];
		$image_urn  = $this->upload_image($token, $author_urn, $image_path);
		$post_urn   = $this->create_post($token, $author_urn, $text, $image_urn, $options);

		return array(
			'post_urn'   => $post_urn,
			'post_url'   => self::post_url($post_urn),
			'image_urn'  => $image_urn,
			'author_urn' => $author_urn,
			'user'       => $user,
		);
	}

	/**
	 * @param string $post_urn
	 * @return string Public URL of the post
	 */
	public static function post_url($post_urn)
	{
		return 'https://www.linkedin.com/feed/update/'.$post_urn.'/';
	}

	/**
	 * Convert plain text to LinkedIn "little text" (the format of `commentary`).
	 *
	 * - Reserved chars \ | { } @ [ ] ( ) < > # * _ ~ are backslash-escaped, otherwise
	 *   LinkedIn can silently truncate the post at that character.
	 * - "#word" at the start of the text or after whitespace becomes a hashtag
	 *   ({hashtag|\#|word}); word = letters/digits only. A "#" elsewhere
	 *   (C#, URL fragments like page#top) stays a literal, escaped "#".
	 * - URLs are left as text (with reserved chars escaped); LinkedIn auto-links them.
	 *
	 * @param string $text UTF-8
	 * @return string
	 */
	public static function to_little_text($text)
	{
		$escape = function ($s) {
			return preg_replace('/([\\\\|{}@\[\]()<>#*_~])/u', '\\\\$1', $s);
		};

		$out = preg_replace_callback(
			'%(https?://\S+)|(?<!\S)#([\p{L}\p{M}\p{N}]+)|([\\\\|{}@\[\]()<>#*_~])%u',
			function ($m) use ($escape) {
				if (isset($m[1]) && $m[1] !== '')
				{
					return $escape($m[1]);
				}
				if (isset($m[2]) && $m[2] !== '')
				{
					return '{hashtag|\\#|'.$m[2].'}';
				}
				return '\\'.$m[3];
			},
			(string) $text
		);

		if ($out === NULL)
		{
			throw new Linkedin_share_exception('Post text is not valid UTF-8.', Linkedin_share_exception::INPUT);
		}
		return $out;
	}

	/**
	 * @param string|null $key
	 * @return mixed Effective config (or one key). Never use this to print client_secret.
	 */
	public function config($key = NULL)
	{
		if ($key === NULL)
		{
			return $this->config;
		}
		return isset($this->config[$key]) ? $this->config[$key] : NULL;
	}

	// ------------------------------------------------------------------------
	// Internals
	// ------------------------------------------------------------------------

	protected function session()
	{
		if ($this->CI === NULL || ! isset($this->CI->session))
		{
			throw new Linkedin_share_exception(
				'CI session is not loaded. Load the session library, or pass $state to get_auth_url() and $expected_state to handle_callback().',
				Linkedin_share_exception::CONFIG
			);
		}
		return $this->CI->session;
	}

	protected function require_config(array $keys)
	{
		foreach ($keys as $key)
		{
			if (empty($this->config[$key]))
			{
				throw new Linkedin_share_exception('Linkedin_share config "'.$key.'" is not set (application/config/linkedin.php).', Linkedin_share_exception::CONFIG);
			}
		}
	}

	protected function rest_headers($token)
	{
		return array(
			'Authorization: Bearer '.$token,
			'Content-Type: application/json',
			'LinkedIn-Version: '.$this->config['api_version'],
			'X-Restli-Protocol-Version: 2.0.0',
		);
	}

	protected function assert_status(array $res, array $expected, $message)
	{
		if (in_array($res['status'], $expected, TRUE))
		{
			return;
		}

		$detail = '';
		$json = json_decode($res['body'], TRUE);
		if (is_array($json))
		{
			foreach (array('message', 'error_description', 'error') as $k)
			{
				if (isset($json[$k]) && is_string($json[$k]))
				{
					$detail = $json[$k];
					break;
				}
			}
		}
		if ($detail === '')
		{
			$detail = substr(trim($res['body']), 0, 300);
		}

		throw new Linkedin_share_exception(
			$message.' (HTTP '.$res['status'].')'.($detail !== '' ? ': '.$detail : ''),
			Linkedin_share_exception::HTTP, $res['status'], $res['body']
		);
	}

	protected function decode_json(array $res, array $required_keys)
	{
		$data = json_decode($res['body'], TRUE);
		if ( ! is_array($data))
		{
			throw new Linkedin_share_exception('LinkedIn returned invalid JSON: '.json_last_error_msg(),
				Linkedin_share_exception::RESPONSE, $res['status'], $res['body']);
		}
		foreach ($required_keys as $key)
		{
			if ( ! isset($data[$key]))
			{
				throw new Linkedin_share_exception('LinkedIn response is missing "'.$key.'".',
					Linkedin_share_exception::RESPONSE, $res['status'], $res['body']);
			}
		}
		return $data;
	}

	/**
	 * @return array status (int), headers (lower-cased name => value), body (string)
	 */
	protected function request($method, $url, array $headers = array(), $body = NULL)
	{
		if ( ! function_exists('curl_init'))
		{
			throw new Linkedin_share_exception('PHP curl extension is not installed.', Linkedin_share_exception::CONFIG);
		}

		$response_headers = array();
		// Disable "Expect: 100-continue" so curl doesn't wait on it for larger bodies.
		$headers[] = 'Expect:';

		$ch = curl_init($url);
		curl_setopt_array($ch, array(
			CURLOPT_CUSTOMREQUEST  => $method,
			CURLOPT_HTTPHEADER     => $headers,
			CURLOPT_RETURNTRANSFER => TRUE,
			CURLOPT_FOLLOWLOCATION => FALSE,
			CURLOPT_CONNECTTIMEOUT => (int) $this->config['connect_timeout'],
			CURLOPT_TIMEOUT        => (int) $this->config['timeout'],
			CURLOPT_USERAGENT      => 'CI3-Linkedin_share/1.0',
			CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$response_headers) {
				$parts = explode(':', $line, 2);
				if (count($parts) === 2)
				{
					$response_headers[strtolower(trim($parts[0]))] = trim($parts[1]);
				}
				return strlen($line);
			},
		));
		if ($body !== NULL)
		{
			curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
		}

		$response_body = curl_exec($ch);
		if ($response_body === FALSE)
		{
			$error = curl_error($ch);
			$errno = curl_errno($ch);
			$this->close_curl($ch);
			throw new Linkedin_share_exception('HTTP request to LinkedIn failed (cURL '.$errno.'): '.$error, Linkedin_share_exception::TRANSPORT);
		}

		$status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		$this->close_curl($ch);

		return array('status' => $status, 'headers' => $response_headers, 'body' => (string) $response_body);
	}

	protected function close_curl($ch)
	{
		// curl_close() is a no-op since PHP 8.0 and deprecated in 8.5.
		if (PHP_VERSION_ID < 80000)
		{
			curl_close($ch);
		}
	}
}
