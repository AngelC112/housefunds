<?php
ob_start();
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(0);
// ============================================================
// RICORRENTI.PHP - CRUD Spese ricorrenti (multi-casa)
//
// Un modello genera spese reali nella tabella spese; la generazione è
// gestita da ricorrenti_gen.php (chiamata anche da spese.php).
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
// Legge e valida i campi comuni a creazione e modifica.
// Ritorna i dati validati, con l'importo già col segno giusto.
// ------------------------------------------------------------
function leggiDatiRicorrente(PDO $db, int $casaId): array {
    $dati = inputJSON();
    verificaCSRF($dati['csrf_token'] ?? null);

    $idCategoria = validaIntero($dati['id_categoria'] ?? 0, 'Categoria');
    $importo     = validaImporto($dati['importo'] ?? null);
    $descrizione = sanitizzaStringa($dati['descrizione'] ?? '', 255);
    $idSottoCat  = !empty($dati['id_sottocategoria']) ? validaIntero($dati['id_sottocategoria'], 'Sottocategoria') : null;
    $privata     = !empty($dati['privata']) ? 1 : 0;

    $intervallo  = validaIntero($dati['intervallo'] ?? 1, 'Intervallo');
    if ($intervallo > 999) rispostaErrore(400, 'Intervallo troppo grande.');
    $unita = (string)($dati['unita'] ?? 'mesi');
    if (!in_array($unita, RICORRENZA_UNITA, true)) {
        rispostaErrore(400, 'Unità non valida.');
    }

    $dataInizio = !empty($dati['data_inizio']) ? validaData($dati['data_inizio']) : date('Y-m-d');
    $dataFine   = !empty($dati['data_fine'])   ? validaData($dati['data_fine'])   : null;
    if ($dataFine !== null && $dataFine < $dataInizio) {
        rispostaErrore(400, 'La data di fine non può precedere la data di inizio.');
    }

    // Categoria valida, attiva e della casa
    $cat = $db->prepare("SELECT tipo, sistema FROM categorie WHERE id = ? AND casa_id = ? AND attiva = 1");
    $cat->execute([$idCategoria, $casaId]);
    $categoria = $cat->fetch();
    if (!$categoria) rispostaErrore(400, 'Categoria non valida o non appartiene a questa casa.');
    if (!empty($categoria['sistema']) && !$idSottoCat) {
        rispostaErrore(400, "Seleziona l'evento a cui associare la spesa.");
    }

    // Segno dell'importo in base al tipo di categoria
    if ($categoria['tipo'] === 'accredito' && $importo > 0) {
        $importo = -abs($importo);
    } elseif ($categoria['tipo'] === 'uscita' && $importo < 0) {
        $importo = abs($importo);
    }

    // Sottocategoria coerente con la categoria
    if ($idSottoCat) {
        $sc = $db->prepare("SELECT id FROM sottocategorie WHERE id = ? AND id_categoria = ? AND attiva = 1");
        $sc->execute([$idSottoCat, $idCategoria]);
        if (!$sc->fetch()) rispostaErrore(400, 'Sottocategoria non valida.');
    }

    return [
        'id_categoria'      => $idCategoria,
        'id_sottocategoria' => $idSottoCat,
        'descrizione'       => $descrizione,
        'importo'           => $importo,
        'privata'           => $privata,
        'intervallo'        => $intervallo,
        'unita'             => $unita,
        'data_inizio'       => $dataInizio,
        'data_fine'         => $dataFine,
        // Usato solo in modifica (pausa/ripresa); assente = attiva
        'attiva'            => array_key_exists('attiva', $dati) ? (!empty($dati['attiva']) ? 1 : 0) : 1,
    ];
}

