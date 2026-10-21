<?php
defined('BASEPATH') OR exit('No direct script access allowed');

require_once APPPATH.'third_party/linkedin_share/Linkedin_share_exception.php';

/**
 * Social_card - optional CI3 library: generates an "I'M ATTENDING" PNG (1200x627) with GD.
 * Replace the drawing code with your own conference design if you like.
 *
 * Requires the gd extension with FreeType (imagettftext).
 *
 * $this->load->library('social_card');
 * $this->social_card->generate(
 *     array('name' => 'Aina', 'title' => 'Engineer', 'company' => 'Acme'),
 *     array('name' => 'MyConf 2026', 'date' => '12-13 Dec 2026', 'venue' => 'KLCC'),
 *     APPPATH.'cache/cards/abc.png'
 * );
 */
class Social_card
{
	const W = 1200;
	const H = 627;

	/** @var string Directory with DejaVuSans.ttf and DejaVuSans-Bold.ttf */
	protected $font_dir;

	/**
	 * @param array $params font_dir (optional)
	 */
	public function __construct($params = array())
	{
		$this->font_dir = (is_array($params) && ! empty($params['font_dir']))
			? rtrim($params['font_dir'], '/\\').'/'
			: APPPATH.'third_party/linkedin_share/fonts/';
	}

	/**
	 * Draw the card and write it as PNG.
	 *
	 * @param array  $attendee name (required), title, company
	 * @param array  $event    name, date, venue
	 * @param string $output_path File to write; its directory is created if missing
	 * @return string $output_path
	 */
	public function generate(array $attendee, array $event, $output_path)
	{
		if ( ! function_exists('imagettftext'))
		{
			throw new Linkedin_share_exception('GD with FreeType (imagettftext) is required for Social_card.', Linkedin_share_exception::CARD);
		}

		$font_regular = $this->font_dir.'DejaVuSans.ttf';
		$font_bold    = $this->font_dir.'DejaVuSans-Bold.ttf';
		foreach (array($font_regular, $font_bold) as $font)
		{
			if ( ! is_file($font))
			{
				throw new Linkedin_share_exception('Font not found: '.$font, Linkedin_share_exception::CARD);
			}
		}

		$name    = $this->str($attendee, 'name');
		$title   = $this->str($attendee, 'title');
		$company = $this->str($attendee, 'company');
		if ($name === '')
		{
			throw new Linkedin_share_exception('Attendee name is required for the social card.', Linkedin_share_exception::INPUT);
		}

		$dir = dirname($output_path);
		if ( ! is_dir($dir) && ! @mkdir($dir, 0775, TRUE) && ! is_dir($dir))
		{
			throw new Linkedin_share_exception('Cannot create card directory: '.$dir, Linkedin_share_exception::CARD);
		}

		$img = imagecreatetruecolor(self::W, self::H);

		// Background gradient (navy -> purple)
		for ($y = 0; $y < self::H; $y++)
		{
			$t = $y / self::H;
			$c = imagecolorallocate($img, (int) (15 + 45 * $t), (int) (23 + 10 * $t), (int) (66 + 60 * $t));
			imageline($img, 0, $y, self::W, $y, $c);
		}

		$white  = imagecolorallocate($img, 255, 255, 255);
		$muted  = imagecolorallocate($img, 190, 198, 230);
		$accent = imagecolorallocate($img, 255, 196, 0);
		$dark   = imagecolorallocate($img, 20, 20, 40);

		// Decorative circles
		imagefilledellipse($img, self::W - 60, 80, 360, 360, imagecolorallocatealpha($img, 255, 255, 255, 115));
		imagefilledellipse($img, self::W - 180, self::H - 20, 260, 260, imagecolorallocatealpha($img, 255, 196, 0, 112));

		$x = 80;
		$max_width = self::W - 2 * $x;

		// Event name + badge
		$event_name = $this->str($event, 'name');
		imagettftext($img, $this->fit($event_name, $font_bold, 26, 16, $max_width), 0, $x, 110, $white, $font_bold, $event_name);
		imagefilledrectangle($img, $x, 150, $x + 300, 196, $accent);
		imagettftext($img, 18, 0, $x + 22, 182, $dark, $font_bold, "I'M ATTENDING");

		// Attendee name (shrinks when long)
		imagettftext($img, $this->fit($name, $font_bold, 64, 32, $max_width), 0, $x, 300, $white, $font_bold, $name);

		// Title @ company
		$role = trim($title.($company !== '' ? ($title !== '' ? ' @ ' : '').$company : ''));
		if ($role !== '')
		{
			imagettftext($img, $this->fit($role, $font_regular, 28, 18, $max_width), 0, $x, 360, $muted, $font_regular, $role);
		}

		// Divider + date & venue
		imagefilledrectangle($img, $x, 440, $x + 80, 445, $accent);
		$date  = $this->str($event, 'date');
		$venue = $this->str($event, 'venue');
		imagettftext($img, $this->fit($date, $font_bold, 22, 16, $max_width), 0, $x, 500, $white, $font_bold, $date);
		imagettftext($img, $this->fit($venue, $font_regular, 20, 14, $max_width), 0, $x, 540, $muted, $font_regular, $venue);

		$ok = imagepng($img, $output_path);
		if (PHP_VERSION_ID < 80000)
		{
			imagedestroy($img); // no-op since PHP 8.0
		}

		if ( ! $ok)
		{
			throw new Linkedin_share_exception('Could not write PNG: '.$output_path, Linkedin_share_exception::CARD);
		}
		return $output_path;
	}

	protected function str(array $a, $key)
	{
		return isset($a[$key]) ? trim((string) $a[$key]) : '';
	}

	protected function fit($text, $font, $max, $min, $max_width)
	{
		if ($text === '')
		{
			return $max;
		}
		for ($size = $max; $size > $min; $size -= 2)
		{
			$box = imagettfbbox($size, 0, $font, $text);
			if ($box !== FALSE && $box[2] - $box[0] <= $max_width)
			{
				return $size;
			}
		}
		return $min;
	}
}
