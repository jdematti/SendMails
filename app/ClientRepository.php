<?php

declare(strict_types=1);

final class ClientRepository
{
    public static function countAll(): int
    {
        return (int) self::pdo()->query('SELECT COUNT(*) FROM ' . self::sourceSql())->fetchColumn();
    }

    public static function countWithEmail(): int
    {
        $map = self::columnMap();
        $email = self::trimmedExpression($map['email']);

        return (int) self::pdo()
            ->query('SELECT COUNT(*) FROM ' . self::sourceSql() . " WHERE NULLIF($email, '') IS NOT NULL")
            ->fetchColumn();
    }

    public static function search(string $term = '', int $limit = 200, string $plan = '', string $subscription = '', string $delivery = ''): array
    {
        $limit = in_array($limit, [20, 50, 100], true) ? $limit : 20;
        $fetchLimit = ($subscription !== '' || $delivery !== '') ? max(500, $limit * 10) : $limit;
        $select = self::selectSql();
        $map = self::columnMap();
        $sql = 'SELECT TOP ' . $fetchLimit . ' ' . $select . ' FROM ' . self::sourceSql() . ' src';
        $params = [];
        $where = [];

        if ($term !== '') {
            $searchColumns = array_values(array_unique(array_filter([
                $map['codigo_cliente'],
                $map['razon_social'],
                $map['email'],
                $map['telefono_movil'],
                $map['localidad'],
                $map['provincia'],
            ])));
            $conditions = [];
            foreach ($searchColumns as $index => $column) {
                $key = ':term' . $index;
                $conditions[] = 'CONVERT(nvarchar(4000), ' . self::quoteName($column) . ') LIKE ' . $key;
                $params[$key] = '%' . $term . '%';
            }
            if ($conditions) {
                $where[] = '(' . implode(' OR ', $conditions) . ')';
            }
        }

        if ($plan !== '' && $map['plan_contratado'] !== null) {
            $where[] = self::trimmedExpression($map['plan_contratado']) . ' = :plan';
            $params[':plan'] = $plan;
        }

        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= ' ORDER BY razon_social, codigo_cliente';
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        $rows = self::filterByCentralStatus($stmt->fetchAll(), $subscription, $delivery);
        return array_slice($rows, 0, $limit);
    }

    public static function plans(): array
    {
        $map = self::columnMap();
        if ($map['plan_contratado'] === null) {
            return [];
        }

        $plan = self::trimmedExpression($map['plan_contratado']);
        $sql = "SELECT DISTINCT NULLIF($plan, '') AS plan_contratado
                FROM " . self::sourceSql() . "
                WHERE NULLIF($plan, '') IS NOT NULL
                ORDER BY plan_contratado";

        return array_map('strval', self::pdo()->query($sql)->fetchAll(PDO::FETCH_COLUMN));
    }

    public static function findByOids(array $oids): array
    {
        $oids = array_values(array_filter(array_map('trim', $oids), static fn (string $v): bool => $v !== ''));
        if (!$oids) {
            return [];
        }

        $placeholders = [];
        $params = [];
        foreach ($oids as $index => $oid) {
            $key = ':oid' . $index;
            $placeholders[] = $key;
            $params[$key] = $oid;
        }

        $map = self::columnMap();
        $identifier = self::trimmedExpression($map['oid']);
        $sql = 'SELECT ' . self::selectSql() . ' FROM ' . self::sourceSql() . ' WHERE ' . $identifier . ' IN (' . implode(',', $placeholders) . ') ORDER BY razon_social, codigo_cliente';
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function selectionToken(array $client, int $index): string
    {
        $payload = [
            '_selection_index' => $index,
            'branch_id' => BranchRepository::currentId(),
            'oid' => trim((string) ($client['oid'] ?? '')),
            'codigo_cliente' => (string) ($client['codigo_cliente'] ?? ''),
            'razon_social' => (string) ($client['razon_social'] ?? ''),
            'plan_contratado' => (string) ($client['plan_contratado'] ?? ''),
            'email' => (string) ($client['email'] ?? ''),
            'telefono_movil' => (string) ($client['telefono_movil'] ?? ''),
            'localidad' => (string) ($client['localidad'] ?? ''),
            'provincia' => (string) ($client['provincia'] ?? ''),
        ];

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new RuntimeException('No se pudo preparar la seleccion del cliente.');
        }

        $encoded = self::base64UrlEncode($json);
        return $encoded . '.' . hash_hmac('sha256', $encoded, csrf_token());
    }