// ------------------------------------------------------------
function lista(): void {
    [$utente, $casaId] = richiedeAccessoCasa();
    $db = getDB();

    // Genera le scadenze arretrate prima di mostrare l'elenco.
    generaRicorrenzeScadute($db, $casaId);

    $stmt = $db->prepare("
        SELECT
            r.id, r.descrizione, r.importo, r.privata,
            r.intervallo, r.unita, r.data_inizio, r.data_fine,
            r.prossima_data, r.attiva, r.creato_il,
            c.id AS categoria_id, c.nome AS categoria, c.tipo AS categoria_tipo, c.colore AS categoria_colore,
            sc.id AS sottocategoria_id, sc.nome AS sottocategoria,
            u.id AS utente_id, u.nome AS utente_nome
        FROM spese_ricorrenti r
        JOIN categorie c ON c.id = r.id_categoria
        JOIN utenti u    ON u.id = r.id_utente
        LEFT JOIN sottocategorie sc ON sc.id = r.id_sottocategoria
        WHERE r.casa_id = ? AND (r.privata = 0 OR r.id_utente = ?)
        ORDER BY r.attiva DESC, r.prossima_data ASC, r.id DESC
    ");
    $stmt->execute([$casaId, $utente['id']]);
    $righe = $stmt->fetchAll();

    foreach ($righe as &$r) {
        $r['importo']    = (float)$r['importo'];
        $r['privata']    = (bool)$r['privata'];
        $r['attiva']     = (bool)$r['attiva'];
        $r['intervallo'] = (int)$r['intervallo'];
    }

    risposta(['ricorrenti' => $righe]);
}

// ------------------------------------------------------------
function dettaglio(int $id): void {
    [$utente, $casaId] = richiedeAccessoCasa();
    $db = getDB();

    $stmt = $db->prepare("
        SELECT
            r.id, r.descrizione, r.importo, r.privata,
            r.intervallo, r.unita, r.data_inizio, r.data_fine,
            r.prossima_data, r.attiva, r.creato_il, r.id_utente,
            c.id AS categoria_id, c.nome AS categoria, c.tipo AS categoria_tipo, c.colore AS categoria_colore,
            sc.id AS sottocategoria_id, sc.nome AS sottocategoria
        FROM spese_ricorrenti r
        JOIN categorie c ON c.id = r.id_categoria
        LEFT JOIN sottocategorie sc ON sc.id = r.id_sottocategoria
        WHERE r.id = ? AND r.casa_id = ?
    ");
    $stmt->execute([$id, $casaId]);
    $r = $stmt->fetch();
    if (!$r) rispostaErrore(404, 'Ricorrenza non trovata.');

    if ($r['privata'] && (int)$r['id_utente'] !== (int)$utente['id']) {
        rispostaErrore(403, 'Accesso negato.');
    }

    $r['importo']    = (float)$r['importo'];
    $r['privata']    = (bool)$r['privata'];
    $r['attiva']     = (bool)$r['attiva'];
    $r['intervallo'] = (int)$r['intervallo'];

    risposta(['ricorrente' => $r]);
}

// ------------------------------------------------------------
function crea(): void {
    [$utente, $casaId] = richiedeAccessoCasa();
    $db = getDB();
    $d  = leggiDatiRicorrente($db, $casaId);

    // Il cursore parte dalla data di inizio: le scadenze arretrate (inizio nel
    // passato) vengono generate subito dopo l'inserimento.
    $prossima = $d['data_inizio'];

    $stmt = $db->prepare("
        INSERT INTO spese_ricorrenti
            (casa_id, id_utente, id_categoria, id_sottocategoria, descrizione, importo, privata,
             intervallo, unita, data_inizio, data_fine, prossima_data, attiva)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
    ");
    $stmt->execute([
        $casaId, $utente['id'], $d['id_categoria'], $d['id_sottocategoria'], $d['descrizione'],
        $d['importo'], $d['privata'], $d['intervallo'], $d['unita'],
        $d['data_inizio'], $d['data_fine'], $prossima,
    ]);

    $nuovoId = ultimoId('spese_ricorrenti');

    // Genera subito le eventuali scadenze già dovute.
    generaRicorrenzeScadute($db, $casaId);

    risposta(['id' => $nuovoId, 'messaggio' => 'Spesa ricorrente creata.'], 201);
}

// ------------------------------------------------------------
function aggiorna(int $id): void {
    [$utente, $casaId] = richiedeAccessoCasa();
    // checkPermesso(..., 'admin') risponde 403 ai non admin: qui serve solo sapere il ruolo,
    // perché l'autore può agire sulle proprie ricorrenze anche da semplice membro.
    $isAdmin = checkPermesso($utente['id'], $casaId) === 'admin';
    $db      = getDB();

    $stmt = $db->prepare("SELECT id, id_utente, prossima_data FROM spese_ricorrenti WHERE id = ? AND casa_id = ?");
    $stmt->execute([$id, $casaId]);
    $esistente = $stmt->fetch();
    if (!$esistente) rispostaErrore(404, 'Ricorrenza non trovata.');

    if (!$isAdmin && (int)$esistente['id_utente'] !== (int)$utente['id']) {
        rispostaErrore(403, 'Non puoi modificare questa ricorrenza.');
    }

    $d      = leggiDatiRicorrente($db, $casaId);
    $attiva = $d['attiva'];

    // Cursore: se la nuova data di inizio è più avanti del cursore attuale,
    // riparti da lì; altrimenti mantieni il cursore (niente arretrati rigenerati).
    $prossima = $esistente['prossima_data'];
    if ($d['data_inizio'] > $prossima) $prossima = $d['data_inizio'];
    // Nessuna scadenza rimasta entro la data di fine: la ricorrenza è conclusa.
    if ($d['data_fine'] !== null && $prossima > $d['data_fine']) $attiva = 0;

    $db->prepare("
        UPDATE spese_ricorrenti
        SET id_categoria=?, id_sottocategoria=?, descrizione=?, importo=?, privata=?,
            intervallo=?, unita=?, data_inizio=?, data_fine=?, prossima_data=?, attiva=?
        WHERE id=? AND casa_id=?
    ")->execute([
        $d['id_categoria'], $d['id_sottocategoria'], $d['descrizione'], $d['importo'], $d['privata'],
        $d['intervallo'], $d['unita'], $d['data_inizio'], $d['data_fine'], $prossima, $attiva,
        $id, $casaId,
    ]);

    if ($attiva) generaRicorrenzeScadute($db, $casaId);

    risposta(['messaggio' => 'Spesa ricorrente aggiornata.']);
}

// ------------------------------------------------------------
function elimina(int $id): void {
    [$utente, $casaId] = richiedeAccessoCasa();
    // checkPermesso(..., 'admin') risponde 403 ai non admin: qui serve solo sapere il ruolo,
    // perché l'autore può agire sulle proprie ricorrenze anche da semplice membro.
    $isAdmin = checkPermesso($utente['id'], $casaId) === 'admin';
    $db      = getDB();

    $stmt = $db->prepare("SELECT id, id_utente FROM spese_ricorrenti WHERE id = ? AND casa_id = ?");
    $stmt->execute([$id, $casaId]);
    $r = $stmt->fetch();
    if (!$r) rispostaErrore(404, 'Ricorrenza non trovata.');

    if (!$isAdmin && (int)$r['id_utente'] !== (int)$utente['id']) {
        rispostaErrore(403, 'Non puoi eliminare questa ricorrenza.');
    }

    // Le spese già generate restano (id_ricorrenza diventa NULL per la FK).
    $db->prepare("DELETE FROM spese_ricorrenti WHERE id = ? AND casa_id = ?")->execute([$id, $casaId]);
    risposta(['messaggio' => 'Spesa ricorrente eliminata. Le spese già registrate restano.']);
}
