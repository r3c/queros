<?php

require('../../src/queros.php');

$router = new Queros\Router(array(
    'index'	=> array('', '', 'data'),
    'forum'	=> array('forum/',	array(
        '.topic'		=> array('topic-<topic:\\d+>(/<page:\\d+:1>(-<title:[-0-9A-Za-z]+>))', '', 'data'),
        '.post.edit'	=> array('edit-post-<post:\\d+>', '', 'data')
    ))
));

$count = 10000;
$start = microtime(true);

for ($i = 0; $i < $count; ++$i) {
    $router->match('GET', '');
    $router->match('GET', 'forum/topic-52');
    $router->match('GET', 'forum/topic-17/3');
    $router->match('GET', 'forum/topic-42/5-titre-du-topic');
}

$delta = microtime(true) - $start;

echo "find time: " . round(1 / $delta * $count * 4) . " calls/s, " . round($delta * 1000 / $count / 4, 3) . " ms/call<br />";

$count = 10000;
$start = microtime(true);

for ($i = 0; $i < $count; ++$i) {
    $router->url('index');
    $router->url('forum.topic', array('topic' => 15, 'other' => 'key', 'in' => 'query-string'));
    $router->url('forum.topic', array('topic' => 15, 'page' => 2, 'title' => 'test'));
    $router->url('forum.post.edit', array('post' => 1));
}

$delta = microtime(true) - $start;

echo "url time: " . round(1 / $delta * $count * 4) . " calls/s, " . round($delta * 1000 / $count / 4, 3) . " ms/call<br />";
