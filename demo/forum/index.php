<?php

isset ($_GET['route']) or die;

require ('../../src/queros.php');

function handle_index ($query, $router)
{
	return new Queros\Reply ('<h1>Index page</h1>
<ul>
	<li><a href="' . htmlspecialchars ($router->url ('forum.topic', array ('topic' => 42, 'page' => 3))) . '">Go to topic #42 on page 3</a></li>
	<li><a href="' . htmlspecialchars ($router->url ('forum.post.edit', array ('post' => 17))) . '">Edit post #17</a></li>
</ul>');
}

function handle_topic ($query, $router)
{
	return new Queros\Reply ('<h1>Topic page</h1>
<p>Reading topic #' . htmlspecialchars ($query->get_or_fail ('topic')) . ' on page #' . htmlspecialchars ($query->get_or_fail ('page')) . '.</p>
<a href="' . htmlspecialchars ($router->url ('index')) . '">Back</a>');
}

function handle_post ($query, $router)
{
	$content = '<h1>Post page</h1>';
	$text = $query->get_or_default ('text');

	if ($text !== null)
		$content .= '<p>Post #' . htmlspecialchars ($query->get_or_fail ('post')) . ' has been updated with text "' . htmlspecialchars ($text) . '".</p>';
	else
	{
		$content .= '<p>Editing post #' . htmlspecialchars ($query->get_or_fail ('post')) . ':</p>
<form action="' . htmlspecialchars ($router->url ('forum.post.edit')) . '" method="POST">
	<textarea name="text" cols="80" rows="6"></textarea><br />
	<input type="submit" value="OK" />
</form>';
	}

	$content .= '<a href="' . htmlspecialchars ($router->url ('index')) . '">Back</a>';

	return new Queros\Reply ($content);
}

$router = new Queros\Router (array
(
	'index'	=> array ('(index)',	'func:handle_index'),
	'forum'	=> array ('forum-',		array
	(
		'.topic'		=> array ('topic-<topic:\\d+>(-<page:\\d+:1>(-<title:[-0-9A-Za-z]+>))',	'func:handle_topic'),
		'.post.edit'	=> array ('edit-post-<post:\\d+>',										'func:handle_post')
	))
));

try
{
	$reply = $router->call ($_GET['route'], $_REQUEST, array ($router));
	$reply->send ();
}
catch (Queros\Exception $exception)
{
	$exception->send ();
}

?>
