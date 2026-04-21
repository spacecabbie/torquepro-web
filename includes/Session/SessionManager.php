<?php
declare(strict_types=1);

namespace TorqueLogs\Session;

/**
 * Manages destructive session operations — delete and merge.
 *
 * Both methods operate on the sessions table. DELETE cascades to 
 * sensor_readings and gps_points automatically via foreign key constraints.
 *
 * Origin: del_session.php, merge_sessions.php (updated for normalized schema)
 */
class SessionManager
{
    public function __construct(private readonly \PDO $pdo) {}

    /**
     * Delete a session and all its related data.
     *
     * Due to ON DELETE CASCADE foreign keys, this automatically deletes:
     * - All sensor_readings for this session
     * - All gps_points for this session
     * - All upload_requests_processed entries
     *
     * @param  string $sessionId  Session ID to delete.
     * @return int                Number of rows deleted (from sessions table).
     * @throws \PDOException on database failure
     */
    public function delete(string $sessionId): int
    {
        $stmt = $this->pdo->prepare("DELETE FROM sessions WHERE session_id = :sid");
        $stmt->execute([':sid' => $sessionId]);

        return $stmt->rowCount();
    }

    /**
     * Merge two adjacent sessions by re-keying all rows of $withId to $intoId.
     *
     * The two sessions must be direct neighbours in the ordered session list
     * (i.e. $intoId is the older session and $withId is the session immediately
     * before it). The adjacency check uses the $allSids list which must be
     * ordered newest-first (as returned by SessionRepository::findAll()).
     *
     * Returns the surviving session ID ($intoId) on success, or null if the
     * adjacency validation fails.
     *
     * Updates:
     * - sensor_readings.session_id
     * - gps_points.session_id
     * - sessions table (combines upload counts and timestamps)
     *
     * Origin: merge_sessions.php (updated for normalized schema)
     *
     * @param  string        $intoId   Session ID to merge into (older, survives).
     * @param  string        $withId   Session ID to be absorbed (younger, deleted).
     * @param  list<string>  $allSids  All known session IDs ordered newest-first.
     * @return string|null             $intoId on success, null on validation failure.
     * @throws \PDOException on database failure
     */
    public function merge(string $intoId, string $withId, array $allSids): ?string
    {
        $idx1 = array_search($intoId, $allSids, true);
        $idx2 = array_search($withId, $allSids, true);

        // Both sessions must exist and be direct neighbours.
        // $withId (younger) must be one position ahead (lower index) of $intoId.
        if ($idx1 === false || $idx2 === false || $idx1 !== ((int) $idx2 + 1)) {
            return null;
        }

        $this->pdo->beginTransaction();

        try {
            // Update sensor_readings
            $stmt = $this->pdo->prepare(
                "UPDATE sensor_readings SET session_id = :into WHERE session_id = :with"
            );
            $stmt->execute([':into' => $intoId, ':with' => $withId]);

            // Update gps_points
            $stmt = $this->pdo->prepare(
                "UPDATE gps_points SET session_id = :into WHERE session_id = :with"
            );
            $stmt->execute([':into' => $intoId, ':with' => $withId]);

            // Update upload_requests_processed
            $stmt = $this->pdo->prepare(
                "UPDATE upload_requests_processed SET session_id = :into WHERE session_id = :with"
            );
            $stmt->execute([':into' => $intoId, ':with' => $withId]);

            // Combine session metadata and delete the absorbed session
            // Update the surviving session's timestamps and upload count
            $stmt = $this->pdo->prepare(
                "UPDATE sessions s1
                 JOIN sessions s2 ON s2.session_id = :with
                 SET s1.start_time = LEAST(s1.start_time, s2.start_time),
                     s1.last_update = GREATEST(s1.last_update, s2.last_update),
                     s1.upload_count = s1.upload_count + s2.upload_count
                 WHERE s1.session_id = :into"
            );
            $stmt->execute([':into' => $intoId, ':with' => $withId]);

            // Delete the absorbed session
            $stmt = $this->pdo->prepare("DELETE FROM sessions WHERE session_id = :with");
            $stmt->execute([':with' => $withId]);

            $this->pdo->commit();
            return $intoId;
            
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
