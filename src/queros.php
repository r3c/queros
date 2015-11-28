<?php

/*
** Query Routing System
*/

namespace Queros;

define ('QUEROS', '1.0.3.0');

/*
** Request error.
*/
class Error extends \Exception
{
	public function	__construct ($reply, $message = null)
	{
		if ($message !== null)
			parent::__construct ($message);

		$this->reply = $reply;
	}
}

/*
** Single request object.
*/
class Query
{
	public $method;
	public $parameters;
	public $router;

	private $callback;
	private $options;

	public function __construct ($router, $callback, $options, $method, $parameters)
	{
		$this->callback = $callback;
		$this->method = $method;
		$this->options = $options;
		$this->parameters = $parameters;
		$this->router = $router;
	}

	public function invoke ()
	{
		// Invoke callback ($query, $arguments, $option1, $option2, ...)
		$reply = call_user_func_array ($this->callback, array_merge
		(
			array ($this),
			array (func_get_args ()),
			$this->options
		));

		if ($reply !== null)
			return $reply;

		return Reply::code (200);
	}

	public function get_or_default ($key, $value = null)
	{
		return isset ($this->parameters[$key]) ? $this->parameters[$key] : $value;
	}

	public function get_or_fail ($key, $status = 400)
	{
		if (!isset ($this->parameters[$key]))
			throw new Error (Reply::code ($status), 'Missing value for parameter "' . $key . '"');

		return $this->parameters[$key];
	}
}

/*
** Regular answer.
*/
class Reply
{
	const REDIRECT_PERMANENT	= 301;
	const REDIRECT_FOUND		= 302;
	const REDIRECT_PROXY		= 305;
	const REDIRECT_TEMPORARY	= 307;

	private static $messages = array
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

	public static function code ($status, $contents = null)
	{
		return new Reply ($status, null, $contents);
	}

	public static function ok ($contents)
	{
		return new Reply (null, null, $contents);
	}

	public static function to ($url, $status = self::REDIRECT_FOUND)
	{
		return new Reply ($status, array ('Location' => $url), null);
	}

	public function __construct ($status, $headers, $contents)
	{
		$this->contents = $contents;
		$this->headers = $headers;
		$this->status = $status !== null ? (int)$status : null;
	}

	public function	send ()
	{
		if ($this->status !== null)
		{
			if (isset (self::$messages[$this->status]))
				header ('HTTP/1.1 ' . $this->status . ' ' . self::$messages[$this->status], true, $this->status);
			else
				header ('HTTP/1.1 ' . $this->status, true, $this->status);
		}

		if ($this->headers !== null)
		{
			foreach ($this->headers as $name => $value)
				header ($name . ': ' . $value);
		}

		if ($this->contents !== null)
			echo $this->contents;
	}
}

/*
** Requests router.
*/
class Router
{
	const BRANCH_APPEND = '+';
	const BRANCH_IGNORE = '!';
	const BRANCH_RESET = '=';
	const CONSTANT = 0;
	const DELIMITER = '/';
	const ESCAPE = '%';
	const OPTION = 1;
	const OPTION_BEGIN = '(';
	const OPTION_END = ')';
	const PARAM = 2;
	const PARAM_ARGUMENT = ':';
	const PARAM_BEGIN = '<';
	const PARAM_END = '>';
	const PREFIX = '!prefix';
	const RESOLVE_LEAF = 0;
	const RESOLVE_NODE = 1;
	const SUFFIX = '!suffix';

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
			if (is_callable ($source))
				$routes = $sources ();
			else if (is_string ($source))
				require ($source);
			else
				$routes = $source;

			if (!is_array ($routes))
				throw new \Exception ('unable to load routes configuration from source');

			list ($resolvers, $reversers) = self::convert ($routes, '', '');

			// Save to cache
			if ($cache !== null)
			{
				$contents =
					'<?php ' .
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
			'call'	=> function ($query, $arguments, $function)
			{
				return call_user_func_array ($function, array_merge (array ($query), $arguments));
			},
			'code'	=> function ($query, $arguments, $status, $contents = null)
			{
				return Reply::code ($status, $contents);
			},
			'file'	=> function ($query, $arguments, $path, $function)
			{
				require ($path);

				return call_user_func_array ($function, array_merge (array ($query), $arguments));
			},
			'void'	=> function ()
			{
				return Reply::ok (null);
			}
		);

