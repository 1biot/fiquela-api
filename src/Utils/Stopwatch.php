<?php

namespace Api\Utils;

class Stopwatch
{
    private static array $startTimes = [];

    public static function start(string $name = '__default__'): void
    {
        self::$startTimes[$name] = microtime(true);
    }

    public static function stop(string $name = '__default__'): float
    {
        if (!isset(self::$startTimes[$name])) {
            throw new \RuntimeException("Stopwatch '$name' was not started.");
        }

        $duration = microtime(true) - self::$startTimes[$name];
        unset(self::$startTimes[$name]);
        return $duration;
    }

    public static function lap(string $name = '__default__'): float
    {
        if (!isset(self::$startTimes[$name])) {
            throw new \RuntimeException("Stopwatch '$name' was not started.");
        }

        return microtime(true) - self::$startTimes[$name];
    }
}
