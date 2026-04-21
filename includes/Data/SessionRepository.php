<?php
declare(strict_types=1);

namespace TorqueLogs\Data;

/**
 * Loads and summarises the list of upload sessions.
 *
 * Reads from the raw_logs table (DB_TABLE constant), groups rows by the
 * session column, and discards single-ping noise (sessions with fewer than
 * 2 data points). Returns structured arrays ready for view consumption.
 *
 * Origin: get_sessions.php
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
        $table = defined('DB_TABLE') ? DB_TABLE : 'raw_logs';

        $stmt = $this->pdo->query(
            "SELECT COUNT(*) AS `Session Size`,
                    MIN(time)  AS `MinTime`,
                    MAX(time)  AS `MaxTime`,
                    session
             FROM `{$table}`
             GROUP BY session
             ORDER BY time DESC"
        );

        $sids  = [];
        $dates = [];
        $sizes = [];

        foreach ($stmt->fetchAll() as $row) {
            $sessionSize = (int) $row['Session Size'];

            if ($sessionSize < self::MIN_SESSION_SIZE) {
                continue;
            }

            $sid      = (string) $row['session'];
            $cleanSid = preg_replace('/\D/', '', $sid) ?? '';

            $durationMs  = (int) $row['MaxTime'] - (int) $row['MinTime'];
            $durationStr = gmdate('H:i:s', (int) ($durationMs / 1000));

            $sids[]        = $cleanSid;
            $dates[$sid]   = date('F d, Y  H:i', (int) substr($sid, 0, -3));
            $sizes[$sid]   = ' (Length ' . $durationStr . ')';
        }

        return [
            'sids'  => $sids,
            'dates' => $dates,
            'sizes' => $sizes,
        ];
    }
}
