<?php

namespace Psr\Log;

/**
 * Mock interface for PSR-3 logger.
 *
 * Defined here (rather than required from vendor/psr/log) because the
 * ChoreStrike test bootstrap ships with OCP class mocks only and does
 * not install psr/log locally; the Docker test environment pulls the
 * real package via Composer.
 */
interface LoggerInterface
{
    public function emergency($message, array $context = []);
    public function alert($message, array $context = []);
    public function critical($message, array $context = []);
    public function error($message, array $context = []);
    public function warning($message, array $context = []);
    public function notice($message, array $context = []);
    public function info($message, array $context = []);
    public function debug($message, array $context = []);
    public function log($level, $message, array $context = []);
}
