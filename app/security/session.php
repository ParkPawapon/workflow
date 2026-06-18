<?php

declare(strict_types=1);

require_once __DIR__ . '/../helpers.php';

if (!function_exists('app_session_start')) {
    function app_session_start(): void
    {
        if (session_status() !== PHP_SESSION_NONE) {
            return;
        }

        $idle_timeout = (int) app_env('SESSION_IDLE_TIMEOUT', 7200);
        $absolute_timeout = (int) app_env('SESSION_ABSOLUTE_TIMEOUT', 28800);
        $gc_lifetime = max($idle_timeout, $absolute_timeout, (int) ini_get('session.gc_maxlifetime'));

        if ($gc_lifetime > 0) {
            ini_set('session.gc_maxlifetime', (string) $gc_lifetime);
        }

        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        $params = session_get_cookie_params();
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => $params['path'] ?? '/',
            'domain' => $params['domain'] ?? '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        session_start();

        if (!isset($_SESSION['session_started_at'])) {
            $_SESSION['session_started_at'] = time();
        }

        if (!isset($_SESSION['last_activity'])) {
            $_SESSION['last_activity'] = time();
        }

        if ($absolute_timeout > 0 && time() - (int) $_SESSION['session_started_at'] > $absolute_timeout) {
            session_unset();
            session_destroy();
            session_start();
        }

        if ($idle_timeout > 0 && time() - (int) $_SESSION['last_activity'] > $idle_timeout) {
            session_unset();
            session_destroy();
            session_start();
        }

        $_SESSION['last_activity'] = time();
    }
}
