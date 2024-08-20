<?php

require_once(__DIR__ . '/../vendor/autoload.php');

return function ($context) {

    $context->log('Hello, Logs!');
    $context->error('Hello, Errors!');


    if ($context->req->method === 'GET') {
        return $context->res->send('Hello, World!');
    }

    return $context->res->json([
        'motto' => 'Build like a team of hundreds_',
        'learn' => 'https://appwrite.io/docs',
        'connect' => 'https://appwrite.io/discord',
        'getInspired' => 'https://builtwith.appwrite.io',
    ]);
};