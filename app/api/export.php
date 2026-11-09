<?php
// ============================================================
// EXPORT.PHP - Export spese in Excel (.xlsx) e PDF (multi-casa)
// ============================================================
require_once __DIR__ . '/config.php';
inizializzaAPI();

[$utente, $casaId] = richiedeAccessoCasa();
$isAdmin = checkPermesso($utente['id'], $casaId, 'admin');
$formato = strtolower($_GET['formato'] ?? 'csv'); // 'csv' | 'excel' | 'pdf'

match($formato) {
    'csv'   => exportCSV($utente, $casaId, $isAdmin),
    'excel' => exportExcel($utente, $casaId, $isAdmin),
    'pdf'   => exportPDF($utente, $casaId, $isAdmin),
    default => rispostaErrore(400, 'Formato non supportato. Usa: csv, excel, pdf.')
};

// ------------------------------------------------------------
// Recupera spese filtrate (condivisa tra tutti gli export)
// ------------------------------------------------------------
function getSpeseFiltrate(array $utente, int $casaId, bool $isAdmin): array {
    $db     = getDB();
    $dove   = ['s.casa_id = ?'];
    $params = [$casaId];

    // Privacy: i membri vedono solo non-private + le proprie
    if (!$isAdmin) {
        $dove[]   = '(s.privata = 0 OR s.id_utente = ?)';
        $params[] = $utente['id'];
    }

    if (!empty($_GET['anno'])) {
        $dove[]   = sqlAnno('s.data_spesa') . ' = ?';
        $params[] = (int)$_GET['anno'];
    }
    if (!empty($_GET['mese'])) {
        $dove[]   = sqlMese('s.data_spesa') . ' = ?';
        $params[] = max(1, min(12, (int)$_GET['mese']));
    }
    if (!empty($_GET['data_da'])) {
        $dove[]   = 's.data_spesa >= ?';
        $params[] = validaData($_GET['data_da']);
    }
    if (!empty($_GET['data_a'])) {
        $dove[]   = 's.data_spesa <= ?';
        $params[] = validaData($_GET['data_a']);
    }
    if (!empty($_GET['id_categoria'])) {
        $dove[]   = 's.id_categoria = ?';
        $params[] = validaIntero($_GET['id_categoria'], 'Categoria');
    }

    $where = 'WHERE ' . implode(' AND ', $dove);

    $stmt = $db->prepare("
        SELECT
            s.data_spesa, c.nome AS categoria, c.tipo AS tipo_categoria,
            sc.nome AS sottocategoria, s.descrizione,
            s.importo, u.nome AS utente, s.privata
        FROM spese s
        JOIN categorie c ON c.id = s.id_categoria
        JOIN utenti u    ON u.id = s.id_utente
        LEFT JOIN sottocategorie sc ON sc.id = s.id_sottocategoria
        $where
        ORDER BY s.data_spesa DESC, s.id DESC
        LIMIT 10000
    ");
    $stmt->execute($params);
    return $stmt->fetchAll();
}

// ------------------------------------------------------------
// EXPORT CSV
// ------------------------------------------------------------
function exportCSV(array $utente, int $casaId, bool $isAdmin): never {
    $spese = getSpeseFiltrate($utente, $casaId, $isAdmin);
    $nome  = 'housefunds_' . date('Ymd_His') . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $nome . '"');
    header('Cache-Control: no-cache, no-store');

    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF"); // BOM UTF-8

    fputcsv($out, ['Data', 'Categoria', 'Tipo', 'Sottocategoria', 'Descrizione', 'Importo (€)', 'Utente', 'Privata'], ';');

    foreach ($spese as $s) {
        fputcsv($out, [
            $s['data_spesa'],
            $s['categoria'],
            $s['tipo_categoria'] === 'accredito' ? 'Entrata' : 'Uscita',
            $s['sottocategoria'] ?? '',
            $s['descrizione'] ?? '',
            number_format((float)$s['importo'], 2, ',', '.'),
            $s['utente'],
            $s['privata'] ? 'Sì' : 'No',
        ], ';');
    }

    fputcsv($out, [], ';');
    $totUscite  = array_sum(array_map(fn($r) => $r['tipo_categoria'] === 'uscita'    ? (float)$r['importo'] : 0, $spese));
    $totEntrate = array_sum(array_map(fn($r) => $r['tipo_categoria'] === 'accredito' ? abs((float)$r['importo']) : 0, $spese));
    fputcsv($out, ['', '', '', '', 'TOTALE USCITE',  number_format($totUscite,  2, ',', '.'), '', ''], ';');
    fputcsv($out, ['', '', '', '', 'TOTALE ENTRATE', number_format($totEntrate, 2, ',', '.'), '', ''], ';');
    fputcsv($out, ['', '', '', '', 'SALDO',          number_format($totEntrate - $totUscite, 2, ',', '.'), '', ''], ';');

    fclose($out);
    exit;
}

