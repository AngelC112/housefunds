<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/eventi_sistema.php';
inizializzaAPI();

$metodo = $_SERVER['REQUEST_METHOD'];
$azione = $_GET['action'] ?? '';
$db     = getDB();

// ─── HELPER COLLEGAMENTI ──────────────────────────────────────

/**
 * Restituisce gli eventi attivi della casa che devono proporre la popup
 * per una spesa con la categoria/sottocategoria indicate.
 *
 * Un evento corrisponde se:
 *  - la sottocategoria è collegata direttamente (escluso = 0), oppure
 *  - l'intera categoria è collegata e la sottocategoria non è esclusa,
 *  - per spese senza sottocategoria: l'intera categoria è collegata.
 * Gli eventi senza mini-categorie vengono ignorati.
 */
function eventiPerSpesa(PDO $db, int $casaId, int $catId, ?int $subId): array {
    if ($subId) {
        $stmt = $db->prepare("
            SELECT e.id, e.nome, e.colore
            FROM eventi e
            WHERE e.casa_id = ? AND e.attivo = 1
              AND (
                EXISTS (SELECT 1 FROM sottocategorie_eventi se
                        WHERE se.evento_id = e.id AND se.sottocategoria_id = ? AND se.escluso = 0)
                OR (
                    EXISTS (SELECT 1 FROM categorie_eventi ce
                            WHERE ce.evento_id = e.id AND ce.categoria_id = ?)
                    AND NOT EXISTS (SELECT 1 FROM sottocategorie_eventi se
                            WHERE se.evento_id = e.id AND se.sottocategoria_id = ? AND se.escluso = 1)
                )
              )
            ORDER BY e.nome ASC, e.id ASC
        ");
        $stmt->execute([$casaId, $subId, $catId, $subId]);
    } else {
        $stmt = $db->prepare("
            SELECT e.id, e.nome, e.colore
            FROM eventi e
            JOIN categorie_eventi ce ON ce.evento_id = e.id AND ce.categoria_id = ?
            WHERE e.casa_id = ? AND e.attivo = 1
            ORDER BY e.nome ASC, e.id ASC
        ");
        $stmt->execute([$catId, $casaId]);
    }
    $eventi = $stmt->fetchAll();
    if (!$eventi) return [];

    $stmtCat = $db->prepare("
        SELECT ec.id, ec.nome, ec.importo_stimato,
            COALESCE((
                SELECT SUM(ABS(s.importo))
                FROM spese_eventi sev
                JOIN spese s ON s.id = sev.spesa_id
                WHERE sev.evento_categoria_id = ec.id
            ), 0) AS speso
        FROM eventi_categorie ec
        WHERE ec.evento_id = ?
        ORDER BY ec.ordine, ec.id
    ");

    $risultato = [];
    foreach ($eventi as $e) {
        $stmtCat->execute([$e['id']]);
        $cats = $stmtCat->fetchAll();
        if (!$cats) continue;
        $risultato[] = [
            'evento_id'   => (int)$e['id'],
            'evento_nome' => $e['nome'],
            'colore'      => $e['colore'],
            'categorie'   => array_map(fn($r) => [
                'id'              => (int)$r['id'],
                'nome'            => $r['nome'],
                'importo_stimato' => (float)$r['importo_stimato'],
                'speso'           => (float)$r['speso'],
            ], $cats),
        ];
    }
    return $risultato;
}

/**
 * Collegamenti (categorie intere, sottocategorie incluse/escluse) per evento,
 * in forma leggibile per i chip di riepilogo.
 * Ritorna [evento_id => [ {tipo, ...}, ... ]].
 */
function collegamentiPerEventi(PDO $db, int $casaId): array {
    $out = [];

    $stmt = $db->prepare("
        SELECT ce.evento_id, c.id AS categoria_id, c.nome AS categoria_nome, c.colore AS categoria_colore,
            (SELECT COUNT(*) FROM sottocategorie_eventi se
             JOIN sottocategorie sc ON sc.id = se.sottocategoria_id
             WHERE se.evento_id = ce.evento_id AND se.escluso = 1 AND sc.id_categoria = c.id) AS escluse
        FROM categorie_eventi ce
        JOIN categorie c ON c.id = ce.categoria_id
        JOIN eventi e    ON e.id = ce.evento_id
        WHERE e.casa_id = ? AND c.sistema IS NULL
        ORDER BY c.nome
    ");
    $stmt->execute([$casaId]);
    foreach ($stmt->fetchAll() as $r) {
        $out[(int)$r['evento_id']][] = [
            'tipo'             => 'categoria',
            'categoria_id'     => (int)$r['categoria_id'],
            'categoria_nome'   => $r['categoria_nome'],
            'categoria_colore' => $r['categoria_colore'],
            'escluse'          => (int)$r['escluse'],
        ];
    }

    $stmt = $db->prepare("
        SELECT se.evento_id, sc.id AS sottocategoria_id, sc.nome AS sottocategoria_nome,
               c.id AS categoria_id, c.nome AS categoria_nome, c.colore AS categoria_colore
        FROM sottocategorie_eventi se
        JOIN sottocategorie sc ON sc.id = se.sottocategoria_id
        JOIN categorie c       ON c.id = sc.id_categoria
        JOIN eventi e          ON e.id = se.evento_id
        WHERE e.casa_id = ? AND se.escluso = 0 AND c.sistema IS NULL
        ORDER BY c.nome, sc.nome
    ");
    $stmt->execute([$casaId]);
    foreach ($stmt->fetchAll() as $r) {
        $out[(int)$r['evento_id']][] = [
            'tipo'                => 'sottocategoria',
            'categoria_id'        => (int)$r['categoria_id'],
            'categoria_nome'      => $r['categoria_nome'],
            'categoria_colore'    => $r['categoria_colore'],
            'sottocategoria_id'   => (int)$r['sottocategoria_id'],
            'sottocategoria_nome' => $r['sottocategoria_nome'],
        ];
    }

    return $out;
}

/** Filtra una lista di ID mantenendo solo interi positivi univoci. */
function idUnivoci(mixed $lista): array {
    if (!is_array($lista)) return [];
    $ids = array_filter(array_map('intval', $lista), fn($v) => $v > 0);
    return array_values(array_unique($ids));
}

switch ($azione) {

    // ─── EVENTI ────────────────────────────────────────────────

    case 'list':
        [$utente, $casaId] = richiedeAccessoCasa();
        $stmt = $db->prepare("
            SELECT e.*,
                COALESCE(SUM(ec.importo_stimato), 0) AS totale_stimato,
                COALESCE((
                    SELECT SUM(s.importo)
                    FROM spese_eventi se
                    JOIN eventi_categorie ec2 ON se.evento_categoria_id = ec2.id
                    JOIN spese s ON se.spesa_id = s.id
                    WHERE ec2.evento_id = e.id
                ), 0) AS totale_speso
            FROM eventi e
            LEFT JOIN eventi_categorie ec ON ec.evento_id = e.id
            WHERE e.attivo = 1 AND e.casa_id = ?
            GROUP BY e.id
            ORDER BY e.id DESC
        ");
        $stmt->execute([$casaId]);
        $eventi = $stmt->fetchAll();

        // Collegamenti per i chip di riepilogo sulle card
        $coll = collegamentiPerEventi($db, $casaId);
        foreach ($eventi as &$ev) {
            $ev['collegamenti'] = $coll[(int)$ev['id']] ?? [];
        }
        unset($ev);

        risposta($eventi);
        break;

    case 'get':
        [$utente, $casaId] = richiedeAccessoCasa();
        $id   = validaIntero($_GET['id'] ?? 0, 'ID evento');
        $stmt = $db->prepare("SELECT * FROM eventi WHERE id = ? AND attivo = 1 AND casa_id = ?");
        $stmt->execute([$id, $casaId]);
        $evento = $stmt->fetch();
        if (!$evento) rispostaErrore(404, 'Evento non trovato.');

        $stmt = $db->prepare("
            SELECT ec.*,
                COALESCE(SUM(s.importo), 0) AS speso
            FROM eventi_categorie ec
            LEFT JOIN spese_eventi se ON se.evento_categoria_id = ec.id
            LEFT JOIN spese s ON se.spesa_id = s.id
            WHERE ec.evento_id = ?
            GROUP BY ec.id
            ORDER BY ec.nome ASC
        ");
        $stmt->execute([$id]);
        $evento['categorie'] = $stmt->fetchAll();
        risposta($evento);
        break;

    case 'create':
        [$utente, $casaId] = richiedeAdminDiCasa();
        $dati  = inputJSON();
        $nome  = sanitizzaStringa($dati['nome'] ?? '', 255);
        $desc  = sanitizzaStringa($dati['descrizione'] ?? '', 500);
        $col   = sanitizzaStringa($dati['colore'] ?? '#4f46e5', 7);
        if ($nome === '') rispostaErrore(400, 'Nome evento obbligatorio.');

        $stmt = $db->prepare("INSERT INTO eventi (casa_id, nome, descrizione, colore) VALUES (?, ?, ?, ?)");
        $stmt->execute([$casaId, $nome, $desc, $col]);
        $nuovoId = ultimoId('eventi');
        // Crea la sottocategoria in EVENTI collegata al nuovo evento
        sincronizzaEventoSistema($db, $casaId, $nuovoId);
        risposta(['id' => $nuovoId]);
        break;

    case 'update':
        [$utente, $casaId] = richiedeAdminDiCasa();
        $dati  = inputJSON();
        $id    = validaIntero($dati['id'] ?? 0, 'ID evento');
        $nome  = sanitizzaStringa($dati['nome'] ?? '', 255);
        $desc  = sanitizzaStringa($dati['descrizione'] ?? '', 500);
        $col   = sanitizzaStringa($dati['colore'] ?? '#4f46e5', 7);
        $att   = isset($dati['attivo']) ? (int)(bool)$dati['attivo'] : 1;
        if ($nome === '') rispostaErrore(400, 'Nome evento obbligatorio.');

        $stmt = $db->prepare("UPDATE eventi SET nome=?, descrizione=?, colore=?, attivo=? WHERE id=? AND casa_id=?");
        $stmt->execute([$nome, $desc, $col, $att, $id, $casaId]);
        // Rinomina/attiva la sottocategoria in EVENTI
        sincronizzaEventoSistema($db, $casaId, $id);
        risposta(['ok' => true]);
        break;

    case 'delete':
        [$utente, $casaId] = richiedeAdminDiCasa();
        $dati = inputJSON();
        $id   = validaIntero($dati['id'] ?? 0, 'ID evento');
        $db->prepare("UPDATE eventi SET attivo=0 WHERE id=? AND casa_id=?")->execute([$id, $casaId]);
        // Disattiva la sottocategoria in EVENTI (lo storico spese resta) ed eventualmente la categoria
        sincronizzaEventoSistema($db, $casaId, $id);
        risposta(['ok' => true]);
        break;

    // ─── MINI-CATEGORIE ────────────────────────────────────────

    case 'cat_create':
        [$utente, $casaId] = richiedeAdminDiCasa();
        $dati     = inputJSON();
        $eventoId = validaIntero($dati['evento_id'] ?? 0, 'ID evento');
        $nome     = sanitizzaStringa($dati['nome'] ?? '', 255);
        $importo  = validaImporto($dati['importo_stimato'] ?? 0);
        $ordine   = (int)($dati['ordine'] ?? 0);
        if ($nome === '') rispostaErrore(400, 'Nome mini-categoria obbligatorio.');

        // Verifica che l'evento appartenga alla casa attiva
        $check = $db->prepare("SELECT id FROM eventi WHERE id = ? AND casa_id = ? AND attivo = 1");
        $check->execute([$eventoId, $casaId]);
        if (!$check->fetch()) rispostaErrore(403, 'Evento non appartenente alla casa.');

        $stmt = $db->prepare("
            INSERT INTO eventi_categorie (evento_id, nome, importo_stimato, ordine)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$eventoId, $nome, $importo, $ordine]);
        risposta(['id' => ultimoId('eventi_categorie')]);
        break;

    case 'cat_update':
        [$utente, $casaId] = richiedeAdminDiCasa();
        $dati    = inputJSON();
        $id      = validaIntero($dati['id'] ?? 0, 'ID mini-categoria');
        $nome    = sanitizzaStringa($dati['nome'] ?? '', 255);
        $importo = validaImporto($dati['importo_stimato'] ?? 0);
        $ordine  = (int)($dati['ordine'] ?? 0);
        if ($nome === '') rispostaErrore(400, 'Nome mini-categoria obbligatorio.');

        // Verifica che la mini-categoria appartenga a un evento della casa
        $check = $db->prepare("
            SELECT ec.id FROM eventi_categorie ec
            JOIN eventi e ON ec.evento_id = e.id
            WHERE ec.id = ? AND e.casa_id = ?
        ");
        $check->execute([$id, $casaId]);
        if (!$check->fetch()) rispostaErrore(403, 'Mini-categoria non appartenente alla casa.');

        $stmt = $db->prepare("
            UPDATE eventi_categorie SET nome=?, importo_stimato=?, ordine=? WHERE id=?
        ");
        $stmt->execute([$nome, $importo, $ordine, $id]);
        risposta(['ok' => true]);
        break;

    case 'cat_delete':
        [$utente, $casaId] = richiedeAdminDiCasa();
        $dati = inputJSON();
        $id   = validaIntero($dati['id'] ?? 0, 'ID mini-categoria');

        // Verifica che la mini-categoria appartenga a un evento della casa
        $check = $db->prepare("
            SELECT ec.id FROM eventi_categorie ec
            JOIN eventi e ON ec.evento_id = e.id
            WHERE ec.id = ? AND e.casa_id = ?
        ");
        $check->execute([$id, $casaId]);
        if (!$check->fetch()) rispostaErrore(403, 'Mini-categoria non appartenente alla casa.');

        $db->prepare("DELETE FROM eventi_categorie WHERE id=?")->execute([$id]);
        risposta(['ok' => true]);
        break;

    // ─── COLLEGAMENTO SOTTOCATEGORIA → EVENTO ──────────────────

    case 'link_sottocategoria':
        [$utente, $casaId] = richiedeAdminDiCasa();
        $dati      = inputJSON();
        $subId     = validaIntero($dati['sottocategoria_id'] ?? 0, 'ID sottocategoria');
        $eventoId  = validaIntero($dati['evento_id'] ?? 0, 'ID evento');
		 
        // Verifica che la sottocategoria e l'evento appartengano alla casa
        $checkSub = $db->prepare("
            SELECT sc.id FROM sottocategorie sc
            JOIN categorie c ON sc.id_categoria = c.id
            WHERE sc.id = ? AND c.casa_id = ?
        ");
        $checkSub->execute([$subId, $casaId]);
        if (!$checkSub->fetch()) rispostaErrore(403, 'Sottocategoria non appartenente alla casa.');

        $checkEvento = $db->prepare("SELECT id FROM eventi WHERE id = ? AND casa_id = ? AND attivo = 1");
        $checkEvento->execute([$eventoId, $casaId]);
        if (!$checkEvento->fetch()) rispostaErrore(403, 'Evento non appartenente alla casa.');

        // Una sottocategoria può essere collegata a più eventi: non sovrascrive gli altri
        $stmt = $db->prepare("
            INSERT INTO sottocategorie_eventi (sottocategoria_id, evento_id, escluso)
            VALUES (?, ?, 0)
            " . sqlSuConflitto(['sottocategoria_id', 'evento_id'], 'escluso = 0') . "
        ");
        $stmt->execute([$subId, $eventoId]);
        risposta(['ok' => true]);
        break;

    case 'unlink_sottocategoria':
        [$utente, $casaId] = richiedeAdminDiCasa();
        $dati  = inputJSON();
        $subId = validaIntero($dati['sottocategoria_id'] ?? 0, 'ID sottocategoria');

        // Verifica che la sottocategoria appartenga alla casa
        $check = $db->prepare("
            SELECT sc.id FROM sottocategorie sc
            JOIN categorie c ON sc.id_categoria = c.id
            WHERE sc.id = ? AND c.casa_id = ?
        ");
        $check->execute([$subId, $casaId]);
        if (!$check->fetch()) rispostaErrore(403, 'Sottocategoria non appartenente alla casa.');

        // Con evento_id rimuove solo il collegamento a quell'evento
        if (!empty($dati['evento_id'])) {
            $eventoId = validaIntero($dati['evento_id'], 'ID evento');
            $db->prepare("DELETE FROM sottocategorie_eventi WHERE sottocategoria_id=? AND evento_id=?")
               ->execute([$subId, $eventoId]);
        } else {
            $db->prepare("DELETE FROM sottocategorie_eventi WHERE sottocategoria_id=?")->execute([$subId]);
        }
        risposta(['ok' => true]);
        break;

    // ─── COLLEGAMENTI CATEGORIA/SOTTOCATEGORIA → EVENTO ───────

    // Dati per il modal evento: categorie con sottocategorie, collegamenti
    // di tutti gli eventi della casa ed elenco eventi (per i flag degli altri eventi)
    case 'link_data':
        [$utente, $casaId] = richiedeAccessoCasa();

        $stmt = $db->prepare("
            SELECT id, nome, colore, tipo, attiva
            FROM categorie
            WHERE casa_id = ? AND sistema IS NULL
            ORDER BY ordinamento ASC, nome ASC
        ");
        $stmt->execute([$casaId]);
        $categorie = $stmt->fetchAll();

        $stmt = $db->prepare("
            SELECT sc.id, sc.id_categoria, sc.nome, sc.attiva
            FROM sottocategorie sc
            JOIN categorie c ON c.id = sc.id_categoria
            WHERE c.casa_id = ? AND c.sistema IS NULL
            ORDER BY sc.ordinamento ASC, sc.nome ASC
        ");
        $stmt->execute([$casaId]);
        $perCat = [];
        foreach ($stmt->fetchAll() as $s) {
            $perCat[(int)$s['id_categoria']][] = [
                'id'     => (int)$s['id'],
                'nome'   => $s['nome'],
                'attiva' => (int)$s['attiva'],
            ];
        }
        foreach ($categorie as &$c) {
            $c['id']             = (int)$c['id'];
            $c['attiva']         = (int)$c['attiva'];
            $c['sottocategorie'] = $perCat[$c['id']] ?? [];
        }
        unset($c);

        $stmt = $db->prepare("
            SELECT ce.categoria_id, ce.evento_id
            FROM categorie_eventi ce
            JOIN eventi e ON e.id = ce.evento_id
            WHERE e.casa_id = ? AND e.attivo = 1
        ");
        $stmt->execute([$casaId]);
        $linkCat = array_map(fn($r) => [
            'categoria_id' => (int)$r['categoria_id'],
            'evento_id'    => (int)$r['evento_id'],
        ], $stmt->fetchAll());

        $stmt = $db->prepare("
            SELECT se.sottocategoria_id, se.evento_id, se.escluso
            FROM sottocategorie_eventi se
            JOIN eventi e ON e.id = se.evento_id
            WHERE e.casa_id = ? AND e.attivo = 1
        ");
        $stmt->execute([$casaId]);
        $linkSub = array_map(fn($r) => [
            'sottocategoria_id' => (int)$r['sottocategoria_id'],
            'evento_id'         => (int)$r['evento_id'],
            'escluso'           => (int)$r['escluso'],
        ], $stmt->fetchAll());

        $stmt = $db->prepare("SELECT id, nome, colore FROM eventi WHERE casa_id = ? AND attivo = 1 ORDER BY nome");
        $stmt->execute([$casaId]);
        $eventi = array_map(fn($r) => [
            'id'     => (int)$r['id'],
            'nome'   => $r['nome'],
            'colore' => $r['colore'],
        ], $stmt->fetchAll());

        risposta([
            'categorie'    => $categorie,
            'collegamenti' => ['categorie' => $linkCat, 'sottocategorie' => $linkSub],
            'eventi'       => $eventi,
        ]);
        break;

    // Sostituisce in blocco i collegamenti di un evento.
    // Input: evento_id, categorie[] (intere), sottocategorie_incluse[], sottocategorie_escluse[]
    case 'set_collegamenti':
        [$utente, $casaId] = richiedeAdminDiCasa();
        $dati     = inputJSON();
        $eventoId = validaIntero($dati['evento_id'] ?? 0, 'ID evento');

        $check = $db->prepare("SELECT id FROM eventi WHERE id = ? AND casa_id = ? AND attivo = 1");
        $check->execute([$eventoId, $casaId]);
        if (!$check->fetch()) rispostaErrore(403, 'Evento non appartenente alla casa.');

        $catIds  = idUnivoci($dati['categorie'] ?? []);
        $inclIds = idUnivoci($dati['sottocategorie_incluse'] ?? []);
        $esclIds = idUnivoci($dati['sottocategorie_escluse'] ?? []);

        // Categorie valide della casa
        $catValide = [];
        if ($catIds) {
            $ph   = implode(',', array_fill(0, count($catIds), '?'));
            $stmt = $db->prepare("SELECT id FROM categorie WHERE casa_id = ? AND sistema IS NULL AND id IN ($ph)");
            $stmt->execute(array_merge([$casaId], $catIds));
            $catValide = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        }

        // Sottocategorie valide della casa, con la rispettiva categoria
        $subCat = [];
        $tutteSub = array_values(array_unique(array_merge($inclIds, $esclIds)));
        if ($tutteSub) {
            $ph   = implode(',', array_fill(0, count($tutteSub), '?'));
            $stmt = $db->prepare("
                SELECT sc.id, sc.id_categoria FROM sottocategorie sc
                JOIN categorie c ON c.id = sc.id_categoria
                WHERE c.casa_id = ? AND c.sistema IS NULL AND sc.id IN ($ph)
            ");
            $stmt->execute(array_merge([$casaId], $tutteSub));
            foreach ($stmt->fetchAll() as $r) $subCat[(int)$r['id']] = (int)$r['id_categoria'];
        }

        // Normalizza: esclusioni solo per categorie collegate per intero,
        // inclusioni solo per categorie NON collegate per intero (altrimenti ridondanti)
        $righeSub = [];
        foreach ($esclIds as $sid) {
            if (isset($subCat[$sid]) && in_array($subCat[$sid], $catValide, true)) $righeSub[$sid] = 1;
        }
        foreach ($inclIds as $sid) {
            if (isset($subCat[$sid]) && !in_array($subCat[$sid], $catValide, true) && !isset($righeSub[$sid])) {
                $righeSub[$sid] = 0;
            }
        }

        $db->beginTransaction();
        try {
            $db->prepare("DELETE FROM categorie_eventi WHERE evento_id = ?")->execute([$eventoId]);
            $db->prepare("DELETE FROM sottocategorie_eventi WHERE evento_id = ?")->execute([$eventoId]);

            $insCat = $db->prepare("INSERT INTO categorie_eventi (categoria_id, evento_id) VALUES (?, ?)");
            foreach ($catValide as $cid) $insCat->execute([$cid, $eventoId]);

            $insSub = $db->prepare("INSERT INTO sottocategorie_eventi (sottocategoria_id, evento_id, escluso) VALUES (?, ?, ?)");
            foreach ($righeSub as $sid => $escluso) $insSub->execute([$sid, $eventoId, $escluso]);

            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            logErrore('set_collegamenti fallito: ' . $e->getMessage(), ['evento_id' => $eventoId]);
            rispostaErrore(500, 'Errore nel salvataggio dei collegamenti.');
        }
        // Ripristina il collegamento della sottocategoria di sistema (EVENTI › evento)
        sincronizzaEventoSistema($db, $casaId, $eventoId);
        risposta(['ok' => true]);
        break;

    // Eventi da proporre per una spesa (0, 1 o più eventi)
    case 'check_spesa':
        [$utente, $casaId] = richiedeAccessoCasa();
        $subId = !empty($_GET['sottocategoria_id']) ? validaIntero($_GET['sottocategoria_id'], 'ID sottocategoria') : null;
        $catId = !empty($_GET['categoria_id'])      ? validaIntero($_GET['categoria_id'], 'ID categoria')           : null;

        if ($subId) {
            // La categoria si ricava dalla sottocategoria (fonte affidabile)
            $stmt = $db->prepare("
                SELECT sc.id_categoria FROM sottocategorie sc
                JOIN categorie c ON c.id = sc.id_categoria
                WHERE sc.id = ? AND c.casa_id = ?
            ");
            $stmt->execute([$subId, $casaId]);
            $catId = $stmt->fetchColumn();
            if (!$catId) rispostaErrore(403, 'Sottocategoria non appartenente alla casa.');
            $catId = (int)$catId;
        } elseif ($catId) {
            $stmt = $db->prepare("SELECT id FROM categorie WHERE id = ? AND casa_id = ?");
            $stmt->execute([$catId, $casaId]);
            if (!$stmt->fetch()) rispostaErrore(403, 'Categoria non appartenente alla casa.');
        } else {
            risposta([]);
        }

        risposta(eventiPerSpesa($db, $casaId, $catId, $subId));
        break;

    case 'check_sottocategoria':
        [$utente, $casaId] = richiedeAccessoCasa();
        // Compatibilità: restituisce solo il primo evento. Usare 'check_spesa' per l'elenco completo.
        $subId = validaIntero($_GET['sottocategoria_id'] ?? 0, 'ID sottocategoria');

        $stmt = $db->prepare("
            SELECT sc.id_categoria FROM sottocategorie sc
            JOIN categorie c ON c.id = sc.id_categoria
            WHERE sc.id = ? AND c.casa_id = ?
        ");
        $stmt->execute([$subId, $casaId]);
        $catId = $stmt->fetchColumn();
        if (!$catId) risposta(null);

        $eventi = eventiPerSpesa($db, $casaId, (int)$catId, $subId);
        risposta($eventi[0] ?? null);
        break;

    // ─── ASSEGNAZIONE SPESA → MINI-CATEGORIA ───────────────────

    case 'assign_spesa':
        [$utente, $casaId] = richiedeAccessoCasa();
        $dati    = inputJSON();
        $spesaId = validaIntero($dati['spesa_id'] ?? 0, 'ID spesa');
        $catId   = validaIntero($dati['evento_categoria_id'] ?? 0, 'ID mini-categoria');

        // Verifica che la spesa appartenga alla casa
        $checkSpesa = $db->prepare("SELECT id FROM spese WHERE id = ? AND casa_id = ?");
        $checkSpesa->execute([$spesaId, $casaId]);
        if (!$checkSpesa->fetch()) rispostaErrore(403, 'Spesa non appartenente alla casa.');

        // Verifica che la mini-categoria appartenga a un evento della casa
        $checkCat = $db->prepare("
            SELECT ec.id FROM eventi_categorie ec
            JOIN eventi e ON ec.evento_id = e.id
            WHERE ec.id = ? AND e.casa_id = ?
        ");
        $checkCat->execute([$catId, $casaId]);
        if (!$checkCat->fetch()) rispostaErrore(403, 'Mini-categoria non appartenente alla casa.');

        $stmt = $db->prepare("
            INSERT INTO spese_eventi (spesa_id, evento_categoria_id)
            VALUES (?, ?)
            " . sqlSuConflitto(['spesa_id'], 'evento_categoria_id = EXCLUDED.evento_categoria_id') . "
        ");
        $stmt->execute([$spesaId, $catId]);
        risposta(['ok' => true]);
        break;

    case 'unassign_spesa':
        [$utente, $casaId] = richiedeAccessoCasa();
        $dati    = inputJSON();
        $spesaId = validaIntero($dati['spesa_id'] ?? 0, 'ID spesa');

        // Verifica che la spesa appartenga alla casa
        $check = $db->prepare("SELECT id FROM spese WHERE id = ? AND casa_id = ?");
        $check->execute([$spesaId, $casaId]);
        if (!$check->fetch()) rispostaErrore(403, 'Spesa non appartenente alla casa.');

        $db->prepare("DELETE FROM spese_eventi WHERE spesa_id=?")->execute([$spesaId]);
        risposta(['ok' => true]);
        break;

    case 'get_assegnazione':
        [$utente, $casaId] = richiedeAccessoCasa();
        $spesaId = validaIntero($_GET['spesa_id'] ?? 0, 'ID spesa');

        // Verifica che la spesa appartenga alla casa
        $check = $db->prepare("SELECT id FROM spese WHERE id = ? AND casa_id = ?");
        $check->execute([$spesaId, $casaId]);
        if (!$check->fetch()) rispostaErrore(403, 'Spesa non appartenente alla casa.');

        $stmt = $db->prepare("
            SELECT se.evento_categoria_id, ec.evento_id
            FROM spese_eventi se
            JOIN eventi_categorie ec ON ec.id = se.evento_categoria_id
            WHERE se.spesa_id = ?
        ");
        $stmt->execute([$spesaId]);
        $row = $stmt->fetch();
        risposta($row ?: null);
        break;

    case 'get_cat_info':
        [$utente, $casaId] = richiedeAccessoCasa();
        $catId   = validaIntero($_GET['cat_id']  ?? 0, 'ID mini-categoria');
        $spesaId = !empty($_GET['spesa_id']) ? validaIntero($_GET['spesa_id'], 'ID spesa') : null;

        // Dati mini-categoria + speso totale attuale (con verifica casa_id via JOIN)
        $stmt = $db->prepare("
            SELECT ec.id, ec.nome, ec.importo_stimato, ec.evento_id,
                COALESCE((
                    SELECT SUM(s.importo)
                    FROM spese_eventi se
                    JOIN spese s ON se.spesa_id = s.id
                    WHERE se.evento_categoria_id = ec.id
                ), 0) AS speso
            FROM eventi_categorie ec
            JOIN eventi e ON ec.evento_id = e.id
            WHERE ec.id = ? AND e.casa_id = ?
        ");
        $stmt->execute([$catId, $casaId]);
        $cat = $stmt->fetch();
        if (!$cat) rispostaErrore(404, 'Mini-categoria non trovata.');

        // Dati evento
        $stmt = $db->prepare("SELECT id, nome, colore FROM eventi WHERE id = ? AND attivo = 1 AND casa_id = ?");
        $stmt->execute([$cat['evento_id'], $casaId]);
        $evento = $stmt->fetch();
        if (!$evento) rispostaErrore(404, 'Evento non trovato.');

        // Importo della spesa corrente (verifica che appartenga alla casa)
        $importoSpesaCorrente = 0;
        if ($spesaId) {
            $stmt = $db->prepare("
                SELECT ABS(s.importo) AS importo
                FROM spese s
                WHERE s.id = ? AND s.casa_id = ?
            ");
            $stmt->execute([$spesaId, $casaId]);
            $spesa = $stmt->fetch();
            if ($spesa) $importoSpesaCorrente = (float)$spesa['importo'];
        }

        risposta([
            'categoria' => [
                'id'                     => (int)$cat['id'],
                'nome'                   => $cat['nome'],
                'importo_stimato'        => (float)$cat['importo_stimato'],
                'speso'                  => (float)abs($cat['speso']),
                'importo_spesa_corrente' => $importoSpesaCorrente,
            ],
            'evento' => [
                'id'     => (int)$evento['id'],
                'nome'   => $evento['nome'],
                'colore' => $evento['colore'],
            ],
        ]);
        break;

    default:
        rispostaErrore(400, 'Azione non valida.');
}
