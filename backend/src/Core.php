<?php
declare(strict_types=1);

final class ApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public int $status = 400
    ) {
        parent::__construct($message);
    }
}

final class Core
{
    private static ?PDO $pdo = null;

    public static function loadEnv(string $file): void
    {
        if (!is_file($file)) {
            return;
        }

        foreach (
            file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []
            as $line
        ) {
            $line = trim($line);

            if (
                $line === '' ||
                str_starts_with($line, '#') ||
                !str_contains($line, '=')
            ) {
                continue;
            }

            [$name, $value] = explode('=', $line, 2);
            $name = trim($name);

            if (getenv($name) === false) {
                putenv(
                    $name . '=' .
                    trim($value, " \t\n\r\0\x0B\"'")
                );
            }
        }
    }

    public static function env(
        string $key,
        string $default = ''
    ): string {
        $value = getenv($key);

        return $value === false ? $default : $value;
    }

    public static function db(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            self::env('DB_HOST', '127.0.0.1'),
            self::env('DB_PORT', '3306'),
            self::env('DB_NAME', 'registrar_queue_db')
        );

        try {
            self::$pdo = new PDO(
                $dsn,
                self::env('DB_USER', 'root'),
                self::env('DB_PASS', ''),
                [
                    PDO::ATTR_ERRMODE =>
                        PDO::ERRMODE_EXCEPTION,

                    PDO::ATTR_DEFAULT_FETCH_MODE =>
                        PDO::FETCH_ASSOC,

                    PDO::ATTR_EMULATE_PREPARES =>
                        false
                ]
            );

            self::$pdo->exec("SET time_zone = '+08:00'");

            return self::$pdo;

        } catch (PDOException $e) {
            throw new ApiException(
                'Database connection failed. Check XAMPP, schema, and backend/.env.',
                503
            );
        }
    }

    public static function q(
        string $sql,
        array $params = []
    ): PDOStatement {
        $stmt = self::db()->prepare($sql);
        $stmt->execute($params);

        return $stmt;
    }

    public static function one(
        string $sql,
        array $params = []
    ): ?array {
        $row = self::q($sql, $params)->fetch();

        return $row ?: null;
    }

    public static function all(
        string $sql,
        array $params = []
    ): array {
        return self::q($sql, $params)->fetchAll();
    }

    public static function json(): array
    {
        if (
            isset($_SERVER['CONTENT_LENGTH']) &&
            (int) $_SERVER['CONTENT_LENGTH'] > 1000000
        ) {
            throw new ApiException(
                'Request body too large.',
                413
            );
        }

        $raw = file_get_contents('php://input');

        if ($raw === false) {
            throw new ApiException(
                'Unable to read request body.',
                400
            );
        }

        if (trim($raw) === '') {
            return [];
        }

        try {
            // Accept JSON objects, including {}.
            // Reject JSON arrays such as [].

            $decoded = json_decode(
                $raw,
                false,
                512,
                JSON_THROW_ON_ERROR
            );

            if (!($decoded instanceof stdClass)) {
                throw new ApiException(
                    'Expected a JSON object.',
                    400
                );
            }

            return json_decode(
                $raw,
                true,
                512,
                JSON_THROW_ON_ERROR
            );

        } catch (JsonException $e) {
            throw new ApiException(
                'Invalid JSON request: ' . $e->getMessage(),
                400
            );
        }
    }

    public static function field(
        array $data,
        string $name,
        int $max = 190,
        bool $required = true
    ): string {
        $value = trim(
            (string) ($data[$name] ?? '')
        );

        if ($required && $value === '') {
            throw new ApiException(
                ucwords(str_replace('_', ' ', $name)) .
                ' is required.'
            );
        }

        $length = function_exists('mb_strlen')
            ? mb_strlen($value)
            : strlen($value);

        if ($length > $max) {
            throw new ApiException(
                ucwords(str_replace('_', ' ', $name)) .
                ' is too long.'
            );
        }

        return $value;
    }

    public static function positiveId(mixed $value): int
    {
        $id = filter_var(
            $value,
            FILTER_VALIDATE_INT
        );

        if ($id === false || $id < 1) {
            throw new ApiException('Invalid ID.');
        }

        return $id;
    }

    public static function page(): array
    {
        $page = max(
            1,
            min(10000, (int) ($_GET['page'] ?? 1))
        );

        $limit = max(
            1,
            min(100, (int) ($_GET['limit'] ?? 20))
        );

        return [
            $page,
            $limit,
            ($page - 1) * $limit
        ];
    }

    public static function respond(
        mixed $data = null,
        int $status = 200,
        string $message = ''
    ): never {
        http_response_code($status);

        header('Content-Type: application/json; charset=utf-8');

        echo json_encode(
            [
                'success' => true,
                'data' => $data,
                'message' => $message
            ],
            JSON_UNESCAPED_SLASHES |
            JSON_UNESCAPED_UNICODE
        );

        exit;
    }

    public static function fail(
        string $message,
        int $status = 400
    ): never {
        http_response_code($status);

        header('Content-Type: application/json; charset=utf-8');

        echo json_encode(
            [
                'success' => false,
                'message' => $message
            ],
            JSON_UNESCAPED_UNICODE
        );

        exit;
    }

    public static function audit(
        ?int $userId,
        string $action,
        string $type = '',
        ?int $entityId = null,
        string $details = ''
    ): void {
        self::q(
            'INSERT INTO audit_logs
            (user_id, action, entity_type, entity_id, details)
            VALUES (?, ?, ?, ?, ?)',
            [
                $userId,
                $action,
                $type ?: null,
                $entityId,
                $details ?: null
            ]
        );
    }

    public static function setting(
        string $key,
        string $fallback = ''
    ): string {
        $row = self::one(
            'SELECT setting_value
             FROM system_settings
             WHERE setting_key = ?',
            [$key]
        );

        return (string) (
            $row['setting_value'] ?? $fallback
        );
    }

    public static function notify(
        int $userId,
        string $title,
        string $message
    ): void {
        self::q(
            'INSERT INTO notifications
            (user_id, title, message)
            VALUES (?, ?, ?)',
            [
                $userId,
                $title,
                $message
            ]
        );
    }
}

