<?php

declare(strict_types=1);

namespace TorqueLogs\Logging;

use PDO;

/**
 * @deprecated This class targeted the old wide `upload_requests` table which
 * no longer exists in the normalized schema.
 *
 * Audit logging is now handled directly inside upload_data.php by writing to:
 *   - upload_requests_raw   (one row per HTTP request, inserted before the
 *                            main transaction so it survives rollbacks)
 *   - upload_requests_processed  (summary row, inserted after the sensor loop)
 *
 * This file is kept for reference during the migration and will be removed
 * once the codebase is fully migrated. Do NOT call AuditLogger::record() —
 * it will throw a RuntimeException if called.
 */
class AuditLogger
{
    /**
     * @deprecated Use upload_data.php direct inserts into upload_requests_raw
     *             and upload_requests_processed instead.
     * @throws \RuntimeException always
     */
    public static function record(
        PDO $pdo,
        array $get,
        string $result,
        int $sensorCount,
        int $newColumns,
        array $sensorMap = [],
        ?string $error = null,
        ?string $rawQuery = null
    ): void {
        throw new \RuntimeException(
            'AuditLogger::record() is deprecated. '
            . 'The upload_requests table no longer exists. '
            . 'Audit logging is handled by upload_data.php directly.'
        );
    }
}
