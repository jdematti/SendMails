<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$options = getopt('', ['branch:', 'due-date:', 'channel:', 'status:', 'include-sent', 'help']);
if (isset($options['help']) || empty($options['branch']) || empty($options['due-date'])) {
    echo "Diagnostico de solo lectura; no envia correos ni cambia facturas.\n";
    echo "Uso: php diagnose_invoice_selection.php --branch=1 --due-date=2026-11-30 [--channel=email|whatsapp|both] [--status=I|P] [--include-sent]\n";
    echo "Usar la sucursal, vencimiento, canal y estado de la pantalla, sin otros filtros.\n";
    exit(isset($options['help']) ? 0 : 1);
}
require __DIR__ . '/app/bootstrap.php';

function diagnosticMeasure(string $label, callable $read)
{
    $started = microtime(true);
    $value = $read();
    echo $label . ': ' . number_format((microtime(true) - $started) * 1000, 1, '.', '') . " ms\n";
    return $value;
}

try {
    $branch = filter_var($options['branch'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($branch === false) throw new InvalidArgumentException('Sucursal invalida.');
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $options['due-date']);
    if (!$date || $date->format('Y-m-d') !== $options['due-date']) throw new InvalidArgumentException('Vencimiento invalido: usar AAAA-MM-DD.');
    $channel = (string) ($options['channel'] ?? 'email');
    $status = (string) ($options['status'] ?? '');
    if (!in_array($channel, ['email', 'whatsapp', 'both'], true) || !in_array($status, ['', 'I', 'P'], true)) {
        throw new InvalidArgumentException('Canal o estado invalido.');
    }
    $_SESSION['branch_id'] = $branch;
    diagnosticMeasure('Conexion central y comprobacion de esquema', static fn() => Schema::ensure());
    $pdo = diagnosticMeasure('Conexion a la sucursal', static fn() => BranchRepository::pdo($branch));
    echo 'Sucursal #' . $branch . '; vencimiento ' . $date->format('Y-m-d') . '; canal ' . $channel
        . '; estado ' . ($status ?: 'todos') . '; incluir enviados ' . (isset($options['include-sent']) ? 'si' : 'no') . "\n";

    // Metadata contains no recipient names, addresses, credentials or invoice IDs.
    try {
        $sql = "SELECT t.name AS tabla, c.name AS columna, ty.name AS tipo,
                    c.max_length AS longitud_bytes, c.collation_name AS intercalacion
                FROM sys.tables t JOIN sys.columns c ON c.object_id=t.object_id
                JOIN sys.types ty ON ty.user_type_id=c.user_type_id
                WHERE t.schema_id=SCHEMA_ID('dbo') AND
                    ((t.name='FacturasTel' AND c.name IN ('Id','IdCliente','V1_FechaVto','Estado','Autorizada','mail_enviado'))
                     OR (t.name='clientes' AND c.name IN ('OId','DirEMail')))
                ORDER BY t.name,c.column_id";
        echo 'Columnas: ' . json_encode($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC), JSON_THROW_ON_ERROR) . "\n";
        $sql = "SELECT t.name AS tabla, i.name AS indice, i.is_disabled AS deshabilitado,
                    c.name AS columna, ic.key_ordinal AS posicion, ic.is_included_column AS incluida,
                    i.filter_definition AS filtro
                FROM sys.tables t JOIN sys.indexes i ON i.object_id=t.object_id
                JOIN sys.index_columns ic ON ic.object_id=i.object_id AND ic.index_id=i.index_id
                JOIN sys.columns c ON c.object_id=ic.object_id AND c.column_id=ic.column_id
                WHERE t.schema_id=SCHEMA_ID('dbo') AND t.name IN ('FacturasTel','clientes')
                ORDER BY t.name,i.index_id,ic.index_column_id";
        echo 'Indices: ' . json_encode($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC), JSON_THROW_ON_ERROR) . "\n";
    } catch (PDOException $e) {
        echo "Metadatos no disponibles con los permisos actuales. Se continua con la medicion.\n";
    }

    $input = ComposeService::input(['due_date' => $date->format('Y-m-d'), 'channel' => $channel,
        'status' => $status, 'include_sent' => isset($options['include-sent'])]);
    echo "Midiendo la misma seleccion total de la pantalla...\n";
    $ids = diagnosticMeasure('Seleccion total', static fn() => ComposeService::search('invoice', $input, 1, true));
    echo 'Registros: ' . count($ids) . '; respuesta IDs: ' . strlen(json_encode($ids, JSON_THROW_ON_ERROR)) . " bytes\n";
    unset($ids);
    echo "Diagnostico terminado. No se modificaron datos ni se enviaron mensajes.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Diagnostico interrumpido: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
