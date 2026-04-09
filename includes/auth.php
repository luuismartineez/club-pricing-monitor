<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function is_admin_logged_in(): bool
{
    return !empty($_SESSION['admin_user_id']);
}

function require_admin_login(): void
{
    if (!is_admin_logged_in()) {
        header('Location: login.php');
        exit;
    }
}

function admin_login(string $username, string $password): bool
{
    $db = db();

    $stmt = $db->prepare("
        SELECT id, username, password_hash
        FROM admin_users
        WHERE username = ? AND is_active = 1
        LIMIT 1
    ");
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $res = $stmt->get_result();
    $user = $res->fetch_assoc();
    $stmt->close();

    if (!$user) {
        return false;
    }

    if (!password_verify($password, $user['password_hash'])) {
        return false;
    }

    session_regenerate_id(true);
    $_SESSION['admin_user_id'] = (int)$user['id'];
    $_SESSION['admin_username'] = $user['username'];

    return true;
}

function admin_logout(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'] ?? false,
            $params['httponly'] ?? false
        );
    }

    session_destroy();
}