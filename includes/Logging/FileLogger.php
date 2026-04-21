<?php
declare(strict_types=1);

namespace TorqueLogs\Logging;

require_once __DIR__ . '/../Psr/Log/LoggerInterface.php';
require_once __DIR__ . '/../Psr/Log/LogLevel.php';

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * PSR-3 compliant file-based logger.
 *
 * Writes one JSON object per line to a daily rotating log file.
 * The log directory is created automatically on first write.
 * A .htaccess deny rule is injected if the directory is newly created.
 *
 * Log files are named:  {prefix}_YYYY-MM-DD.log
 *
 * Origin: upload_data.php → log_torque_request()
 *
 * @see https://www.php-fig.org/psr/psr-3/
 */
class FileLogger implements LoggerInterface
{
    /**
     * @param string $logDir  Absolute path to the log directory.
     * @param string $prefix  Filename prefix (e.g. 'torque_upload').
     */
    public function __construct(
        private readonly string $logDir,
        private readonly string $prefix = 'app'
    ) {}

    // -------------------------------------------------------------------------
    // PSR-3 convenience methods (delegate to log())
    // -------------------------------------------------------------------------

    /** @inheritDoc */
    public function emergency(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::EMERGENCY, $message, $context);
    }

    /** @inheritDoc */
    public function alert(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::ALERT, $message, $context);
    }

    /** @inheritDoc */
    public function critical(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::CRITICAL, $message, $context);
    }

    /** @inheritDoc */
    public function error(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::ERROR, $message, $context);
    }

    /** @inheritDoc */
    public function warning(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::WARNING, $message, $context);
    }

    /** @inheritDoc */
    public function notice(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::NOTICE, $message, $context);
    }

    /** @inheritDoc */
    public function info(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::INFO, $message, $context);
    }

    /** @inheritDoc */
    public function debug(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::DEBUG, $message, $context);
    }

    // -------------------------------------------------------------------------
    // Core log method
    // -------------------------------------------------------------------------

    /**
     * Log a message at the given level.
     *
     * The $context array is merged into the JSON entry. Placeholder substitution
     * follows PSR-3 §1.2: {key} tokens in $message are replaced with
     * (string) $context['key'] when present.
     *
     * @param  mixed              $level    A \Psr\Log\LogLevel constant string.
     * @param  string|\Stringable $message
     * @param  array<string,mixed> $context
     * @return void
     */
    public function log(mixed $level, string|\Stringable $message, array $context = []): void
    {
        $this->ensureLogDir();

        $interpolated = $this->interpolate((string) $message, $context);

        // Structured fields take priority; $context values fill remaining keys.
        $entry = [
            'ts'      => date('Y-m-d H:i:s'),
            'level'   => $level,
            'message' => $interpolated,
            'ip'      => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        ] + $context;

        $logfile = $this->logDir . '/' . $this->prefix . '_' . date('Y-m-d') . '.log';
        file_put_contents($logfile, json_encode($entry, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Create the log directory if it does not exist, and add an .htaccess
     * deny rule to prevent direct browser access to log files.
     *
     * @return void
     */
    private function ensureLogDir(): void
    {
        if (is_dir($this->logDir)) {
            return;
        }

        mkdir($this->logDir, 0750, true);
        file_put_contents($this->logDir . '/.htaccess', "Deny from all\n");
    }

    /**
     * Replace {placeholder} tokens in $message with values from $context.
     *
     * Per PSR-3, any value that can be cast to string is interpolated.
     * Placeholders whose keys are absent in $context are left unchanged.
     *
     * @param  string              $message
     * @param  array<string,mixed> $context
     * @return string
     */
    private function interpolate(string $message, array $context): string
    {
        $replace = [];

        foreach ($context as $key => $value) {
            if (is_string($value) || (is_object($value) && method_exists($value, '__toString'))) {
                $replace['{' . $key . '}'] = (string) $value;
            }
        }

        return strtr($message, $replace);
    }
}
