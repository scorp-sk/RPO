<?php

declare(strict_types=1);

use FastRoute\Dispatcher;
use FastRoute\RouteCollector;
use Rpo\Api\EntityController;
use Rpo\Api\SearchController;
use Rpo\Auth\TokenRepository;
use Rpo\Config;
use Rpo\Db;
use Rpo\Http\JsonResponse;
use Rpo\Http\Request;

require __DIR__ . '/vendor/autoload.php';

$request = Request::fromGlobals();

try {
    $config = Config::load(getenv('RPO_CONFIG') ?: __DIR__ . '/config.php');

    $dispatcher = FastRoute\simpleDispatcher(function (RouteCollector $r) use ($config): void {
        $r->addRoute('GET', $config->basePath . '/search', 'search');
        $r->addRoute('GET', $config->basePath . '/entity/{id}', 'entity');
    });
    $route = $dispatcher->dispatch($request->method, $request->path);

    if ($route[0] === Dispatcher::FOUND) {
        $pdo = Db::connect($config);
        $tokens = new TokenRepository($pdo);
        $tokenId = $tokens->authenticate($request->token);

        if ($tokenId === null) {
            $response = JsonResponse::unauthorized($request->uri);
        } else {
            $tokens->recordUse($tokenId);
            $response = match ($route[1]) {
                'search' => (new SearchController($pdo, $config->license, $config->searchLimit))($request),
                'entity' => (new EntityController($pdo, $config->license))($request, $route[2]['id']),
            };
        }
    } else {
        $response = $route[0] === Dispatcher::METHOD_NOT_ALLOWED
            ? JsonResponse::methodNotAllowed()
            : JsonResponse::error(404, 'Page Not Found', $request->uri);
    }
} catch (\Throwable $e) {
    error_log('RPO API: ' . $e);
    $response = JsonResponse::error(500, 'Internal Server Error', $request->uri);
}

$response->send();
