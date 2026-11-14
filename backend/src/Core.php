
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
