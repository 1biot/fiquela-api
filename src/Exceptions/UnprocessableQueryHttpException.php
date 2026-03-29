<?php

namespace Api\Exceptions;

use Slim\Exception\HttpSpecializedException;

class UnprocessableQueryHttpException extends HttpSpecializedException
{
    protected $code = 422;
    protected $message = 'Unprocessable Content.';
    protected string $title = '422 Unprocessable Content';
    protected string $description = 'The request was well-formed but was unable to be followed due to semantic errors.';
}
