<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require __DIR__ . '/../app/InvoiceRepository.php';
$criteria = new ReflectionMethod(InvoiceRepository::class, 'invoiceSearchCriteria');
$ids = array_map(static fn(int $id): string => 'F' . $id, range(1, 4000));
$ids[] = 'A&B<"';
$result = $criteria->invoke(null, '2026-11-19', 'I', false, ['selected_ids'=>$ids], 'email', "''");
if (count($result['params']) !== 4) throw new RuntimeException('La seleccion debe usar un solo parametro adicional.');
$xml = simplexml_load_string($result['params'][':selected_ids_xml']);
if ($xml === false || array_map('strval', iterator_to_array($xml->id, false)) !== $ids) {
    throw new RuntimeException('La seleccion debe conservar todos los IDs y caracteres especiales.');
}
if (!in_array('ISNULL(F.mail_enviado, 0) = 0', $result['where'], true) || $result['params'][':status'] !== 'I') {
    throw new RuntimeException('Conservar restricciones de estado y enviados.');
}
$empty = $criteria->invoke(null, '2026-11-19', '', true, ['selected_ids'=>[]], 'email', "''");
if ($empty['params'][':selected_ids_xml'] !== '<ids></ids>') throw new RuntimeException('Seleccion vacia');
foreach ([array_map('strval', range(1,20001)), ["ID\x01"], [str_repeat('a',81)]] as $invalid) {
    try { $criteria->invoke(null, '2026-11-19', '', true, ['selected_ids'=>$invalid], 'email', "''"); }
    catch (InvalidArgumentException $e) { continue; }
    throw new RuntimeException('Seleccion invalida aceptada');
}
echo "INVOICE SELECTION OK: 4001 IDs, XML, filtros, seleccion vacia y limites. Sin base ni envios.\n";
