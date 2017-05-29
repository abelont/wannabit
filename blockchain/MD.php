<?php
require_once('Parsedown.php');

class MD
{
	private static $instance = null;

	private static function get_instance()
	{
		if (self::$instance == null)
		{
			self::$instance = new MD();
		}
		return self::$instance;
	}

	public static function render($text)
	{
		$md = self::get_instance()->parser->text(trim($text));
		preg_match_all('/&lt;(#[0-9a-fA-F]+|):{1}(#[0-9a-fA-F]+|)&gt;([\s\S]*?)<:>/s', $md, $matches, PREG_SET_ORDER);

		foreach ($matches as $match) 
		{
			$span = "<span style=\"";
			if ($match[1] != '') $span .= "background-color: " . $match[1] . "; ";
			if ($match[2] != '') $span .= "color: " . $match[2] . "; ";
			$span .= "\">";
			$span .= $match[3];
			$span .= "</span>";
			$md = str_replace($match[0], $span, $md);
		}
		return $md;
	}

	public $parser;

	public function __construct()
	{
		$this->parser = new Parsedown();
	}
}

?>
