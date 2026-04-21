<?php
declare(strict_types=1);

namespace Psr\Log;

/**
 * Describes log levels defined by RFC 5424.
 *
 * This is a manual copy of the PSR-3 LogLevel class.
 * Source: https://github.com/php-fig/log (MIT Licence)
 *
 * @see https://www.php-fig.org/psr/psr-3/
 */
class LogLevel
{
    public const EMERGENCY = 'emergency';
    public const ALERT     = 'alert';
    public const CRITICAL  = 'critical';
    public const ERROR     = 'error';
    public const WARNING   = 'warning';
    public const NOTICE    = 'notice';
    public const INFO      = 'info';
    public const DEBUG     = 'debug';
}
