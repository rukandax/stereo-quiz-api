<?php

use Slim\App;
use Psr7Middlewares\Middleware\TrailingSlash;

return function (App $app) {
    $app->add(new TrailingSlash(false));
    
    $app->add(function($request, $response, $next) {
        $response = $next($request, $response);
        return $response
                    ->withHeader('Access-Control-Allow-Origin', '*')
                    ->withHeader('Access-Control-Allow-Headers', 'X-Requested-With, Content-Type, Accept, Origin, Authorization')
                    ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, PATCH');
    });
};
