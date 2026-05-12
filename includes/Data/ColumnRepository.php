<?php
declare(strict_types=1);

namespace TorqueLogs\Data;

use TorqueLogs\Helpers\DataHelper;

/**
 * Loads plottable column metadata and per-session emptiness flags.
 *
 * Queries the sensors table for registered k* sensors and determines which 
 * sensors contain meaningful data for a given session.
 *
 * Origin: get_columns.php (updated for normalized schema)
 */
class ColumnRepository
{
    public function __construct(private readonly \PDO $pdo) {}

    /**
     * Return all plottable sensor columns from the sensors table.
     *
     * Each entry contains:
     *  - 'key'   string  Sensor key (e.g. 'kd', 'kff1006')
     *  - 'label' string  Human-readable sensor name from short_name/full_name/fallback
     *  - 'unit'  string  Display unit symbol from unit_types, if known
     *
     * @return list<array{key: string, label: string, unit: string}>
     * @throws \PDOException on database failure
     */
    public function findPlottable(): array
    {
        // Query the sensors table for all registered sensors
        $stmt = $this->pdo->query(
            "SELECT s.sensor_key, s.short_name, s.full_name, COALESCE(u.symbol, '') AS unit
             FROM sensors s
             LEFT JOIN unit_types u ON u.id = s.unit_id
             ORDER BY s.sensor_key"
        );

        $fallbacks = DataHelper::csvToMap(__DIR__ . '/../../data/torque_keys.csv');
        $columns   = [];

        foreach ($stmt->fetchAll() as $row) {
            // Use short_name if available, otherwise full_name, otherwise CSV fallback, otherwise sensor_key
            $displayName = $row['short_name'] ?: $row['full_name'] ?: ($fallbacks[$row['sensor_key']] ?? $row['sensor_key']);
            
            $columns[] = [
                'key'   => $row['sensor_key'],
                'label' => $displayName,
                'unit'  => (string) $row['unit'],
            ];
        }

        return $columns;
    }

    /**
     * Return plottable sensors that have at least one reading in a session.
     *
     * The global sensors table may contain keys seen in other sessions, so the
     * dashboard should use this method once a session is selected to avoid
     * offering panel choices that will immediately return "No data".
     *
     * @param  string $sessionId
     * @return list<array{key: string, label: string, unit: string, sample_count: int}>
     * @throws \PDOException on database failure
     */
    public function findForSession(string $sessionId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT
                 r.sensor_key,
                 COALESCE(s.short_name, s.full_name, r.sensor_key) AS label,
                 COALESCE(u.symbol, '') AS unit,
                 COUNT(*) AS sample_count
             FROM sensor_readings r
             LEFT JOIN sensors s ON s.sensor_key = r.sensor_key
             LEFT JOIN unit_types u ON u.id = s.unit_id
             WHERE r.session_id = :sid
             GROUP BY r.sensor_key, s.short_name, s.full_name, u.symbol
             ORDER BY label, r.sensor_key"
        );
        $stmt->execute([':sid' => $sessionId]);

        $fallbacks = DataHelper::csvToMap(__DIR__ . '/../../data/torque_keys.csv');
        $columns   = [];

        foreach ($stmt->fetchAll() as $row) {
            $label = (string) $row['label'];
            if ($label === (string) $row['sensor_key']) {
                $label = $fallbacks[$row['sensor_key']] ?? $label;
            }

            $columns[] = [
                'key'          => (string) $row['sensor_key'],
                'label'        => $label,
                'unit'         => (string) $row['unit'],
                'sample_count' => (int) $row['sample_count'],
            ];
        }

        return $columns;
    }

    /**
     * Return a map of sensor key → bool indicating whether each sensor
     * contains fewer than 2 distinct non-null values for the given session.
     *
     * A sensor is considered "empty" (true) when it has < 2 distinct values,
     * meaning it carries no useful variation to plot.
     *
     * Uses a single aggregated query instead of one query per sensor to
     * avoid N+1 round-trips when many sensors are registered.
     *
     * @param  string                             $sessionId  Session ID string.
     * @param  list<array{colname: string, colcomment: string}> $columns   Output of findPlottable().
     * @return array<string, bool>  sensor_key → true if empty, false if has data.
     * @throws \PDOException on database failure
     */
    public function findEmpty(string $sessionId, array $columns): array
    {
        if (empty($columns)) {
            return [];
        }

        // One query: count distinct values per sensor for the session.
        $stmt = $this->pdo->prepare(
            "SELECT sensor_key, COUNT(DISTINCT value) AS cnt
             FROM sensor_readings
             WHERE session_id = :sid
             GROUP BY sensor_key"
        );
        $stmt->execute([':sid' => $sessionId]);

        // Build a lookup: sensor_key → distinct-value count.
        $counts = [];
        foreach ($stmt->fetchAll() as $row) {
            $counts[$row['sensor_key']] = (int) $row['cnt'];
        }

        // Any sensor not present in the result has 0 readings → empty.
        $result = [];
        foreach ($columns as $col) {
            $key = $col['colname'];
            $result[$key] = ($counts[$key] ?? 0) < 2;
        }

        return $result;
    }
}
