<?php

require ('../src/queros.php');

function handle_index ($query)
{
	return 'handle_index(' . $query->method . ')';
}

function handle_option ($query)
{
	$optional = $query->get_or_default ('optional', '');
	$something = $query->get_or_default ('something', '');

	return 'handle_option(' . $query->method . ", '$something', '$optional')";
}

function handle_param_first ($query)
{
	$mandatory = $query->get_or_fail ('mandatory');
	$optional = (int)$query->get_or_fail ('optional');
	$string = $query->get_or_default ('string', '');

	return 'handle_param_first(' . $query->method . ", $mandatory, $optional, '$string')";
}

function handle_param_second ($query)
{
	return 'handle_param_second(' . $query->method . ')';
}

$test = new Queros\Router (array
(
	array ('anonymous', 'GET', 'data', 'anonymous'),
	'callback.'	=> array ('callback', array
	(
		'data'		=> array ('/data', 'GET', 'data', '17'),
		'echo'		=> array ('/echo', 'GET', 'echo', '17'),
		'unknown'	=> array ('/unknown', 'GET', 'unknown')
	)),
	'escape'	=> array ('<escape:/truc/>', 'GET', 'data', 'escape'),
	'index'		=> array ('(index)', 'GET', 'call', 'handle_index'),
	'method.'	=> array ('method', array
	(
		'any'	=> array ('/any', '', 'data'),
		'put'	=> array ('/put', 'PUT', 'data')
	)),
	'name.'		=> array ('name', array
	(
		'+append'	=> array ('/append', 'GET', 'data'),
		'!ignore'	=> array ('/ignore', 'GET', 'data'),
		'=reset'	=> array ('/reset', 'GET', 'data')
	)),
	'option1'	=> array ('(<something>)followed by(<optional>)', '', 'call', 'handle_option'),
	'option2.'	=> array ('option', array
	(
		'leaf1'	=> array ('(/<a:\\d+>)/<b:\\d+>', 'GET', 'data', 'option2'),
		'leaf2'	=> array ('(/<a:\\d+>)/x', 'GET', 'data', 'option2'),
	)),
	'overlap1'	=> array ('overlap', 'GET', 'data', 'overlap1'),
	'overlap2.'	=> array ('overlap', array
	(
		'leaf'	=> array ('/2', 'GET', 'data', 'overlap2')
	)),
	'overlap3.'	=> array ('overlap', array
	(
		'leaf'	=> array ('/3', 'GET', 'data', 'overlap3')
	)),
	'param.'	=> array ('param/', array
	(
		'first'		=> array ('first-<mandatory:\\d+>(/<optional:\\d+:1>(-<string:[-0-9A-Za-z]+>))', 'GET', 'call', 'handle_param_first'),
		'second'	=> array ('second', 'GET,POST', 'call', 'handle_param_second')
	)),
	'tree.'		=> array ('tree/', array
	(
		'!prefix'	=> 'begin1-',
		'!suffix'	=> '-end1',
		'leaf'		=> array ('leaf', '', 'data', 'leaf1'),
		'node.'		=> array ('node/', array
		(
			'!prefix'	=> 'begin2-',
			'!suffix'	=> '-end2',
			'leaf'		=> array ('leaf', '', 'data', 'leaf2')
		))
	))
));

header ('Content-Type: text/plain');

assert_options (ASSERT_BAIL, true);

function assert_exception ($callback, $message)
{
	try
	{
		$callback ();

		assert (false);
	}
	catch (Exception $exception)
	{
		assert (strpos ($exception->getMessage (), $message) !== false);
	}
}

// Query validation, valid route
assert ($test->match ('GET', '') !== null);

// Query validation, invalid route
assert ($test->match ('GET', 'not-exists') === null);

// Route resolution, standard callbacks
assert ($test->invoke ('GET', 'callback/data') === '17');
ob_start (); $test->invoke ('GET', 'callback/echo'); assert (ob_get_clean () === '17');

// Route resolution, unknown callback
assert_exception (function () use ($test) { $test->invoke ('GET', 'callback/unknown'); }, '"unknown"');

