<?php
declare(strict_types=1);

namespace TorqueLogs\Data;

use TorqueLogs\Helpers\DataHelper;

/**
 * Loads chart data, sparkline series and statistics for a pair of sensor columns.
 *
 * Handles unit conversion (speed km/h ↔ mph, temperature °C ↔ °F) using the
 * SOURCE_IS_FAHRENHEIT, USE_FAHRENHEIT, SOURCE_IS_MILES and USE_MILES constants
 * defined in config.php.
 *
 * All column names are validated against the allowed-columns whitelist before
 * being used in the query — they are never taken raw from user input.
 *
 * Origin: plot.php
 */
class PlotRepository
{
    /**
     * Default sensor columns when no selection is provided.
     * kd  = OBD Speed
     * kf  = Intake Air Temperature
     */
    private const DEFAULT_V1 = 'kd';
    private const DEFAULT_V2 = 'kf';

    public function __construct(private readonly \PDO $pdo) {}

    /**
     * Load chart data and statistics for two sensor columns of a session.
     *
     * $allowedCols must be the list returned by ColumnRepository::findPlottable()
     * — only column names present in this list are accepted as $v1 / $v2.
     *
     * Returns null when the session ID is not in $allowedSids.
     *
     * The return array contains:
     *  - v1, v2              string   actual column names used
     *  - v1Label, v2Label    string   quoted JS label strings (incl. unit)
     *  - d1, d2              list     [[timestamp, value], …]
     *  - sparkdata1, 2       string   comma-separated reverse-ordered values
     *  - max1, max2          float
     *  - min1, min2          float
     *  - avg1, avg2          float
     *  - pcnt25_1, pcnt25_2  float
     *  - pcnt75_1, pcnt75_2  float
     *
     * @param  string        $sessionId   Validated numeric session ID.
     * @param  list<string>  $allowedSids All known session IDs (whitelist check).
     * @param  list<array{colname: string, colcomment: string}> $columns Plottable columns.
     * @param  string|null   $v1          Requested column 1 (validated against $columns).
     * @param  string|null   $v2          Requested column 2 (validated against $columns).
     * @param  string        $csvPath     Absolute path to torque_keys.csv.
     * @return array<string,mixed>|null   Null if session is not in $allowedSids.
     * @throws \PDOException on database failure
     */
    public function load(
        string   $sessionId,
        array    $allowedSids,
        array    $columns,
        ?string  $v1,
        ?string  $v2,
        string   $csvPath
    ): ?array {
        if (!in_array($sessionId, $allowedSids, true)) {
            return null;
        }

        $allowedCols = array_column($columns, 'colname');

        // Build column name → comment map for label lookup (DB sensor.short_name takes priority).
        $colCommentMap = [];
        foreach ($columns as $col) {
            $colCommentMap[$col['colname']] = $col['colcomment'];
        }

        $v1 = ($v1 !== null && in_array($v1, $allowedCols, true)) ? $v1 : self::DEFAULT_V1;
        $v2 = ($v2 !== null && in_array($v2, $allowedCols, true)) ? $v2 : self::DEFAULT_V2;

        // Load Torque key → human-readable label map.
        $jsarr = json_decode(DataHelper::csvToJson($csvPath), true) ?? [];

        // Query sensor_readings table with PIVOT-like query.
        // Note: each named placeholder may only appear once in a PDO statement
        // (native prepared-statement mode rejects reuse). Use distinct names for
        // CASE WHEN vs. IN list even though the values are identical.
        $stmt = $this->pdo->prepare(
            "SELECT
                    `timestamp` AS time,
                    MAX(CASE WHEN sensor_key = :v1_case THEN value END) AS v1_value,
                    MAX(CASE WHEN sensor_key = :v2_case THEN value END) AS v2_value
             FROM sensor_readings
             WHERE session_id = :sid
                 AND sensor_key IN (:v1_in, :v2_in)
             GROUP BY `timestamp`
             ORDER BY `timestamp` DESC"
        );
        $stmt->execute([
            ':sid'    => $sessionId,
            ':v1_case' => $v1,
            ':v2_case' => $v2,
            ':v1_in'   => $v1,
            ':v2_in'   => $v2,
        ]);

        [$speedFactor, $speedUnit] = $this->resolveSpeedConversion();
        [$tempFunc,    $tempUnit]  = $this->resolveTempConversion();

        $d1 = $d2 = $spark1 = $spark2 = [];

        foreach ($stmt->fetchAll() as $row) {
            $v1_raw = (float) ($row['v1_value'] ?? 0);
            $v2_raw = (float) ($row['v2_value'] ?? 0);
            
            [$x1, $unit1] = $this->convertValue($v1_raw, $jsarr[$v1] ?? '', $speedFactor, $speedUnit, $tempFunc, $tempUnit);
            [$x2, $unit2] = $this->convertValue($v2_raw, $jsarr[$v2] ?? '', $speedFactor, $speedUnit, $tempFunc, $tempUnit);

            $d1[]     = [$row['time'], $x1];
            $d2[]     = [$row['time'], $x2];
            $spark1[] = $x1;
            $spark2[] = $x2;
        }

        if (empty($spark1) || empty($spark2)) {
            return null;
        }

        // Use DB sensor.short_name if available, else torque_keys.csv, else raw column name.
        $label1 = ($colCommentMap[$v1] ?: ($jsarr[$v1] ?? $v1)) . ($unit1 ?? '');
        $label2 = ($colCommentMap[$v2] ?: ($jsarr[$v2] ?? $v2)) . ($unit2 ?? '');

        return [
            'v1'        => $v1,
            'v2'        => $v2,
            'v1Label'   => '"' . $label1 . '"',
            'v2Label'   => '"' . $label2 . '"',
            'd1'        => $d1,
            'd2'        => $d2,
            'sparkdata1' => DataHelper::makeSparkData(array_map('strval', $spark1)),
            'sparkdata2' => DataHelper::makeSparkData(array_map('strval', $spark2)),
            'max1'       => round((float) max($spark1), 1),
            'max2'       => round((float) max($spark2), 1),
            'min1'       => round((float) min($spark1), 1),
            'min2'       => round((float) min($spark2), 1),
            'avg1'       => round(DataHelper::average($spark1), 1),
            'avg2'       => round(DataHelper::average($spark2), 1),
            'pcnt25_1'   => round((float) DataHelper::calcPercentile($spark1, 25), 1),
            'pcnt25_2'   => round((float) DataHelper::calcPercentile($spark2, 25), 1),
            'pcnt75_1'   => round((float) DataHelper::calcPercentile($spark1, 75), 1),
            'pcnt75_2'   => round((float) DataHelper::calcPercentile($spark2, 75), 1),
        ];
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Convert a single sensor value applying speed or temperature unit conversion
     * if the sensor label mentions "Speed" or "Temp".
     *
     * @param  float    $raw         Raw value from the database.
     * @param  string   $label       Human-readable sensor name from torque_keys.csv.
     * @param  float    $speedFactor Multiplication factor for speed conversion.
     * @param  string   $speedUnit   Unit string for speed, e.g. ' [km/h]'.
     * @param  callable $tempFunc    Conversion closure for temperature.
     * @param  string   $tempUnit    Unit string for temperature, e.g. ' [°C]'.
     * @return array{0: float, 1: string}  [converted value, unit string]
     */
    private function convertValue(
        float    $raw,
        string   $label,
        float    $speedFactor,
        string   $speedUnit,
        callable $tempFunc,
        string   $tempUnit
    ): array {
        if (DataHelper::substriCount($label, 'Speed') > 0) {
            return [$raw * $speedFactor, $speedUnit];
        }

        if (DataHelper::substriCount($label, 'Temp') > 0) {
            return [($tempFunc)($raw), $tempUnit];
        }

        return [$raw, ''];
    }

    /**
     * Resolve the speed multiplication factor and unit label from config constants.
     *
     * @return array{0: float, 1: string}  [factor, unit label]
     */
    private function resolveSpeedConversion(): array
    {
        $srcMiles = defined('SOURCE_IS_MILES') && SOURCE_IS_MILES;
        $useMiles = defined('USE_MILES')       && USE_MILES;

        if (!$srcMiles && $useMiles) {
            return [0.621371, ' [mph]'];
        }

        if ($srcMiles && $useMiles) {
            return [1.0, ' [mph]'];
        }

        if ($srcMiles && !$useMiles) {
            return [1.609344, ' [km/h]'];
        }

        return [1.0, ' [km/h]'];
    }

    /**
     * Resolve the temperature conversion closure and unit label from config constants.
     *
     * @return array{0: callable, 1: string}  [conversion closure, unit label]
     */
    private function resolveTempConversion(): array
    {
        $srcF = defined('SOURCE_IS_FAHRENHEIT') && SOURCE_IS_FAHRENHEIT;
        $useF = defined('USE_FAHRENHEIT')        && USE_FAHRENHEIT;

        if (!$srcF && $useF) {
            return [fn(float $t): float => $t * 9.0 / 5.0 + 32.0, ' [&deg;F]'];
        }

        if ($srcF && $useF) {
            return [fn(float $t): float => $t, ' [&deg;F]'];
        }

        if ($srcF && !$useF) {
            return [fn(float $t): float => ($t - 32.0) * 5.0 / 9.0, ' [&deg;C]'];
        }

        return [fn(float $t): float => $t, ' [&deg;C]'];
    }
}
