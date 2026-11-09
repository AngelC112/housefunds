<?php
// ============================================================
// import.php — IMPORT SPESE (multi-casa)
																		  
										
// ============================================================
declare(strict_types=1);

require_once __DIR__ . '/config.php';
inizializzaAPI();

								   
set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) return false;
    logErrore("PHP $severity: $message in $file:$line");
    return true;
});

set_exception_handler(function (Throwable $e) {
    logErrore('Uncaught: ' . $e->getMessage());
    rispostaErrore(500, 'Errore interno del server.');
});

[$utente, $casaId] = richiedeAccessoCasa();

																		 
if (function_exists('session_write_close')) {
    session_write_close();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    rispostaErrore(405, 'Metodo non consentito.');
}

															   
																	   
															   
$contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';

if (stripos($contentType, 'application/json') !== false) {
														  
    $dati = inputJSON();
							   
    $csrf = $dati['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
				
    verificaCSRF($csrf);

    if (empty($dati['righe']) || !is_array($dati['righe'])) {
        rispostaErrore(400, 'Body JSON non valido: serve l\'array "righe".');
    }

    $risultato = importaRighe($dati['righe'], $utente, $casaId);
    risposta($risultato, 200);
}

												 
$csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? null;
									 
						   
			
verificaCSRF($csrfToken);

if (empty($_FILES['file'])) {
    rispostaErrore(400, 'Nessun file caricato.');
}
$file = $_FILES['file'];
$estensione = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
$maxBytes = MAX_UPLOAD_MB * 1024 * 1024;

if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    rispostaErrore(400, 'Errore upload: codice ' . ($file['error'] ?? '?'));
}
if (($file['size'] ?? 0) > $maxBytes) {
    rispostaErrore(400, 'File troppo grande. Massimo ' . MAX_UPLOAD_MB . ' MB.');
}
if (!in_array($estensione, ['xlsx', 'csv'], true)) {
    rispostaErrore(400, 'Formato non supportato. Accettati: xlsx, csv.');
}

if (!is_dir(UPLOAD_PATH)) @mkdir(UPLOAD_PATH, 0755, true);
$tmpPath = UPLOAD_PATH . 'import_' . bin2hex(random_bytes(8)) . '.' . $estensione;
if (!move_uploaded_file($file['tmp_name'], $tmpPath)) {
    rispostaErrore(500, 'Impossibile salvare il file.');
}

try {
    $righe = $estensione === 'csv' ? leggiCSV($tmpPath) : leggiExcel($tmpPath);
    $risultato = importaRighe($righe, $utente, $casaId);
    risposta($risultato, 200);
} finally {
    if (file_exists($tmpPath)) @unlink($tmpPath);
}

// ============================================================
											
															   
function leggiCSV(string $path): array {
    $handle = fopen($path, 'r');
    if (!$handle) rispostaErrore(500, 'Impossibile aprire il file CSV.');
    $bom = fread($handle, 3);
    if ($bom !== "\xEF\xBB\xBF") rewind($handle);
    $primaRiga = fgets($handle);
    rewind($handle);
    if ($bom === "\xEF\xBB\xBF") fread($handle, 3);
    $separatore = (substr_count($primaRiga, ';') > substr_count($primaRiga, ',')) ? ';' : ',';
    $righe = [];
    $intestazione = null;
    while (($riga = fgetcsv($handle, 1000, $separatore)) !== false) {
        if (!$intestazione) {
            $intestazione = array_map('strtolower', array_map('trim', $riga));
            continue;
        }
        if (count($riga) < 2) continue;
        $righe[] = array_combine($intestazione, array_pad($riga, count($intestazione), ''));
    }
    fclose($handle);
    return $righe;
}

function leggiExcel(string $path): array {
    $autoload = BASE_PATH . '/vendor/autoload.php';
    if (!file_exists($autoload)) {
        rispostaErrore(501, 'PhpSpreadsheet non installato sul server. Usa CSV oppure il parsing client.');
    }
    require_once $autoload;
    $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($path);
    $reader->setReadDataOnly(true);
    $sp = $reader->load($path);
    $sheet = $sp->getActiveSheet();
    $dati = $sheet->toArray(null, true, true, false);
    if (empty($dati)) rispostaErrore(400, 'File Excel vuoto.');
    $intestazione = array_map('strtolower', array_map('trim', $dati[0]));
    $righe = [];
    for ($i = 1; $i < count($dati); $i++) {
        $r = $dati[$i];
        if (count(array_filter($r, fn($v) => $v !== null && $v !== '')) === 0) continue;
        $righe[] = array_combine($intestazione, array_pad($r, count($intestazione), ''));
    }
    return $righe;
}

