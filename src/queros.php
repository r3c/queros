<?php

/*
** Query Routing System
*/

namespace Queros;

define ('QUEROS', '1.0.3.0');

abstract class Answer
{
	public $contents;
	public $headers;

	public function __construct ($contents = null, $headers = array ())
	{
		$this->contents = $contents;
		$this->headers = $headers;
	}

	public abstract function send ();
}

class Error extends \Exception
{
	private static	$messages = array
	(
		400	=> 'Bad Request',
		401	=> 'Unauthorized',
		403	=> 'Forbidden',
		404	=> 'Not Found',
		405	=> 'Method Not Allowed',
		406	=> 'Not Acceptable',
		410	=> 'Gone',
		500	=> 'Internal Server Error',
		501	=> 'Not Implemented'
	);

	public $answer;
	public $code;

	public function	__construct ($code, $answer = null)
	{
		if ($answer !== null && $answer->contents !== null)
			parent::__construct ($answer->contents);

		$this->answer = $answer;
		$this->code = $code;
	}

	public function	send ()
	{
		if (isset (self::$messages[$this->code]))
			header ('HTTP/1.1 ' . $this->code . ' ' . self::$messages[$this->code], false, $this->code);
		else
			header ('HTTP/1.1 ' . $this->code, false, $this->code);

		if ($this->answer !== null)
			$this->answer->send ();
	}
}

class Query
{
	public $parameters;

	private $callback;
	private $options;

	public function __construct ($callback, $options, $parameters)
	{
		$this->callback = $callback;
		$this->options = $options;
		$this->parameters = $parameters;
	}

	public function call ()
	{
		$relay = array_merge (array ($this), func_get_args ());
		$reply = call_user_func_array ($this->callback, array_merge (array ($relay), $this->options));

		if ($reply !== null)
			return $reply;

		throw new Error (500, new Reply ('Handler did not return a valid reply'));
	}

	public function get_or_default ($key, $value = null)
	{
		return isset ($this->parameters[$key]) ? $this->parameters[$key] : $value;
	}

	public function get_or_fail ($key, $code = 400)
	{
		if (!isset ($this->parameters[$key]))
			throw new Error ($code, new Reply ('Missing value for parameter "' . $key . '"'));

		return $this->parameters[$key];
	}
}

class Redirect extends Answer
{
	const PERMANENT	= 301;
	const FOUND		= 302;
	const PROXY		= 305;
	const TEMPORARY	= 307;

	private $code;
	private	$url;

	public function	__construct ($url, $code = Redirect::FOUND)
	{
		parent::__construct (null, array ('Location' => $this->url));

		$this->code = $code;
		$this->url = $url;
	}

	public function	send ()
	{
		header ('Location: ' . $this->url, false, $this->code);
	}
}

class Reply extends Answer
{
	public function	send ()
	{
		foreach ($this->headers as $name => $value)
			header ($name . ': ' . $value);

		if ($this->contents !== null)
			echo $this->contents;
	}
}

class Router
{
	const	CONSTANT = 0;
	const	DELIMITER = '/';
	const	ESCAPE = '%';
	const	OPTION = 1;
	const	OPTION_BEGIN = '(';
	const	OPTION_END = ')';
	const	PARAM = 2;
	const	PARAM_ARGUMENT = ':';
	const	PARAM_BEGIN = '<';
	const	PARAM_END = '>';
	const	RESOLVE_LEAF = 0;
	const	RESOLVE_NODE = 1;

	private $callbacks;
	private $resolvers;
	private $reversers;
	private $sticky;

