<?php
ob_start();
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(0);
// ============================================================
// PIVOT.PHP - Dashboard KPI + Pivot configurabili (multi-casa)
// ============================================================
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/ricorrenti_gen.php';
inizializzaAPI();

set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) return false;
    if (function_exists('logErrore')) logErrore("PHP: $message in $file:$line");
    rispostaErrore(500, "Errore PHP: $message");
    return true;
});
set_exception_handler(function (\Throwable $e) {
    if (function_exists('logErrore')) logErrore('Uncaught: ' . $e->getMessage());
    rispostaErrore(500, 'Errore: ' . $e->getMessage());
});

// ------------------------------------------------------------
// EVENTI (definite PRIMA del dispatch: le const non sono hoisted): join ed espressioni condivise da pivot e dashboard
// Una spesa appartiene a un evento se:
//  - è assegnata a una mini-categoria (spese_eventi), oppure
//  - è inserita in EVENTI › <evento> (eventi.sottocategoria_id)
//    anche se la popup è stata saltata.
// ------------------------------------------------------------
const JOIN_EVENTI = "
        LEFT JOIN spese_eventi sev        ON sev.spesa_id = s.id
        LEFT JOIN eventi_categorie ev_cat ON ev_cat.id = sev.evento_categoria_id
        LEFT JOIN eventi ev_ass           ON ev_ass.id = ev_cat.evento_id
        LEFT JOIN eventi ev_sub           ON ev_sub.sottocategoria_id = s.id_sottocategoria
                                         AND ev_sub.casa_id = s.casa_id
";
const EXPR_EVENTO_ID = 'COALESCE(ev_ass.id, ev_sub.id)';

$metodo = $_SERVER['REQUEST_METHOD'];
$azione = $_GET['azione'] ?? '';
$id     = isset($_GET['id']) ? validaIntero($_GET['id'], 'ID') : null;

match(true) {
    $metodo === 'GET'    && $azione === 'dashboard'           => dashboard(),
    $metodo === 'GET'    && $azione === 'esegui'   &&  $id   => eseguiPivot($id),
    $metodo === 'GET'    && $azione === 'lista'               => listaPivot(),
    $metodo === 'GET'    && $azione === 'listaADM'            => listaPivotADM(),
    $metodo === 'POST'   && $azione === 'crea'                => creaPivot(),
    $metodo === 'PUT'    && $azione === 'aggiorna' &&  $id   => aggiornaPivot($id),
    $metodo === 'DELETE' &&                            $id   => eliminaPivot($id),
    default => rispostaErrore(404, 'Endpoint non trovato.')
};


// ------------------------------------------------------------
// Clausola WHERE privacy (riutilizzata ovunque)
// $isAdmin=true → nessun filtro aggiuntivo
// ------------------------------------------------------------
function wherePrivacy(bool $isAdmin, int $idUtente, string $alias = 's'): array {
    if ($isAdmin) return ['', []];
    return ["($alias.privata = 0 OR $alias.id_utente = ?)", [$idUtente]];
}

// ------------------------------------------------------------
// Filtri comuni da GET (data, categoria, utente)
// ------------------------------------------------------------
function filtriComuni(array &$dove, array &$params, string $alias = 's'): void {
    if (!empty($_GET['anno'])) {
        $anno     = (int)$_GET['anno'];
        $dove[]   = sqlAnno("$alias.data_spesa") . ' = ?';
        $params[] = $anno;
    }
    if (!empty($_GET['mese'])) {
        $mese     = max(1, min(12, (int)$_GET['mese']));
        $dove[]   = sqlMese("$alias.data_spesa") . ' = ?';
        $params[] = $mese;
    }
    if (!empty($_GET['data_da'])) {
        $dove[]   = "$alias.data_spesa >= ?";
        $params[] = validaData($_GET['data_da']);
    }
    if (!empty($_GET['data_a'])) {
        $dove[]   = "$alias.data_spesa <= ?";
        $params[] = validaData($_GET['data_a']);
    }
    if (!empty($_GET['id_categoria'])) {
        $dove[]   = "$alias.id_categoria = ?";
        $params[] = validaIntero($_GET['id_categoria'], 'Categoria');
    }
    if (!empty($_GET['id_utente'])) {
        $dove[]   = "$alias.id_utente = ?";
        $params[] = validaIntero($_GET['id_utente'], 'Utente');
    }
}

