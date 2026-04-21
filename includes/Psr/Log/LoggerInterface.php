<?php
declare(strict_types=1);

namespace Psr\Log;

/**
 * Describes a logger instance.
 *
 * This is a manual copy of the PSR-3 LoggerInterface.
 * Source: https://github.com/php-fig/log (MIT Licence)
 *
 * The message MUST be a string or object implementing __toString().
 *
 * The message MAY contain placeholders in the form: {foo} where foo will be
 * replaced by the context data in key "foo".
 *
 * The context array can contain arbitrary data. The only assumption that can
 * be made by implementors is that if an Exception instance is given to produce
 * a stack trace, it MUST be in a key named "exception".
 *
 * @see https://www.php-fig.org/psr/psr-3/
 */
interface LoggerInterface
{
    /**
     * System is unusable.
     *
     * @param string|\Stringable $message
     * @param array<mixed>       $context
     */
    public function emergency(string|\Stringable $message, array $context = []): void;

    /**
     * Action must be taken immediately.
     *
     * Example: Entire website down, database unavailable, etc.
     * This should trigger SMS alerts and wake you up.
     *
     * @param string|\Stringable $message
     * @param array<mixed>       $context
     */
    public function alert(string|\Stringable $message, array $context = []): void;

    /**
     * Critical conditions.
     *
     * Example: Application component unavailable, unexpected exception.
     *
     * @param string|\Stringable $message
     * @param array<mixed>       $context
     */
    public function critical(string|\Stringable $message, array $context = []): void;

    /**
     * Runtime errors that do not require immediate action but should typically
     * be logged and monitored.
     *
     * @param string|\Stringable $message
     * @param array<mixed>       $context
     */
    public function error(string|\Stringable $message, array $context = []): void;

    /**
     * Exceptional occurrences that are not errors.
     *
     * Example: Use of deprecated APIs, poor use of an API, undesirable things
     * that are not necessarily wrong.
     *
     * @param string|\Stringable $message
     * @param array<mixed>       $context
     */
    public function warning(string|\Stringable $message, array $context = []): void;

    /**
     * Normal but significant events.
     *
     * @param string|\Stringable $message
     * @param array<mixed>       $context
     */
    public function notice(string|\Stringable $message, array $context = []): void;

    /**
     * Interesting events.
     *
     * Example: User logs in, SQL logs.
     *
     * @param string|\Stringable $message
     * @param array<mixed>       $context
     */
    public function info(string|\Stringable $message, array $context = []): void;

    /**
     * Detailed debug information.
     *
     * @param string|\Stringable $message
     * @param array<mixed>       $context
     */
    public function debug(string|\Stringable $message, array $context = []): void;

    /**
     * Logs with an arbitrary level.
     *
     * @param mixed              $level   One of the LogLevel constants
     * @param string|\Stringable $message
     * @param array<mixed>       $context
     *
     * @throws \Psr\Log\InvalidArgumentException if $level is not a valid level
     */
    public function log(mixed $level, string|\Stringable $message, array $context = []): void;
}
