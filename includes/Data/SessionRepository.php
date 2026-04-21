<?php
declare(strict_types=1);

namespace TorqueLogs\Data;

/**
 * Loads and summarises the list of upload sessions.
 *
 * Reads from the sessions table and discards single-ping noise (sessions with 
 * fewer than 2 data points). Returns structured arrays ready for view consumption.
 *
 * Origin: get_sessions.php (updated for normalized schema)
 */
class SessionRepository
{
    /**
     * Minimum number of data points a session must have to be included.
     */
    private const MIN_SESSION_SIZE = 2;

    public function __construct(private readonly \PDO $pdo) {}

    /**
     * Return all qualifying sessions ordered newest-first.
     *
     * The returned array contains three parallel arrays keyed by the numeric
     * session ID string:
     *
     *  - $result['sids']      list<string>          ordered session IDs (digits only)
     *  - $result['dates']     array<string, string>  sid → human-readable date
     *  - $result['sizes']     array<string, string>  sid → "(Length HH:MM:SS)" string
     *
     * @return array{sids: list<string>, dates: array<string,string>, sizes: array<string,string>}
     * @throws \PDOException on database failure
     */
    public function findAll(): array
    {
        // Query the sessions table (includes pre-calculated upload_count and timestamps)
        $stmt = $this->pdo->query(
            "SELECT 
                session_id,
                upload_count,
                start_time,
                last_update,
                TIMESTAMPDIFF(SECOND, start_time, last_update) AS duration_sec
             FROM sessions
             ORDER BY start_time DESC"
        );

        $sids  = [];
        $dates = [];
        $sizes = [];

        foreach ($stmt->fetchAll() as $row) {
            $uploadCount = (int) $row['upload_count'];

            // Skip sessions with too few data points
            if ($uploadCount < self::MIN_SESSION_SIZE) {
                continue;
            }

            $sid      = (string) $row['session_id'];
            $cleanSid = preg_replace('/\D/', '', $sid) ?? '';

            $durationSec = (int) $row['duration_sec'];
            $durationStr = gmdate('H:i:s', $durationSec);

            $sids[]        = $cleanSid;
            $dates[$sid]   = date('F d, Y  H:i', strtotime($row['start_time']));
            $sizes[$sid]   = ' (Length ' . $durationStr . ')';
        }

        return [
            'sids'  => $sids,
            'dates' => $dates,
            'sizes' => $sizes,
        ];
    }
}
