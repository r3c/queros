<?php

require ('../src/queros.php');

function	handle_index ($router, $custom, $params)
{
	echo "handle::index<br />";
}

function	handle_topic ($router, $custom, $params)
{
	echo "handle::topic($params[topic], $params[page])<br />";
}

function	handle_post ($router, $custom, $params)
{
	echo "handle::post<br />";
}

function	handle_test ($router, $custom, $params)
{
	echo "handle::test<br />";
}

$routes = array
(
	'index'	=> array ('(index)',	'func:handle_index'),
	'forum'	=> array ('forum/',		array
	(
		'.topic'		=> array ('topic-<topic:\\\\d+>(/<page:\\\\d+:1>(-<title:[-0-9A-Za-z]+>))',	'func:handle_topic:topic:page'),
		'.post.edit'	=> array ('edit-post',														'func:handle_post')
	)),
	'test'	=> array ('(<something>)followed by(<optional>)',	'func:handle_test')
);

$test = new Test ($routes);

$test->call ('');
$test->call ('index');
$test->call ('forum/topic-52');
$test->call ('forum/topic-17/3');
$test->call ('forum/topic-42/5-titre-du-topic');

echo $test->uri ('index') . "<br />";
echo $test->uri ('forum.topic', array ('topic' => 15)) . "<br />";
echo $test->uri ('forum.topic', array ('topic' => 15, 'page' => 2, 'title' => 'test')) . "<br />";
echo $test->uri ('forum.post.edit') . "<br />";


?>
