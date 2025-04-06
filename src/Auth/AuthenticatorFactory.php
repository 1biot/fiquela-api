<?php

namespace Api\Auth;

use Api;
use Psr;

class AuthenticatorFactory
{
    /** @var AuthenticatorInterface[] */
    private array $authenticators = [];

    public function register(AuthenticatorInterface $authenticator): void
    {
        $this->authenticators[] = $authenticator;
    }

    public function authenticate(Psr\Http\Message\ServerRequestInterface $request): ?Api\Workspace
    {
        foreach ($this->authenticators as $authenticator) {
            if ($authenticator->supports($request)) {
                return $authenticator->authenticate($request);
            }
        }

        return null;
    }
}
