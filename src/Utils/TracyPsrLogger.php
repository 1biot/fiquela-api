<?php

namespace Api\Utils;

use Psr\Log\LoggerInterface;
use Tracy\Debugger;
use Tracy\ILogger;

class TracyPsrLogger implements LoggerInterface
{
    /**
     * @var ILogger
     */
    private $logger;

    public function __construct(ILogger $logger = null)
    {
        if ($logger === null) {
            $logger = Debugger::getLogger();
        }
        $this->logger = $logger;
    }

    /**
     * @inheritdoc
     */
    public function emergency($message, array $context = array()): void
    {
        $this->log(ILogger::EXCEPTION, $message, $context);
    }

    /**
     * @inheritdoc
     */
    public function alert($message, array $context = array()): void
    {
        $this->log(ILogger::WARNING, $message, $context);
    }

    /**
     * @inheritdoc
     */
    public function critical($message, array $context = array()): void
    {
        $this->log(ILogger::CRITICAL, $message, $context);
    }

    /**
     * @inheritdoc
     */
    public function error($message, array $context = array()): void
    {
        $this->log(ILogger::ERROR, $message, $context);
    }

    /**
     * @inheritdoc
     */
    public function warning($message, array $context = array()): void
    {
        $this->log(ILogger::WARNING, $message, $context);
    }

    /**
     * @inheritdoc
     */
    public function notice($message, array $context = array()): void
    {
        $this->log(ILogger::WARNING, $message, $context);
    }

    /**
     * @inheritdoc
     */
    public function info($message, array $context = array()): void
    {
        $this->log(ILogger::INFO, $message, $context);
    }

    /**
     * @inheritdoc
     */
    public function debug($message, array $context = array()): void
    {
        $this->log(ILogger::DEBUG, $message, $context);
    }

    /**
     * @inheritdoc
     */
    public function log($level, $message, array $context = array()): void
    {
        if (!empty($context)) {
            $message .= " " . self::stringifyContext($context);
        }
        $this->logger->log($message, $level);
    }

    /**
     * @param array $context
     * @return string
     */
    private static function stringifyContext(array $context): string
    {
        foreach ($context as $key => &$value) {
            if ($value instanceof \Exception) {
                $value = [
                    "message" => $value->getMessage(),
                    "code" => $value->getCode(),
                    "trace" => $value->getTraceAsString()
                ];
            }
            if (json_encode($value) === false) {
                user_error("Unsupported value", E_USER_WARNING);
                $value = null;
            }
        }
        return json_encode($context);
    }
}
