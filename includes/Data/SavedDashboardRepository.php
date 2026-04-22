<?php
declare(strict_types=1);

namespace TorqueLogs\Data;

/**
 * SavedDashboardRepository — persistence for saved dashboard layouts.
 *
 * `state_json` stores the minimal URL-equivalent state so the slug resolver
 * can reconstruct the full dashboard.php query string:
 *
 *   {
 *     "id":   "SESSION_ID",
 *     "grid": "2x3",
 *     "p": [
 *       {"s": ["kd"], "cs": 1, "rs": 1},
 *       {"s": ["kf"], "cs": 2, "rs": 1}
 *     ]
 *   }
 *
 * `owner_device_hash` is the SHA-256 hex of the Torque `device_id` string.
 * We never store the raw device_id.
 */
class SavedDashboardRepository
{
    public function __construct(private readonly \PDO $pdo) {}

    // ── Slug helpers ─────────────────────────────────────────────────────────

    /**
     * Generate a random URL-safe slug of the given length.
     */
    public static function generateSlug(int $length = 8): string
    {
        $chars  = 'abcdefghjkmnpqrstuvwxyz23456789'; // no ambiguous chars
        $result = '';
        $max    = strlen($chars) - 1;
        for ($i = 0; $i < $length; $i++) {
            $result .= $chars[random_int(0, $max)];
        }
        return $result;
    }

    /**
     * Sanitise a user-supplied slug: lowercase, only [a-z0-9-], 3–80 chars.
     * Returns null if the value is invalid after sanitisation.
     *
     * @return string|null Clean slug or null on failure.
     */
    public static function sanitiseSlug(string $raw): ?string
    {
        $slug = strtolower(trim($raw));
        $slug = preg_replace('/[^a-z0-9-]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');
        if (strlen($slug) < 3 || strlen($slug) > 80) {
            return null;
        }
        return $slug;
    }

    // ── Write ────────────────────────────────────────────────────────────────

    /**
     * Upsert a saved dashboard.
     *
     * If a row with `slug` already exists AND `owner_device_hash` matches,
     * it is updated. If the slug belongs to a different owner, a
     * \RuntimeException is thrown so the caller can generate a new slug.
     *
     * @param  string      $slug            URL slug (sanitised by caller).
     * @param  string      $title           Human-readable title (≤120 chars).
     * @param  string      $stateJson       JSON state blob.
     * @param  string|null $ownerEmail      Informational; not used for auth.
     * @param  string|null $deviceId        Raw device_id — hashed before storage.
     * @param  \DateTime|null $expiresAt    NULL = never expires.
     * @return string                       The stored slug.
     * @throws \RuntimeException            If slug is taken by a different owner.
     * @throws \PDOException                On DB failure.
     */
    public function upsert(
        string    $slug,
        string    $title,
        string    $stateJson,
        ?string   $ownerEmail,
        ?string   $deviceId,
        ?\DateTime $expiresAt = null,
    ): string {
        $deviceHash = ($deviceId !== null && $deviceId !== '')
            ? hash('sha256', $deviceId)
            : null;

        // Check if slug exists and, if so, who owns it.
        $existing = $this->findBySlug($slug);

        if ($existing !== null) {
            // Slug exists — only allow update if same owner.
            $existingHash = $existing['owner_device_hash'] ?? null;
            if ($deviceHash !== null && $existingHash !== null) {
                if (!hash_equals($existingHash, $deviceHash)) {
                    throw new \RuntimeException('Slug is owned by a different device.');
                }
            }

            // Update existing row.
            $stmt = $this->pdo->prepare(
                'UPDATE saved_dashboards
                    SET title             = :title,
                        state_json        = :state_json,
                        owner_email       = :owner_email,
                        owner_device_hash = :device_hash,
                        expires_at        = :expires_at
                  WHERE slug = :slug'
            );
        } else {
            // Insert new row.
            $stmt = $this->pdo->prepare(
                'INSERT INTO saved_dashboards
                        (slug, title, state_json, owner_email, owner_device_hash, expires_at)
                 VALUES (:slug, :title, :state_json, :owner_email, :device_hash, :expires_at)'
            );
        }

        $stmt->execute([
            ':slug'        => $slug,
            ':title'       => mb_substr($title, 0, 120),
            ':state_json'  => $stateJson,
            ':owner_email' => $ownerEmail,
            ':device_hash' => $deviceHash,
            ':expires_at'  => $expiresAt?->format('Y-m-d H:i:s'),
        ]);

        return $slug;
    }

    // ── Read ─────────────────────────────────────────────────────────────────

    /**
     * Find a saved dashboard by its slug.
     *
     * Returns null if not found or if the row has expired.
     *
     * @return array{
     *     id: int,
     *     slug: string,
     *     title: string|null,
     *     state_json: string,
     *     owner_email: string|null,
     *     owner_device_hash: string|null,
     *     expires_at: string|null,
     *     created_at: string,
     *     updated_at: string,
     * }|null
     */
    public function findBySlug(string $slug): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, slug, title, state_json,
                    owner_email, owner_device_hash,
                    expires_at, created_at, updated_at
               FROM saved_dashboards
              WHERE slug = ?
                AND (expires_at IS NULL OR expires_at > NOW())
              LIMIT 1'
        );
        $stmt->execute([$slug]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return ($row !== false) ? $row : null;
    }

    // ── Delete ───────────────────────────────────────────────────────────────

    /**
     * Delete a saved dashboard by slug.
     *
     * If `deviceHash` is provided, deletion is only permitted when the hash
     * matches the stored owner hash (timing-safe compare).
     *
     * @param  string      $slug
     * @param  string|null $deviceId Raw device_id for ownership check.
     * @return bool                  True if a row was deleted.
     * @throws \RuntimeException     If ownership check fails.
     */
    public function deleteBySlug(string $slug, ?string $deviceId = null): bool
    {
        if ($deviceId !== null) {
            $existing = $this->findBySlug($slug);
            if ($existing === null) {
                return false;
            }
            $storedHash = $existing['owner_device_hash'] ?? null;
            $inputHash  = hash('sha256', $deviceId);
            if ($storedHash !== null && !hash_equals($storedHash, $inputHash)) {
                throw new \RuntimeException('Device ID does not match dashboard owner.');
            }
        }

        $stmt = $this->pdo->prepare('DELETE FROM saved_dashboards WHERE slug = ?');
        $stmt->execute([$slug]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Check whether a slug is already taken (including expired rows).
     */
    public function slugExists(string $slug): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM saved_dashboards WHERE slug = ? LIMIT 1'
        );
        $stmt->execute([$slug]);
        return $stmt->fetchColumn() !== false;
    }
}
