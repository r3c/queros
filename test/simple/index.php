<?php

require ('../../src/queros.php');

function	handle_index ($router, $params)
{
	return new Queros\ContentsReply ("handle::index<br />");
}

function	handle_topic ($router, $params)
{
	return new Queros\ContentsReply ("handle::topic($params[topic], $params[page], $params[title])<br />");
}

function	handle_post ($router, $params)
{
	return new Queros\ContentsReply ("handle::post<br />");
}

function	handle_test ($router, $params)
{
	return new Queros\ContentsReply ("handle::test<br />");
}

$routes = array
(
	'index'	=> array ('(index)',	'func:handle_index'),
	'forum'	=> array ('forum/',		array
	(
		'.topic'		=> array ('topic-<topic:\\d+>(/<page:\\d+:1>(-<title:[-0-9A-Za-z]+>))',	'func:handle_topic'),
		'.post.edit'	=> array ('edit-post',													'func:handle_post')
	)),
	'test'	=> array ('(<something>)followed by(<optional>)',	'func:handle_test')
);

$test = new Queros\Router ($routes);

$test->call ('')->send ();
$test->call ('index')->send ();
$test->call ('forum/topic-52')->send ();
$test->call ('forum/topic-17/3')->send ();
$test->call ('forum/topic-42/5-titre-du-topic')->send ();

echo $test->url ('index') . "<br />";
echo $test->url ('forum.topic', array ('topic' => 15, 'other' => 'key', 'in' => 'query-string')) . "<br />";
echo $test->url ('forum.topic', array ('topic' => 15, 'page' => 2, 'title' => 'test')) . "<br />";
echo $test->url ('forum.post.edit') . "<br />";

?>
