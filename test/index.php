<?php

require ('../src/queros.php');

function handle_index ($query)
{
	return Queros\Reply::ok ('handle_index(' . $query->method . ')');
}

function handle_option ($query)
{
	$optional = $query->get_or_default ('optional', '');
	$something = $query->get_or_default ('something', '');

	return Queros\Reply::ok ('handle_option(' . $query->method . ", '$something', '$optional')");
}

function handle_param_first ($query)
{
	$mandatory = $query->get_or_fail ('mandatory');
	$optional = (int)$query->get_or_fail ('optional');
	$string = $query->get_or_default ('string', '');

	return Queros\Reply::ok ('handle_param_first(' . $query->method . ", $mandatory, $optional, '$string')");
}

function handle_param_second ($query)
{
	return Queros\Reply::ok ('handle_param_second(' . $query->method . ')');
}

$test = new Queros\Router (array
(
	'index'		=> array ('(index)', 'GET', 'call', 'handle_index'),
	'option1'	=> array ('(<something>)followed by(<optional>)', '', 'call', 'handle_option'),
	'option2'	=> array ('option', array
	(
		'.leaf1'	=> array ('(/<a:\\d+>)/<b:\\d+>', 'GET', 'code', 200, 'option2'),
		'.leaf2'	=> array ('(/<a:\\d+>)/x', 'GET', 'code', 200, 'option2'),
	)),
	'overlap1'	=> array ('overlap', 'GET', 'code', 200, 'overlap1'),
	'overlap2'	=> array ('overlap', array
	(
		'.leaf'	=> array ('/2', 'GET', 'code', 200, 'overlap2')
	)),
	'overlap3'	=> array ('overlap', array
	(
		'.leaf'	=> array ('/3', 'GET', 'code', 200, 'overlap3')
	)),
	'param'		=> array ('param/', array
	(
		'.first'	=> array ('first-<mandatory:\\d+>(/<optional:\\d+:1>(-<string:[-0-9A-Za-z]+>))', 'GET', 'call', 'handle_param_first'),
		'.second'	=> array ('second', 'GET,POST', 'call', 'handle_param_second')
	)),
	'tree'		=> array ('tree/', array
	(
		'!prefix'	=> 'begin1-',
		'!suffix'	=> '-end1',
		'.leaf'		=> array ('leaf', '', 'code', 200),
		'.node'		=> array ('node/', array
		(
			'!prefix'	=> 'begin2-',
			'!suffix'	=> '-end2',
			'.leaf'		=> array ('leaf', '', 'code', 200)
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

// Route resolution, standard usage
assert ($test->invoke ('GET', '')->contents === 'handle_index(GET)');
assert ($test->invoke ('GET', 'index')->contents === 'handle_index(GET)');
assert ($test->invoke ('GET', 'param/first-17/3')->contents === "handle_param_first(GET, 17, 3, '')");
assert ($test->invoke ('GET', 'param/first-42/5-my-topic-title')->contents === "handle_param_first(GET, 42, 5, 'my-topic-title')");
assert ($test->invoke ('GET', 'param/second')->contents === 'handle_param_second(GET)');
assert ($test->invoke ('POST', 'param/second')->contents === 'handle_param_second(POST)');
assert ($test->invoke ('PUT', 'followed by')->contents === "handle_option(PUT, '', '')");
assert ($test->invoke ('GET', 'XXXfollowed byYYY')->contents === "handle_option(GET, 'XXX', 'YYY')");
assert ($test->invoke ('GET', 'option/42/17')->contents === 'option2');
assert ($test->invoke ('GET', 'option/17')->contents === 'option2');

// Route resolution, optional parameters
assert ($test->invoke ('GET', 'param/first-52')->contents === "handle_param_first(GET, 52, 1, '')");
assert ($test->invoke ('GET', 'param/first-52/1')->contents === "handle_param_first(GET, 52, 1, '')");

// Route resolution, overlapping routes
assert ($test->invoke ('GET', 'overlap')->contents === 'overlap1');
assert ($test->invoke ('GET', 'overlap/2')->contents === 'overlap2');
assert ($test->invoke ('GET', 'overlap/3')->contents === 'overlap3');

// Route resolution, exception on unknown route
assert ($test->invoke ('GET', 'param/first-17/')->status === 404);
assert ($test->invoke ('GET', 'not-exists')->status === 404);
assert ($test->invoke ('POST', '')->status === 404);
assert ($test->invoke ('PUT', 'param/second')->status === 404);

// Route resolution, prefixes and suffixes
assert ($test->invoke ('GET', 'tree/begin1-leaf-end1')->status === 200);
assert ($test->invoke ('GET', 'tree/begin1-node/begin2-leaf-end2-end1')->status === 200);

// URL generation, standard usage
assert ($test->url ('index') === '');
assert ($test->url ('index', array ('other' => 'key', 'in' => 'query-string')) === '?other=key&in=query-string');
assert ($test->url ('param.first', array ('mandatory' => 15, 'optional' => 2, 'string' => 'test')) === 'param/first-15/2-test');
assert ($test->url ('param.second') === 'param/second');
assert ($test->url ('option1', array ('something' => '.~', 'optional' => '~.')) == '.~followed by~.');

// URL generation, optional parameters
assert ($test->url ('param.first', array ('mandatory' => 15)) === 'param/first-15');
assert ($test->url ('param.first', array ('mandatory' => 15, 'optional' => 1)) === 'param/first-15');
assert ($test->url ('param.first', array ('mandatory' => 15, 'string' => 'some-title')) === 'param/first-15/1-some-title');

// URL generation, exception on missing mandatory parameter
assert_exception (function () use ($test) { $test->url ('param.first', array ('optional' => 1, 'string' => 'test')); }, '"param.first"');

// URL generation, prefixes and suffixes
assert ($test->url ('tree.leaf') === 'tree/begin1-leaf-end1');
assert ($test->url ('tree.node.leaf') === 'tree/begin1-node/begin2-leaf-end2-end1');

echo 'Tests OK!';

?>