// ============================================================
																		  
															   
function importaRighe(array $righe, array $utente, int $casaId): array {
    if (empty($righe)) rispostaErrore(400, 'Nessuna riga da importare.');

    $db = getDB();

    // Categorie e sottocategorie filtrate per casa
    $stmtCat = $db->prepare("SELECT id, LOWER(nome) AS nome, tipo FROM categorie WHERE attiva = 1 AND casa_id = ?");
    $stmtCat->execute([$casaId]);
    $mapCat = [];
    foreach ($stmtCat->fetchAll() as $c) $mapCat[$c['nome']] = $c;

    $stmtSc = $db->prepare("
        SELECT sc.id, sc.id_categoria, LOWER(sc.nome) AS nome
        FROM sottocategorie sc
        JOIN categorie c ON sc.id_categoria = c.id
        WHERE sc.attiva = 1 AND c.casa_id = ?
    ");
    $stmtSc->execute([$casaId]);
    $mapSc = [];
    foreach ($stmtSc->fetchAll() as $s) $mapSc[$s['id_categoria']][$s['nome']] = $s['id'];

    $mappaColonne = [
        'data'           => ['data', 'data_spesa', 'data spesa', 'date'],
        'categoria'      => ['categoria', 'category', 'cat'],
        'sottocategoria' => ['sottocategoria', 'sotto categoria', 'sottocat', 'subcategory'],
        'descrizione'    => ['descrizione', 'description', 'nota', 'note', 'desc'],
        'importo'        => ['importo', 'amount', 'valore', 'euro', 'importo (€)'],
        'privata'        => ['privata', 'private', 'nascosta'],
    ];
    $trovaCampo = function (array $riga, array $nomi) {
											  
        $lower = [];
        foreach ($riga as $k => $v) $lower[strtolower((string)$k)] = $v;
        foreach ($nomi as $n) {
            if (array_key_exists($n, $lower)) return $lower[$n];
            if (array_key_exists($n, $riga)) return $riga[$n];
        }
        return null;
    };

    $importate = 0;
    $errori = [];
    $saltate = 0;

    $db->beginTransaction();
    try {
        $stmt = $db->prepare("
            INSERT INTO spese (casa_id, id_utente, data_spesa, id_categoria, id_sottocategoria, descrizione, importo, privata)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");

        foreach ($righe as $idx => $riga) {
            $numRiga = $idx + 2;
            $dataRaw     = trim((string)($trovaCampo($riga, $mappaColonne['data']) ?? ''));
            $catNome     = strtolower(trim((string)($trovaCampo($riga, $mappaColonne['categoria']) ?? '')));
            $scNome      = strtolower(trim((string)($trovaCampo($riga, $mappaColonne['sottocategoria']) ?? '')));
            $descrizione = sanitizzaStringa((string)($trovaCampo($riga, $mappaColonne['descrizione']) ?? ''));
            $importoRaw  = str_replace([' '], '', str_replace(',', '.', (string)($trovaCampo($riga, $mappaColonne['importo']) ?? '')));
																						 
            $importoRaw  = preg_replace('/\.(?=\d{3}(\D|$))/', '', $importoRaw);
            $privataRaw  = strtolower(trim((string)($trovaCampo($riga, $mappaColonne['privata']) ?? 'no')));

            $data = null;
            foreach (['d/m/Y', 'Y-m-d', 'd-m-Y', 'm/d/Y', 'd/m/y'] as $fmt) {
                $dt = DateTime::createFromFormat($fmt, $dataRaw);
                if ($dt && $dt->format($fmt) === $dataRaw) { $data = $dt->format('Y-m-d'); break; }
            }
            if (!$data) {
                $errori[] = "Riga $numRiga: data non valida '$dataRaw'.";
                $saltate++; continue;
            }
            $importo = filter_var($importoRaw, FILTER_VALIDATE_FLOAT);
            if ($importo === false || $importoRaw === '') {
                $errori[] = "Riga $numRiga: importo non valido '$importoRaw'.";
                $saltate++; continue;
            }
            $importo = round((float)$importo, 2);

            if (empty($catNome) || !isset($mapCat[$catNome])) {
                $errori[] = "Riga $numRiga: categoria '$catNome' non trovata.";
                $saltate++; continue;
            }
            $cat = $mapCat[$catNome];
            if ($cat['tipo'] === 'accredito' && $importo > 0) $importo = -abs($importo);
            if ($cat['tipo'] === 'uscita' && $importo < 0) $importo = abs($importo);

            $idSc = null;
            if ($scNome && isset($mapSc[$cat['id']][$scNome])) {
                $idSc = $mapSc[$cat['id']][$scNome];
            }
            $privata = in_array($privataRaw, ['si', 'sì', 'yes', '1', 'true', 'vero', 'x'], true) ? 1 : 0;

            $stmt->execute([$casaId, $utente['id'], $data, $cat['id'], $idSc, $descrizione, $importo, $privata]);
            $importate++;
        }
        $db->commit();
    } catch (\Throwable $e) {
        $db->rollBack();
        logErrore('Import fallito: ' . $e->getMessage());
        rispostaErrore(500, 'Errore durante l\'importazione. Nessuna riga salvata.');
    }

    return [
        'importate' => $importate,
        'saltate'   => $saltate,
        'errori'    => $errori,
        'messaggio' => "Import completato: $importate righe importate, $saltate saltate.",
    ];
}
