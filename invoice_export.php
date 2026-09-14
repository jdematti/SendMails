<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

function invoice_xlsx_xml(string $value): string
{
    return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
}

function invoice_xlsx_cell(int $column, int $row, string $value): string
{
    $address = chr(64 + $column) . $row;
    return '<c r="' . $address . '" t="inlineStr"><is><t>' . invoice_xlsx_xml($value) . '</t></is></c>';
}

function invoice_xlsx_row(int $rowNumber, array $values): string
{
    $cells = '';
    foreach (array_values($values) as $index => $value) {
        $cells .= invoice_xlsx_cell($index + 1, $rowNumber, (string) $value);
    }

    return '<row r="' . $rowNumber . '">' . $cells . '</row>';
}

function invoice_zip_dos_time(): array
{
    $now = getdate();
    $year = max(1980, (int) $now['year']);
    $date = (($year - 1980) << 9) | ((int) $now['mon'] << 5) | (int) $now['mday'];
    $time = ((int) $now['hours'] << 11) | ((int) $now['minutes'] << 5) | (int) floor((int) $now['seconds'] / 2);

    return [$time, $date];
}

function invoice_zip_archive(array $files): string
{
    [$time, $date] = invoice_zip_dos_time();
    $local = '';
    $central = '';
    $offset = 0;
    $count = 0;

    foreach ($files as $name => $content) {
        $name = str_replace('\\', '/', (string) $name);
        $content = (string) $content;
        $crc = crc32($content);
        if ($crc < 0) {
            $crc += 4294967296;
        }
        $size = strlen($content);
        $nameLength = strlen($name);

        $header = pack(
            'VvvvvvVVVvv',
            0x04034b50,
            20,
            0,
            0,
            $time,
            $date,
            $crc,
            $size,
            $size,
            $nameLength,
            0
        ) . $name;

        $local .= $header . $content;
        $central .= pack(
            'VvvvvvvVVVvvvvvVV',
            0x02014b50,
            20,
            20,
            0,
            0,
            $time,
            $date,
            $crc,
            $size,
            $size,
            $nameLength,
            0,
            0,
            0,
            0,
            0,
            $offset
        ) . $name;

        $offset += strlen($header) + $size;
        $count++;
    }

    $centralOffset = strlen($local);
    $centralSize = strlen($central);
    $end = pack('VvvvvVVv', 0x06054b50, 0, 0, $count, $count, $centralSize, $centralOffset, 0);

    return $local . $central . $end;
}

try {
    $dueDate = query_string('venc');
    $status = query_string('status');
    $includeSent = query_string('include_sent') === '1';
    $filterSnb = query_string('snb');
    $filterEmail = query_string('email');
    $filterPhone = query_string('phone');
    $filterName = query_string('name');
    $channel = query_string('channel', 'email');
    $templateId = normalize_int(query_string('template_id'), 0, 0);
    $limit = normalize_int(query_string('limit', '250'), 250, 100, 10000);
    if ($dueDate === '') {
        throw new InvalidArgumentException('Selecciona un vencimiento para exportar.');
    }

    if ($templateId > 0) {
        $template = InvoiceRepository::findTemplate($templateId);
        if (!$template || !(bool) $template['is_active']) {
            throw new RuntimeException('Selecciona una plantilla de facturas activa.');
        }
    } else {
        $template = InvoiceRepository::defaultTemplate();
    }
    $items = InvoiceRepository::search($dueDate, $status, $includeSent, $template, [
        'snb' => $filterSnb,
        'email' => $filterEmail,
        'phone' => $filterPhone,
        'name' => $filterName,
    ], $limit, $channel);

    $sheetRows = [];
    $sheetRows[] = invoice_xlsx_row(1, ['Email', 'Celular', 'Importe', 'Nombre', 'SNB', 'Vencimiento', 'Enviado', 'URL', 'ID Factura']);
    $rowNumber = 2;
    foreach ($items as $item) {
        $sheetRows[] = invoice_xlsx_row($rowNumber, [
            $item['email'],
            $item['telefono_movil'],
            $item['amount_label'],
            $item['client_name'],
            $item['snb'],
            $item['due_date_label'],
            (int) $item['mail_sent'] === 1 ? ($item['sent_label'] ?: 'si') : 'no',
            $item['invoice_url'],
            $item['invoice_id'],
        ]);
        $rowNumber++;
    }

    $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheetData>' . implode('', $sheetRows) . '</sheetData>'
        . '</worksheet>';

    $workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="Facturas" sheetId="1" r:id="rId1"/></sheets>'
        . '</workbook>';

    $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '</Types>';

    $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>';

    $workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '</Relationships>';

    $xlsx = invoice_zip_archive([
        '[Content_Types].xml' => $contentTypes,
        '_rels/.rels' => $rootRels,
        'xl/workbook.xml' => $workbookXml,
        'xl/_rels/workbook.xml.rels' => $workbookRels,
        'xl/worksheets/sheet1.xml' => $sheetXml,
    ]);

    $filename = 'facturas-' . preg_replace('/[^0-9-]/', '', $dueDate) . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($xlsx));
    echo $xlsx;
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo $e->getMessage();
}
