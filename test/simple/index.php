<?php

isset ($_GET['route']) or die;

require ('../../src/queros.php');

function	handle_index ($router, $custom)
{
	return new Queros\HTTPResponse ('<h1>Index page</h1>
<ul>
	<li><a href="' . htmlspecialchars ($router->uri ('forum.topic', array ('topic' => 42, 'page' => 3))) . '">Go to topic #42 on page 3</a></li>
	<li><a href="' . htmlspecialchars ($router->uri ('forum.post.edit', array ('post' => 17))) . '">Edit post #17</a></li>
</ul>');
}

function	handle_topic ($router, $custom, $params)
{
	return new Queros\HTTPResponse ('<h1>Topic page</h1>
<p>Reading topic #' . htmlspecialchars ($params['topic']) . ' on page n°' . htmlspecialchars ($params['page']) . '.</p>
<a href="' . htmlspecialchars ($router->uri ('index')) . '">Back</a>');
}

function	handle_post ($router, $custom, $params)
{
	$content = '<h1>Post page</h1>';

	if (isset ($_POST['text']))
		$content .= '<p>Post #' . htmlspecialchars ($params['post']) . ' has been updated with text "' . htmlspecialchars ($_POST['text']) . '".</p>';
	else
	{
		$content .= '<p>Editing post #' . htmlspecialchars ($params['post']) . ':</p>
<form action="' . htmlspecialchars ($router->uri ('forum.post.edit', $params)) . '" method="POST">
	<textarea name="text" cols="80" rows="6"></textarea><br />
	<input type="submit" value="OK" />
</form>';
	}

	$content .= '<a href="' . htmlspecialchars ($router->uri ('index')) . '">Back</a>';

	return new Queros\HTTPResponse ($content);
}

$router = new Queros\Router (array
(
	'index'	=> array ('(index)',	'func:handle_index'),
	'forum'	=> array ('forum-',		array
	(
		'.topic'		=> array ('topic-<topic:\\\\d+>(-<page:\\\\d+:1>(-<title:[-0-9A-Za-z]+>))',	'func:handle_topic:topic:page'),
		'.post.edit'	=> array ('edit-post-<post:\\\\d+>',										'func:handle_post:post')
	))
));

try
{
	$router->call ($_GET['route'])->send ();
}
catch (Queros\HTTPException $exception)
{
	$exception->send ();
}

?>
