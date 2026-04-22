<?php
declare(strict_types=1);

/**
 * includes/Data/SummaryRepository.php
 *
 * Loads statistics for ALL sensors in a session in a single query.
 * Used by the Data Summary table at the bottom of the dashboard.
 *
 * Replaces the old two-sensor-only approach in PlotRepository.
 */

namespace TorqueLogs\Data;

class SummaryRepository
{
    public function __construct(private readonly \PDO $pdo) {}

    /**
     * Return summary statistics for every sensor that has readings in the
     * given session, ordered by reading count descending (most active first).
     *
     * MariaDB does not have a native PERCENTILE_CONT function in older versions,
     * so P25/P75 are approximated via a subquery rank approach.
     * For the dashboard summary table this approximation is sufficient.
     *
     * @param string $sessionId
     * @return array<int, array{
     *     sensor_key: string,
     *     label: string,
     *     unit: string,
     *     cnt: int,
     *     min: float,
     *     max: float,
     *     avg: float,
     *     p25: float,
     *     p75: float,
     *     sparkline: string
     * }>
     */
    public function findForSession(string $sessionId): array
    {
        // Step 1: aggregate stats per sensor (fast, single pass).
        $stmt = $this->pdo->prepare(
            'SELECT
                 r.sensor_key,
                 COALESCE(s.short_name, s.full_name, r.sensor_key) AS label,
                 COALESCE(u.symbol, \'\')                           AS unit,
                 COUNT(r.value)   AS cnt,
                 MIN(r.value)     AS min_val,
                 MAX(r.value)     AS max_val,
                 AVG(r.value)     AS avg_val
             FROM sensor_readings r
             LEFT JOIN sensors    s ON r.sensor_key = s.sensor_key
             LEFT JOIN unit_types u ON s.unit_id    = u.id
             WHERE r.session_id = ?
             GROUP BY r.sensor_key, s.short_name, s.full_name, u.symbol
             ORDER BY cnt DESC'
        );
        $stmt->execute([$sessionId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if (empty($rows)) {
            return [];
        }

        // Step 2: for each sensor compute P25/P75 and a sparkline string.
        // We load the ordered values per sensor separately.
        // This is O(sensors) extra queries but sensors per session is small (~30).
        $sparkStmt = $this->pdo->prepare(
            'SELECT value
               FROM sensor_readings
              WHERE session_id = ?
                AND sensor_key = ?
              ORDER BY timestamp ASC'
        );

        $result = [];
        foreach ($rows as $row) {
            $sparkStmt->execute([$sessionId, $row['sensor_key']]);
            $values = $sparkStmt->fetchAll(\PDO::FETCH_COLUMN);

            [$p25, $p75] = $this->percentiles($values);

            // Sparkline: up to 40 evenly-sampled values, comma-separated.
            $sparkline = $this->sampleSparkline($values, 40);

            $result[] = [
                'sensor_key' => $row['sensor_key'],
                'label'      => $row['label'],
                'unit'       => $row['unit'],
                'cnt'        => (int)   $row['cnt'],
                'min'        => (float) $row['min_val'],
                'max'        => (float) $row['max_val'],
                'avg'        => round((float) $row['avg_val'], 2),
                'p25'        => $p25,
                'p75'        => $p75,
                'sparkline'  => $sparkline,
            ];
        }

        return $result;
    }

    /**
     * Compute P25 and P75 from a sorted list of values.
     *
     * @param  list<float|string> $values  Already fetched from DB (unsorted).
     * @return array{float, float}         [p25, p75]
     */
    private function percentiles(array $values): array
    {
        if (empty($values)) {
            return [0.0, 0.0];
        }

        $sorted = $values;
        sort($sorted, SORT_NUMERIC);
        $n = count($sorted);

        $p25 = (float) $sorted[(int) floor(0.25 * ($n - 1))];
        $p75 = (float) $sorted[(int) floor(0.75 * ($n - 1))];

        return [round($p25, 2), round($p75, 2)];
    }

    /**
     * Sample up to $max evenly-spaced values and return as comma-separated string
     * for use with peity.js sparklines.
     *
     * @param  list<float|string> $values
     * @param  int                $max
     * @return string
     */
    private function sampleSparkline(array $values, int $max): string
    {
        $n = count($values);
        if ($n === 0) {
            return '';
        }

        if ($n <= $max) {
            return implode(',', array_map('floatval', $values));
        }

        $step    = ($n - 1) / ($max - 1);
        $sampled = [];
        for ($i = 0; $i < $max; $i++) {
            $sampled[] = (float) $values[(int) round($i * $step)];
        }

        return implode(',', $sampled);
    }
}
