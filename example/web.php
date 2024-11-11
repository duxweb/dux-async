<?php
declare(strict_types=1);

$file = __DIR__ . '/../vendor/autoload.php';

if (!is_file($file)) {
    exit('Please run "composer intall" to install the dependencies, Composer is not installed, please install <a href="https://getcomposer.org/" target="_blank">Composer</a>.');
}

require $file;


use Core\App;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

App::create(__DIR__, 1337);

// hello world
App::web()->get('/', function ($request,  $response, array $args) {
    return sendText($response, 'hello');
});


App::web()->post('/upload', function (ServerRequestInterface $request, ResponseInterface $response, array $args) {

    print_r($request->getUploadedFiles());

    return sendText($response, 'hello');
});

App::run();