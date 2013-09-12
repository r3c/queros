<?php

/*
** Query Routing System
*/

namespace Queros;

define ('QUEROS',	'1.0.2.0');

class	Exception extends \Exception
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

	private	$error;
	private	$reply;

	public function	__construct ($error, $reply = null)
	{
		if ($reply !== null)
			parent::__construct ($reply->get_contents ());

		$this->error = $error;
		$this->reply = $reply;
	}

	public function	get_error ()
	{
		return $this->error;
	}

	public function	get_reply ()
	{
		return $this->reply;
	}

	public function	send ()
	{
		if (isset (self::$messages[$this->error]))
			header ('HTTP/1.1 ' . $this->error . ' ' . self::$messages[$this->error], false, $this->error);
		else
			header ('HTTP/1.1 ' . $this->error, false, $this->error);

		if ($this->reply !== null)
			$this->reply->send ();
	}

	public function	set_reply ($reply)
	{
		return $this->reply = $reply;
	}
}

abstract class	Reply
{
	public abstract function	get_contents ();

	public abstract function	get_headers ();

	public abstract function	send ();
}

class	ContentsReply extends Reply
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

class	RedirectReply extends Reply
{
	const	PERMANENT = 301;
	const	TEMPORARY = 307;

	private $code;
	private	$url;

	public function	__construct ($url, $code = 302)
	{
		$this->code = $code;
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
		header ('Location: ' . $this->url, false, $this->code);
	}
}

class	VoidReply extends Reply
{
	public function	get_contents ()
	{
		return null;
	}

	public function	get_headers ()
	{
		return array ();
	}

	public function	send ()
	{
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
	private $resolvers;
	private $reversers;

	public function	__construct ($source, $cache = null)
	{
		// Build or load resolvers and reversers from routes or cache
		if ($cache !== null && file_exists ($cache))
			require ($cache);
		else
		{
			// Load routes and convert to resolvers and reversers
			if (is_string ($source))
				require ($source);
			else
				$routes = $source;

			if (!is_array ($routes))
				throw new \Exception ('unable to load routes from source');

			$resolvers = array ();
			$reversers = array ();

			self::convert ($resolvers, $reversers, $routes, '', array ());

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
				return new VoidReply ();
			}
		);

		// Initialize members
		$this->resolvers = $resolvers;
		$this->reversers = $reversers;		
	}

	public function	call ($path, $params = array ())
	{
		$reply = $this->resolve ($this->resolvers, $path, array_merge ($_GET, $params));

		if ($reply !== null)
			return $reply;

		throw new Exception (500, new ContentsReply ('Handler for path "' . $path . '" did not return a valid reply'));
	}

	public function	url ($name, $params = array (), $anchor = null)
	{
		if (!isset ($this->reversers[$name]))
			throw new \Exception ('can\'t create link to unknown route "' . $name . '"');

		$first = false;
		$keys = array ();
		$url = self::reverse ($this->reversers[$name], $params, $keys);

		foreach ($params as $key => $value)
		{
			if (!isset ($keys[$key]))
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
		}

		if ($anchor !== null)
			$url .= '#' . rawurlencode ($anchor);

		return $url;
	}

	private static function	convert (&$resolvers, &$reversers, $routes, $prefix, $reverser)
	{
		foreach ($routes as $suffix => $route)
		{
			$name = $prefix . $suffix;
			$params = array ();
			$i = 0;

			$fragments = self::parse ($route[0], $i);
			$pattern = self::generate ($fragments, $params);

			if (is_array ($route[1]))
			{
				$children = array ();

				self::convert ($children, $reversers, $route[1], $name, array_merge ($reverser, $fragments));

				$resolvers[] = array (self::DELIMITER . '^' . $pattern . self::DELIMITER, $params, self::RESOLVE_NODE, $children);
			}
			else
			{
				$callback = explode (':', $route[1]);

				$resolvers[] = array (self::DELIMITER . '^' . $pattern . '$' . self::DELIMITER, $params, self::RESOLVE_LEAF, $callback[0], array_slice ($callback, 1));
				$reversers[$name] = array_merge ($reverser, $fragments);
			}
		}
	}

	private static function	export ($input)
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

	private static function	generate ($fragments, &$params)
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
					$pattern .= '(?:' . self::generate ($fragment[1], $params) . ')?';

					break;

				case self::PARAM:
					$params[$fragment[1]] = count ($params) + 1;
					$pattern .= '(' . $fragment[2] . ')';

					break;
			}
		}

		return $pattern;
	}

	private static function	parse ($string, &$i)
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

	private function	resolve ($resolvers, $path, $params)
	{
		foreach ($resolvers as $resolver)
		{
			if (preg_match ($resolver[0], $path, $match) === 1)
			{
				foreach ($resolver[1] as $key => $value)
					$params[$key] = isset ($match[$value]) ? $match[$value] : null;

				switch ($resolver[2])
				{
					case self::RESOLVE_LEAF:
						$name = $resolver[3];

						if (!isset ($this->callbacks[$name]))
							throw new Exception (500, new ContentsReply ('Unknown handler type "' . $name . '"'));

						$arguments = array_merge (array (array ($this, $params)), $resolver[4]);
						$callback = $this->callbacks[$name];

						return call_user_func_array ($callback, $arguments);

					case self::RESOLVE_NODE:
						return $this->resolve ($resolver[3], substr ($path, strlen ($match[0])), $params);
				}

				throw new Exception (500, new ContentsReply ('Unknown configuration error'));
			}
		}

		throw new Exception (404, new ContentsReply ('No page found for path "' . $path . '"'));
	}

	private static function	reverse ($fragments, $params, &$keys)
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
					$url .= self::reverse ($fragment[1], $params, $keys);

					break;

				case self::PARAM:
					if (isset ($params[$fragment[1]]))
						$url .= $params[$fragment[1]];
					else if ($fragment[3] !== null)
						$url .= $fragment[3];
					else
						$set = false;

					$keys[$fragment[1]] = true;

					break;
			}
		}

		if ($set)
			return $url;

		return '';
	}
}

?>
