<?php

declare(strict_types=1);

final class Auth
{
    private static array $userCache = [];

    private const PUBLIC_PAGES = [
        'login.php',
        'forgot_password.php',
        'reset_password.php',
        'unsubscribe.php',
        'whatsapp_webhook.php',
    ];

    private const ADMIN_PAGES = [
        'purge.php',
        'smtp.php',
        'config_db.php',
        'users.php',
        'user_edit.php',
        'branches.php',
        'branch_edit.php',
        'whatsapp.php',
    ];

    private const BRANCH_OPTIONAL_PAGES = [
        'purge.php',
        'branch_select.php',
        'change_password.php',
        'logout.php',
        'config_db.php',
        'users.php',
        'user_edit.php',
        'branches.php',
        'branch_edit.php',
        'whatsapp.php',
    ];

    public static function enforceRequest(): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }

        $page = self::currentPage();

        if (!Database::configExists()) {
            if ($page !== 'config_db.php') {
                redirect('config_db.php');
            }
            return;
        }

        Schema::ensure();

        if (in_array($page, self::PUBLIC_PAGES, true)) {
            if ($page === 'unsubscribe.php') {
                return;
            }
            if (self::checkSession(true)) {
                self::redirectAfterLogin();
            }
            return;
        }

        $user = self::checkSession(true);
        if (!$user) {
            self::denyUnauthenticated();
        }

        if (self::mustChangePassword($user) && !in_array($page, ['change_password.php', 'logout.php'], true)) {
            redirect('change_password.php');
        }

        if (in_array($page, self::ADMIN_PAGES, true) && !self::isAdmin()) {
            self::denyForbidden();
        }

        if (!in_array($page, self::BRANCH_OPTIONAL_PAGES, true)
            && !BranchRepository::ensureSelectedForUser((int) $user['id'])) {
            redirect('branch_select.php');
        }
    }

    public static function attempt(string $identifier, string $password): bool
    {
        $user = UserRepository::findByIdentifier($identifier);
        if (!$user || !password_verify($password, (string) $user['password_hash'])) {
            return false;
        }

        session_regenerate_id(true);
        self::$userCache = [];
        $_SESSION['auth_user_id'] = (int) $user['id'];
        $_SESSION['auth_last_activity'] = time();
        unset($_SESSION['branch_id']);
        UserRepository::touchLogin((int) $user['id']);
        return true;
    }

    public static function logout(): void
    {
        self::$userCache = [];
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                (bool) $params['secure'],
                (bool) $params['httponly']
            );
        }
        session_destroy();
    }

    public static function currentUser(): ?array
    {
        return self::checkSession(false);
    }

    public static function isLoggedIn(): bool
    {
        return self::currentUser() !== null;
    }

    public static function isAdmin(): bool
    {
        $user = self::currentUser();
        return $user && (string) $user['role'] === 'Admin';
    }

    public static function mustChangePassword(?array $user = null): bool
    {
        $user = $user ?: self::currentUser();
        return $user && (bool) ($user['must_change_password'] ?? false);
    }

    public static function canProcessQueue(int $pendingCount): bool
    {
        if (self::isAdmin()) {
            return true;
        }

        return $pendingCount <= 10;
    }

    public static function requireAdmin(): void
    {
        if (!self::isAdmin()) {
            self::denyForbidden();
        }
    }

    public static function redirectAfterLogin(): void
    {
        if (self::mustChangePassword()) {
            redirect('change_password.php');
        }

        $user = self::currentUser();
        if ($user && !BranchRepository::ensureSelectedForUser((int) $user['id'])) {
            redirect('branch_select.php');
        }

        redirect('index.php');
    }

    public static function baseUrl(): string
    {
        $settings = Settings::app();
        $configured = trim((string) ($settings['base_url'] ?? ''));
        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443'
            || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
        $scheme = $https ? 'https' : 'http';
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $dir = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '')));
        $dir = in_array($dir, ['/', '.', '\\'], true) ? '' : rtrim($dir, '/');

        return $scheme . '://' . $host . $dir;
    }

    private static function checkSession(bool $enforceTimeout): ?array
    {
        $id = (int) ($_SESSION['auth_user_id'] ?? 0);
        if ($id <= 0) {
            return null;
        }

        $lastActivity = (int) ($_SESSION['auth_last_activity'] ?? 0);
        if ($enforceTimeout && $lastActivity > 0 && time() - $lastActivity > AUTH_SESSION_TIMEOUT_SECONDS) {
            self::logout();
            session_start();
            flash('warning', 'La sesion vencio por inactividad.');
            return null;
        }

        $user = self::$userCache[$id] ?? (self::$userCache[$id] = UserRepository::findActive($id));
        if (!$user) {
            self::logout();
            session_start();
            return null;
        }

        $_SESSION['auth_last_activity'] = time();
        return $user;
    }

    private static function denyUnauthenticated(): void
    {
        if (self::expectsJson()) {
            http_response_code(401);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['ok' => false, 'error' => 'Sesion no iniciada.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        redirect('login.php');
    }

    private static function denyForbidden(): void
    {
        if (self::expectsJson()) {
            http_response_code(403);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['ok' => false, 'error' => 'No tienes permisos para acceder a esta funcion.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        flash('error', 'No tienes permisos para acceder a esa pagina.');
        redirect('index.php');
    }

    private static function expectsJson(): bool
    {
        return in_array(self::currentPage(), ['queue_process_step.php', 'invoice_process_step.php'], true)
            || stripos((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false;
    }

    private static function currentPage(): string
    {
        return basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    }
}
