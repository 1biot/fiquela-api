<?php

namespace Api\Auth;

use Api\Workspace;
use Psr\Http\Message\ServerRequestInterface;

class AuthenticatorFactory
{
    /** @var AuthenticatorInterface[] */
    private array $authenticators = [];

    public function register(AuthenticatorInterface $authenticator): void
    {
        $this->authenticators[] = $authenticator;
    }

    public function authenticate(ServerRequestInterface $request): ?Workspace
    {
        foreach ($this->authenticators as $authenticator) {
            if ($authenticator->supports($request)) {
                return $authenticator->authenticate($request);
            }
        }

        return null;
    }
}
