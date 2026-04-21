<?php
declare(strict_types=1);

namespace TorqueLogs\Session;

/**
 * Manages destructive session operations — delete and merge.
 *
 * Both methods operate on the raw_logs table. Validations are performed
 * before any write to prevent data corruption.
 *
 * Origin: del_session.php, merge_sessions.php
 */
class SessionManager
{
    public function __construct(private readonly \PDO $pdo) {}

    /**
     * Delete all rows belonging to a session.
     *
     * @param  string $sessionId  Numeric session ID to delete.
     * @return int                Number of rows deleted.
     * @throws \PDOException on database failure
     */
    public function delete(string $sessionId): int
    {
        $table = defined('DB_TABLE') ? DB_TABLE : 'raw_logs';

        $stmt = $this->pdo->prepare("DELETE FROM `{$table}` WHERE session = :sid");
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
     * Origin: merge_sessions.php
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

        $table = defined('DB_TABLE') ? DB_TABLE : 'raw_logs';

        $stmt = $this->pdo->prepare(
            "UPDATE `{$table}` SET session = :into WHERE session = :with"
        );
        $stmt->execute([':into' => $intoId, ':with' => $withId]);

        return $intoId;
    }
}