// ============================================================
// DASHBOARD
// ============================================================
function dashboard(): void {
    [$utente, $casaId] = richiedeAccessoCasa();
    rilasciaSessione();
    $isAdmin = checkPermesso($utente['id'], $casaId );//, 'admin');
    $db      = getDB();

    // La dashboard è la prima vista all'apertura: genera qui le ricorrenti scadute.
    generaRicorrenzeScadute($db, $casaId);

    [$privacySQL, $privacyParams] = wherePrivacy($isAdmin, $utente['id']);

    $anno = (int)($_GET['anno'] ?? date('Y'));
    $mese = !empty($_GET['mese']) ? (int)$_GET['mese'] : null;

    $dove   = ['s.casa_id = ?', sqlAnno('s.data_spesa') . ' = ?'];
    $params = [$casaId, $anno];
    if ($privacySQL) { $dove[] = $privacySQL; $params = array_merge($params, $privacyParams); }
    if ($mese)       { $dove[] = sqlMese('s.data_spesa') . ' = ?'; $params[] = $mese; }

    $where = 'WHERE ' . implode(' AND ', $dove);

    // --- KPI principali ---
    $kpi = $db->prepare("
        SELECT
            SUM(CASE WHEN c.tipo = 'uscita'    THEN s.importo ELSE 0 END)      AS totale_uscite,
            SUM(CASE WHEN c.tipo = 'accredito' THEN ABS(s.importo) ELSE 0 END) AS totale_entrate,
            SUM(CASE WHEN c.tipo = 'uscita' AND " . EXPR_EVENTO_ID . " IS NOT NULL
                     THEN s.importo ELSE 0 END)                                  AS uscite_eventi,
            COUNT(*)                                                             AS numero_spese
        FROM spese s
        JOIN categorie c ON c.id = s.id_categoria
        " . JOIN_EVENTI . "
        $where
    ");
    $kpi->execute($params);
    $kpiDati = $kpi->fetch();

    // --- Uscite per categoria (grafico torta) ---
    // Le spese collegate a un evento confluiscono tutte nella fetta "EVENTI",
    // qualunque sia la categoria in cui sono state inserite.
    $stmtCol = $db->prepare("SELECT colore FROM categorie WHERE casa_id = ? AND sistema = 'eventi'");
    $stmtCol->execute([$casaId]);
    $coloreEventi = $stmtCol->fetchColumn() ?: '#4f46e5';

    $perCat = $db->prepare("
        SELECT
            CASE WHEN " . EXPR_EVENTO_ID . " IS NOT NULL THEN 'EVENTI' ELSE c.nome END   AS nome,
            CASE WHEN " . EXPR_EVENTO_ID . " IS NOT NULL THEN ? ELSE c.colore END        AS colore,
            SUM(s.importo) AS totale
        FROM spese s
        JOIN categorie c ON c.id = s.id_categoria
        " . JOIN_EVENTI . "
        $where AND c.tipo = 'uscita'
        GROUP BY 1, 2
        ORDER BY totale DESC
    ");
    $perCat->execute(array_merge([$coloreEventi], $params));

    // --- Andamento mensile anno corrente (grafico barre) ---
    $mensileParams = array_merge([$casaId, $anno], $privacyParams);
    $mensile = $db->prepare("
        SELECT
            " . sqlMese('s.data_spesa') . " AS mese,
            SUM(CASE WHEN c.tipo = 'uscita'    THEN s.importo ELSE 0 END)      AS uscite,
            SUM(CASE WHEN c.tipo = 'accredito' THEN ABS(s.importo) ELSE 0 END) AS entrate
        FROM spese s
        JOIN categorie c ON c.id = s.id_categoria
        WHERE s.casa_id = ? AND " . sqlAnno('s.data_spesa') . " = ?
        " . ($privacySQL ? "AND $privacySQL" : '') . "
        GROUP BY " . sqlMese('s.data_spesa') . "
        ORDER BY mese ASC
    ");
    $mensile->execute($mensileParams);

    // --- Ultime 10 spese ---
    $ultime = $db->prepare("
        SELECT s.id, s.data_spesa, s.descrizione, s.importo, s.privata,
               c.nome AS categoria, c.colore AS categoria_colore, c.tipo AS categoria_tipo,
               u.nome AS utente_nome, u.id AS utente_id
        FROM spese s
        JOIN categorie c ON c.id = s.id_categoria
        JOIN utenti u    ON u.id = s.id_utente
        $where
        ORDER BY s.data_spesa DESC, s.id DESC
        LIMIT 10
    ");
    $ultime->execute($params);
    $ultimeRis = $ultime->fetchAll();
    foreach ($ultimeRis as &$r) {
        $r['importo'] = (float)$r['importo'];
        $r['privata'] = (bool)$r['privata'];
    }

    $uscite  = (float)($kpiDati['totale_uscite']  ?? 0);
    $usciteEventi = (float)($kpiDati['uscite_eventi'] ?? 0);
    $entrate = (float)($kpiDati['totale_entrate'] ?? 0);

    risposta([
        'anno'          => $anno,
        'mese'          => $mese,
        'kpi'           => [
            'uscite'       => round($uscite, 2),
            'entrate'      => round($entrate, 2),
            'saldo'        => round($entrate - $uscite, 2),
            'uscite_eventi'=> round($usciteEventi, 2),
            'numero_spese' => (int)$kpiDati['numero_spese'],
        ],
        'per_categoria' => $perCat->fetchAll(),
        'mensile'       => $mensile->fetchAll(),
        'ultime_spese'  => $ultimeRis,
    ]);
}

// ============================================================
// PIVOT - Lista configurazioni (membri)
// ============================================================
function listaPivot(): void {
    [, $casaId] = richiedeAccessoCasa();
    rilasciaSessione();
    $stmt = getDB()->prepare("
        SELECT * FROM pivot_configurazioni
        WHERE casa_id = ? AND visibile = 1
        ORDER BY ordinamento ASC
    ");
    $stmt->execute([$casaId]);
    risposta(['pivot' => $stmt->fetchAll()]);
}

// ============================================================
// PIVOT - Lista configurazioni (admin di casa)
// ============================================================
function listaPivotADM(): void {
    [, $casaId] = richiedeAdminDiCasa();
    rilasciaSessione();
    $stmt = getDB()->prepare("
        SELECT * FROM pivot_configurazioni
        WHERE casa_id = ?
        ORDER BY ordinamento ASC
    ");
    $stmt->execute([$casaId]);
    risposta(['pivot' => $stmt->fetchAll()]);
}

// ============================================================
// PIVOT - Esecuzione
// ============================================================
function eseguiPivot(int $id): void {
    [$utente, $casaId] = richiedeAccessoCasa();
    rilasciaSessione();
    $isAdmin = checkPermesso($utente['id'], $casaId );//, 'admin');
    $db      = getDB();

    $stmt = $db->prepare("SELECT * FROM pivot_configurazioni WHERE id = ? AND casa_id = ?");
    $stmt->execute([$id, $casaId]);
    $config = $stmt->fetch();
    if (!$config) rispostaErrore(404, 'Pivot non trovata.');

    $rigaDims = array_values(array_filter([
        $config['riga']   ?? null,
        $config['riga_2'] ?? null,
        $config['riga_3'] ?? null,
    ]));
    if (empty($rigaDims)) $rigaDims = ['categoria'];
    $colonnaDim = $config['colonna']     ?? 'mese';
    $valore     = $config['valore']      ?? 'somma';
    $filtroTipo   = $config['filtro_tipo']   ?? 'tutti';
    $filtroEvento = $config['filtro_evento'] ?? 'tutti';

    $mappaSelect = function (string $dim): string {
        return match ($dim) {
            'categoria'        => 'c.nome',
            'sottocategoria'   => "COALESCE(sc.nome, 'Senza sottocategoria')",
            'utente'           => 'u.nome',
            'mese'             => sqlEtichette(sqlMese('s.data_spesa'), ['Gennaio','Febbraio','Marzo','Aprile','Maggio','Giugno','Luglio','Agosto','Settembre','Ottobre','Novembre','Dicembre']),
            'anno'             => sqlAnno('s.data_spesa'),
            'giorno_settimana' => sqlEtichette(sqlGiornoSettimana('s.data_spesa'), ['Lunedì','Martedì','Mercoledì','Giovedì','Venerdì','Sabato','Domenica']),
            'tipo'             => "CASE c.tipo WHEN 'accredito' THEN 'Entrate' ELSE 'Uscite' END",
            // Eventi: uniscono le spese inserite in EVENTI e quelle assegnate dalla popup
            'evento'           => "COALESCE(ev_ass.nome, ev_sub.nome, 'Nessun evento')",
            'voce_evento'      => "COALESCE(ev_cat.nome, CASE WHEN ev_sub.id IS NOT NULL THEN 'Voce non assegnata' END, 'Nessun evento')",
            'categoria_eventi' => "CASE WHEN " . EXPR_EVENTO_ID . " IS NOT NULL THEN 'EVENTI' ELSE c.nome END",
            // Spese di un evento: nome evento; altre spese: sottocategoria
            'sottocategoria_evento' => "COALESCE(ev_ass.nome, ev_sub.nome, sc.nome, 'Senza sottocategoria')",
            default            => 'c.nome',
        };
    };

    $valExpr = match ($valore) {
        'conteggio' => 'COUNT(*)',
        'media'     => 'AVG(ABS(s.importo))',
        default     => 'SUM(ABS(s.importo))',
    };

    $selectRighe = [];
    $groupBy     = [];
    foreach ($rigaDims as $i => $d) {
        $selectRighe[] = $mappaSelect($d) . ' AS riga_' . $i;
        $groupBy[]     = $mappaSelect($d);
    }
    $selectRighe = implode(', ', $selectRighe);
    $groupBy[]   = $mappaSelect($colonnaDim);
    $groupBySQL  = implode(', ', $groupBy);

    $dove   = ['s.casa_id = ?'];
    $params = [$casaId];

    // Privacy scoped per casa
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
        $params[] = (int)$_GET['mese'];
    }
    if ($filtroTipo === 'uscita') {
        $dove[] = "c.tipo = 'uscita'";
    } elseif ($filtroTipo === 'accredito') {
        $dove[] = "c.tipo = 'accredito'";
    }
    if ($filtroEvento === 'esclusi') {
        $dove[] = EXPR_EVENTO_ID . ' IS NULL';
    } elseif ($filtroEvento === 'solo') {
        $dove[] = EXPR_EVENTO_ID . ' IS NOT NULL';
    }

    $whereSQL = 'WHERE ' . implode(' AND ', $dove);

    $sql = "
        SELECT
            $selectRighe,
            " . $mappaSelect($colonnaDim) . " AS colonna,
            $valExpr AS valore
        FROM spese s
        JOIN categorie c        ON c.id = s.id_categoria
        LEFT JOIN sottocategorie sc ON sc.id = s.id_sottocategoria
        LEFT JOIN utenti u      ON u.id = s.id_utente
        " . JOIN_EVENTI . "
        $whereSQL
        GROUP BY $groupBySQL
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $colonneUniche = array_values(array_unique(array_column($rows, 'colonna')));
    $ordinaDimensione = function (array &$valori, string $dim) {
        $mesi   = ['Gennaio','Febbraio','Marzo','Aprile','Maggio','Giugno','Luglio','Agosto','Settembre','Ottobre','Novembre','Dicembre'];
        $giorni = ['Lunedì','Martedì','Mercoledì','Giovedì','Venerdì','Sabato','Domenica'];
        if ($dim === 'mese') {
            $idx = array_flip($mesi);
            usort($valori, fn($a, $b) => ($idx[$a] ?? 99) <=> ($idx[$b] ?? 99));
        } elseif ($dim === 'giorno_settimana') {
            $idx = array_flip($giorni);
            usort($valori, fn($a, $b) => ($idx[$a] ?? 99) <=> ($idx[$b] ?? 99));
        } elseif ($dim === 'anno') {
            sort($valori, SORT_NUMERIC);
        } else {
            sort($valori, SORT_NATURAL | SORT_FLAG_CASE);
        }
        // Le voci "senza evento" vanno in fondo
        foreach (['Voce non assegnata', 'Nessun evento'] as $inFondo) {
            $k = array_search($inFondo, $valori, true);
            if ($k !== false) { unset($valori[$k]); $valori[] = $inFondo; $valori = array_values($valori); }
        }
    };
    $ordinaDimensione($colonneUniche, $colonnaDim);

    $costruisciAlbero = function (array $rows, int $livello, int $maxLiv) use (&$costruisciAlbero, $colonneUniche) {
        $gruppi = [];
        foreach ($rows as $r) {
            $key = $r['riga_' . $livello] ?? '—';
            $gruppi[$key][] = $r;
        }
        ksort($gruppi, SORT_NATURAL | SORT_FLAG_CASE);
        $nodi = [];
        foreach ($gruppi as $label => $sub) {
            $valori = array_fill_keys($colonneUniche, 0);
            foreach ($sub as $r) $valori[$r['colonna']] += (float)$r['valore'];
            $nodo = [
                'label'   => (string)$label,
                'livello' => $livello,
                'valori'  => array_map(fn($v) => round($v, 2), $valori),
                'totale'  => round(array_sum($valori), 2),
                'figli'   => [],
            ];
            if ($livello + 1 < $maxLiv) {
                $nodo['figli'] = $costruisciAlbero($sub, $livello + 1, $maxLiv);
            }
            $nodi[] = $nodo;
        }
        return $nodi;
    };

    $tree = $costruisciAlbero($rows, 0, count($rigaDims));

    $totaliColonne = array_fill_keys($colonneUniche, 0);
    foreach ($tree as $n) foreach ($colonneUniche as $c) $totaliColonne[$c] += $n['valori'][$c];
    $totaliColonne = array_map(fn($v) => round($v, 2), $totaliColonne);

    risposta([
        'config'         => $config,
        'colonne'        => $colonneUniche,
        'tree'           => $tree,
        'totali_colonne' => $totaliColonne,
    ]);
}

// ============================================================
// PIVOT - CRUD (solo admin di casa)
// ============================================================
function creaPivot(): void {
    [, $casaId] = richiedeAdminDiCasa();
    $dati = inputJSON();
    verificaCSRF($dati['csrf_token'] ?? null);

    $campiValidi  = ['categoria','sottocategoria','utente','mese','anno','giorno_settimana','tipo',
                     'evento','voce_evento','categoria_eventi','sottocategoria_evento'];
    $valoriValidi = ['somma','conteggio','media'];
    $tipiValidi   = ['uscita','accredito','tutti'];
    $eventiValidi = ['tutti','esclusi','solo'];

    $nome        = sanitizzaStringa($dati['nome'] ?? '', 100);
    $descrizione = sanitizzaStringa($dati['descrizione'] ?? '', 255);
    $riga        = in_array($dati['riga']   ?? '', $campiValidi) ? $dati['riga']   : null;
    $riga2       = in_array($dati['riga_2'] ?? '', $campiValidi) ? $dati['riga_2'] : null;
    $riga3       = in_array($dati['riga_3'] ?? '', $campiValidi) ? $dati['riga_3'] : null;
    $colonna     = in_array($dati['colonna'] ?? '', $campiValidi) ? $dati['colonna'] : null;
    $valore      = in_array($dati['valore'] ?? '', $valoriValidi) ? $dati['valore'] : 'somma';
    $filtroTipo  = in_array($dati['filtro_tipo'] ?? '', $tipiValidi) ? $dati['filtro_tipo'] : 'tutti';
    $filtroEv    = in_array($dati['filtro_evento'] ?? '', $eventiValidi) ? $dati['filtro_evento'] : 'tutti';
    $ordinamento = (int)($dati['ordinamento'] ?? 0);

    if (empty($nome) || !$riga || !$colonna) rispostaErrore(400, 'Nome, riga e colonna obbligatori.');
    if ($riga === $colonna) rispostaErrore(400, 'Riga e colonna non possono essere uguali.');

    $db = getDB();
    $db->prepare("
        INSERT INTO pivot_configurazioni (casa_id, nome, descrizione, riga, riga_2, riga_3, colonna, valore, filtro_tipo, filtro_evento, ordinamento)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ")->execute([$casaId, $nome, $descrizione, $riga, $riga2, $riga3, $colonna, $valore, $filtroTipo, $filtroEv, $ordinamento]);

    risposta(['id' => ultimoId('pivot_configurazioni'), 'messaggio' => 'Pivot creata.'], 201);
}

function aggiornaPivot(int $id): void {
    [, $casaId] = richiedeAdminDiCasa();
    $dati = inputJSON();
    verificaCSRF($dati['csrf_token'] ?? null);

    $campiValidi  = ['categoria','sottocategoria','utente','mese','anno','giorno_settimana','tipo',
                     'evento','voce_evento','categoria_eventi','sottocategoria_evento'];
    $valoriValidi = ['somma','conteggio','media'];
    $tipiValidi   = ['uscita','accredito','tutti'];
    $eventiValidi = ['tutti','esclusi','solo'];

    $nome        = sanitizzaStringa($dati['nome'] ?? '', 100);
    $descrizione = sanitizzaStringa($dati['descrizione'] ?? '', 255);
    $riga        = in_array($dati['riga']   ?? '', $campiValidi) ? $dati['riga']   : null;
    $riga2       = in_array($dati['riga_2'] ?? '', $campiValidi) ? $dati['riga_2'] : null;
    $riga3       = in_array($dati['riga_3'] ?? '', $campiValidi) ? $dati['riga_3'] : null;
    $colonna     = in_array($dati['colonna'] ?? '', $campiValidi) ? $dati['colonna'] : null;
    $valore      = in_array($dati['valore'] ?? '', $valoriValidi) ? $dati['valore'] : 'somma';
    $filtroTipo  = in_array($dati['filtro_tipo'] ?? '', $tipiValidi) ? $dati['filtro_tipo'] : 'tutti';
    $filtroEv    = in_array($dati['filtro_evento'] ?? '', $eventiValidi) ? $dati['filtro_evento'] : 'tutti';
    $visibile    = !empty($dati['visibile']) ? 1 : 0;
    $ordinamento = (int)($dati['ordinamento'] ?? 0);

    if (empty($nome) || !$riga || !$colonna) rispostaErrore(400, 'Nome, riga e colonna obbligatori.');
    if ($riga === $colonna) rispostaErrore(400, 'Riga e colonna non possono essere uguali.');

    getDB()->prepare("
        UPDATE pivot_configurazioni
        SET nome=?, descrizione=?, riga=?, riga_2=?, riga_3=?, colonna=?, valore=?, filtro_tipo=?, filtro_evento=?, visibile=?, ordinamento=?
        WHERE id=? AND casa_id=?
    ")->execute([$nome, $descrizione, $riga, $riga2, $riga3, $colonna, $valore, $filtroTipo, $filtroEv, $visibile, $ordinamento, $id, $casaId]);

    risposta(['messaggio' => 'Pivot aggiornata.']);
}

function eliminaPivot(int $id): void {
    [, $casaId] = richiedeAdminDiCasa();
    getDB()->prepare("DELETE FROM pivot_configurazioni WHERE id = ? AND casa_id = ?")->execute([$id, $casaId]);
    risposta(['messaggio' => 'Pivot eliminata.']);
}
