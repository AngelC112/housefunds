<?php
ob_start();
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(0);
// ============================================================
// CATEGORIE.PHP - CRUD Categorie e Sottocategorie (multi-casa)
// ============================================================
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/eventi_sistema.php';
inizializzaAPI();

$metodo  = $_SERVER['REQUEST_METHOD'];
$risorsa = $_GET['risorsa'] ?? 'categorie'; // 'categorie' | 'sottocategorie'
$id      = isset($_GET['id']) ? validaIntero($_GET['id'], 'ID') : null;

if ($metodo === 'GET') {
    richiedeAccessoCasa();
    rilasciaSessione();
}

match(true) {
    $metodo === 'GET'    && $risorsa === 'categorie'      && !$id => listaCategorie(),
    $metodo === 'GET'    && $risorsa === 'sottocategorie' && !$id => listaSottocategorie(),
    $metodo === 'POST'   && $risorsa === 'categorie'              => creaCategoria(),
    $metodo === 'POST'   && $risorsa === 'sottocategorie'         => creaSottocategoria(),
    $metodo === 'PUT'    && $risorsa === 'categorie'      &&  $id => aggiornaCategoria($id),
    $metodo === 'PUT'    && $risorsa === 'sottocategorie' &&  $id => aggiornaSottocategoria($id),
    $metodo === 'DELETE' && $risorsa === 'categorie'      &&  $id => eliminaCategoria($id),
    $metodo === 'DELETE' && $risorsa === 'sottocategorie' &&  $id => eliminaSottocategoria($id),
    default => rispostaErrore(404, 'Endpoint non trovato.')
};

// ============================================================
// CATEGORIE
// ============================================================