    public static function findBySelectionTokens(array $tokens): array
    {
        $clients = [];
        foreach ($tokens as $token) {
            $token = trim(is_scalar($token) ? (string) $token : '');
            if ($token === '') {
                continue;
            }

            [$encoded, $signature] = array_pad(explode('.', $token, 2), 2, '');
            $expected = hash_hmac('sha256', $encoded, csrf_token());
            if ($encoded === '' || $signature === '' || !hash_equals($expected, $signature)) {
                throw new RuntimeException('La seleccion de clientes no es valida. Vuelve a elegir los destinatarios.');
            }

            $json = self::base64UrlDecode($encoded);
            $payload = is_string($json) ? json_decode($json, true) : null;
            if (!is_array($payload)) {
                throw new RuntimeException('La seleccion de clientes no es valida. Vuelve a elegir los destinatarios.');
            }

            $tokenBranchId = (int) ($payload['branch_id'] ?? 0);
            $currentBranchId = (int) (BranchRepository::currentId() ?? 0);
            if ($tokenBranchId > 0 && $currentBranchId > 0 && $tokenBranchId !== $currentBranchId) {
                throw new RuntimeException('La seleccion pertenece a otra sucursal. Vuelve a elegir los destinatarios.');
            }

            $clients[] = [
                'branch_id' => (int) ($payload['branch_id'] ?? 0),
                'oid' => trim((string) ($payload['oid'] ?? '')),
                'codigo_cliente' => (string) ($payload['codigo_cliente'] ?? ''),
                'razon_social' => (string) ($payload['razon_social'] ?? ''),
                'plan_contratado' => (string) ($payload['plan_contratado'] ?? ''),
                'email' => (string) ($payload['email'] ?? ''),
                'telefono_movil' => (string) ($payload['telefono_movil'] ?? ''),
                'localidad' => (string) ($payload['localidad'] ?? ''),
                'provincia' => (string) ($payload['provincia'] ?? ''),
            ];
        }

        return $clients;
    }

    public static function allWithValidEmail(): array
    {
        return array_values(array_filter(self::allWithEmail(), [self::class, 'hasValidEmail']));
    }

    public static function countWithValidEmail(): int
    {
        return count(self::allWithValidEmail());
    }

    public static function allWithEmail(): array
    {
        $map = self::columnMap();
        $email = self::trimmedExpression($map['email']);
        $sql = 'SELECT ' . self::selectSql() . ' FROM ' . self::sourceSql() . " WHERE NULLIF($email, '') IS NOT NULL ORDER BY razon_social, codigo_cliente";
        return self::pdo()->query($sql)->fetchAll();
    }

    public static function allReachable(): array
    {
        $map = self::columnMap();
        $email = self::trimmedExpression($map['email']);
        $phone = self::trimmedExpression($map['telefono_movil']);
        $sql = 'SELECT ' . self::selectSql() . ' FROM ' . self::sourceSql()
            . " WHERE NULLIF($email, '') IS NOT NULL OR NULLIF($phone, '') IS NOT NULL"
            . ' ORDER BY razon_social, codigo_cliente';
        return self::pdo()->query($sql)->fetchAll();
    }

    private static function sourceSql(): string
    {
        $source = self::source();
        return self::quoteName($source['schema']) . '.' . self::quoteName($source['table']);
    }

    private static function selectSql(): string
    {
        $map = self::columnMap();
        $branchId = (int) (BranchRepository::currentId() ?? 0);

        return implode(', ', [
            'CAST(' . $branchId . ' AS int) AS branch_id',
            self::trimmedExpression($map['oid']) . ' AS oid',
            self::trimmedExpression($map['codigo_cliente']) . ' AS codigo_cliente',
            "COALESCE(NULLIF(" . self::trimmedExpression($map['razon_social']) . ", ''), 'Sin razon social') AS razon_social",
            self::nullableTrimmedExpression($map['plan_contratado']) . ' AS plan_contratado',
            self::nullableTrimmedExpression($map['email']) . ' AS email',
            self::nullableTrimmedExpression($map['telefono_movil']) . ' AS telefono_movil',
            self::nullableTrimmedExpression($map['localidad']) . ' AS localidad',
            self::nullableTrimmedExpression($map['provincia']) . ' AS provincia',
        ]);
    }

    private static function columnMap(): array
    {
        static $maps = [];
        $cacheKey = (string) (BranchRepository::currentId() ?? 0);
        if (isset($maps[$cacheKey])) {
            return $maps[$cacheKey];
        }

        $columns = self::columns();
        $maps[$cacheKey] = [
            'oid' => self::firstExisting($columns, ['oid', 'OId', 'id', 'ID', 'codigo_cliente', 'CodigoCliente', 'Codigo']),
            'codigo_cliente' => self::firstExisting($columns, ['codigo_cliente', 'CodigoCliente', 'Codigo', 'CodCliente', 'CodSumin', 'ID', 'id', 'OId']),
            'razon_social' => self::firstExisting($columns, ['razon_social', 'RazonSocial', 'Razon_Social', 'Empresa', 'Apellidos', 'Nombre']),
            'plan_contratado' => self::firstExisting($columns, ['plan_contratado', 'PlanContratado', 'Plan', 'InfoSuplem']),
            'email' => self::firstExisting($columns, ['email', 'Email', 'DirEMail', 'Mail', 'Correo', 'CorreoElectronico']),
            'telefono_movil' => self::firstExisting($columns, ['telefono_movil', 'TelefonoMovil', 'TelMovil', 'Movil', 'Telefono', 'Celular']),
            'localidad' => self::firstExisting($columns, ['localidad', 'Localidad']),
            'provincia' => self::firstExisting($columns, ['provincia', 'Provincia']),
        ];

        foreach (['oid', 'codigo_cliente', 'razon_social', 'email'] as $required) {
            if ($maps[$cacheKey][$required] === null) {
                throw new RuntimeException('La vista ' . CLIENT_SOURCE . ' no expone una columna compatible para ' . $required . '.');
            }
        }

        return $maps[$cacheKey];
    }

