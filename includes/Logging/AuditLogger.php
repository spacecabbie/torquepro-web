<?php

declare(strict_types=1);

namespace TorqueLogs\Logging;

use PDO;
use Throwable;

/**
 * Records a row in the upload_requests audit table for every inbound
 * Torque upload attempt.
 *
 * All writes are wrapped in a try/catch so a logging failure never
 * interrupts the upload response sent back to the Torque app.
 */
class AuditLogger
{
    /**
     * Insert one audit row.
     *
     * @param  PDO                  $pdo
     * @param  array<string, mixed> $get          The $_GET superglobal array from the upload request.
     * @param  string               $result       'ok' | 'error' | 'skipped'
     * @param  int                  $sensorCount
     * @param  int                  $newColumns
     * @param  array<string, mixed> $sensorMap    Optional map of sensor key → label.
     * @param  string|null          $error        Human-readable error message, if any.
     * @param  string|null          $rawQuery     Raw QUERY_STRING from $_SERVER.
     * @return void
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
        try {
            $sensorJson = $sensorMap
                ? json_encode($sensorMap, JSON_UNESCAPED_UNICODE)
                : null;

            $stmt = $pdo->prepare(
                "INSERT INTO `upload_requests`
                    (ip, torque_id, eml, app_version, session, data_ts,
                     sensor_count, sensor_data, new_columns, profile_name, result, error_msg, raw_query_string)
                 VALUES
                    (:ip, :torque_id, :eml, :app_version, :session, :data_ts,
                     :sensor_count, :sensor_data, :new_columns, :profile_name, :result, :error_msg, :raw_query_string)"
            );
            $stmt->execute([
                ':ip'           => $_SERVER['REMOTE_ADDR'] ?? '',
                ':torque_id'    => $get['id']          ?? '',
                ':eml'          => $get['eml']         ?? '',
                ':app_version'  => $get['v']           ?? '',
                ':session'      => $get['session']     ?? '',
                ':data_ts'      => isset($get['time']) ? (int) $get['time'] : null,
                ':sensor_count' => $sensorCount,
                ':sensor_data'  => $sensorJson,
                ':new_columns'  => $newColumns,
                ':profile_name' => $get['profileName'] ?? '',
                ':result'       => $result,
                ':error_msg'    => $error,
                ':raw_query_string' => $rawQuery,
            ]);
        } catch (Throwable) {
            // Audit failure must never abort the main upload response.
        }
    }
}
