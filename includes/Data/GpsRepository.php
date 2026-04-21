<?php
declare(strict_types=1);

namespace TorqueLogs\Data;

/**
 * Loads GPS track coordinates for a session.
 *
 * Reads the latitude and longitude from the gps_points table,
 * filters out zero-coordinate rows (no fix), and returns
 * the track as a structured array and a Leaflet-ready JS literal string.
 *
 * Origin: GPS query block in dashboard.php (updated for normalized schema)
 */
class GpsRepository
{
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
     * @param  string $sessionId  Session ID string.
     * @return array{points: list<array{lat: string, lon: string}>, mapdata: string}
     * @throws \PDOException on database failure
     */
    public function findTrack(string $sessionId): array
    {
        // Query the gps_points table (timestamp = BIGINT Unix ms, not 'ts')
        $stmt = $this->pdo->prepare(
            "SELECT latitude, longitude
             FROM gps_points
             WHERE session_id = :sid
             ORDER BY timestamp DESC"
        );
        $stmt->execute([':sid' => $sessionId]);

        $points = [];

        foreach ($stmt->fetchAll() as $row) {
            $lat = $row['latitude'];
            $lon = $row['longitude'];

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
