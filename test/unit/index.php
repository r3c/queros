<?php

require ('../../src/queros.php');

function handle_index ($query)
{
	return new Queros\Reply ('handle::index');
}

function handle_topic ($query)
{
	$page = (int)$query->get_or_default ('page', 1);
	$topic = $query->get_or_fail ('topic');
	$title = $query->get_or_default ('title', '');

	return new Queros\Reply ("handle::topic($topic, $page, '$title')");
}

function handle_post ($query)
{
	return new Queros\Reply ('handle::post');
}

function handle_test ($query)
{
	$optional = $query->get_or_default ('optional', '');
	$something = $query->get_or_default ('something', '');

	return new Queros\Reply ("handle::test('$something', '$optional')");
}

$test = new Queros\Router (array
(
	'index'	=> array ('(index)',	'func:handle_index'),
	'forum'	=> array ('forum/',		array
	(
		'.topic'		=> array ('topic-<topic:\\d+>(/<page:\\d+:1>(-<title:[-0-9A-Za-z]+>))',	'func:handle_topic'),
		'.post.edit'	=> array ('edit-post',													'func:handle_post')
	)),
	'test'	=> array ('(<something>)followed by(<optional>)',	'func:handle_test')
));

header ('Content-Type: text/plain');

assert ($test->call ('')->contents === 'handle::index');
assert ($test->call ('index')->contents === 'handle::index');
assert ($test->call ('forum/topic-52')->contents === "handle::topic(52, 1, '')");
assert ($test->call ('forum/topic-17/3')->contents === "handle::topic(17, 3, '')");
assert ($test->call ('forum/topic-42/5-my-topic-title')->contents === "handle::topic(42, 5, 'my-topic-title')");
assert ($test->call ('followed by')->contents === "handle::test('', '')");
assert ($test->call ('XXXfollowed byYYY')->contents === "handle::test('XXX', 'YYY')");

assert ($test->url ('index') === 'index');
assert ($test->url ('forum.topic', array ('topic' => 15, 'other' => 'key', 'in' => 'query-string')) === 'forum/topic-15/1?other=key&in=query-string');
assert ($test->url ('forum.topic', array ('topic' => 15, 'page' => 2, 'title' => 'test')) === 'forum/topic-15/2-test');
assert ($test->url ('forum.post.edit') === 'forum/edit-post');
assert ($test->url ('test', array ('something' => '[[', 'optional' => ']]')) == '[[followed by]]');

echo 'Tests OK!';

?>
