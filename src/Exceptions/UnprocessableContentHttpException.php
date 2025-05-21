<?php

namespace Api\Exceptions;

use Nette\Schema\ValidationException;
use Slim\Exception\HttpException;

class UnprocessableContentHttpException extends HttpException
{
    /**
     * @var int
     */
    protected $code = 422;

    /**
     * @var string
     */
    protected $message = 'Unprocessable Content.';

    protected string $title = '422 Unprocessable Content';
    protected string $description = 'The request was well-formed but was unable to be followed due to semantic errors.';

    public function getDescription(): string
    {
        $previousException = $this->getPrevious();
        if ($previousException instanceof ValidationException) {
            return implode(",\n", $previousException->getMessages());
        }

        return $this->description;
    }
}
