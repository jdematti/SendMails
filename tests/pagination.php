<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/test_bootstrap.php';
function check(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
$pdo = Database::pdo();
Schema::ensure();
$database = (string) getenv('SENDMAILS_TEST_DATABASE');
$compatibility = (int) $pdo->query('SELECT compatibility_level FROM sys.databases WHERE database_id=DB_ID()')->fetchColumn();
// Only the explicitly named fixture database may change compatibility; restore it even on failure.
if (!preg_match('/^SendMails_Agility_Test_[a-f0-9]{12}$/D', $database)) throw new RuntimeException('Base de pruebas invalida');
try {
    $pdo->exec("ALTER DATABASE [$database] SET COMPATIBILITY_LEVEL = 100");
    $_SESSION['branch_id'] = 1;
    $allClients = ClientRepository::page('', [], 1, true);
    check(count($allClients) === 4000, 'Fixture de 4000 clientes');
    foreach ([1, 2, 80, 81] as $page) {
        $result = ClientRepository::page('', [], $page);
        check(array_column($result['rows'], 'oid') === array_slice($allClients, ($page-1)*50, 50), 'Orden y limites de clientes, pagina '.$page);
        if ($result['rows']) check($result['total'] === 4000, 'Total completo de clientes');
    }
    $filteredClients = ClientRepository::page('Cliente', ['Plan A'], 1, true);
    check(count($filteredClients) === 2000, 'Seleccion total con filtro de plan');
    check(array_column(ClientRepository::page('Cliente', ['Plan A'], 2)['rows'], 'oid') === array_slice($filteredClients, 50, 50), 'Filtro aplicado antes de paginar clientes');

    $template = InvoiceRepository::defaultTemplate();
    $allIds = InvoiceRepository::matchingIds('2026-09-30', 'I', true);
    check(count($allIds) === 4000, 'Fixture de 4000 facturas');
    foreach ([1, 2, 80, 81] as $page) {
        $rows = InvoiceRepository::search('2026-09-30', 'I', true, $template, [], 50, 'email', $page, false);
        check(array_column($rows, 'invoice_id') === array_slice($allIds, ($page-1)*50, 50), 'Orden y limites de facturas, pagina '.$page);
        if ($rows) check(!array_key_exists('page_row_number', $rows[0]), 'No exponer numeracion interna');
    }
    $filters = ['name'=>'Cliente 00'];
    $filteredIds = InvoiceRepository::matchingIds('2026-09-30', 'I', true, $filters);
    check(count($filteredIds) > 50, 'Fixture de facturas filtradas');
    $filteredRows = InvoiceRepository::search('2026-09-30', 'I', true, $template, $filters, 50, 'email', 2, true);
    check(array_column($filteredRows, 'invoice_id') === array_slice($filteredIds, 50, 50), 'Filtro aplicado antes de paginar facturas');
    check(isset($filteredRows[0]['selection_token']), 'Mantener seleccion por token para consumidores anteriores');
    $unpaged = InvoiceRepository::search('2026-09-30', 'I', true, $template, $filters, 0, 'email', 1, false);
    check(array_column($unpaged, 'invoice_id') === $filteredIds, 'Conservar exportacion y revision sin limite');

    $activityFilters = ActivityRepository::filters(['kind'=>'campaign', 'channel'=>'email']);
    $first = ActivityRepository::page($activityFilters, 1);
    $second = ActivityRepository::page($activityFilters, 2);
    check(count($first['rows']) === 50 && count($second['rows']) === 50, 'Seguimiento paginado');
    check($first['total'] === $second['total'] && $first['total'] >= 4000, 'Total del seguimiento');
    check(!array_intersect(array_column($first['rows'],'id'), array_column($second['rows'],'id')), 'Seguimiento sin filas repetidas entre paginas');
    check($second['rows'] === ActivityRepository::page($activityFilters, 2)['rows'], 'Orden estable al recargar');
    check(ActivityRepository::page($activityFilters, (int) ceil($first['total']/50)+1)['rows'] === [], 'Pagina posterior al ultimo registro');
    echo "PAGINATION OK: compatibilidad 100 en base temporal, 4000 clientes/facturas, paginas, filtros, seleccion total y seguimiento. Sin envios externos.\n";
} finally {
    $pdo->exec("ALTER DATABASE [$database] SET COMPATIBILITY_LEVEL = $compatibility");
}
