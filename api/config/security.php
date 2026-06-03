<?php
define('CSRF_TOKEN_LENGTH', 32);
define('CSRF_SESSION_KEY', '_csrf_token');
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOCKOUT_DURATION', 900);

function generateCsrfToken() {
    if (empty($_SESSION[CSRF_SESSION_KEY])) {
        $_SESSION[CSRF_SESSION_KEY] = bin2hex(random_bytes(CSRF_TOKEN_LENGTH));
    }
    return $_SESSION[CSRF_SESSION_KEY];
}

function verifyCsrfToken($token) {
    if (empty($_SESSION[CSRF_SESSION_KEY])) return false;
    return hash_equals($_SESSION[CSRF_SESSION_KEY], $token);
}

function sanitizeInput($value) {
    if (is_array($value)) {
        return array_map('sanitizeInput', $value);
    }
    if (is_string($value)) {
        return htmlspecialchars(trim($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    return $value;
}

function validateEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function validatePassword($password) {
    return strlen($password) >= 8
        && preg_match('/[A-Z]/', $password)
        && preg_match('/[a-z]/', $password)
        && preg_match('/[0-9]/', $password)
        && preg_match('/[\W_]/', $password);
}

function setSecureHeaders() {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}