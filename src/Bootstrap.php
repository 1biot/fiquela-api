<?php

namespace Api;

use Api\Renderers\ErrorRenderer;
use Api\Utils\TracyPsrLogger;
use Contributte;
use Nette;
use Psr\Container;
use Slim;
use Slim\Interfaces\RouteCollectorProxyInterface;
use Tracy\Debugger;

class Bootstrap
{
    public static function initDebugger(): void
    {
        Debugger::$logDirectory = __DIR__ . "/../logs";
        Debugger::enable(mode: Debugger::Detect, logDirectory: __DIR__ . '/../logs');

        Debugger::$showBar = false;
        Debugger::$strictMode = Debugger::$productionMode === Debugger::Development
            ? true
            : (E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
        Debugger::$scream = Debugger::$productionMode === Debugger::Development;
    }

    public static function createContainer(): Container\ContainerInterface
    {
        $loader = new Nette\DI\ContainerLoader(__DIR__ . '/../temp', true);
        self::loadEnvironmentVariables();
        $class = $loader->load(function($compiler) {
            $compiler->loadConfig(__DIR__ . '/../config/app/config.neon');
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
        $app->add(Middlewares\CorsMiddleware::class);
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
        })->add(Middlewares\AuthMiddleware::class);
    }

    public static function addErrorMiddleware(Slim\App $app): void
    {
        $errorMiddleware = $app->addErrorMiddleware(
            Debugger::$productionMode === Debugger::Development,
            Debugger::$productionMode === Debugger::Production,
            Debugger::$productionMode === Debugger::Production,
            new TracyPsrLogger(Debugger::getLogger())
        );

        $errorHandler = $errorMiddleware->getDefaultErrorHandler();
        $errorHandler->registerErrorRenderer('application/json', ErrorRenderer::class);
        $errorHandler->forceContentType('application/json');
    }
}
