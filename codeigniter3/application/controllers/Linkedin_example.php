<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Example: registration "thank you" page -> share social card to LinkedIn.
 *
 *   /linkedin_example           card preview + caption form
 *   /linkedin_example/auth      (POST) saves caption, redirects to LinkedIn
 *   /linkedin_example/callback  LinkedIn redirects here; publishes the post
 *   /linkedin_example/card      serves the current session's PNG
 *
 * In your app, take the attendee/event from your DB instead of the demo arrays.
 * Enable $config['csrf_protection'] in config.php: form_open() then adds the token,
 * which stops other sites from POSTing a caption to auth() on a member's behalf.
 */
class Linkedin_example extends CI_Controller
{
	protected $event = array(
		'name'     => 'MyConf 2026',
		'date'     => '12-13 December 2026',
		'venue'    => 'Kuala Lumpur Convention Centre',
		'url'      => 'https://myconf.example.com',
		'hashtags' => array('MyConf2026', 'TechMalaysia'),
	);

	public function __construct()
	{
		parent::__construct();
		$this->load->library(array('session', 'linkedin_share', 'social_card'));
		$this->load->helper(array('url', 'form'));
	}

	public function index()
	{
		$attendee = array('name' => 'Demo Attendee', 'title' => 'Engineer', 'company' => 'Example Sdn Bhd');

		$card = $this->card_path();
		if ($card === NULL)
		{
			$file = bin2hex(random_bytes(8)).'.png';
			$this->social_card->generate($attendee, $this->event, $this->card_dir().$file);
			$this->session->set_userdata('linkedin_card', $file);
		}

		$tags = '#'.implode(' #', $this->event['hashtags']);
		$text = "I'm attending {$this->event['name']} on {$this->event['date']} at {$this->event['venue']}!\n\n"
			."Join me: {$this->event['url']}\n\n{$tags}";

		echo '<h1>Thanks for registering!</h1>'
			.'<img src="'.site_url('linkedin_example/card').'" width="600" alt="Social card">'
			.form_open('linkedin_example/auth')
			.'<p><textarea name="text" rows="7" cols="70" maxlength="3000">'.html_escape($text).'</textarea></p>'
			.'<button type="submit">Share to LinkedIn</button>'
			.form_close();
	}

	public function auth()
	{
		if ($this->input->method() !== 'post' || $this->card_path() === NULL)
		{
			redirect('linkedin_example');
		}

		$this->session->set_userdata('linkedin_text', (string) $this->input->post('text'));
		redirect($this->linkedin_share->get_auth_url()); // state is stored in the CI session
	}

	public function callback()
	{
		try
		{
			$token = $this->linkedin_share->handle_callback(); // verifies + consumes state
			$card  = $this->card_path();
			if ($card === NULL)
			{
				throw new Linkedin_share_exception('Your session expired, please register again.', Linkedin_share_exception::STATE);
			}

			$result = $this->linkedin_share->share_image(
				$token['access_token'],
				(string) $this->session->userdata('linkedin_text'),
				$card,
				array('alt_text' => 'Attending '.$this->event['name'] /*, 'visibility' => 'CONNECTIONS' */)
			);
			$this->session->unset_userdata('linkedin_text');

			echo '<h1>Shared to LinkedIn</h1>'
				.'<p><a href="'.html_escape($result['post_url']).'" target="_blank" rel="noopener">View your post</a></p>';
		}
		catch (Linkedin_share_exception $e)
		{
			log_message('error', '[linkedin] '.$e->getErrorType().': '.$e->getMessage());

			$message = $e->getErrorType() === Linkedin_share_exception::OAUTH_DENIED
				? 'You cancelled the LinkedIn permission request.'
				: ($e->getErrorType() === Linkedin_share_exception::STATE
					? $e->getMessage()
					: 'Sharing to LinkedIn failed. Please try again.');

			$this->output->set_status_header(400);
			echo '<h1>Could not share</h1><p>'.html_escape($message).'</p>'
				.'<p><a href="'.site_url('linkedin_example').'">Try again</a></p>';
		}
	}

	public function card()
	{
		$card = $this->card_path();
		if ($card === NULL)
		{
			show_404();
		}
		$this->output->set_content_type('png')->set_output(file_get_contents($card));
	}

	protected function card_dir()
	{
		return APPPATH.'cache/linkedin_cards/';
	}

	/** @return string|null Absolute path of this session's card, if it exists. */
	protected function card_path()
	{
		$file = $this->session->userdata('linkedin_card');
		if ( ! is_string($file) || ! preg_match('/^[a-f0-9]{16}\.png$/', $file))
		{
			return NULL;
		}
		$path = $this->card_dir().$file;
		return is_file($path) ? $path : NULL;
	}
}
