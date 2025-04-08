<?php

namespace Api;

use Contributte;
use Nette;
use Psr\Container;
use Slim;
use Slim\Interfaces\RouteCollectorProxyInterface;

class Bootstrap
{
    public static function createContainer(): Container\ContainerInterface
    {
        $loader = new Nette\DI\ContainerLoader(__DIR__ . '/../temp', true);
        $class = $loader->load(function($compiler) {
            $compiler->loadConfig(__DIR__ . '/../config/api/config.neon');
        });
        return new Contributte\Psr11\Container(new $class);
    }

    public static function addRouting(Slim\App $app): void
    {
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
}