final class Auth
{
    private static ?array $cached = null;

    public static function init(): void
    {
        $https = (
            !empty($_SERVER['HTTPS']) &&
            $_SERVER['HTTPS'] !== 'off'
        ) || (
            ($_SERVER['SERVER_PORT'] ?? '') === '443'
        );

        ini_set('session.use_strict_mode', '1');

        session_name(
            Core::env('SESSION_NAME', 'registrar_sid')
        );

        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $https,
            'httponly' => true,
            'samesite' => 'Lax'
        ]);

        session_start();

        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(
                random_bytes(32)
            );
        }
    }

    public static function csrf(): string
    {
        return (string) ($_SESSION['csrf'] ?? '');
    }

    public static function checkCsrf(): void
    {
        $token = (string) (
            $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''
        );

        if (
            $token === '' ||
            !hash_equals(self::csrf(), $token)
        ) {
            throw new ApiException(
                'Security token expired. Refresh the page and try again.',
                419
            );
        }
    }

    public static function user(
        bool $required = true
    ): ?array {
        if (self::$cached !== null) {
            return self::$cached;
        }

        $id = (int) (
            $_SESSION['uid'] ?? 0
        );

        if (!$id) {
            if ($required) {
                throw new ApiException(
                    'Please log in.',
                    401
                );
            }

            return null;
        }

        $user = Core::one(
            "SELECT
                u.id,
                u.username,
                u.email,
                u.full_name,
                u.role,
                u.status,
                u.must_change_password,

                s.id AS student_id,
                s.student_number,
                s.program,
                s.year_level,
                s.verification_status,

                st.id AS staff_id,
                st.employee_number,

                sca.counter_id,
                c.counter_number,
                c.counter_name

            FROM users u

            LEFT JOIN students s
                ON s.user_id = u.id

            LEFT JOIN staff st
                ON st.user_id = u.id

            LEFT JOIN staff_counter_assignments sca
                ON sca.staff_id = st.id
                AND sca.ended_at IS NULL

            LEFT JOIN counters c
                ON c.id = sca.counter_id

            WHERE u.id = ?

            LIMIT 1",
            [$id]
        );

        if (
            !$user ||
            $user['status'] !== 'active'
        ) {
            unset($_SESSION['uid']);

            if ($required) {
                throw new ApiException(
                    'Your account is not active.',
                    401
                );
            }

            return null;
        }

        $user['must_change_password'] =
            (int) $user['must_change_password'];

        foreach (
            [
                'id',
                'student_id',
                'staff_id',
                'counter_id'
            ] as $key
        ) {
            if (
                isset($user[$key]) &&
                $user[$key] !== null
            ) {
                $user[$key] = (int) $user[$key];
            }
        }

        self::$cached = $user;

        return $user;
    }

    public static function requireRole(
        string ...$roles
    ): array {
        $user = self::user();

        if (
            !in_array(
                $user['role'],
                $roles,
                true
            )
        ) {
            throw new ApiException(
                'You do not have permission to do this.',
                403
            );
        }

        if (
            (int) $user['must_change_password'] &&
            !in_array(
                'password_change_allowed',
                $roles,
                true
            )
        ) {
            throw new ApiException(
                'Change your temporary password first.',
                403
            );
        }

        return $user;
    }

    public static function login(int $id): void
    {
        session_regenerate_id(true);

        $_SESSION['uid'] = $id;

        $_SESSION['csrf'] = bin2hex(
            random_bytes(32)
        );

        self::$cached = null;
    }

    public static function logout(): void
    {
        self::$cached = null;

        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();

            setcookie(
                session_name(),
                '',
                [
                    'expires' => time() - 3600,
                    'path' => $params['path'],
                    'domain' => $params['domain'],
                    'secure' => $params['secure'],
                    'httponly' => $params['httponly'],
                    'samesite' => $params['samesite'] ?? 'Lax'
                ]
            );
        }

        session_destroy();
    }
}
