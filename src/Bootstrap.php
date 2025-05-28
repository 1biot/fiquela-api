<?php

namespace Api;

use Api\Renderers\ErrorRenderer;
use Api\Utils\CorsAwareErrorHandler;
use Contributte;
use Nette;
use Psr\Container;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;
use Slim;
use Slim\Interfaces\RouteCollectorProxyInterface;

class Bootstrap
{
    public static function createContainer(): Container\ContainerInterface
    {
        $loader = new Nette\DI\ContainerLoader(__DIR__ . '/../temp', true);
        self::loadEnvironmentVariables();
        self::setupErrorReporting();
        $class = $loader->load(function(Nette\DI\Compiler $compiler) {
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

        $app->group('/api', function (RouteCollectorProxyInterface $apiGroup) {
            $apiGroup->get(
                '/ping',
                function (Slim\Psr7\Request $request, Slim\Psr7\Response $response): Slim\Psr7\Response {
                    $response = $response->withStatus(200)->withHeader('Content-Type', 'text/plain');
                    $response->getBody()->write('pong');
                    return $response;
                }
            );

            $apiGroup->group('/auth', function (RouteCollectorProxyInterface $authGroup) {
                $authGroup->post('/login', [Endpoints\Auth::class, 'login']);
                $authGroup->post('/revoke', [Endpoints\Auth::class, 'revoke']);
            })->add(Middlewares\ApiVersionHeaderMiddleware::class);

            $apiGroup->get('/status', Endpoints\Status::class)
                ->add(Middlewares\ApiVersionHeaderMiddleware::class)
                ->add(Middlewares\AuthMiddleware::class);

            $version = self::getVersion();
            $apiGroup->group(
                self::getVersionEndpoint($version), // @return /v1
                function(RouteCollectorProxyInterface $versionGroup) use ($version) {
                    $versionGroup->get('/files', [Endpoints\Files::class, 'list']);
                    $versionGroup->post('/files', [Endpoints\Files::class, 'insert']);

                    $versionGroup->get('/files/{uuid}', [Endpoints\Files::class, 'detail']);
                    $versionGroup->post('/files/{uuid}', [Endpoints\Files::class, 'update']);
                    $versionGroup->delete('/files/{uuid}', [Endpoints\Files::class, 'delete']); // in progress

                    $versionGroup->post('/query', Endpoints\Query::class);
                    $versionGroup->get('/export/{hash}', Endpoints\Export::class);

                    $versionGroup->get('/history[/{date:\d{4}-\d{2}-\d{2}}]', Endpoints\History::class);
                }
            )->add(Middlewares\ApiVersionHeaderMiddleware::class)
                ->add(Middlewares\AuthMiddleware::class);
        });
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public static function addErrorMiddleware(Slim\App $app): void
    {
        $isDevEnvironment = !self::isProduction();
        $errorMiddleware = $app->addErrorMiddleware(
            $isDevEnvironment,
            true,
            true,
            $app->getContainer()->get(LoggerInterface::class)
        );

        $corsAwareErrorHandler = new CorsAwareErrorHandler(
            $app->getCallableResolver(),
            $app->getResponseFactory(),
            $app->getContainer()->get(LoggerInterface::class)
        );
        $errorMiddleware->setDefaultErrorHandler($corsAwareErrorHandler);
        $errorHandler = $errorMiddleware->getDefaultErrorHandler();
        $errorHandler->registerErrorRenderer('application/json', ErrorRenderer::class);
        $errorHandler->forceContentType('application/json');
    }

    public static function getVersion(): string
    {
        return 'v1';
    }

    public static function isProduction(): bool {
        return ($_ENV['API_ENV'] ?? '') === 'prod';
    }

    private static function getVersionEndpoint(string $version): string
    {
        return sprintf('/%s', $version);
    }

    private static function setupErrorReporting(): void
    {
        if (self::isProduction()) {
            error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
            ini_set('display_errors', '0');
        } else {
            error_reporting(E_ALL);
            ini_set('display_errors', '1');
        }

        ini_set('log_errors', '1');
    }
}
