<?php

require __DIR__ . '/../src/queros.php';

function route_index($query)
{
    return 'route_index(' . $query->method . ')';
}

function route_option($query)
{
    $optional = $query->get_or_default('optional', '');
    $something = $query->get_or_default('something', '');

    return 'route_option(' . $query->method . ", '$something', '$optional')";
}

function route_param_first($query)
{
    $mandatory = $query->get_or_fail('mandatory');
    $optional = (int)$query->get_or_fail('optional');
    $string = $query->get_or_default('string', '');

    return 'route_param_first(' . $query->method . ", $mandatory, $optional, '$string')";
}

function route_param_second($query)
{
    return 'route_param_second(' . $query->method . ')';
}

$test = new Queros\Router(array(
    '!prefix' => '/',
    array('anonymous', 'GET', 'data', 'anonymous'),
    'callback.' => array('callback', array(
        'data' => array('/data', 'GET', 'data', '17'),
        'echo' => array('/echo', 'GET', 'echo', '17'),
        'unknown' => array('/unknown', 'GET', 'unknown')
    )),
    'custom.' => array('custom', array(
        'one' => array('/brace', 'GET', 'custom', '{{', '}}'),
        'two' => array('/bracket', 'GET', 'custom', '[[', ']]')
    )),
    'escape' => array('<escape:/truc/>', 'GET', 'data', 'escape'),
    'goto.' => array('goto', array(
        '301' => array('/301', 'GET', 'goto', 'callback.data', array(), true, true),
        '302' => array('/302', 'GET', 'goto', 'callback.data'),
        'parameters' => array('/parameters', 'GET', 'goto', 'callback.data', array('a' => '1', 'b' => '$a', 'c' => '=3', 'd' => '$'))
    )),
    'index' => array('(index)', 'GET', 'call', 'route_index'),
    'method.' => array('method', array(
        'any' => array('/any', '', 'data'),
        'put' => array('/put', 'PUT', 'data')
    )),
    'name.' => array('name', array(
        '+append' => array('/append', 'GET', 'data'),
        '!ignore' => array('/ignore', 'GET', 'data'),
        '=reset' => array('/reset', 'GET', 'data')
    )),
    'option1' => array('(<something>)followed by(<optional>)', '', 'call', 'route_option'),
    'option2.' => array('option', array(
        'leaf1' => array('(/<a:\\d+>)/<b:\\d+>', 'GET', 'data', 'option2'),
        'leaf2' => array('(/<a:\\d+>)/x', 'GET', 'data', 'option2'),
    )),
    'overlap1' => array('overlap', 'GET', 'data', 'overlap1'),
    'overlap2.' => array('overlap', array(
        'leaf' => array('/2', 'GET', 'data', 'overlap2')
    )),
    'overlap3.' => array('overlap', array(
        'leaf' => array('/3', 'GET', 'data', 'overlap3')
    )),
    'param.' => array('param/', array(
        'first' => array('first-<mandatory:\\d+>(/<optional:\\d+:1>(-<string:[-0-9A-Za-z]+>))', 'GET', 'call', 'route_param_first'),
        'second' => array('second', 'GET,POST', 'call', 'route_param_second')
    )),
    'tree.' => array('tree/', array(
        '!prefix' => 'begin1-',
        '!suffix' => '-end1',
        'leaf' => array('leaf', '', 'data', 'leaf1'),
        'node.' => array('node/', array(
            '!prefix' => 'begin2-',
            '!suffix' => '-end2',
            'leaf' => array('leaf', '', 'data', 'leaf2')
        ))
    ))
));

header('Content-Type: text/plain');

ini_set('assert.exception', true);

function assert_exception($callback, $message_pattern)
{
    try {
        $callback();

        assert(false, 'callback should have raised an exception');
    } catch (Exception $exception) {
        assert(preg_match($message_pattern, $exception->getMessage()), 'exception message should match "' . $message_pattern . '" but was: ' . $exception->getMessage());
    }
}

function assert_headers($callback, $expected_headers)
{
    $callback();
    $actual_headers = headers_list();

    header_remove();

    foreach ($expected_headers as $pattern) {
        assert(count(array_filter($actual_headers, function ($header) use ($pattern) {
            return preg_match($pattern, $header);
        })) === 1, 'some header should match "' . $pattern . '" but headers were: ' . var_export($actual_headers, true));
    }
}

// Register custom callback
$test->register('custom', function ($request, $invoke_arguments, $prefix, $suffix) {
    $array = $invoke_arguments[0];
    $offset = $invoke_arguments[1];

    return $prefix . $array[$offset] . $suffix;
});

// Query validation, valid route
assert($test->match('GET', '/') !== null);

// Query validation, invalid route
assert($test->match('GET', '/not-exists') === null);

// Route resolution, standard callbacks
assert($test->invoke('GET', '/callback/data') === '17');
ob_start();
$test->invoke('GET', '/callback/echo');
assert(ob_get_clean() === '17');

// Route resolution, unknown callback
assert_exception(function () use ($test) {
    $test->invoke('GET', '/callback/unknown');
}, '"unknown"');