	public function __construct ($source, $cache = null)
	{
		// Build or load resolvers and reversers from routes or cache
		if ($cache === null || (@include $cache) === false)
		{
			// Load routes and convert to resolvers and reversers
			if (is_string ($source))
				require ($source);
			else
				$queros = $source;

			if (!is_array ($queros))
				throw new \Exception ('unable to load Queros configuration from source');

			$resolvers = array ();
			$reversers = array ();
			$routes = isset ($queros['routes']) ? (array)$queros['routes'] : array ();
			$suffix = isset ($queros['suffix']) ? (string)$queros['suffix'] : '';

			self::convert ($resolvers, $reversers, $routes, '', $suffix, array ());

			// Save to cache
			if ($cache !== null)
			{
				$contents = '<?php ' .
					'$resolvers = ' . self::export ($resolvers) . '; ' .
					'$reversers = ' . self::export ($reversers) . '; ' .
				'?>';

				if (file_put_contents ($cache, $contents, LOCK_EX) === false)
					throw new \Exception ('unable to create cache');
			}
		}

		// Check variable consistency
		if (!isset ($resolvers))
			throw new \Exception ('missing $resolvers variable in cache');

		if (!isset ($reversers))
			throw new \Exception ('missing $reversers variable in cache');

		// Assign default callbacks
		$this->callbacks = array
		(
			'file'	=> function ($arguments, $path, $function)
			{
				require ($path);

				return call_user_func_array ($function, $arguments);
			},
			'func'	=> function ($arguments, $function)
			{
				return call_user_func_array ($function, $arguments);
			},
			'void'	=> function ()
			{
				return new Reply ();
			}
		);

		// Initialize members
		$this->resolvers = $resolvers;
		$this->reversers = $reversers;		
		$this->sticky = array ();
	}

	public function call ($route, $parameters = array (), $internals = array ())
	{
		$query = $this->find ($route, $parameters);

		return call_user_func_array (array ($query, 'call'), $internals);
	}

	public function find ($route, $parameters = array ())
	{
		return $this->resolve ($this->resolvers, $route, $parameters);
	}

	public function stick ($sticky)
	{
		$this->sticky = $sticky;
	}

	public function url ($name, $parameters = array (), $anchor = null)
	{
		if (!isset ($this->reversers[$name]))
			throw new \Exception ('can\'t create URL to unknown route "' . $name . '"');

		$first = true;
		$inject = array_merge ($this->sticky, $parameters);
		$url = self::reverse ($this->reversers[$name], $inject);

		foreach ($inject as $key => $value)
		{
			if ($first)
			{
				$first = false;
				$url .= '?';
			}
			else
				$url .= '&';

			$url .= rawurlencode ($key) . '=' . rawurlencode ($value);
		}

		if ($anchor !== null)
			$url .= '#' . rawurlencode ($anchor);

		return $url;
	}

	private static function convert (&$resolvers, &$reversers, $routes, $parent, $suffix, $reverser)
	{
		foreach ($routes as $child => $route)
		{
			$groups = array ();
			$name = $parent . $child;
			$i = 0;

			// Node has children, run recursive conversion
			if (is_array ($route[1]))
			{
				$children = array ();
				$fragments = self::parse ($route[0], $i);
				$pattern = self::generate ($fragments, $groups);

				self::convert ($children, $reversers, $route[1], $name, $suffix, array_merge ($reverser, $fragments));

				$resolvers[] = array (self::DELIMITER . '^' . $pattern . self::DELIMITER, $groups, self::RESOLVE_NODE, $children);
			}

			// Leaf
			else
			{
				$callback = explode (':', $route[1]);
				$fragments = self::parse ($route[0] . $suffix, $i);
				$pattern = self::generate ($fragments, $groups);

				$resolvers[] = array (self::DELIMITER . '^' . $pattern . '$' . self::DELIMITER, $groups, self::RESOLVE_LEAF, $callback[0], array_slice ($callback, 1));
				$reversers[$name] = array_merge ($reverser, $fragments);
			}
		}
	}

	private static function export ($input)
	{
		if (is_array ($input))
		{
			$out = '';

			if (array_reduce (array_keys ($input), function (&$result, $item) { return $result === $item ? $item + 1 : null; }, 0) !== count ($input))
			{
				foreach ($input as $key => $value)
					$out .= ($out !== '' ? ',' : '') . self::export ($key) . '=>' . self::export ($value);
			}
			else
			{
				foreach ($input as $value)
					$out .= ($out !== '' ? ',' : '') . self::export ($value);
			}

			return 'array(' . $out . ')';
		}

		return var_export ($input, true);
	}

	private static function generate ($fragments, &$groups)
	{
		$pattern = '';

		foreach ($fragments as $fragment)
		{
			switch ($fragment[0])
			{
				case self::CONSTANT:
					$pattern .= preg_quote ($fragment[1], self::DELIMITER);

					break;

				case self::OPTION:
					$pattern .= '(?:' . self::generate ($fragment[1], $groups) . ')?';

					break;

				case self::PARAM:
					$pattern .= '(' . $fragment[2] . ')';
					$groups[] = $fragment[1];

					break;
			}
		}

		return $pattern;
	}

