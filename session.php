<?php
declare(strict_types=1);

/**
 * session.php — Permanent redirect to dashboard.php.
 *
 * The old session.php was the main UI entry point before the OOP migration.
 * All functionality has moved to dashboard.php. Any bookmarks or external
 * links pointing here will be transparently forwarded.
 *
 * Origin: session.php (replaced — Step 6)
 */

// Preserve any query string so links like session.php?id=123 still work.
$qs = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';

header('Location: dashboard.php' . $qs, true, 301);
exit;