// Route resolution, standard usage
assert($test->invoke('GET', '/') === 'route_index(GET)');
assert($test->invoke('GET', '/index') === 'route_index(GET)');
assert($test->invoke('GET', '/param/first-17/3') === "route_param_first(GET, 17, 3, '')");
assert($test->invoke('GET', '/param/first-42/5-my-topic-title') === "route_param_first(GET, 42, 5, 'my-topic-title')");
assert($test->invoke('GET', '/param/second') === 'route_param_second(GET)');
assert($test->invoke('POST', '/param/second') === 'route_param_second(POST)');
assert($test->invoke('PUT', '/followed by') === "route_option(PUT, '', '')");
assert($test->invoke('GET', '/XXXfollowed byYYY') === "route_option(GET, 'XXX', 'YYY')");
assert($test->invoke('GET', '/option/42/17') === 'option2');
assert($test->invoke('GET', '/option/17') === 'option2');
assert($test->invoke('GET', '/anonymous') === 'anonymous');

// Route resolution, method matching
assert($test->match('GET', '/method/any') !== null);
assert($test->match('PUT', '/method/any') !== null);
assert($test->match('GET', '/method/put') === null);
assert($test->match('PUT', '/method/put') !== null);

// Route resolution, optional parameters
assert($test->invoke('GET', '/param/first-52') === "route_param_first(GET, 52, 1, '')");
assert($test->invoke('GET', '/param/first-52/1') === "route_param_first(GET, 52, 1, '')");

// Route resolution, overlapping routes
assert($test->invoke('GET', '/overlap') === 'overlap1');
assert($test->invoke('GET', '/overlap/2') === 'overlap2');
assert($test->invoke('GET', '/overlap/3') === 'overlap3');

// Route resolution, custom callback
assert($test->invoke('GET', '/custom/brace', array(), array(array(0, 17, 0), 1)) === '{{17}}');
assert($test->invoke('GET', '/custom/bracket', array(), array(array(42), 0)) === '[[42]]');

// Route resolution, redirection
assert_headers(function () use ($test) {
    $test->invoke('GET', '/goto/301');
}, array('@^Location: https?://[.a-z0-9]+/callback/data$@'));

assert_headers(function () use ($test) {
    $test->invoke('GET', '/goto/302');
}, array('@^Location: https?://[.a-z0-9]+/callback/data$@'));

assert_headers(function () use ($test) {
    $test->invoke('GET', '/goto/parameters', array('a' => 2, 'd' => 4));
}, array('@^Location: https?://[.a-z0-9]+/callback/data\\?a=1&b=2&c=3&d=4$@'));

// Route resolution, exception on unknown route
assert_exception(function () use ($test) {
    $test->invoke('GET', '/param/first-17/');
}, '@No route found to "GET /param/first-17/".@');
assert_exception(function () use ($test) {
    $test->invoke('GET', '/not-exists');
}, '@No route found to "GET /not-exists".@');
assert_exception(function () use ($test) {
    $test->invoke('POST', '/');
}, '@No route found to "POST /".@');
assert_exception(function () use ($test) {
    $test->invoke('PUT', '/param/second');
}, '@No route found to "PUT /param/second".@');

// Route resolution, prefixes and suffixes
assert($test->invoke('GET', '/tree/begin1-leaf-end1') === 'leaf1');
assert($test->invoke('GET', '/tree/begin1-node/begin2-leaf-end2-end1') === 'leaf2');

// Route resolution, escaped delimiter
assert($test->invoke('GET', '//truc/') === 'escape');

// URL generation, standard usage
assert($test->url('index') === '/');
assert($test->url('index', array('empty' => '')) === '/?empty');
assert($test->url('param.first', array('mandatory' => 15, 'optional' => 2, 'string' => 'test')) === '/param/first-15/2-test');
assert($test->url('param.second') === '/param/second');
assert($test->url('option1', array('something' => '.~', 'optional' => '~.')) == '/.~followed by~.');

// URL generation, extra parameters
assert($test->url('index', array('other' => 'key', 'in' => 'query-string')) === '/?other=key&in=query-string');
assert($test->url('index', array('scalar' => '43', 'complex' => array(0 => 'first', 1 => array('a' => 'sub-second-1', 'b' => 'sub-second-2')))) === '/?scalar=43&complex%5B0%5D=first&complex%5B1%5D%5Ba%5D=sub-second-1&complex%5B1%5D%5Bb%5D=sub-second-2');

// URL generation, name composition
assert($test->url('reset') === '/name/reset');
assert($test->url('name.') === '/name/ignore');
assert($test->url('name.append') === '/name/append');

// URL generation, optional parameters
assert($test->url('param.first', array('mandatory' => 15)) === '/param/first-15');
assert($test->url('param.first', array('mandatory' => 15, 'optional' => 1)) === '/param/first-15');
assert($test->url('param.first', array('mandatory' => 15, 'string' => 'some-title')) === '/param/first-15/1-some-title');

// URL generation, exception on missing mandatory parameter
assert_exception(function () use ($test) {
    $test->url('param.first', array('optional' => 1, 'string' => 'test'));
}, '@can\'t build URL to incomplete route "param.first"@');

// URL generation, prefixes and suffixes
assert($test->url('tree.leaf') === '/tree/begin1-leaf-end1');
assert($test->url('tree.node.leaf') === '/tree/begin1-node/begin2-leaf-end2-end1');

// URL generation, unknown routes
assert_exception(function () use ($test) {
    $test->url('undefined');
}, '/can\'t build URL to unknown route/');
assert_exception(function () use ($test) {
    $test->url('0');
}, '/can\'t build URL to unknown route/');

echo 'Tests OK!';