	private static function parse ($string, &$i)
	{
		$fragments = array ();
		$length = strlen ($string);

		while ($i < $length && $string[$i] !== self::OPTION_END)
		{
			switch ($string[$i])
			{
				case self::OPTION_BEGIN:
					++$i;

					$sequence = self::parse ($string, $i);

					if ($i >= $length || $string[$i] !== self::OPTION_END)
						throw new \Exception ('unfinished optional sub-sequence');

					$fragments[] = array (self::OPTION, $sequence);

					++$i;

					break;

				case self::PARAM_BEGIN:
					$key = '';

					for (++$i; $i < $length && $string[$i] !== self::PARAM_ARGUMENT && $string[$i] !== self::PARAM_END; ++$i)
					{
						if ($string[$i] === self::ESCAPE && $i + 1 < $length)
							++$i;

						$key .= $string[$i];
					}

					if ($i < $length && $string[$i] === self::PARAM_ARGUMENT)
					{
						$pattern = '';

						for (++$i; $i < $length && $string[$i] !== self::PARAM_ARGUMENT && $string[$i] !== self::PARAM_END; ++$i)
						{
							if ($string[$i] === self::ESCAPE && $i + 1 < $length)
								++$i;

							$pattern .= $string[$i];
						}
					}
					else
						$pattern = '.+';

					if ($i < $length && $string[$i] === self::PARAM_ARGUMENT)
					{
						$default = '';

						for (++$i; $i < $length && $string[$i] !== self::PARAM_ARGUMENT && $string[$i] !== self::PARAM_END; ++$i)
						{
							if ($string[$i] === self::ESCAPE && $i + 1 < $length)
								++$i;

							$default .= $string[$i];
						}
					}
					else
						$default = null;

					if ($i >= $length || $string[$i] !== self::PARAM_END)
						throw new \Exception ('unfinished parameter name');

					$fragments[] = array (self::PARAM, $key, $pattern, $default);

					++$i;

					break;

				default:
					$buffer = '';

					for (; $i < $length && $string[$i] !== self::OPTION_BEGIN && $string[$i] !== self::OPTION_END && $string[$i] !== self::PARAM_BEGIN; ++$i)
					{
						if ($string[$i] === self::ESCAPE && $i + 1 < $length)
							++$i;

						$buffer .= $string[$i];
					}

					$fragments[] = array (self::CONSTANT, $buffer);

					break;
			}
		}

		return $fragments;
	}

	private function resolve ($resolvers, $route, $parameters)
	{
		foreach ($resolvers as $resolver)
		{
			if (preg_match ($resolver[0], $route, $match, PREG_OFFSET_CAPTURE) === 1)
			{
				foreach ($resolver[1] as $index => $key)
					$parameters[$key] = isset ($match[$index + 1]) && $match[$index + 1][1] !== -1 ? $match[$index + 1][0] : null;

				switch ($resolver[2])
				{
					case self::RESOLVE_LEAF:
						$type = $resolver[3];

						if (!isset ($this->callbacks[$type]))
							throw new Error (500, new Reply ('Unknown handler type "' . $type . '"'));

						return new Query ($this->callbacks[$type], $resolver[4], $parameters);

					case self::RESOLVE_NODE:
						return $this->resolve ($resolver[3], substr ($route, strlen ($match[0][0])), $parameters);
				}

				throw new Error (500, new Reply ('Unknown configuration error'));
			}
		}

		throw new Error (404, new Reply ('No page found for route "' . $route . '"'));
	}

	private static function reverse ($fragments, &$parameters)
	{
		$set = true;
		$url = '';

		foreach ($fragments as $fragment)
		{
			switch ($fragment[0])
			{
				case self::CONSTANT:
					$url .= $fragment[1];

					break;

				case self::OPTION:
					$url .= self::reverse ($fragment[1], $parameters);

					break;

				case self::PARAM:
					if (isset ($parameters[$fragment[1]]))
						$url .= $parameters[$fragment[1]];
					else if ($fragment[3] !== null)
						$url .= $fragment[3];
					else
						$set = false;

					unset ($parameters[$fragment[1]]);

					break;
			}
		}

		if ($set)
			return $url;

		return '';
	}
}

?>
