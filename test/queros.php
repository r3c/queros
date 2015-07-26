<?php

require ('../src/queros.php');

function handle_index ($query)
{
	return Queros\Reply::ok ('handle::index(' . $query->method . ')');
}

function handle_topic ($query)
{
	$page = (int)$query->get_or_fail ('page');
	$topic = $query->get_or_fail ('topic');
	$title = $query->get_or_default ('title', '');

	return Queros\Reply::ok ('handle::topic(' . $query->method . ", $topic, $page, '$title')");
}

function handle_post ($query)
{
	return Queros\Reply::ok ('handle::post(' . $query->method . ')');
}

function handle_test ($query)
{
	$optional = $query->get_or_default ('optional', '');
	$something = $query->get_or_default ('something', '');

	return Queros\Reply::ok ('handle::test(' . $query->method . ", '$something', '$optional')");
}

$test = new Queros\Router (array
(
	'index'	=> array ('(index)', 'GET', 'call', 'handle_index'),
	'forum'	=> array ('forum/', array
	(
		'.topic'		=> array ('topic-<topic:\\d+>(/<page:\\d+:1>(-<title:[-0-9A-Za-z]+>))', 'GET', 'call', 'handle_topic'),
		'.post.edit'	=> array ('edit-post', 'GET,POST', 'call', 'handle_post')
	)),
	'test'	=> array ('(<something>)followed by(<optional>)', '', 'call', 'handle_test')
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

// Route resolution, standard usage
assert ($test->call ('')->contents === 'handle::index(GET)');
assert ($test->call ('index')->contents === 'handle::index(GET)');
assert ($test->call ('forum/topic-17/3')->contents === "handle::topic(GET, 17, 3, '')");
assert ($test->call ('forum/topic-42/5-my-topic-title')->contents === "handle::topic(GET, 42, 5, 'my-topic-title')");
assert ($test->call ('forum/edit-post', 'GET')->contents === 'handle::post(GET)');
assert ($test->call ('forum/edit-post', 'POST')->contents === 'handle::post(POST)');
assert ($test->call ('followed by', 'PUT')->contents === "handle::test(PUT, '', '')");
assert ($test->call ('XXXfollowed byYYY')->contents === "handle::test(GET, 'XXX', 'YYY')");

// Route resolution, optional parameters
assert ($test->call ('forum/topic-52')->contents === "handle::topic(GET, 52, 1, '')");
assert ($test->call ('forum/topic-52/1')->contents === "handle::topic(GET, 52, 1, '')");

// Route resolution, exception on unknown route
assert_exception (function () use ($test) { $test->call ('forum/topic-17/'); }, 'No route found');
assert_exception (function () use ($test) { $test->call ('not-exists'); }, 'No route found');
assert_exception (function () use ($test) { $test->call ('', 'POST'); }, 'No route found');
assert_exception (function () use ($test) { $test->call ('forum/edit-post', 'PUT'); }, 'No route found');

// URL generation, standard usage
assert ($test->url ('index') === '');
assert ($test->url ('index', array ('other' => 'key', 'in' => 'query-string')) === '?other=key&in=query-string');
assert ($test->url ('forum.topic', array ('topic' => 15, 'page' => 2, 'title' => 'test')) === 'forum/topic-15/2-test');
assert ($test->url ('forum.post.edit') === 'forum/edit-post');
assert ($test->url ('test', array ('something' => '.~', 'optional' => '~.')) == '.~followed by~.');

// URL generation, optional parameters
assert ($test->url ('forum.topic', array ('topic' => 15)) === 'forum/topic-15');
assert ($test->url ('forum.topic', array ('topic' => 15, 'page' => 1)) === 'forum/topic-15');
assert ($test->url ('forum.topic', array ('topic' => 15, 'title' => 'some-title')) === 'forum/topic-15/1-some-title');

// URL generation, exception on missing mandatory parameter
assert_exception (function () use ($test) { $test->url ('forum.topic', array ('page' => 1, 'title' => 'test')); }, '"forum.topic"');

echo 'Tests OK!';

?>
