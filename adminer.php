<?php
declare(strict_types=1);

/**
 * adminer.php — Authenticated read-only Adminer wrapper.
 *
 * The upstream Adminer single-file distribution lives under includes/Vendor
 * so it is not directly web-accessible. This wrapper:
 * - requires the existing browser login
 * - verifies the configured PDO connection can be opened
 * - pre-authenticates Adminer from includes/config.php constants
 * - strips write-oriented Adminer routes
 * - blocks all POST actions to keep the UI read-only
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/Auth/Auth.php';
require_once __DIR__ . '/includes/Database/Connection.php';

use TorqueLogs\Auth\Auth;
use TorqueLogs\Database\Connection;

Auth::checkBrowser();
Connection::get();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Adminer is read-only in this installation.');
}

// Force Adminer to the application database and credentials.
$_GET['server']   = DB_HOST;
$_GET['username'] = DB_USER;
$_GET['db']       = DB_NAME;
$_GET['ext']      = 'mysqli';

// Prevent direct access to write/admin routes even through handcrafted URLs.
$allowedGetKeys = [
    'server' => true,
    'username' => true,
    'db' => true,
    'ext' => true,
    'ns' => true,
    'table' => true,
    'select' => true,
    'where' => true,
    'order' => true,
    'desc' => true,
    'limit' => true,
    'page' => true,
    'columns' => true,
    'schema' => true,
    'dump' => true,
    'dbsize' => true,
    'refresh' => true,
    'file' => true,
    'version' => true,
    'script' => true,
    'val' => true,
];

foreach (array_keys($_GET) as $key) {
    if (!isset($allowedGetKeys[$key])) {
        unset($_GET[$key]);
    }
}

if (isset($_GET['script']) && !in_array($_GET['script'], ['connect', 'db'], true)) {
    unset($_GET['script']);
}

// Adminer stores login state under its own nested session keys.
$_SESSION['pwds']['server'][DB_HOST][DB_USER] = DB_PASS;
$_SESSION['db']['server'][DB_HOST][DB_USER][DB_NAME] = true;

function adminer_object(): object
{
    return new class extends \Adminer\Adminer {
        public function name()
        {
            return 'Adminer Read-Only';
        }

        /**
         * @return array{0:string,1:string,2:string}
         */
        public function credentials()
        {
            return [DB_HOST, DB_USER, DB_PASS];
        }

        public function database()
        {
            return DB_NAME;
        }

        /**
         * @return list<string>
         */
        public function databases($flush = true)
        {
            return [DB_NAME];
        }

        public function login($login, $password)
        {
            return true;
        }

        public function loginForm()
        {
            echo '<p class="message">Use the Torque Logs dashboard login to access this read-only database view.</p>';
        }

        public function selectLinks(array $tableStatus, $set = '')
        {
            parent::selectLinks($tableStatus, null);
        }

        public function selectImportPrint()
        {
            return false;
        }

        public function importServerPath()
        {
            return '';
        }

        public function homepage()
        {
            echo '<p class="message">Read-only mode: browsing, filtering, schema viewing, and exports are available. Writes are blocked.</p>';
            return true;
        }

        public function head($dark = null)
        {
            echo '<style>
                a[href*="sql="],
                a[href*="import="],
                a[href*="database="],
                a[href*="privileges="],
                a[href*="user="],
                a[href*="create="],
                a[href*="indexes="],
                a[href*="foreign="],
                a[href*="trigger="],
                a[href*="procedure="],
                a[href*="event="],
                a.edit,
                input[name="drop"],
                input[name="truncate"],
                input[name="move"],
                input[name="copy"],
                input[name="save"],
                input[name="insert"],
                input[name="delete"],
                input[name="import"] {
                    display: none !important;
                }
            </style>';

            return true;
        }
    };
}

require __DIR__ . '/includes/Vendor/adminer-standalone.php';
