<?php

namespace Api\Auth;

use Api;
use Psr;

class TokenAuthenticator implements AuthenticatorInterface
{
    private string $token;
    private Api\Workspace $workspace;

    public function __construct(string $apiKey, Api\Workspace $workspace)
    {
        $this->token = password_hash($apiKey, PASSWORD_BCRYPT);
        $this->workspace = $workspace;
    }

    public function supports(Psr\Http\Message\ServerRequestInterface $request): bool
    {
        return str_starts_with($request->getHeaderLine('Authorization'), 'Bearer ');
    }

    public function authenticate(Psr\Http\Message\ServerRequestInterface $request): ?Api\Workspace
    {
        $token = substr($request->getHeaderLine('Authorization'), 7);
        if (password_verify($token, $this->token)) {
            return $this->workspace;
        }

        return null;
    }
}