// Route resolution, standard usage
assert ($test->invoke ('GET', '') === 'handle_index(GET)');
assert ($test->invoke ('GET', 'index') === 'handle_index(GET)');
assert ($test->invoke ('GET', 'param/first-17/3') === "handle_param_first(GET, 17, 3, '')");
assert ($test->invoke ('GET', 'param/first-42/5-my-topic-title') === "handle_param_first(GET, 42, 5, 'my-topic-title')");
assert ($test->invoke ('GET', 'param/second') === 'handle_param_second(GET)');
assert ($test->invoke ('POST', 'param/second') === 'handle_param_second(POST)');
assert ($test->invoke ('PUT', 'followed by') === "handle_option(PUT, '', '')");
assert ($test->invoke ('GET', 'XXXfollowed byYYY') === "handle_option(GET, 'XXX', 'YYY')");
assert ($test->invoke ('GET', 'option/42/17') === 'option2');
assert ($test->invoke ('GET', 'option/17') === 'option2');
assert ($test->invoke ('GET', 'anonymous') === 'anonymous');

// Route resolution, method matching
assert ($test->match ('GET', 'method/any') !== null);
assert ($test->match ('PUT', 'method/any') !== null);
assert ($test->match ('GET', 'method/put') === null);
assert ($test->match ('PUT', 'method/put') !== null);

// Route resolution, optional parameters
assert ($test->invoke ('GET', 'param/first-52') === "handle_param_first(GET, 52, 1, '')");
assert ($test->invoke ('GET', 'param/first-52/1') === "handle_param_first(GET, 52, 1, '')");

// Route resolution, overlapping routes
assert ($test->invoke ('GET', 'overlap') === 'overlap1');
assert ($test->invoke ('GET', 'overlap/2') === 'overlap2');
assert ($test->invoke ('GET', 'overlap/3') === 'overlap3');

// Route resolution, exception on unknown route
assert_exception (function () use ($test) { $test->invoke ('GET', 'param/first-17/'); }, 'No route');
assert_exception (function () use ($test) { $test->invoke ('GET', 'not-exists'); }, 'No route');
assert_exception (function () use ($test) { $test->invoke ('POST', ''); }, 'No route');
assert_exception (function () use ($test) { $test->invoke ('PUT', 'param/second'); }, 'No route');

// Route resolution, prefixes and suffixes
assert ($test->invoke ('GET', 'tree/begin1-leaf-end1') === 'leaf1');
assert ($test->invoke ('GET', 'tree/begin1-node/begin2-leaf-end2-end1') === 'leaf2');

// Route resolution, escaped delimiter
assert ($test->invoke ('GET', '/truc/') === 'escape');

// URL generation, standard usage
assert ($test->url ('index') === '');
assert ($test->url ('index', array ('empty' => '')) === '?empty');
assert ($test->url ('param.first', array ('mandatory' => 15, 'optional' => 2, 'string' => 'test')) === 'param/first-15/2-test');
assert ($test->url ('param.second') === 'param/second');
assert ($test->url ('option1', array ('something' => '.~', 'optional' => '~.')) == '.~followed by~.');

// URL generation, extra parameters
assert ($test->url ('index', array ('other' => 'key', 'in' => 'query-string')) === '?other=key&in=query-string');
assert ($test->url ('index', array ('scalar' => '43', 'complex' => array (0 => 'first', 1 => array ('a' => 'sub-second-1', 'b' => 'sub-second-2')))) === '?scalar=43&complex%5B0%5D=first&complex%5B1%5D%5Ba%5D=sub-second-1&complex%5B1%5D%5Bb%5D=sub-second-2');

// URL generation, name composition
assert ($test->url ('reset') === 'name/reset');
assert ($test->url ('name.') === 'name/ignore');
assert ($test->url ('name.append') === 'name/append');

// URL generation, optional parameters
assert ($test->url ('param.first', array ('mandatory' => 15)) === 'param/first-15');
assert ($test->url ('param.first', array ('mandatory' => 15, 'optional' => 1)) === 'param/first-15');
assert ($test->url ('param.first', array ('mandatory' => 15, 'string' => 'some-title')) === 'param/first-15/1-some-title');

// URL generation, exception on missing mandatory parameter
assert_exception (function () use ($test) { $test->url ('param.first', array ('optional' => 1, 'string' => 'test')); }, '"param.first"');

// URL generation, prefixes and suffixes
assert ($test->url ('tree.leaf') === 'tree/begin1-leaf-end1');
assert ($test->url ('tree.node.leaf') === 'tree/begin1-node/begin2-leaf-end2-end1');

// URL generation, unknown routes
assert_exception (function () use ($test) { $test->url ('undefined'); }, 'unknown route');
assert_exception (function () use ($test) { $test->url ('0'); }, 'unknown route');

echo 'Tests OK!';

?>
