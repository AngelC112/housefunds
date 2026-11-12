<?php
ob_start();
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(0);
// ============================================================
// SPESE.PHP - CRUD Spese (multi-casa)
// ============================================================
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/ricorrenti_gen.php';
inizializzaAPI();

$metodo = $_SERVER['REQUEST_METHOD'];
$id     = isset($_GET['id']) ? validaIntero($_GET['id'], 'ID') : null;

match(true) {
    $metodo === 'GET'    && !$id  => lista(),
    $metodo === 'GET'    &&  $id  => dettaglio($id),
    $metodo === 'POST'            => crea(),
    $metodo === 'PUT'    &&  $id  => aggiorna($id),
    $metodo === 'DELETE' &&  $id  => elimina($id),
    default => rispostaErrore(404, 'Endpoint non trovato.')
};

// ------------------------------------------------------------
function lista(): void {
    [$utente, $casaId] = richiedeAccessoCasa();
    $isAdmin = checkPermesso($utente['id'], $casaId);//, 'admin');
    $db      = getDB();

    // Materializza le scadenze arretrate delle spese ricorrenti (generazione pigra).
    generaRicorrenzeScadute($db, $casaId);

    $dove   = ['s.casa_id = ?'];
    $params = [$casaId];

    // Filtro privacy: i membri vedono solo le spese non-private + le proprie
    //if (!$isAdmin) {
        $dove[]   = '(s.privata = 0 OR s.id_utente = ?)';
        $params[] = $utente['id'];
    //}

    if (!empty($_GET['anno'])) {
        $anno = (int)$_GET['anno'];
        if ($anno >= 1900 && $anno <= 2100) {
            $dove[]   = sqlAnno('s.data_spesa') . ' = ?';
            $params[] = $anno;
        }
    }
    if (!empty($_GET['mese'])) {
        $mese = (int)$_GET['mese'];
        if ($mese >= 1 && $mese <= 12) {
            $dove[]   = sqlMese('s.data_spesa') . ' = ?';
            $params[] = $mese;
        }
    }
    if (!empty($_GET['evento_id'])) {
        $eventoId = validaIntero($_GET['evento_id'], 'Evento');
        $dove[]   = 'EXISTS (SELECT 1 FROM spese_eventi se JOIN eventi_categorie ec ON ec.id = se.evento_categoria_id WHERE se.spesa_id = s.id AND ec.evento_id = ?)';
        $params[] = $eventoId;
    }
    if (!empty($_GET['id_sottocategoria_evento'])) {
        $ecId   = validaIntero($_GET['id_sottocategoria_evento'], 'Categoria evento');
        $dove[] = 'EXISTS (SELECT 1 FROM spese_eventi se WHERE se.spesa_id = s.id AND se.evento_categoria_id = ?)';
        $params[] = $ecId;
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
    if (!empty($_GET['id_sottocategoria'])) {
        $dove[]   = 's.id_sottocategoria = ?';
        $params[] = validaIntero($_GET['id_sottocategoria'], 'Sottocategoria');
    }
    if (!empty($_GET['id_utente'])) {
        // Un membro può filtrare solo per se stesso
        $filtroUtente = validaIntero($_GET['id_utente'], 'Utente');
        /*if (!$isAdmin && $filtroUtente !== $utente['id']) {
            rispostaErrore(403, 'Non puoi filtrare per altri utenti.');
        }
		*/
        $dove[]   = 's.id_utente = ?';
        $params[] = $filtroUtente;
    }
    if (isset($_GET['privata'])) {
        // Solo l'admin può filtrare per privata=1 (vedrebbe spese private altrui)
        /*if (!$isAdmin && $_GET['privata'] === '1') {
            rispostaErrore(403, 'Non puoi filtrare le spese private altrui.');
        }
		*/
        $dove[]   = 's.privata = ?';
        $params[] = $_GET['privata'] === '1' ? 1 : 0;
    }
    if (!empty($_GET['cerca'])) {
        $dove[]   = 's.descrizione ' . sqlIlike() . ' ?';
        $params[] = '%' . sanitizzaStringa($_GET['cerca']) . '%';
    }

    // Paginazione
    $pagina    = max(1, (int)($_GET['pagina'] ?? 1));
    $perPagina = min(200, max(10, (int)($_GET['per_pagina'] ?? 50)));
    $offset    = ($pagina - 1) * $perPagina;

    $whereSQL = 'WHERE ' . implode(' AND ', $dove);

    // Conteggio totale + totali importi
    $stmtTot = $db->prepare("
        SELECT
            COUNT(*) AS totale_righe,
            COALESCE(SUM(CASE WHEN c.tipo='uscita'    THEN s.importo       ELSE 0 END), 0) AS totale_uscite,
            COALESCE(SUM(CASE WHEN c.tipo='accredito' THEN ABS(s.importo)  ELSE 0 END), 0) AS totale_entrate
        FROM spese s
        JOIN categorie c ON c.id = s.id_categoria
        $whereSQL
    ");
    $stmtTot->execute($params);
    $totRow     = $stmtTot->fetch();
    $totale     = (int)$totRow['totale_righe'];
    $totUscite  = (float)$totRow['totale_uscite'];
    $totEntrate = (float)$totRow['totale_entrate'];

    // Ordinamento dinamico (whitelist anti-injection)
    $sortMap = [
        'data'           => 's.data_spesa',
        'descrizione'    => 's.descrizione',
        'importo'        => 's.importo',
        'categoria'      => 'c.nome',
        'sottocategoria' => 'sc.nome',
    ];
    $sortKey = $_GET['sort'] ?? 'data';
    $sortCol = $sortMap[$sortKey] ?? 's.data_spesa';
    $sortDir = (strtolower($_GET['order'] ?? 'desc') === 'asc') ? 'ASC' : 'DESC';
    $orderBy = $sortKey === 'importo'
        ? "ABS($sortCol) $sortDir, s.id DESC"
        : "$sortCol $sortDir, s.id DESC";

    $sql = "
        SELECT
            s.id, s.data_spesa, s.descrizione, s.importo, s.privata, s.id_ricorrenza,
            s.creato_il, s.modificato_il,
            c.id AS categoria_id, c.nome AS categoria, c.tipo AS categoria_tipo, c.colore AS categoria_colore,
            sc.id AS sottocategoria_id, sc.nome AS sottocategoria,
            u.id AS utente_id, u.nome AS utente_nome,
            ev_cat.nome AS evento_categoria,
            ev.nome AS evento_nome,
            ev.colore AS evento_colore
        FROM spese s
        JOIN categorie c    ON c.id = s.id_categoria
        JOIN utenti u       ON u.id = s.id_utente
        LEFT JOIN sottocategorie sc     ON sc.id = s.id_sottocategoria
        LEFT JOIN spese_eventi sev      ON sev.spesa_id = s.id
        LEFT JOIN eventi_categorie ev_cat ON ev_cat.id = sev.evento_categoria_id
        LEFT JOIN eventi ev             ON ev.id = ev_cat.evento_id
        $whereSQL
        ORDER BY $orderBy
        LIMIT $perPagina OFFSET $offset
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $spese = $stmt->fetchAll();

    foreach ($spese as &$s) {
        $s['importo']       = (float)$s['importo'];
        $s['privata']       = (bool)$s['privata'];
        $s['id_ricorrenza'] = $s['id_ricorrenza'] !== null ? (int)$s['id_ricorrenza'] : null;
    }

    risposta([
        'spese' => $spese,
        'paginazione' => [
            'totale'    => $totale,
            'pagina'    => $pagina,
            'per_pagina' => $perPagina,
            'pagine'    => (int)ceil($totale / $perPagina),
        ],
        'totali' => [
            'uscite'  => round($totUscite, 2),
            'entrate' => round($totEntrate, 2),
            'saldo'   => round($totEntrate - $totUscite, 2),
        ],
    ]);
}

// ------------------------------------------------------------
function dettaglio(int $id): void {
    [$utente, $casaId] = richiedeAccessoCasa();
    //$isAdmin = checkPermesso($utente['id'], $casaId);//, 'admin');
    $db      = getDB();

    $stmt = $db->prepare("
        SELECT
            s.id, s.data_spesa, s.descrizione, s.importo, s.privata,
            s.creato_il, s.modificato_il,
            c.id AS categoria_id, c.nome AS categoria, c.tipo AS categoria_tipo, c.colore AS categoria_colore,
            sc.id AS sottocategoria_id, sc.nome AS sottocategoria,
            u.id AS utente_id, u.nome AS utente_nome,
            ev_cat.nome AS evento_categoria,
            ev.nome AS evento_nome,
            ev.colore AS evento_colore
        FROM spese s
        JOIN categorie c    ON c.id = s.id_categoria
        JOIN utenti u       ON u.id = s.id_utente
        LEFT JOIN sottocategorie sc     ON sc.id = s.id_sottocategoria
        LEFT JOIN spese_eventi sev      ON sev.spesa_id = s.id
        LEFT JOIN eventi_categorie ev_cat ON ev_cat.id = sev.evento_categoria_id
        LEFT JOIN eventi ev             ON ev.id = ev_cat.evento_id
        WHERE s.id = ? AND s.casa_id = ?
    ");
    $stmt->execute([$id, $casaId]);
    $spesa = $stmt->fetch();

    if (!$spesa) rispostaErrore(404, 'Spesa non trovata.');

    // Spesa privata: visibile solo al proprietario e all'admin di casa
    //if ($spesa['privata'] && !$isAdmin && $spesa['utente_id'] !== $utente['id']) {
    if ($spesa['privata'] && $spesa['utente_id'] !== $utente['id']) {
        rispostaErrore(403, 'Accesso negato.');
    }

    $spesa['importo'] = (float)$spesa['importo'];
    $spesa['privata'] = (bool)$spesa['privata'];

    risposta(['spesa' => $spesa]);
}

// ------------------------------------------------------------
function crea(): void {
    [$utente, $casaId] = richiedeAccessoCasa();
    $dati = inputJSON();

    verificaCSRF($dati['csrf_token'] ?? null);

    $dataSpesa   = validaData($dati['data_spesa'] ?? '');
    $idCategoria = validaIntero($dati['id_categoria'] ?? 0, 'Categoria');
    $importo     = validaImporto($dati['importo'] ?? null);
    $descrizione = sanitizzaStringa($dati['descrizione'] ?? '', 255);
    $idSottoCat  = !empty($dati['id_sottocategoria']) ? validaIntero($dati['id_sottocategoria'], 'Sottocategoria') : null;
    $privata     = !empty($dati['privata']) ? 1 : 0;

    $db = getDB();

    // Verifica che la categoria esista, sia attiva e appartenga alla casa
    $cat = $db->prepare("SELECT tipo, sistema FROM categorie WHERE id = ? AND casa_id = ? AND attiva = 1");
    $cat->execute([$idCategoria, $casaId]);
    $categoria = $cat->fetch();
    if (!$categoria) rispostaErrore(400, 'Categoria non valida o non appartiene a questa casa.');
    // Categoria di sistema EVENTI: la sottocategoria (= evento) è obbligatoria
    if (!empty($categoria['sistema']) && !$idSottoCat) {
        rispostaErrore(400, "Seleziona l'evento a cui associare la spesa.");
    }

    // Normalizza segno importo in base al tipo categoria
    if ($categoria['tipo'] === 'accredito' && $importo > 0) {
        $importo = -abs($importo);
    } elseif ($categoria['tipo'] === 'uscita' && $importo < 0) {
        $importo = abs($importo);
    }

    // Verifica che la sottocategoria appartenga alla categoria (e quindi alla casa)
    if ($idSottoCat) {
        $sc = $db->prepare("SELECT id FROM sottocategorie WHERE id = ? AND id_categoria = ? AND attiva = 1");
        $sc->execute([$idSottoCat, $idCategoria]);
        if (!$sc->fetch()) rispostaErrore(400, 'Sottocategoria non valida.');
    }

    $stmt = $db->prepare("
        INSERT INTO spese (casa_id, id_utente, data_spesa, id_categoria, id_sottocategoria, descrizione, importo, privata)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$casaId, $utente['id'], $dataSpesa, $idCategoria, $idSottoCat, $descrizione, $importo, $privata]);

    risposta(['id' => ultimoId('spese'), 'messaggio' => 'Spesa inserita.'], 201);
}

// ------------------------------------------------------------
function aggiorna(int $id): void {
    [$utente, $casaId] = richiedeAccessoCasa();
    // checkPermesso(..., 'admin') risponderebbe 403 ai non admin, impedendo
    // all'autore di agire sulle proprie spese: qui serve solo sapere il ruolo.
    $isAdmin = checkPermesso($utente['id'], $casaId) === 'admin';
    $dati    = inputJSON();

    verificaCSRF($dati['csrf_token'] ?? null);

    $db   = getDB();
    $stmt = $db->prepare("SELECT id, id_utente FROM spese WHERE id = ? AND casa_id = ?");
    $stmt->execute([$id, $casaId]);
    $spesa = $stmt->fetch();

    if (!$spesa) rispostaErrore(404, 'Spesa non trovata.');

    // Solo il proprietario o l'admin di casa può modificare
    if (!$isAdmin && (int)$spesa['id_utente'] !== (int)$utente['id']) {
        rispostaErrore(403, 'Non puoi modificare questa spesa.');
    }

    $dataSpesa   = validaData($dati['data_spesa'] ?? '');
    $idCategoria = validaIntero($dati['id_categoria'] ?? 0, 'Categoria');
    $importo     = validaImporto($dati['importo'] ?? null);
    $descrizione = sanitizzaStringa($dati['descrizione'] ?? '', 255);
    $idSottoCat  = !empty($dati['id_sottocategoria']) ? validaIntero($dati['id_sottocategoria'], 'Sottocategoria') : null;
    $privata     = !empty($dati['privata']) ? 1 : 0;

    // Verifica categoria appartenga alla casa
    $cat = $db->prepare("SELECT tipo, sistema FROM categorie WHERE id = ? AND casa_id = ? AND attiva = 1");
    $cat->execute([$idCategoria, $casaId]);
    $categoria = $cat->fetch();
    if (!$categoria) rispostaErrore(400, 'Categoria non valida o non appartiene a questa casa.');
    // Categoria di sistema EVENTI: la sottocategoria (= evento) è obbligatoria
    if (!empty($categoria['sistema']) && !$idSottoCat) {
        rispostaErrore(400, "Seleziona l'evento a cui associare la spesa.");
    }

    if ($categoria['tipo'] === 'accredito' && $importo > 0) {
        $importo = -abs($importo);
    } elseif ($categoria['tipo'] === 'uscita' && $importo < 0) {
        $importo = abs($importo);
    }

    $db->prepare("
        UPDATE spese
        SET data_spesa=?, id_categoria=?, id_sottocategoria=?, descrizione=?, importo=?, privata=?
        WHERE id=? AND casa_id=?
    ")->execute([$dataSpesa, $idCategoria, $idSottoCat, $descrizione, $importo, $privata, $id, $casaId]);

    risposta(['messaggio' => 'Spesa aggiornata.']);
}

// ------------------------------------------------------------
function elimina(int $id): void {
    [$utente, $casaId] = richiedeAccessoCasa();
    // checkPermesso(..., 'admin') risponderebbe 403 ai non admin, impedendo
    // all'autore di agire sulle proprie spese: qui serve solo sapere il ruolo.
    $isAdmin = checkPermesso($utente['id'], $casaId) === 'admin';
    $db      = getDB();

    $stmt = $db->prepare("SELECT id, id_utente FROM spese WHERE id = ? AND casa_id = ?");
    $stmt->execute([$id, $casaId]);
    $spesa = $stmt->fetch();

    if (!$spesa) rispostaErrore(404, 'Spesa non trovata.');

    // Solo il proprietario o l'admin di casa può eliminare
    if (!$isAdmin && (int)$spesa['id_utente'] !== (int)$utente['id']) {
        rispostaErrore(403, 'Non puoi eliminare questa spesa.');
    }

    $db->prepare("DELETE FROM spese WHERE id = ? AND casa_id = ?")->execute([$id, $casaId]);
    risposta(['messaggio' => 'Spesa eliminata.']);
}
