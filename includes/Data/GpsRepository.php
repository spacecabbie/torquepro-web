<?php
declare(strict_types=1);

namespace TorqueLogs\Data;

/**
 * Loads GPS track coordinates for a session.
 *
 * Reads the Torque latitude (kff1006) and longitude (kff1005) columns from
 * the raw_logs table, filters out zero-coordinate rows (no fix), and returns
 * the track as a structured array and a Leaflet-ready JS literal string.
 *
 * Origin: GPS query block in dashboard.php
 */
class GpsRepository
{
    /** Torque column name for latitude. */
    private const COL_LAT = 'kff1006';

    /** Torque column name for longitude. */
    private const COL_LON = 'kff1005';

    /** Leaflet JS literal returned when no GPS data is available. */
    public const DEFAULT_MAP_DATA = '[[37.235, -115.8111]]';

    public function __construct(private readonly \PDO $pdo) {}

    /**
     * Return GPS track data for a session.
     *
     * The return value contains:
     *  - 'points'   list<array{lat: string, lon: string}>  raw coordinate pairs
     *  - 'mapdata'  string  Leaflet LatLng array literal, e.g. '[[lat,lon],…]'
     *               Falls back to DEFAULT_MAP_DATA when no fix exists.
     *
     * @param  string $sessionId  Numeric session ID.
     * @return array{points: list<array{lat: string, lon: string}>, mapdata: string}
     * @throws \PDOException on database failure
     */
    public function findTrack(string $sessionId): array
    {
        $table = defined('DB_TABLE') ? DB_TABLE : 'raw_logs';

        $stmt = $this->pdo->prepare(
            "SELECT `" . self::COL_LAT . "`, `" . self::COL_LON . "`
             FROM `{$table}`
             WHERE session = :sid
             ORDER BY time DESC"
        );
        $stmt->execute([':sid' => $sessionId]);

        $points = [];

        foreach ($stmt->fetchAll() as $row) {
            $lat = $row[self::COL_LAT];
            $lon = $row[self::COL_LON];

            // Skip rows with no GPS fix (either coordinate is zero means invalid).
            if ((float) $lat === 0.0 || (float) $lon === 0.0) {
                continue;
            }

            $points[] = ['lat' => (string) $lat, 'lon' => (string) $lon];
        }

        if (empty($points)) {
            return ['points' => [], 'mapdata' => self::DEFAULT_MAP_DATA];
        }

        $pts     = array_map(fn($d) => '[' . $d['lat'] . ',' . $d['lon'] . ']', $points);
        $mapdata = '[' . implode(',', $pts) . ']';

        return ['points' => $points, 'mapdata' => $mapdata];
    }
}