// ------------------------------------------------------------
// EXPORT EXCEL (richiede PhpSpreadsheet via Composer)
// Fallback su CSV se non disponibile
// ------------------------------------------------------------
function exportExcel(array $utente, int $casaId, bool $isAdmin): never {
    $composerAutoload = BASE_PATH . '/vendor/autoload.php';

    if (!file_exists($composerAutoload)) {
        exportCSV($utente, $casaId, $isAdmin);
    }

    require_once $composerAutoload;

    $spese = getSpeseFiltrate($utente, $casaId, $isAdmin);

    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet       = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Spese');

    $intestazioni = ['Data', 'Categoria', 'Tipo', 'Sottocategoria', 'Descrizione', 'Importo (€)', 'Utente', 'Privata'];
    $sheet->fromArray($intestazioni, null, 'A1');

    $styleIntestazione = [
        'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
        'fill'      => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => '4F46E5']],
        'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER],
    ];
    $sheet->getStyle('A1:H1')->applyFromArray($styleIntestazione);

    $riga = 2;
    foreach ($spese as $s) {
        $importo = (float)$s['importo'];
        $sheet->fromArray([
            $s['data_spesa'],
            $s['categoria'],
            $s['tipo_categoria'] === 'accredito' ? 'Entrata' : 'Uscita',
            $s['sottocategoria'] ?? '',
            $s['descrizione'] ?? '',
            abs($importo),
            $s['utente'],
            $s['privata'] ? 'Sì' : 'No',
        ], null, "A$riga");

        $coloreImporto = $s['tipo_categoria'] === 'accredito' ? '16A34A' : 'DC2626';
        $sheet->getStyle("F$riga")->getFont()->getColor()->setRGB($coloreImporto);
        $sheet->getStyle("F$riga")->getNumberFormat()->setFormatCode('#,##0.00 €');
        $riga++;
    }

    $riga++;
    $totUscite  = array_sum(array_map(fn($r) => $r['tipo_categoria'] === 'uscita'    ? abs((float)$r['importo']) : 0, $spese));
    $totEntrate = array_sum(array_map(fn($r) => $r['tipo_categoria'] === 'accredito' ? abs((float)$r['importo']) : 0, $spese));

    $sheet->setCellValue("E$riga", 'Totale Uscite');
    $sheet->setCellValue("F$riga", $totUscite);
    $sheet->getStyle("F$riga")->getFont()->getColor()->setRGB('DC2626');
    $riga++;
    $sheet->setCellValue("E$riga", 'Totale Entrate');
    $sheet->setCellValue("F$riga", $totEntrate);
    $sheet->getStyle("F$riga")->getFont()->getColor()->setRGB('16A34A');
    $riga++;
    $sheet->setCellValue("E$riga", 'Saldo');
    $sheet->setCellValue("F$riga", $totEntrate - $totUscite);
    $sheet->getStyle("F$riga")->getFont()->setBold(true);

    foreach (range('A', 'H') as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }

    $nome   = 'housefunds_' . date('Ymd_His') . '.xlsx';
    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $nome . '"');
    header('Cache-Control: no-cache, no-store');

    $writer->save('php://output');
    exit;
}