		// Initialize members
		$this->resolvers = $resolvers;
		$this->reversers = $reversers;
		$this->sticky = array ();
	}

	public function invoke ($method, $path, $parameters = array (), $internals = array ())
	{
		$query = $this->match ($method, $path, $parameters);

		if ($query === null)
			return Reply::code (404);

		return call_user_func_array (array ($query, 'invoke'), $internals);
	}

	public function match ($method, $path, $parameters = array ())
	{
		return $this->resolve ($this->resolvers, strtoupper ($method), $path, $parameters);
	}

	public function register ($type, $callback)
	{
		$this->callbacks[$type] = $callback;
	}

	public function stick ($sticky)
	{
		$this->sticky = $sticky;
	}

	public function url ($name, $parameters = array (), $anchor = null)
	{
		if (!isset ($this->reversers[$name]))
			throw new \Exception ('can\'t build URL to unknown route "' . $name . '"');

		$first = true;
		$inject = array_merge ($this->sticky, $parameters);
		$url = self::reverse ($this->reversers[$name], true, $inject);

		if ($url === null)
			throw new \Exception ('can\'t build URL to incomplete route "' . $name . '"');

		foreach ($inject as $key => $value)
		{
			if ($value === null)
				continue;

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

	/*
	** Convert input routes into resolvers (used for path and method to route
	** resolution) and reversers (used for route to URL construction).
	*/
	private static function convert ($routes, $parent, $suffix)
	{
		if (isset ($routes[self::PREFIX]))
		{
			$prefix = $routes[self::PREFIX];

			unset ($routes[self::PREFIX]);
		}
		else
			$prefix = '';

		if (isset ($routes[self::SUFFIX]))
		{
			$suffix = $routes[self::SUFFIX] . $suffix;

			unset ($routes[self::SUFFIX]);
		}

		$resolvers = array ();
		$reversers = array ();

		foreach ($routes as $branch => $route)
		{
			$i = 0;

			// Build route name from current branch
			switch (strlen ($branch) > 0 ? $branch[0] : '')
			{
				case self::BRANCH_APPEND:
					$name = $parent . substr ($branch, 1);

					break;

				case self::BRANCH_IGNORE:
					$name = $parent;

					break;

				case self::BRANCH_RESET:
					$name = substr ($branch, 1);

					break;

				default:
					$name = $parent . $branch;

					break;
			}

			// Node has children, run recursive conversion
			if (count ($route) === 2 && is_string ($route[0]) && is_array ($route[1]))
			{
				$fragments = self::parse ($prefix . $route[0], $i);

				list ($child_resolvers, $child_reversers) = self::convert ($route[1], $name, $suffix);

				// Register child reversers
				$components = self::make_components ($fragments);

				foreach ($child_reversers as $child_name => $child_components)
					$reversers[$child_name] = array_merge ($components, $child_components);

				// Append child resolvers
				list ($pattern, $captures) = self::make_pattern ($fragments);

				$pattern = self::DELIMITER . '^' . $pattern . self::DELIMITER;

				if (!isset ($resolvers[$pattern]))
					$resolvers[$pattern] = array ($captures, self::RESOLVE_NODE, array ());

				$resolvers[$pattern][2] = array_merge ($resolvers[$pattern][2], $child_resolvers);
			}

			// Node is a leaf, register callback
			else if (count ($route) >= 3 && is_string ($route[0]) && is_string ($route[1]) && is_string ($route[2]))
			{
				$fragments = self::parse ($prefix . $route[0] . $suffix, $i);

				// Register final reverser
				$reversers[$name] = self::make_components ($fragments);

				// Append final resolvers
				list ($pattern, $captures) = self::make_pattern ($fragments);

				$pattern = self::DELIMITER . '^' . $pattern . '$' . self::DELIMITER;

				if (!isset ($resolvers[$pattern]))
					$resolvers[$pattern] = array ($captures, self::RESOLVE_LEAF, array ());

				foreach (array_map ('strtoupper', explode (',', $route[1])) as $method)
				{
					if (isset ($resolvers[$pattern][2][$method]))
						throw new \Exception ('duplicate method "' . $method . '" for pattern "' . $route[0] . '" on branch "' . $branch . '"');

					$resolvers[$pattern][2][$method] = array ($route[2], array_slice ($route, 3));
				}
			}

			// Invalid configuration
			else
				throw new \Exception ('invalid configuration on branch "' . $branch . '"');
		}

		return array ($resolvers, $reversers);
	}

	/*
	** Wrap native var_export function with better support for indexed arrays.
	*/
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

	/*
	** Make URL construction components from parsed URL template fragments.
	*/
	private static function make_components ($fragments)
	{
		$components = array ();

		foreach ($fragments as $fragment)
		{
			switch ($fragment[0])
			{
				case self::CONSTANT:
					$components[] = array (self::CONSTANT, $fragment[1]);

					break;

				case self::OPTION:
					$components[] = array (self::OPTION, self::make_components ($fragment[1]));

					break;

				case self::PARAM:
					$components[] = array (self::PARAM, $fragment[2], $fragment[3]);

					break;
			}
		}

		return $components;
	}

	/*
	** Make URL regular expression pattern from parsed URL template fragments.
	*/
	private static function make_pattern ($fragments)
	{
		$captures = array ();
		$pattern = '';

		foreach ($fragments as $fragment)
		{
			switch ($fragment[0])
			{
				case self::CONSTANT:
					$pattern .= preg_quote ($fragment[1], self::DELIMITER);

					break;

				case self::OPTION:
					list ($child_pattern, $child_captures) = self::make_pattern ($fragment[1]);

					$captures = array_merge ($captures, $child_captures);
					$pattern .= '(?:' . $child_pattern . ')?';

					break;

				case self::PARAM:
					$captures[] = array ($fragment[2], $fragment[3]);
					$pattern .= '(' . $fragment[1] . ')';

					break;
			}
		}

		return array ($pattern, $captures);
	}

	/*
	** Parse URL template into fragments.
	*/
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
						throw new \Exception ('unfinished parameter "' . $key . '"');

					$fragments[] = array (self::PARAM, $pattern, $key, $default);

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

	/*
	** Find route matching given path and method using known resolvers.
	*/
	private function resolve ($resolvers, $method, $path, $parameters)
	{
		foreach ($resolvers as $pattern => $resolver)
		{
			if (preg_match ($pattern, $path, $match, PREG_OFFSET_CAPTURE) !== 1)
				continue;

			foreach ($resolver[0] as $index => $parameter)
			{
				list ($key, $default) = $parameter;

				$parameters[$key] = isset ($match[$index + 1]) && $match[$index + 1][1] !== -1 ? $match[$index + 1][0] : $default;
			}

			// Node matched, continue searching recursively on children
			if ($resolver[1] === self::RESOLVE_NODE)
				return $this->resolve ($resolver[2], $method, substr ($path, strlen ($match[0][0])), $parameters);

			// Leaf matched, search suitable method and start processing
			foreach ($resolver[2] as $accept => $route)
			{
				if ($accept !== '' && $accept !== $method)
					continue;

				$options = $route[1];
				$type = $route[0];

				if (!isset ($this->callbacks[$type]))
					throw new \Exception ('unknown callback type "' . $type . '"');

				return new Query ($this, $this->callbacks[$type], $options, $method, $parameters);
			}
		}

		return null;
	}

	/*
	** Build URL from given URL construction components.
	*/
	private static function reverse ($components, $forced, &$parameters)
	{
		$defined = true;
		$result = '';

		foreach ($components as $component)
		{
			switch ($component[0])
			{
				case self::CONSTANT:
					$result .= $component[1];

					break;

				case self::OPTION:
					$append = self::reverse ($component[1], false, $parameters);

					if ($append !== null)
					{
						$forced = true;
						$result .= $append;
					}

					break;

				case self::PARAM:
					if (isset ($parameters[$component[1]]))
					{
						$parameter = (string)$parameters[$component[1]];

						if ($parameter !== $component[2])
							$forced = true;

						$result .= rawurlencode ($parameter);
					}
					else if ($component[2] !== null)
						$result .= rawurlencode ($component[2]);
					else
						$defined = false;

					unset ($parameters[$component[1]]);

					break;
			}
		}

		if ($defined && $forced)
			return $result;

		return null;
	}
}

?>
