<?php

namespace Api\Middlewares;

use Api\Auth\AuthenticatorInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Exception\HttpUnauthorizedException;
use Slim\Psr7\Request;

readonly class AuthMiddleware
{
    public function __construct(private AuthenticatorInterface $authenticator) {}

    public function __invoke(Request $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $workspace = $this->authenticator->authenticate($request);
        if (!$workspace) {
            throw new HttpUnauthorizedException($request);
        }

        return $handler->handle($request->withAttribute('workspace', $workspace));
    }
}
