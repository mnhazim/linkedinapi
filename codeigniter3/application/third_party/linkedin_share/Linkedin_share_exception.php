<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * The single exception type thrown by Linkedin_share and Social_card.
 *
 * Use getErrorType() to decide what to show the user:
 *   config        - library misconfigured (missing client_id, no session, bad option)
 *   state         - OAuth state missing/mismatch (expired session, CSRF, replayed callback)
 *   oauth_denied  - user pressed Cancel on LinkedIn, or LinkedIn returned ?error=
 *   transport     - cURL failure (DNS, TLS, timeout)
 *   http          - LinkedIn returned an unexpected HTTP status (see getHttpStatus/getResponseBody)
 *   response      - 2xx but body/headers not what we expected (bad JSON, missing field)
 *   input         - invalid argument (empty text, text too long, unreadable image, invalid UTF-8)
 *   card          - social card generation failed (GD/FreeType missing, font missing, not writable)
 *
 * Messages never contain the client secret or access token, so they are safe to log.
 * They may contain LinkedIn's error message, so don't echo them to end users verbatim
 * unless you are fine with that.
 */
class Linkedin_share_exception extends RuntimeException
{
	const CONFIG       = 'config';
	const STATE        = 'state';
	const OAUTH_DENIED = 'oauth_denied';
	const TRANSPORT    = 'transport';
	const HTTP         = 'http';
	const RESPONSE     = 'response';
	const INPUT        = 'input';
	const CARD         = 'card';

	/** @var string */
	protected $error_type;

	/** @var int|null */
	protected $http_status;

	/** @var string|null */
	protected $response_body;

	/**
	 * @param string      $message
	 * @param string      $error_type    One of the class constants
	 * @param int|null    $http_status
	 * @param string|null $response_body Raw response body (LinkedIn API errors only)
	 * @param Exception|null $previous
	 */
	public function __construct($message, $error_type, $http_status = NULL, $response_body = NULL, $previous = NULL)
	{
		parent::__construct($message, (int) $http_status, $previous);
		$this->error_type    = $error_type;
		$this->http_status   = $http_status;
		$this->response_body = $response_body;
	}

	/** @return string */
	public function getErrorType()
	{
		return $this->error_type;
	}

	/** @return int|null */
	public function getHttpStatus()
	{
		return $this->http_status;
	}

	/** @return string|null */
	public function getResponseBody()
	{
		return $this->response_body;
	}

	/** @return array|null Decoded JSON error body from LinkedIn, if any. */
	public function getResponseJson()
	{
		if ($this->response_body === NULL || $this->response_body === '')
		{
			return NULL;
		}
		$data = json_decode($this->response_body, TRUE);
		return is_array($data) ? $data : NULL;
	}
}
