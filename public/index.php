<?php
declare(strict_types=1);

use Tracy\Debugger;

require __DIR__ . '/../vendor/autoload.php';

Debugger::enable();
Slim\Factory\AppFactory::setContainer(Api\Bootstrap::createContainer());
$app = Slim\Factory\AppFactory::create();

$app->addBodyParsingMiddleware();
Api\Bootstrap::addRouting($app);
$app->addErrorMiddleware(false, true, true);

$app->run();