// ------------------------------------------------------------
// EXPORT PDF (mPDF se disponibile, altrimenti HTML scaricabile)
// ------------------------------------------------------------
function exportPDF(array $utente, int $casaId, bool $isAdmin): never {
    $spese = getSpeseFiltrate($utente, $casaId, $isAdmin);

    $totUscite  = array_sum(array_map(fn($r) => $r['tipo_categoria'] === 'uscita'    ? abs((float)$r['importo']) : 0, $spese));
    $totEntrate = array_sum(array_map(fn($r) => $r['tipo_categoria'] === 'accredito' ? abs((float)$r['importo']) : 0, $spese));
    $saldo      = $totEntrate - $totUscite;

    $appNome = APP_NOME_DEFAULT;
    $periodo = !empty($_GET['anno']) ? (string)(int)$_GET['anno'] : date('Y');

    $righe = '';
    foreach ($spese as $s) {
        $tipo    = $s['tipo_categoria'] === 'accredito' ? 'Entrata' : 'Uscita';
        $colore  = $s['tipo_categoria'] === 'accredito' ? '#16a34a' : '#dc2626';
        $importo = number_format(abs((float)$s['importo']), 2, ',', '.');
        $priv    = $s['privata'] ? ' 🔒' : '';
        $righe  .= "<tr>
            <td>{$s['data_spesa']}</td>
            <td>{$s['categoria']}</td>
            <td style='color:{$colore};font-weight:bold'>$tipo</td>
            <td>" . htmlspecialchars($s['sottocategoria'] ?? '-') . "</td>
            <td>" . htmlspecialchars($s['descrizione'] ?? '-') . "</td>
            <td style='text-align:right;color:{$colore}'>$importo €</td>
            <td>{$s['utente']}{$priv}</td>
        </tr>";
    }

    $saldoColore  = $saldo >= 0 ? '#16a34a' : '#dc2626';
    $generatoIl   = date('d/m/Y H:i');
    $totUsciteStr  = number_format($totUscite,  2, ',', '.');
    $totEntrateStr = number_format($totEntrate, 2, ',', '.');
    $saldoStr      = number_format($saldo,      2, ',', '.');

    $html = <<<HTML
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="utf-8">
<title>$appNome - Report $periodo</title>
<style>
  body { font-family: Arial, sans-serif; font-size: 11px; color: #1e293b; margin: 20px; }
  h1   { font-size: 18px; color: #4f46e5; margin-bottom: 4px; }
  p.sub { color: #64748b; font-size: 10px; margin-bottom: 16px; }
  table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
  th    { background: #4f46e5; color: #fff; padding: 6px 8px; text-align: left; font-size: 10px; }
  td    { padding: 5px 8px; border-bottom: 1px solid #e2e8f0; }
  tr:nth-child(even) td { background: #f8fafc; }
  .totali td { font-weight: bold; background: #f1f5f9; }
  .saldo td  { font-size: 13px; }
</style>
</head>
<body>
<h1>$appNome</h1>
<p class="sub">Report spese — Periodo: $periodo — Generato il: $generatoIl</p>
<table>
  <thead>
    <tr>
      <th>Data</th><th>Categoria</th><th>Tipo</th><th>Sottocategoria</th>
      <th>Descrizione</th><th>Importo</th><th>Utente</th>
    </tr>
  </thead>
  <tbody>
    $righe
    <tr class='totali'>
      <td colspan='5'>Totale Uscite</td>
      <td style='text-align:right;color:#dc2626'>$totUsciteStr €</td><td></td>
    </tr>
    <tr class='totali'>
      <td colspan='5'>Totale Entrate</td>
      <td style='text-align:right;color:#16a34a'>$totEntrateStr €</td><td></td>
    </tr>
    <tr class='totali saldo'>
      <td colspan='5'><strong>Saldo</strong></td>
      <td style='text-align:right;color:{$saldoColore}'><strong>$saldoStr €</strong></td><td></td>
    </tr>
  </tbody>
</table>
</body>
</html>
HTML;

    $composerAutoload = BASE_PATH . '/vendor/autoload.php';
    if (file_exists($composerAutoload)) {
        require_once $composerAutoload;
        if (class_exists('\Mpdf\Mpdf')) {
            $tmpDir = UPLOAD_PATH . 'mpdf';
            if (!is_dir($tmpDir)) @mkdir($tmpDir, 0755, true);
            $mpdf = new \Mpdf\Mpdf(['mode' => 'utf-8', 'format' => 'A4-L', 'tempDir' => $tmpDir]);
            $mpdf->WriteHTML($html);
            $mpdf->Output('housefunds_' . date('Ymd_His') . '.pdf', 'D');
            exit;
        }
    }

    // Fallback HTML
    header('Content-Type: text/html; charset=utf-8');
    header('Content-Disposition: attachment; filename="housefunds_' . date('Ymd_His') . '.html"');
    echo $html;
    exit;
}
