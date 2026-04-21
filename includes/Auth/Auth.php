<?php
declare(strict_types=1);

namespace TorqueLogs\Auth;

/**
 * Application authentication — browser (session) and app (Torque-ID) modes.
 *
 * Two independent guard methods cover the two entry-point types:
 *
 *  - checkBrowser() → for HTML pages viewed by a human (dashboard, export, …)
 *    Uses PHP sessions. On failure redirects to login.php and halts.
 *
 *  - checkApp() → for the Torque Pro upload endpoint (upload_data.php)
 *    Validates the device Torque-ID supplied in $_GET. On failure sends
 *    HTTP 401 with a plain-text error message and halts.
 *
 * Login/logout helpers manage the session lifecycle.
 *
 * All credential comparisons use hash_equals() (timing-safe).
 * Credentials are never accepted from $_GET for browser logins.
 *
 * Origin: auth_functions.php, auth_user.php, auth_app.php
 */
class Auth
{
    /** PHP session key used to track login state. */
    private const SESSION_KEY = 'torque_logged_in';

    // -------------------------------------------------------------------------
    // Public guard methods
    // -------------------------------------------------------------------------

    /**
     * Ensure the current HTTP request comes from an authenticated browser user.
     *
     * Starts the PHP session (if not already started), then checks the session
     * flag. If the user is not logged in, execution is redirected to login.php
     * and halted. Must be called at the top of every browser entry point.
     *
     * @return void
     */
    public static function checkBrowser(): void
    {
        self::startSession();

        if (!self::isSessionAuthenticated()) {
            header('Location: login.php');
            exit;
        }
    }

    /**
     * Ensure the current HTTP request comes from an authenticated Torque app.
     *
     * Reads the hashed Torque device-ID from $_GET['id']. If the ID is absent,
     * malformed, or does not match the configured hash(es), responds with
     * HTTP 401 and halts. Must be called at the top of upload_data.php.
     *
     * @return void
     */
    public static function checkApp(): void
    {
        $id = self::extractTorqueId();

        if (!self::validateTorqueId($id)) {
            http_response_code(401);
            exit('ERROR. Not authorised. Please check your Torque settings.');
        }
    }

    // -------------------------------------------------------------------------
    // Login / logout
    // -------------------------------------------------------------------------

    /**
     * Attempt to log in from a browser POST request.
     *
     * Reads 'user' and 'pass' exclusively from $_POST (never $_GET).
     * Uses hash_equals() for all comparisons.
     * On success the session flag is set to true.
     *
     * @return bool True if credentials match, false otherwise.
     */
    public static function login(): bool
    {
        self::startSession();

        $user = $_POST['user'] ?? '';
        $pass = $_POST['pass'] ?? '';

        if (!self::validateUserPass($user, $pass)) {
            return false;
        }

        // Regenerate session ID after privilege change (session fixation prevention).
        session_regenerate_id(true);
        $_SESSION[self::SESSION_KEY] = true;

        return true;
    }

    /**
     * Destroy the current session and clear the session cookie.
     *
     * Safe to call even when no session is active.
     *
     * @return void
     */
    public static function logout(): void
    {
        self::startSession();

        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        session_destroy();
    }

    /**
     * Return whether the current browser session is authenticated.
     *
     * Starts the session if not already active.
     *
     * @return bool
     */
    public static function isLoggedIn(): bool
    {
        self::startSession();
        return self::isSessionAuthenticated();
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Start the PHP session with a scoped cookie path (current directory).
     *
     * No-op if a session is already active.
     *
     * @return void
     */
    private static function startSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_set_cookie_params(0, dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
            session_start();
        }
    }

    /**
     * Check the session flag.
     *
     * @return bool
     */
    private static function isSessionAuthenticated(): bool
    {
        return isset($_SESSION[self::SESSION_KEY]) && $_SESSION[self::SESSION_KEY] === true;
    }

    /**
     * Validate a username/password pair against the configured constants.
     *
     * If AUTH_USER and AUTH_PASS are both empty, all credentials are accepted
     * (open access mode — matches original behaviour).
     *
     * @param  string $user
     * @param  string $pass
     * @return bool
     */
    private static function validateUserPass(string $user, string $pass): bool
    {
        $configUser = defined('AUTH_USER') ? AUTH_USER : '';
        $configPass = defined('AUTH_PASS') ? AUTH_PASS : '';

        // Open-access mode: no credentials configured.
        if ($configUser === '' && $configPass === '') {
            return true;
        }

        return hash_equals($configUser, $user) && hash_equals($configPass, $pass);
    }

    /**
     * Extract and validate the Torque device-ID format from $_GET['id'].
     *
     * The Torque app sends a 32-character hex MD5 hash of the device ID.
     * Returns the matched hash string, or empty string on failure.
     *
     * @return string 32-char hex string or ''.
     */
    private static function extractTorqueId(): string
    {
        $raw = $_GET['id'] ?? '';

        if (preg_match('/^[0-9a-f]{32}$/i', $raw, $m)) {
            return $m[0];
        }

        return '';
    }

    /**
     * Validate a Torque device-ID hash against the configured constant(s).
     *
     * TORQUE_ID   — plain device ID (will be MD5-hashed for comparison)
     * TORQUE_ID_HASH — pre-hashed MD5 device ID
     *
     * If both constants are empty the ID check passes (open access mode).
     * Uses hash_equals() for all comparisons (timing-safe).
     *
     * @param  string $id  The 32-char hex ID extracted from the request.
     * @return bool
     */
    private static function validateTorqueId(string $id): bool
    {
        $configId     = defined('TORQUE_ID')      ? TORQUE_ID      : '';
        $configHash   = defined('TORQUE_ID_HASH') ? TORQUE_ID_HASH : '';

        // Derive hash list from plain ID constant (takes precedence).
        if ($configId !== '') {
            $hashes = array_map('md5', (array) $configId);
        } elseif ($configHash !== '') {
            $hashes = (array) $configHash;
        } else {
            // Open-access mode: no ID restriction configured.
            return true;
        }

        foreach ($hashes as $hash) {
            if (hash_equals((string) $hash, $id)) {
                return true;
            }
        }

        return false;
    }
}
