<?php
/**
 * api/_helpers.php
 * Helper functions used across student pages.
 * Place this file at: your-project/api/_helpers.php
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Generate or retrieve a CSRF token for the current session.
 * Used in forms to prevent cross-site request forgery.
 */
function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Validate a submitted CSRF token against the session token.
 */
function verifyCsrf(string $token): bool
{
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Return JSON response and exit. Useful for AJAX endpoints.
 */
function jsonResponse(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

/**
 * Sanitize a string for safe output.
 */
function clean(string $str): string
{
    return htmlspecialchars(trim($str), ENT_QUOTES, 'UTF-8');
}