<?php

namespace Api\Auth;

use Api;
use Psr;

class AuthenticatorFactory implements AuthenticatorInterface
{
    /** @var AuthenticatorInterface[] */
    private array $authenticators = [];
    private ?AuthenticatorInterface $loginProvider = null;

    public function register(AuthenticatorInterface $authenticator, bool $asLoginProvider = false): void
    {
        if ($asLoginProvider && $this->loginProvider === null) {
            $this->loginProvider = $authenticator;
        } elseif ($asLoginProvider && $this->loginProvider instanceof AuthenticatorInterface) {
            throw new \RuntimeException('Only one login provider is allowed');
        }

        $this->authenticators[] = $authenticator;
    }

    public function supports(Psr\Http\Message\ServerRequestInterface $request): bool
    {
        foreach ($this->authenticators as $authenticator) {
            if ($authenticator->supports($request)) {
                return true;
            }
        }

        return false;
    }

    public function authenticate(Psr\Http\Message\ServerRequestInterface $request): ?Api\Workspace
    {
        try {
            return $this->getAuthenticator($request)->authenticate($request);
        } catch (\Exception $e) {
            return null;
        }
    }

    public function login(array $credentials): ?string
    {
        return $this->loginProvider?->login($credentials);
    }

    public function getLoginProvider(): ?AuthenticatorInterface
    {
        return $this->loginProvider;
    }

    private function getAuthenticator(Psr\Http\Message\ServerRequestInterface $request): AuthenticatorInterface
    {
        if (count($this->authenticators) === 0) {
            throw new \RuntimeException('No authenticators registered');
        }

        foreach ($this->authenticators as $authenticator) {
            if ($authenticator->supports($request)) {
                return $authenticator;
            }
        }

        throw new \RuntimeException('No suitable authenticator found');
    }
}
