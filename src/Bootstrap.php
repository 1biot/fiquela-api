<?php

namespace Api;

use Contributte;
use Nette;
use Psr\Container;
use Psr\Http\Server\RequestHandlerInterface;
use Slim;
use Slim\Interfaces\RouteCollectorProxyInterface;
use Slim\Psr7\Request;

class Bootstrap
{
    public static function createContainer(): Container\ContainerInterface
    {
        $loader = new Nette\DI\ContainerLoader(__DIR__ . '/../temp', true);
        self::loadEnvironmentVariables();
        $class = $loader->load(function($compiler) {
            $compiler->loadConfig(__DIR__ . '/../config/api/config.neon');
        });

        return new Contributte\Psr11\Container(new $class);
    }

    private static function loadEnvironmentVariables(): void
    {
        if (file_exists(__DIR__ . '/../.env')) {
            \Dotenv\Dotenv::createUnsafeImmutable(dirname(__DIR__))->load();
        }
    }

    public static function addRouting(Slim\App $app): void
    {
        self::addCorsPolicy($app);
        $app->get('/', function (Slim\Psr7\Request $request, Slim\Psr7\Response $response): Slim\Psr7\Response {
            $response = $response->withStatus(200)->withHeader('Content-Type', 'text/plain');
            $response->getBody()->write('FiQueLa API');
            return $response;
        });

        $app->group('/api/v1', function (RouteCollectorProxyInterface $group) {
            $group->get('/files', [Endpoints\Files::class, 'list']);
            $group->post('/files', [Endpoints\Files::class, 'insert']);

            $group->get('/files/{uuid}', [Endpoints\Files::class, 'detail']);
            $group->post('/files/{uuid}', [Endpoints\Files::class, 'update']);
            $group->delete('/files/{uuid}', [Endpoints\Files::class, 'delete']); // in progress

            $group->post('/query', Endpoints\Query::class);

            $group->get('/history[/{date:\d{4}-\d{2}-\d{2}}]', Endpoints\History::class);

            $group->get('/export/{hash}', Endpoints\Export::class);

            $group->get('/ping', function (Slim\Psr7\Request $request, Slim\Psr7\Response $response): Slim\Psr7\Response {
                $response = $response->withStatus(200)->withHeader('Content-Type', 'text/plain');
                $response->getBody()->write('pong');
                return $response;
            });
        })->add(Auth\AuthMiddleware::class);
    }

    private static function addCorsPolicy(Slim\App $app): void
    {
        $app->add(function (Request $request, RequestHandlerInterface $handler) {
            $origin = $request->getHeaderLine('Origin') ?: '*';

            if ($request->getMethod() === 'OPTIONS') {
                $response = new Slim\Psr7\Response();
            } else {
                $response = $handler->handle($request);
            }

            return $response
                ->withHeader('Access-Control-Allow-Origin', $origin)
                ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
                ->withHeader('Access-Control-Allow-Headers', 'X-Requested-With, Content-Type, Accept, Origin, Authorization')
                ->withHeader('Access-Control-Allow-Credentials', 'true');
        });

        $app->options('/{routes:.+}', function ($request, $response) {
            return $response->withStatus(200);
        });
    }
}
