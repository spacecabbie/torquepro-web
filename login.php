<?php
declare(strict_types=1);

/**
 * login.php — Browser authentication entry point.
 *
 * Handles both rendering the login form (GET) and processing the submitted
 * credentials (POST). On successful login the user is redirected to
 * dashboard.php. On failure the form is re-displayed with an error message.
 *
 * No business logic lives here — all credential validation is delegated to
 * TorqueLogs\Auth\Auth.
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/Auth/Auth.php';

use TorqueLogs\Auth\Auth;

// If already logged in, go straight to the dashboard.
if (Auth::isLoggedIn()) {
    header('Location: dashboard.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (Auth::login()) {
        header('Location: dashboard.php');
        exit;
    }

    $error = 'Invalid username or password.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Torque Logs — Login</title>
    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH"
        crossorigin="anonymous"
    >
    <style>
        body {
            background-color: #1a1d23;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .login-card {
            background-color: #22262e;
            border: 1px solid #2e333d;
            border-radius: 0.75rem;
            width: 100%;
            max-width: 380px;
            padding: 2.5rem 2rem;
        }

        .login-card h1 {
            font-size: 1.25rem;
            font-weight: 600;
            color: #e2e8f0;
            margin-bottom: 0.25rem;
        }

        .login-card .subtitle {
            font-size: 0.8rem;
            color: #64748b;
            margin-bottom: 1.75rem;
        }

        .form-label {
            font-size: 0.8rem;
            color: #94a3b8;
        }

        .form-control {
            background-color: #1a1d23;
            border-color: #2e333d;
            color: #e2e8f0;
        }

        .form-control:focus {
            background-color: #1a1d23;
            border-color: #3b82f6;
            color: #e2e8f0;
            box-shadow: 0 0 0 0.2rem rgba(59, 130, 246, 0.25);
        }

        .btn-login {
            background-color: #3b82f6;
            border-color: #3b82f6;
            color: #fff;
            font-weight: 500;
            width: 100%;
        }

        .btn-login:hover {
            background-color: #2563eb;
            border-color: #2563eb;
            color: #fff;
        }
    </style>
</head>
<body>
    <div class="login-card">
        <h1>Torque Logs</h1>
        <p class="subtitle">Sign in to continue</p>

        <?php if ($error !== ''): ?>
            <div class="alert alert-danger py-2 px-3 mb-3" style="font-size:0.85rem;" role="alert">
                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <form method="post" action="login.php" novalidate>
            <div class="mb-3">
                <label for="user" class="form-label">Username</label>
                <input
                    type="text"
                    id="user"
                    name="user"
                    class="form-control"
                    autocomplete="username"
                    required
                >
            </div>
            <div class="mb-4">
                <label for="pass" class="form-label">Password</label>
                <input
                    type="password"
                    id="pass"
                    name="pass"
                    class="form-control"
                    autocomplete="current-password"
                    required
                >
            </div>
            <button type="submit" class="btn btn-login">Sign in</button>
        </form>
    </div>
</body>
</html>
