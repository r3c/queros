<?php

isset ($_GET['route']) or die;

require ('../../src/queros.php');

function handle_index ($query, $router)
{
	echo '<h1>Index page</h1>
<ul>
	<li><a href="' . htmlspecialchars (make_url ($router->url ('forum.topic', array ('topic' => 42, 'page' => 3)))) . '">Go to topic #42 on page 3</a></li>
	<li><a href="' . htmlspecialchars (make_url ($router->url ('forum.post.edit', array ('post' => 17)))) . '">Edit post #17</a></li>
</ul>';
}

function handle_topic ($query, $router)
{
	echo '<h1>Topic page</h1>
<p>Reading topic #' . htmlspecialchars ($query->get_or_fail ('topic')) . ' on page #' . htmlspecialchars ($query->get_or_fail ('page')) . '.</p>
<a href="' . htmlspecialchars (make_url ($router->url ('index'))) . '">Back</a>';
}

function handle_post ($query, $router)
{
	echo '<h1>Post page</h1>';

	$post = $query->get_or_fail ('post');

	if ($query->method === 'POST')
	{
		$text = $query->get_or_fail ('text');

		echo '<p>Post #' . htmlspecialchars ($post) . ' has been updated with text "' . htmlspecialchars ($text) . '".</p>';
	}
	else
	{
		echo '<p>Editing post #' . htmlspecialchars ($post) . ':</p>
<form action="' . htmlspecialchars (make_url ($router->url ('forum.post.edit', array ('post' => $post)))) . '" method="POST">
	<textarea name="text" cols="80" rows="6"></textarea><br />
	<input type="submit" value="OK" />
</form>';
	}

	echo '<a href="' . htmlspecialchars (make_url ($router->url ('index'))) . '">Back</a>';
}

function make_url ($path)
{
	return './' . $path;
}

$router = new Queros\Router (array
(
	'index'	=> array ('', 'GET', 'call', 'handle_index'),
	'forum'	=> array ('forum-',	array
	(
		'.topic'		=> array ('topic-<topic:\\d+>(-<page:\\d+:1>(-<title:[-0-9A-Za-z]+>))', 'GET', 'call', 'handle_topic'),
		'.post.edit'	=> array ('edit-post-<post:\\d+>', 'GET,POST', 'call', 'handle_post')
	))
));

try
{
	$router->invoke ($_SERVER['REQUEST_METHOD'], $_GET['route'], $_REQUEST, array ($router));
}
catch (Queros\Failure $failure)
{
	$failure->send ();
}

?>
