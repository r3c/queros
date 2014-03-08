<?php

/*
** Query Routing System
*/

namespace Queros;

define ('QUEROS',	'1.0.3.0');

abstract class	Answer
{
	public abstract function	get_contents ();

	public abstract function	get_headers ();

	public abstract function	send ();
}

class	Error extends \Exception
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

	private	$answer;
	private	$status;

	public function	__construct ($status, $answer = null)
	{
		if ($answer !== null)
			parent::__construct ($answer->get_contents ());

		$this->answer = $answer;
		$this->status = $status;
	}

	public function	get_answer ()
	{
		return $this->answer;
	}

	public function	get_status ()
	{
		return $this->status;
	}

	public function	send ()
	{
		if (isset (self::$messages[$this->status]))
			header ('HTTP/1.1 ' . $this->status . ' ' . self::$messages[$this->status], false, $this->status);
		else
			header ('HTTP/1.1 ' . $this->status, false, $this->status);

		if ($this->answer !== null)
			$this->answer->send ();
	}

	public function	set_answer ($answer)
	{
		return $this->answer = $answer;
	}
}

class	Invoke
{
	private $arguments;
	private $callback;
	private $parameters;
	private $router;

	public function __construct ($router, $callback, $arguments, $parameters)
	{
		$this->arguments = $arguments;
		$this->callback = $callback;
		$this->parameters = $parameters;
		$this->router = $router;
	}

	public function call ($parameters = array (), $internals = array ())
	{
		$parameters = array_merge ($parameters, $this->parameters);
		$through = array_merge (array ($this->router, $parameters), $internals);

		$reply = call_user_func_array ($this->callback, array_merge (array ($through), $this->arguments));

		if ($reply !== null)
			return $reply;

		throw new Error (500, new Reply ('Handler for path "' . $path . '" did not return a valid reply'));
	}

	public function get ($key, $value = null)
	{
		return isset ($this->parameters[$key]) ? $this->parameters[$key] : $value;
	}
}

class	Redirect extends Answer
{
	const PERMANENT	= 301;
	const FOUND		= 302;
	const PROXY		= 305;
	const TEMPORARY	= 307;

	private $status;
	private	$url;

	public function	__construct ($url, $status = 302)
	{
		$this->status = $status;
		$this->url = $url;
	}

	public function	get_contents ()
	{
		return null;
	}

	public function	get_headers ()
	{
		return array ('Location' => $this->url);
	}

	public function	send ()
	{
		header ('Location: ' . $this->url, false, $this->status);
	}
}

class	Reply extends Answer
{
	private	$contents;
	private $headers;

	public function	__construct ($contents = null, $headers = array ())
	{
		$this->contents = $contents;
		$this->headers = $headers;
	}

	public function	get_contents ()
	{
		return $this->contents;
	}

	public function	get_headers ()
	{
		return $this->headers;
	}

	public function	send ()
	{
		foreach ($this->headers as $name => $value)
			header ($name . ': ' . $value);

		if ($this->contents !== null)
			echo $this->contents;
	}
}

class	Router
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
	private $parameters;
	private $resolvers;
	private $reversers;

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
		$this->parameters = array ();
		$this->resolvers = $resolvers;
		$this->reversers = $reversers;		
	}

	public function call ($path, $parameters = array (), $internals = array ())
	{
		$this->find ($path)->call ($parameters, $internals);
	}

	public function find ($path)
	{
		return $this->resolve ($this->resolvers, $path, array ());
	}

	public function stick ($parameters)
	{
		$this->parameters = $parameters;
	}

	public function url ($name, $parameters = array (), $anchor = null)
	{
		if (!isset ($this->reversers[$name]))
			throw new \Exception ('can\'t create URL to unknown route "' . $name . '"');

		$remains = array_merge ($this->parameters, $parameters);
		$url = self::reverse ($this->reversers[$name], $remains);

		$first = true;

		foreach ($remains as $key => $value)
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
			$name = $parent . $child;
			$parameters = array ();
			$i = 0;

			// Node has children, run recursive conversion
			if (is_array ($route[1]))
			{
				$children = array ();
				$fragments = self::parse ($route[0], $i);
				$pattern = self::generate ($fragments, $parameters);

				self::convert ($children, $reversers, $route[1], $name, $suffix, array_merge ($reverser, $fragments));

				$resolvers[] = array (self::DELIMITER . '^' . $pattern . self::DELIMITER, $parameters, self::RESOLVE_NODE, $children);
			}

			// Leaf
			else
			{
				$callback = explode (':', $route[1]);
				$fragments = self::parse ($route[0] . $suffix, $i);
				$pattern = self::generate ($fragments, $parameters);

				$resolvers[] = array (self::DELIMITER . '^' . $pattern . '$' . self::DELIMITER, $parameters, self::RESOLVE_LEAF, $callback[0], array_slice ($callback, 1));
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

	private static function generate ($fragments, &$parameters)
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
					$pattern .= '(?:' . self::generate ($fragment[1], $parameters) . ')?';

					break;

				case self::PARAM:
					$parameters[$fragment[1]] = count ($parameters) + 1;
					$pattern .= '(' . $fragment[2] . ')';

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

	private function resolve ($resolvers, $path, $parameters)
	{
		foreach ($resolvers as $resolver)
		{
			if (preg_match ($resolver[0], $path, $match, PREG_OFFSET_CAPTURE) === 1)
			{
				foreach ($resolver[1] as $key => $value)
					$parameters[$key] = isset ($match[$value]) && $match[$value][1] !== -1 ? $match[$value][0] : null;

				switch ($resolver[2])
				{
					case self::RESOLVE_LEAF:
						$name = $resolver[3];

						if (!isset ($this->callbacks[$name]))
							throw new Error (500, new Reply ('Unknown handler type "' . $name . '"'));

						return new Invoke ($this, $this->callbacks[$name], $resolver[4], $parameters);

					case self::RESOLVE_NODE:
						return $this->resolve ($resolver[3], substr ($path, strlen ($match[0][0])), $parameters);
				}

				throw new Error (500, new Reply ('Unknown configuration error'));
			}
		}

		throw new Error (404, new Reply ('No page found for path "' . $path . '"'));
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
