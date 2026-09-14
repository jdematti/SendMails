<?php

declare(strict_types=1);

final class UserRepository
{
    public static function all(): array
    {
        Schema::ensure();
        return Database::pdo()
            ->query('SELECT * FROM dbo.SendMail_Users ORDER BY is_active DESC, role ASC, username ASC')
            ->fetchAll();
    }

    public static function find(int $id): ?array
    {
        Schema::ensure();
        $stmt = Database::pdo()->prepare('SELECT * FROM dbo.SendMail_Users WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    public static function findActive(int $id): ?array
    {
        Schema::ensure();
        $stmt = Database::pdo()->prepare('SELECT * FROM dbo.SendMail_Users WHERE id = :id AND is_active = 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    public static function findByIdentifier(string $identifier): ?array
    {
        Schema::ensure();
        $identifier = trim($identifier);
        if ($identifier === '') {
            return null;
        }

        $stmt = Database::pdo()->prepare(
            'SELECT TOP 1 *
             FROM dbo.SendMail_Users
             WHERE is_active = 1 AND (username = :identifier_username OR email = :identifier_email)'
        );
        $stmt->execute([
            ':identifier_username' => $identifier,
            ':identifier_email' => $identifier,
        ]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    public static function create(string $username, string $fullName, string $email, string $role, string $password, bool $mustChangePassword): int
    {
        Schema::ensure();
        self::validateUserData($username, $fullName, $email, $role);
        self::validatePassword($password);

        $stmt = Database::pdo()->prepare(
            'INSERT INTO dbo.SendMail_Users (username, full_name, email, role, password_hash, must_change_password)
             OUTPUT INSERTED.id
             VALUES (:username, :full_name, :email, :role, :password_hash, :must_change_password)'
        );
        $stmt->execute([
            ':username' => trim($username),
            ':full_name' => trim($fullName),
            ':email' => trim($email),
            ':role' => $role,
            ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ':must_change_password' => $mustChangePassword ? 1 : 0,
        ]);

        return (int) $stmt->fetchColumn();
    }

    public static function update(int $id, string $username, string $fullName, string $email, string $role, bool $isActive): void
    {
        Schema::ensure();
        self::validateUserData($username, $fullName, $email, $role);
        self::assertCanChangeRoleOrStatus($id, $role, $isActive);

        $stmt = Database::pdo()->prepare(
            'UPDATE dbo.SendMail_Users
             SET username = :username,
                 full_name = :full_name,
                 email = :email,
                 role = :role,
                 is_active = :is_active,
                 updated_at = SYSDATETIME()
             WHERE id = :id'
        );
        $stmt->execute([
            ':id' => $id,
            ':username' => trim($username),
            ':full_name' => trim($fullName),
            ':email' => trim($email),
            ':role' => $role,
            ':is_active' => $isActive ? 1 : 0,
        ]);
    }

    public static function setPassword(int $id, string $password, bool $mustChangePassword): void
    {
        Schema::ensure();
        self::validatePassword($password);

        $stmt = Database::pdo()->prepare(
            'UPDATE dbo.SendMail_Users
             SET password_hash = :password_hash,
                 must_change_password = :must_change_password,
                 updated_at = SYSDATETIME()
             WHERE id = :id'
        );
        $stmt->execute([
            ':id' => $id,
            ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ':must_change_password' => $mustChangePassword ? 1 : 0,
        ]);
    }

    public static function changePassword(int $id, string $currentPassword, string $newPassword): void
    {
        $user = self::findActive($id);
        if (!$user || !password_verify($currentPassword, (string) $user['password_hash'])) {
            throw new RuntimeException('La contraseña actual no es correcta.');
        }

        self::setPassword($id, $newPassword, false);
    }

    public static function touchLogin(int $id): void
    {
        $stmt = Database::pdo()->prepare('UPDATE dbo.SendMail_Users SET last_login_at = SYSDATETIME() WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }

    public static function createPasswordReset(string $identifier, string $ipAddress, string $userAgent): ?array
    {
        $user = self::findByIdentifier($identifier);
        if (!$user) {
            return null;
        }

        $token = bin2hex(random_bytes(32));
        $stmt = Database::pdo()->prepare(
            'INSERT INTO dbo.SendMail_PasswordResets (user_id, token_hash, expires_at, ip_address, user_agent)
             VALUES (:user_id, :token_hash, DATEADD(minute, CAST(:minutes AS int), SYSDATETIME()), :ip_address, :user_agent)'
        );
        $stmt->execute([
            ':user_id' => (int) $user['id'],
            ':token_hash' => hash('sha256', $token),
            ':minutes' => AUTH_PASSWORD_RESET_MINUTES,
            ':ip_address' => substr($ipAddress, 0, 80),
            ':user_agent' => substr($userAgent, 0, 300),
        ]);

        return ['user' => $user, 'token' => $token];
    }

    public static function findValidReset(string $token): ?array
    {
        Schema::ensure();
        $token = trim($token);
        if ($token === '') {
            return null;
        }

        $stmt = Database::pdo()->prepare(
            'SELECT TOP 1 r.*, u.username, u.full_name, u.email, u.is_active
             FROM dbo.SendMail_PasswordResets r
             INNER JOIN dbo.SendMail_Users u ON u.id = r.user_id
             WHERE r.token_hash = :token_hash
               AND r.used_at IS NULL
               AND r.expires_at >= SYSDATETIME()
               AND u.is_active = 1
             ORDER BY r.id DESC'
        );
        $stmt->execute([':token_hash' => hash('sha256', $token)]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    public static function resetPasswordWithToken(string $token, string $password): void
    {
        $reset = self::findValidReset($token);
        if (!$reset) {
            throw new RuntimeException('El link de recuperacion no es valido o ya vencio.');
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            self::validatePassword($password);
            $passwordStmt = $pdo->prepare(
                'UPDATE dbo.SendMail_Users
                 SET password_hash = :password_hash,
                     must_change_password = 0,
                     updated_at = SYSDATETIME()
                 WHERE id = :id'
            );
            $passwordStmt->execute([
                ':id' => (int) $reset['user_id'],
                ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ]);

            $stmt = $pdo->prepare('UPDATE dbo.SendMail_PasswordResets SET used_at = SYSDATETIME() WHERE id = :id');
            $stmt->execute([':id' => (int) $reset['id']]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function activeAdminCount(): int
    {
        Schema::ensure();
        return (int) Database::pdo()
            ->query("SELECT COUNT(*) FROM dbo.SendMail_Users WHERE is_active = 1 AND role = 'Admin'")
            ->fetchColumn();
    }

    private static function validateUserData(string $username, string $fullName, string $email, string $role): void
    {
        if (trim($username) === '' || trim($fullName) === '' || trim($email) === '') {
            throw new InvalidArgumentException('Usuario, nombre y email son obligatorios.');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Ingresa un email valido.');
        }

        if (!in_array($role, ['Admin', 'Usuario'], true)) {
            throw new InvalidArgumentException('Rol invalido.');
        }
    }

    private static function validatePassword(string $password): void
    {
        if (strlen($password) < 8) {
            throw new InvalidArgumentException('La contraseña debe tener al menos 8 caracteres.');
        }
    }

    private static function assertCanChangeRoleOrStatus(int $id, string $role, bool $isActive): void
    {
        $current = self::find($id);
        if (!$current) {
            throw new RuntimeException('Usuario no encontrado.');
        }

        if ((string) $current['role'] !== 'Admin') {
            return;
        }

        if ($role === 'Admin' && $isActive) {
            return;
        }

        if (self::activeAdminCount() <= 1) {
            throw new RuntimeException('No se puede quitar o desactivar el ultimo usuario Admin activo.');
        }
    }
}
