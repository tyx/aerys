<?php

namespace Aerys;

use Psr\Log\{
    LogLevel,
    LoggerInterface as PsrLogger
};

abstract class Logger implements PsrLogger {
    const DEBUG = LogLevel::DEBUG;
    const INFO = LogLevel::INFO;
    const NOTICE = LogLevel::NOTICE;
    const WARNING = LogLevel::WARNING;
    const ERROR = LogLevel::ERROR;
    const CRITICAL = LogLevel::CRITICAL;
    const ALERT = LogLevel::ALERT;
    const EMERGENCY = LogLevel::EMERGENCY;
    const LEVELS = [
        self::DEBUG => 1,
        self::INFO => 2,
        self::NOTICE => 3,
        self::WARNING => 4,
        self::ERROR => 5,
        self::CRITICAL => 6,
        self::ALERT => 7,
        self::EMERGENCY => 8,
    ];

    private $outputLevel = 0;

    abstract protected function dolog($level, $message, array $context = []);

    final public function emergency($message, array $context = []) {
        return $this->log(self::EMERGENCY, $message);
    }

    final public function alert($message, array $context = []) {
        return $this->log(self::ALERT, $message);
    }

    final public function critical($message, array $context = []) {
        return $this->log(self::CRITICAL, $message);
    }

    final public function error($message, array $context = []) {
        return $this->log(self::ERROR, $message);
    }

    final public function warning($message, array $context = []) {
        return $this->log(self::WARNING, $message);
    }

    final public function notice($message, array $context = []) {
        return $this->log(self::NOTICE, $message);
    }

    final public function info($message, array $context = []) {
        return $this->log(self::INFO, $message);
    }

    final public function debug($message, array $context = []) {
        return $this->climate->out(self::DEBUG, $message);
    }

    final public function log($level, $message, array $context = []) {
        if ($this->canEmit($level)) {
            $this->doLog($level, $message, $context);
        }
    }

    private function canEmit(string $logLevel) {
        return isset(self::LEVELS[$logLevel])
            ? ($this->outputLevel <= self::LEVELS[$logLevel])
            : false;
    }

    final protected function setOutputLevel(int $outputLevel) {
        if ($outputLevel < min(self::LEVELS)) {
            $outputLevel = min(self::LEVELS);
        } elseif ($outputLevel > max(self::LEVELS)) {
            $outputLevel = max(self::LEVELS);
        }

        $this->outputLevel = $outputLevel;
    }
}