    private static function columns(): array
    {
        $source = self::source();
        $stmt = self::pdo()->prepare(
            'SELECT COLUMN_NAME
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :table'
        );
        $stmt->execute([
            ':schema' => $source['schema'],
            ':table' => $source['table'],
        ]);

        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (!$columns) {
            throw new RuntimeException('No se encontro el origen de clientes ' . $source['schema'] . '.' . $source['table'] . '.');
        }

        return array_map('strval', $columns);
    }

    private static function source(): array
    {
        static $sources = [];
        $cacheKey = (string) (BranchRepository::currentId() ?? 0);
        if (isset($sources[$cacheKey])) {
            return $sources[$cacheKey];
        }

        $pdo = self::pdo();
        $stmt = $pdo->prepare(
            'SELECT COUNT(1)
             FROM INFORMATION_SCHEMA.VIEWS
             WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :table'
        );
        $stmt->execute([
            ':schema' => CLIENT_SOURCE_SCHEMA,
            ':table' => CLIENT_SOURCE,
        ]);

        if ((int) $stmt->fetchColumn() > 0) {
            $sources[$cacheKey] = [
                'schema' => CLIENT_SOURCE_SCHEMA,
                'table' => CLIENT_SOURCE,
            ];
            return $sources[$cacheKey];
        }

        $stmt = $pdo->prepare(
            "SELECT COUNT(1)
             FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = 'dbo' AND TABLE_NAME = 'clientes'"
        );
        $stmt->execute();
        if ((int) $stmt->fetchColumn() > 0) {
            $sources[$cacheKey] = [
                'schema' => 'dbo',
                'table' => 'clientes',
            ];
            return $sources[$cacheKey];
        }

        $sources[$cacheKey] = [
            'schema' => CLIENT_SOURCE_SCHEMA,
            'table' => CLIENT_SOURCE,
        ];
        return $sources[$cacheKey];
    }

    private static function firstExisting(array $columns, array $candidates): ?string
    {
        $lookup = [];
        foreach ($columns as $column) {
            $lookup[strtolower($column)] = $column;
        }

        foreach ($candidates as $candidate) {
            $key = strtolower($candidate);
            if (isset($lookup[$key])) {
                return $lookup[$key];
            }
        }

        return null;
    }

    private static function quoteName(string $name): string
    {
        return '[' . str_replace(']', ']]', $name) . ']';
    }

    private static function trimmedExpression(?string $column): string
    {
        if ($column === null) {
            return "''";
        }

        return 'LTRIM(RTRIM(CONVERT(nvarchar(4000), ' . self::quoteName($column) . ')))';
    }

    private static function trimmedExpressionForAlias(?string $column, string $alias): string
    {
        if ($column === null) {
            return "''";
        }

        return 'LTRIM(RTRIM(CONVERT(nvarchar(4000), ' . self::quoteName($alias) . '.' . self::quoteName($column) . ')))';
    }

    private static function pdo(): PDO
    {
        return BranchRepository::pdo();
    }

    private static function filterByCentralStatus(array $rows, string $subscription, string $delivery): array
    {
        if ($subscription === '' && $delivery === '') {
            return $rows;
        }

        $emails = array_column($rows, 'email');
        $subscriptionStatus = $subscription !== '' ? UnsubscribeRepository::statusByEmail($emails) : [];
        $exclusionStatus = $delivery !== '' ? EmailExclusionRepository::statusByEmail($emails) : [];

        return array_values(array_filter($rows, static function (array $row) use ($subscription, $delivery, $subscriptionStatus, $exclusionStatus): bool {
            $email = UnsubscribeRepository::normalizeEmail((string) ($row['email'] ?? ''));
            $isUnsubscribed = $email !== '' && (bool) ($subscriptionStatus[$email] ?? false);
            $isExcluded = $email !== '' && (bool) ($exclusionStatus[$email] ?? false);

            if ($subscription === 'unsubscribed' && !$isUnsubscribed) {
                return false;
            }
            if ($subscription === 'subscribed' && ($email === '' || $isUnsubscribed)) {
                return false;
            }
            if ($delivery === 'excluded' && !$isExcluded) {
                return false;
            }
            if ($delivery === 'enabled' && ($email === '' || $isExcluded)) {
                return false;
            }

            return true;
        }));
    }

    private static function nullableTrimmedExpression(?string $column): string
    {
        return 'NULLIF(' . self::trimmedExpression($column) . ", '')";
    }

    private static function hasValidEmail(array $client): bool
    {
        return filter_var((string) ($client['email'] ?? ''), FILTER_VALIDATE_EMAIL) !== false;
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value)
    {
        $base64 = strtr($value, '-_', '+/');
        $padding = strlen($base64) % 4;
        if ($padding > 0) {
            $base64 .= str_repeat('=', 4 - $padding);
        }

        return base64_decode($base64, true);
    }
}