function listaCategorie(): void {
    [, $casaId] = richiedeAccessoCasa();
    $db   = getDB();
    $solo = $_GET['tipo'] ?? null; // 'uscita' | 'accredito'

    $sql    = "SELECT id, nome, tipo, colore, icona, ordinamento, attiva, sistema FROM categorie WHERE casa_id = ?";
    $params = [$casaId];

    if ($solo === 'uscita' || $solo === 'accredito') {
        $sql    .= " AND tipo = ?";
        $params[] = $solo;
    }

    $sql .= " ORDER BY ordinamento ASC, nome ASC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $categorie = $stmt->fetchAll();

    if (!empty($_GET['con_sottocategorie'])) {
        $stmtSc = $db->prepare("
            SELECT sc.id, sc.id_categoria, sc.nome, sc.ordinamento, sc.attiva
            FROM sottocategorie sc
            JOIN categorie c ON c.id = sc.id_categoria
            WHERE c.casa_id = ? AND sc.attiva = 1
            ORDER BY sc.ordinamento ASC, sc.nome ASC
        ");
        $stmtSc->execute([$casaId]);
        $sottocategorie = $stmtSc->fetchAll();

        $mappa = [];
        foreach ($sottocategorie as $sc) {
            $mappa[$sc['id_categoria']][] = $sc;
        }
        foreach ($categorie as &$cat) {
            $cat['sottocategorie'] = $mappa[$cat['id']] ?? [];
        }
    }

    risposta(['categorie' => $categorie]);
}

function creaCategoria(): void {
    [$utente, $casaId] = richiedeAdminDiCasa();
    $dati = inputJSON();
    verificaCSRF($dati['csrf_token'] ?? null);

    $nome        = sanitizzaStringa($dati['nome'] ?? '', 100);
    $tipo        = in_array($dati['tipo'] ?? '', ['uscita', 'accredito']) ? $dati['tipo'] : 'uscita';
    $colore      = preg_match('/^#[0-9a-fA-F]{6}$/', $dati['colore'] ?? '') ? $dati['colore'] : '#6366f1';
    $icona       = sanitizzaStringa($dati['icona'] ?? '', 50);
    $ordinamento = (int)($dati['ordinamento'] ?? 0);

    if (empty($nome)) rispostaErrore(400, 'Nome categoria obbligatorio.');
    if (nomeCategoriaRiservato($nome)) {
        rispostaErrore(409, 'Il nome "' . CATEGORIA_EVENTI_NOME . '" è riservato: la categoria viene creata automaticamente dagli eventi.');
    }

    $db = getDB();
    try {
        $db->prepare("INSERT INTO categorie (casa_id, nome, tipo, colore, icona, ordinamento) VALUES (?,?,?,?,?,?)")
           ->execute([$casaId, $nome, $tipo, $colore, $icona ?: null, $ordinamento]);
        risposta(['id' => ultimoId('categorie'), 'messaggio' => 'Categoria creata.'], 201);
    } catch (PDOException $e) {
        if ($e->getCode() === '23505') rispostaErrore(409, 'Categoria già esistente.');
        throw $e;
    }
}

function aggiornaCategoria(int $id): void {
    [, $casaId] = richiedeAdminDiCasa();
    $dati = inputJSON();
    verificaCSRF($dati['csrf_token'] ?? null);

    $nome        = sanitizzaStringa($dati['nome'] ?? '', 100);
    $tipo        = in_array($dati['tipo'] ?? '', ['uscita', 'accredito']) ? $dati['tipo'] : 'uscita';
    $colore      = preg_match('/^#[0-9a-fA-F]{6}$/', $dati['colore'] ?? '') ? $dati['colore'] : '#6366f1';
    $icona       = sanitizzaStringa($dati['icona'] ?? '', 50);
    $ordinamento = (int)($dati['ordinamento'] ?? 0);
    $attiva      = !empty($dati['attiva']) ? 1 : 0;

    if (empty($nome)) rispostaErrore(400, 'Nome categoria obbligatorio.');
    if (eCategoriaSistema(getDB(), $id)) {
        rispostaErrore(403, 'La categoria ' . CATEGORIA_EVENTI_NOME . ' è gestita automaticamente dagli eventi e non è modificabile.');
    }
    if (nomeCategoriaRiservato($nome)) {
        rispostaErrore(409, 'Il nome "' . CATEGORIA_EVENTI_NOME . '" è riservato alla categoria degli eventi.');
    }

    $affected = getDB()->prepare("
        UPDATE categorie SET nome=?, tipo=?, colore=?, icona=?, ordinamento=?, attiva=?
        WHERE id=? AND casa_id=?
    ")->execute([$nome, $tipo, $colore, $icona ?: null, $ordinamento, $attiva, $id, $casaId]);

    risposta(['messaggio' => 'Categoria aggiornata.']);
}

function eliminaCategoria(int $id): void {
    [, $casaId] = richiedeAdminDiCasa();
    $db = getDB();

    // Verifica che la categoria appartenga alla casa
    $stmt = $db->prepare("SELECT id FROM categorie WHERE id = ? AND casa_id = ?");
    $stmt->execute([$id, $casaId]);
    if (!$stmt->fetch()) rispostaErrore(404, 'Categoria non trovata.');
    if (eCategoriaSistema($db, $id)) {
        rispostaErrore(403, 'La categoria ' . CATEGORIA_EVENTI_NOME . ' è gestita automaticamente dagli eventi e non può essere eliminata.');
    }

    // Controlla spese collegate (scoped per casa)
    $stmt = $db->prepare("SELECT COUNT(*) FROM spese WHERE id_categoria = ? AND casa_id = ?");
    $stmt->execute([$id, $casaId]);
    if ((int)$stmt->fetchColumn() > 0) {
        rispostaErrore(409, 'Impossibile eliminare: esistono spese collegate. Disattiva la categoria.');
    }

    $db->prepare("DELETE FROM categorie WHERE id = ? AND casa_id = ?")->execute([$id, $casaId]);
    risposta(['messaggio' => 'Categoria eliminata.']);
}

// ============================================================
// SOTTOCATEGORIE
// ============================================================

function listaSottocategorie(): void {
    [, $casaId] = richiedeAccessoCasa();
    $db     = getDB();
    $params = [$casaId];
    $where  = ['c.casa_id = ?'];

    if (!empty($_GET['id_categoria'])) {
        $where[]  = 'sc.id_categoria = ?';
        $params[] = validaIntero($_GET['id_categoria'], 'Categoria');
    }
    if (empty($_GET['includi_disattive'])) {
        $where[] = 'sc.attiva = 1';
    }

    $whereSQL = 'WHERE ' . implode(' AND ', $where);
    $stmt = $db->prepare("
        SELECT sc.id, sc.id_categoria, sc.nome, sc.ordinamento, sc.attiva,
               c.nome AS categoria_nome,
               -- Una sottocategoria può essere collegata a più eventi:
               -- evento_id = primo collegamento diretto (compatibilità), eventi_ids = tutti
               (SELECT MIN(se.evento_id) FROM sottocategorie_eventi se
                 WHERE se.sottocategoria_id = sc.id AND se.escluso = 0) AS evento_id,
               (SELECT " . sqlConcatGruppo('se.evento_id', 'se.evento_id') . " FROM sottocategorie_eventi se
                 WHERE se.sottocategoria_id = sc.id AND se.escluso = 0) AS eventi_ids
        FROM sottocategorie sc
        JOIN categorie c ON c.id = sc.id_categoria
        $whereSQL
        ORDER BY sc.ordinamento ASC, sc.nome ASC
    ");
    $stmt->execute($params);

    risposta(['sottocategorie' => $stmt->fetchAll()]);
}

function creaSottocategoria(): void {
    [, $casaId] = richiedeAdminDiCasa();
    $dati = inputJSON();
    verificaCSRF($dati['csrf_token'] ?? null);

    $idCategoria = validaIntero($dati['id_categoria'] ?? 0, 'Categoria');
    $nome        = sanitizzaStringa($dati['nome'] ?? '', 100);
    $ordinamento = (int)($dati['ordinamento'] ?? 0);

    if (empty($nome)) rispostaErrore(400, 'Nome sottocategoria obbligatorio.');

    $db = getDB();
    // Verifica che la categoria esista e appartenga alla casa
    $cat = $db->prepare("SELECT id FROM categorie WHERE id = ? AND casa_id = ?");
    $cat->execute([$idCategoria, $casaId]);
    if (!$cat->fetch()) rispostaErrore(400, 'Categoria non trovata o non appartiene a questa casa.');
    if (eCategoriaSistema($db, $idCategoria)) {
        rispostaErrore(403, 'Le sottocategorie di ' . CATEGORIA_EVENTI_NOME . ' si creano automaticamente creando un evento.');
    }

    $db->prepare("INSERT INTO sottocategorie (id_categoria, nome, ordinamento) VALUES (?,?,?)")
       ->execute([$idCategoria, $nome, $ordinamento]);

    risposta(['id' => ultimoId('sottocategorie'), 'messaggio' => 'Sottocategoria creata.'], 201);
}

function aggiornaSottocategoria(int $id): void {
    [, $casaId] = richiedeAdminDiCasa();
    $dati = inputJSON();
    verificaCSRF($dati['csrf_token'] ?? null);

    $nome        = sanitizzaStringa($dati['nome'] ?? '', 100);
    $ordinamento = (int)($dati['ordinamento'] ?? 0);
    $attiva      = !empty($dati['attiva']) ? 1 : 0;

    if (empty($nome)) rispostaErrore(400, 'Nome obbligatorio.');

    // Verifica che la sottocategoria appartenga a una categoria di questa casa
    $db   = getDB();
    $stmt = $db->prepare("
        SELECT sc.id FROM sottocategorie sc
        JOIN categorie c ON c.id = sc.id_categoria
        WHERE sc.id = ? AND c.casa_id = ?
    ");
    $stmt->execute([$id, $casaId]);
    if (!$stmt->fetch()) rispostaErrore(404, 'Sottocategoria non trovata.');
    if (eSottocategoriaSistema($db, $id)) {
        rispostaErrore(403, 'Le sottocategorie di ' . CATEGORIA_EVENTI_NOME . ' si modificano dagli eventi.');
    }

    $db->prepare("UPDATE sottocategorie SET nome=?, ordinamento=?, attiva=? WHERE id=?")
       ->execute([$nome, $ordinamento, $attiva, $id]);

    risposta(['messaggio' => 'Sottocategoria aggiornata.']);
}

function eliminaSottocategoria(int $id): void {
    [, $casaId] = richiedeAdminDiCasa();
    $db = getDB();

    // Verifica ownership tramite JOIN sulla casa
    $stmt = $db->prepare("
        SELECT sc.id FROM sottocategorie sc
        JOIN categorie c ON c.id = sc.id_categoria
        WHERE sc.id = ? AND c.casa_id = ?
    ");
    $stmt->execute([$id, $casaId]);
    if (!$stmt->fetch()) rispostaErrore(404, 'Sottocategoria non trovata.');
    if (eSottocategoriaSistema($db, $id)) {
        rispostaErrore(403, 'Le sottocategorie di ' . CATEGORIA_EVENTI_NOME . ' si eliminano eliminando l\'evento.');
    }

    // Controlla spese collegate (scoped per casa)
    $stmt = $db->prepare("SELECT COUNT(*) FROM spese WHERE id_sottocategoria = ? AND casa_id = ?");
    $stmt->execute([$id, $casaId]);
    if ((int)$stmt->fetchColumn() > 0) {
        rispostaErrore(409, 'Impossibile eliminare: esistono spese collegate. Disattiva la sottocategoria.');
    }

    $db->prepare("DELETE FROM sottocategorie WHERE id = ?")->execute([$id]);
    risposta(['messaggio' => 'Sottocategoria eliminata.']);
}
