<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require __DIR__ . '/test_bootstrap.php';
function check(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
$pdo = Database::pdo();
$database = (string) getenv('SENDMAILS_TEST_DATABASE');
if (!preg_match('/^SendMails_Agility_Test_[a-f0-9]{12}$/D', $database)) throw new RuntimeException('Base inesperada');
$compatibility = (int) $pdo->query('SELECT compatibility_level FROM sys.databases WHERE database_id=DB_ID()')->fetchColumn();
$unsubscribes = []; $exclusions = [];
try {
    $pdo->exec("ALTER DATABASE [$database] SET COMPATIBILITY_LEVEL = 100");
    $_SESSION['branch_id'] = 1;
    $ids = array_map('strval', range(1,4000));
    $clients = ClientRepository::findByOids(array_merge($ids, ['1', 'missing&<"']));
    check(count($clients) === 4000, 'Seleccion completa sin duplicados ni error XML');
    check($clients[0]['localidad'] === 'Localidad' && $clients[0]['provincia'] === 'Provincia', 'Conservar variables de plantilla');
    $listed = ClientRepository::page('', [], 1);
    check(count($listed['rows']) === 50 && $listed['total'] === 4000, 'Listado de campanas paginado');
    check(!array_key_exists('localidad', $listed['rows'][0]), 'Listado sin campos que no muestra');
    $stmt = $pdo->prepare('INSERT INTO dbo.SendMail_Unsubscribes(branch_id,email,email_normalized) OUTPUT INSERTED.id VALUES(:branch,:email,:normalized)');
    foreach ([1=>1,2=>4] as $branch=>$client) {
        $email = "cliente$client@example.invalid";
        $stmt->execute([':branch'=>$branch, ':email'=>$email, ':normalized'=>$email]);
        $unsubscribes[] = (int) $stmt->fetchColumn();
    }
    $exclude = $pdo->prepare('INSERT INTO dbo.SendMail_EmailExclusions(email,email_normalized) OUTPUT INSERTED.id VALUES(:email,:normalized)');
    $exclude->execute([':email'=>'cliente2@example.invalid', ':normalized'=>'cliente2@example.invalid']);
    $exclusions[] = (int) $exclude->fetchColumn();
    $emails = array_column($clients,'email');
    $expected = array_filter(UnsubscribeRepository::statusByEmail($emails,1));
    $expected += array_filter(EmailExclusionRepository::statusByEmail($emails));
    $actual = EmailExclusionRepository::campaignBlockedEmails($emails,1);
    ksort($expected); ksort($actual);
    check($actual === $expected && count($actual) === 2, 'Bajas por sucursal y exclusiones globales equivalentes');
    check(EmailExclusionRepository::campaignBlockedEmails([],1) === [], 'Seleccion vacia');
    $input = ComposeService::input(['name'=>'Campana grande', 'channel'=>'email', 'scheduled_at'=>'2026-11-19T12:00',
        'template_id'=>TemplateRepository::all(true)[0]['id'], 'ids'=>$ids]);
    $started = microtime(true);
    $review = ComposeService::review('campaign',$input);
    check($review['counts']['email'] === 3998 && $review['counts']['email_excluded'] === 2, 'Revision respeta bloqueos');
    $draft = DraftRepository::save(0,'campaign',$input['name'],['input'=>$input,'review'=>$review],0,'review');
    // A new exclusion after review must still be checked at confirmation.
    $exclude->execute([':email'=>'cliente3@example.invalid', ':normalized'=>'cliente3@example.invalid']);
    $exclusions[] = (int) $exclude->fetchColumn();
    $result = ComposeService::confirm('campaign',$draft['id'],$draft['revision']);
    $batch = (int) $result['links'][0]['id'];
    check((int) $pdo->query("SELECT COUNT(*) FROM dbo.SendMail_Queue WHERE campaign_id=$batch")->fetchColumn() === 3997, 'Confirmar revalida exclusiones recientes');
    echo 'CAMPAIGN OK: seleccion, revision y confirmacion de 4000 clientes; bloqueos y compatibilidad 100. Revision + confirmacion: '.round((microtime(true)-$started)*1000)." ms. Base temporal; sin envios.\n";
} finally {
    if ($unsubscribes) $pdo->exec('DELETE FROM dbo.SendMail_Unsubscribes WHERE id IN ('.implode(',',$unsubscribes).')');
    if ($exclusions) $pdo->exec('DELETE FROM dbo.SendMail_EmailExclusions WHERE id IN ('.implode(',',$exclusions).')');
    $pdo->exec("ALTER DATABASE [$database] SET COMPATIBILITY_LEVEL = $compatibility");
}
