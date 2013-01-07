<?php

isset ($_GET['route']) or die;

require ('../../src/queros.php');

function	handle_index ($queros)
{
	ob_start ();
?>
	<h1>Index page</h1>
	<ul>
		<li><a href="forum/topic-42?page=3">Go to topic #42 on page 3</a></li>
		<li><a href="forum/edit-post?id=17">Edit post #17</a></li>
	</ul>
<?php
	return new Queros\HTTPReply (ob_get_clean ());
}

function	handle_topic ($queros, $id, $page)
{
	ob_start ();
?>
	<h1>Topic page</h1>
	<p>Reading topic #<?php echo htmlspecialchars ($id); ?> on page n°<?php echo htmlspecialchars ($page) ?>.</p>
	<a href="..">Back</a>
<?php
	return new Queros\HTTPReply (ob_get_clean ());
}

function	handle_post ($queros, $id, $text)
{
	ob_start ();
?>
	<h1>Post page</h1>
<?php
	if (isset ($text))
	{
?>
		<p>Post #<?php echo htmlspecialchars ($id); ?> has been updated with text "<?php echo htmlspecialchars ($text); ?>".</p>
<?php
	}
	else
	{
?>
		<p>Editing post #<?php echo $id; ?>:</p>
		<form action="<?php echo htmlspecialchars ($queros->resolve ('forum.post.edit')); ?>" method="POST">
			<textarea name="text" cols="80" rows="6"></textarea><br />
			<input type="submit" value="OK" />
		</form>
<?php
	}
?>
	<a href="..">Back</a>
<?php

	return new Queros\HTTPReply (ob_get_clean ());
}

$router = new Queros\Router (array
(
	'index'	=> array ('@^(index)?$@',		'call:handle_index'),
	'forum'	=> array ('@^forum/@',			array
	(
		'.topic'		=> array ('@^topic-([0-9]+)$@',	'call:handle_topic',	array ('match:1', 'get:page')),
		'.post.edit'	=> array ('@^edit-post$@',		'call:handle_post',		array ('get:id', 'post:text'))
	))
));

try
{
	$router->dispatch ($_GET['route']);
}
catch (Exception $exception)
{
	ob_end_clean ();

	echo $exception;
}

?>
