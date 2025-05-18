<?php

namespace Api\Middlewares;

use Api\Auth\AuthenticatorFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Exception\HttpUnauthorizedException;
use Slim\Psr7\Request;

readonly class AuthMiddleware
{
    public function __construct(private AuthenticatorFactory $factory) {}

    public function __invoke(Request $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $workspace = $this->factory->authenticate($request);
        if (!$workspace) {
            throw new HttpUnauthorizedException($request);
        }

        return $handler->handle($request->withAttribute('workspace', $workspace));
    }
}
