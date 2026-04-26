<?php

declare(strict_types=1);

if (!function_exists('app_env')) {
    function app_env(string $key, $default = null)
    {
        if (isset($_ENV[$key])) {
            return $_ENV[$key];
        }
        $value = getenv($key);

        if ($value !== false) {
            return $value;
        }

        return $default;
    }
}

if (!function_exists('app_is_debug')) {
    function app_is_debug(): bool
    {
        $env = strtolower((string) app_env('APP_ENV', 'production'));
        $debug = app_env('APP_DEBUG', null);

        if ($debug !== null) {
            return filter_var($debug, FILTER_VALIDATE_BOOLEAN);
        }

        return in_array($env, ['local', 'development', 'dev', 'staging'], true);
    }
}

if (!function_exists('system_not_ready_alert')) {
    function system_not_ready_alert(string $detail, string $title = 'ระบบยังไม่พร้อมใช้งาน'): array
    {
        $message = app_is_debug()
            ? $detail
            : 'ระบบกำลังปรับปรุง กรุณาติดต่อผู้ดูแลระบบ';

        return [
            'type' => 'warning',
            'title' => $title,
            'message' => $message,
        ];
    }
}

if (!function_exists('app_base_path')) {
    function app_base_path(): string
    {
        $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
        $base = rtrim(str_replace('\\', '/', dirname($script)), '/');

        return $base === '/' ? '' : $base;
    }
}

if (!function_exists('app_url')) {
    function app_url(string $path = ''): string
    {
        $base = app_base_path();
        $path = '/' . ltrim($path, '/');

        return $base . $path;
    }
}

if (!function_exists('app_request_id')) {
    function app_request_id(): string
    {
        if (!empty($_SERVER['APP_REQUEST_ID'])) {
            return (string) $_SERVER['APP_REQUEST_ID'];
        }

        $id = bin2hex(random_bytes(16));
        $_SERVER['APP_REQUEST_ID'] = $id;

        return $id;
    }
}

if (!function_exists('h')) {
    function h(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('app_format_position_label')) {
    function app_format_position_label(?string $value): string
    {
        $label = trim((string) $value);

        if ($label === '') {
            return '';
        }

        $normalized_label = str_replace('อํานวย', 'อำนวย', $label);

        if ($normalized_label === 'ผู้อำนวยการโรงเรียน') {
            return 'ผู้อำนวยการโรงเรียนดีบุกพังงาวิทยายน';
        }

        return $label;
    }
}

if (!function_exists('redirect_to')) {
    function redirect_to(string $path, int $status = 302): void
    {
        header('Location: ' . app_url($path), true, $status);
        exit();
    }
}

if (!function_exists('json_response')) {
    function json_response(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit();
    }
}

if (!function_exists('json_success')) {
    function json_success(string $message, array $data = [], int $status = 200): void
    {
        json_response([
            'success' => true,
            'error' => null,
            'message' => $message,
            'data' => $data,
        ], $status);
    }
}

if (!function_exists('json_error')) {
    function json_error(string $message, array $data = [], int $status = 400): void
    {
        json_response([
            'success' => false,
            'error' => $message,
            'message' => $message,
            'data' => $data,
        ], $status);
    }
}

if (!function_exists('request_wants_json')) {
    function request_wants_json(): bool
    {
        $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');

        return stripos($accept, 'application/json') !== false;
    }
}

if (!function_exists('flash_set')) {
    function flash_set(string $key, $value): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['_flash'][$key] = $value;
    }
}

if (!function_exists('flash_get')) {
    function flash_get(string $key, $default = null)
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (!isset($_SESSION['_flash'][$key])) {
            return $default;
        }
        $value = $_SESSION['_flash'][$key];
        unset($_SESSION['_flash'][$key]);

        return $value;
    }
}
