<?php

/*
** Query Routing System
*/

namespace Queros;

define ('QUEROS',	'1.0.0.0');

class	HTTPException extends \Exception
{
	private static	$statuses = array
	(
		301	=> 'Moved Permanently',
		302	=> 'Found',
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

	public function	__construct ($status, $message = null)
	{
		if ($message !== null)
			$this->message = $message;
		else if (isset (self::$statuses[$status]))
			$this->message = self::$statuses[$status];
		else
			$this->message = '';

		$this->status = $status;
	}

	public function	send ()
	{
		if (isset (self::$statuses[$this->status]))
			header ('HTTP/1.1 ' . $this->status . ' ' . self::$statuses[$this->status]);
		else
			header ('HTTP/1.1 ' . $this->status);

		echo $this->message;
	}
}

class	HTTPReply
{
	public function	__construct ($content, $headers = array ())
	{
		$this->content = $content;
		$this->headers = $headers;
	}

	public function	send ()
	{
		foreach ($this->headers as $name => $value)
			header ($name . ': ' . $value);

		echo $this->content;
	}
}

class	Router
{
	private $handlers;
	private	$readers;
	private $routes;

	public function	__construct ($routes)
	{
		$this->handlers = array
		(
			'call'	=> function ($params, $function)
			{
				return call_user_func_array ($function, $params);
			},
			'file'	=> function ($params, $path, $function)
			{
				require ($path);

				return call_user_func_array ($function, $params);
			}
		);

		$this->readers = array
		(
			'get'	=> function ($match, $key, $default = null)
			{
				if (isset ($_GET[$key]))
					return $_GET[$key];

				return $default;
			},
			'match'	=> function ($match, $group, $default = null)
			{
				if (isset ($match[$group]))
					return $match[$group];

				return $default;
			},
			'post'	=> function ($match, $key, $default = null)
			{
				if (isset ($_POST[$key]))
					return $_POST[$key];

				return $default;
			}
		);

		$this->routes = $routes;
	}

	public function	dispatch ($path)
	{
		try
		{
			$reply = $this->reply ($this->routes, $path);
			$reply->send ();
		}
		catch (HTTPException $error)
		{
			$error->send ();
		}

		exit;
	}

	public function	resolve ($name)
	{
		return 'FIXME_not_implemented';
	}

	public function	set_handler ($type, $callback)
	{
		$this->handlers[$type] = $callback;
	}

	public function	set_reader ($type, $callback)
	{
		$this->readers[$type] = $callback;
	}

	private function	reply ($routes, $path)
	{
		foreach ($routes as $route)
		{
			if (preg_match ($route[0], $path, $match) === 1)
			{
				if (is_array ($route[1]))
					return $this->reply ($route[1], substr ($path, strlen ($match[0])));

				$params = array ($this);

				if (isset ($route[2]))
				{
					foreach ($route[2] as $expr)
					{
						$options = explode (':', $expr);

						if (!isset ($this->readers[$options[0]]))
							throw new HTTPException (500, 'Unknown reader type "' . $options[0] . '"');

						$context = array_merge (array ($match), array_slice ($options, 1));
						$reader = $this->readers[$options[0]];

						$params[] = call_user_func_array ($reader, $context);
					}
				}

				$options = explode (':', $route[1]);

				if (!isset ($this->handlers[$options[0]]))
					throw new HTTPException (500, 'Unknown handler type "' . $option[0] . '"');

				$context = array_merge (array ($params), array_slice ($options, 1));
				$handler = $this->handlers[$options[0]];

				return call_user_func_array ($handler, $context);
			}
		}

		throw new HTTPException (404);
	}
}

?>
