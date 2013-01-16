<?php

require ('../../src/queros.php');

$router = new Queros\Router (array
(
	'index'	=> array ('(index)',	'void'),
	'forum'	=> array ('forum/',		array
	(
		'.topic'		=> array ('topic-<topic:\\\\d+>(/<page:\\\\d+:1>(-<title:[-0-9A-Za-z]+>))',	'void'),
		'.post.edit'	=> array ('edit-post-<post:\\\\d+>',										'void')
	))
));

$start = microtime (true);

for ($i = 0; $i < 10000; ++$i)
{
	$router->call ('');
	$router->call ('index');
	$router->call ('forum/topic-52');
	$router->call ('forum/topic-17/3');
	$router->call ('forum/topic-42/5-titre-du-topic');
}

echo "call time: " . ((microtime (true) - $start) * 1000) . " ms<br />";

$start = microtime (true);

for ($i = 0; $i < 10000; ++$i)
{
	$router->url ('index');
	$router->url ('forum.topic', array ('topic' => 15, 'other' => 'key', 'in' => 'query-string'));
	$router->url ('forum.topic', array ('topic' => 15, 'page' => 2, 'title' => 'test'));
	$router->url ('forum.post.edit');
}

echo "url time: " . ((microtime (true) - $start) * 1000) . " ms<br />";

?>
