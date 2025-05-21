<?php

namespace Api\Schemas;

use Nette\Schema\Expect;

class Auth implements Schema
{

    public function getSchema(): mixed
    {
        return Expect::array(
            [
                'username' => Expect::string()->required(),
                'password' => Expect::string()->required()
                    ->min(8)
                    /*->pattern('~^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[!@#$%^&*()_+]).+$~')
                    ->assert(
                        fn ($value) => is_string($value),
                        'Password must contain at least one letter, one uppercase letter, one number'
                        .' and one special character (!@#$%^&*()_+])'
                    )*/,
            ]
        );
    }
}
