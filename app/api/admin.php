<?php
ob_start();
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(0);
// ============================================================
// ADMIN.PHP - Gestione casa, membri, inviti, stats
//             + Invio email invito (v4)
//             + Blocco reinvio 24h (v4)
//             + Stato inviti (v4)
// ============================================================
require_once __DIR__ . '/config.php';
inizializzaAPI();

$metodo  = $_SERVER['REQUEST_METHOD'];
$risorsa = $_GET['risorsa'] ?? '';
$id      = isset($_GET['id']) ? validaIntero($_GET['id'], 'ID') : null;

if ($metodo === 'GET') {
    richiedeAccessoCasa();
    rilasciaSessione();
}

$azione = $_GET['azione'] ?? '';

match(true) {
    // Casa — lista/info
    $metodo === 'GET'    && $risorsa === 'casa'                                   => infoCasa(),
    // Casa — rinomina/colore
    $metodo === 'PUT'    && $risorsa === 'casa'    && $azione === ''              => aggiornaCasa(),
    // Casa — cambia default
    $metodo === 'PUT'    && $risorsa === 'casa'    && $azione === 'default'       => impostaCasaDefault(),
    // Casa — assegna nuovo admin prima di eliminare
    $metodo === 'PUT'    && $risorsa === 'casa'    && $azione === 'assegna_admin' => assegnaAdmin(),
    // Casa — crea nuova
    $metodo === 'POST'   && $risorsa === 'case'                                   => creaCasa(),
    // Casa — elimina/abbandona
    $metodo === 'DELETE' && $risorsa === 'casa'    &&  $id                        => eliminaCasa($id),
    // Membri
    $metodo === 'GET'    && $risorsa === 'membri'       && !$id                   => listaMembri(),
    $metodo === 'GET'    && $risorsa === 'membri'       &&  $id                   => dettaglioMembro($id),
    $metodo === 'PUT'    && $risorsa === 'membri'       &&  $id                   => aggiornaMembro($id),
    $metodo === 'DELETE' && $risorsa === 'membri'       &&  $id                   => rimuoviMembro($id),
    // Inviti
    $metodo === 'GET'    && $risorsa === 'inviti'                                 => listaInviti(),
    $metodo === 'POST'   && $risorsa === 'inviti'                                 => creaInvito(),
    $metodo === 'POST'   && $risorsa === 'inviti'  && $azione === 'reinvia'       => reinviaEmail($id),   // NUOVO v4
    $metodo === 'DELETE' && $risorsa === 'inviti'       &&  $id                   => revocaInvito($id),
    // Stats
    $metodo === 'GET'    && $risorsa === 'stats'                                  => statsCasa(),
    default => rispostaErrore(404, 'Endpoint non trovato.')
};

// ============================================================
// CASA
// ============================================================

function infoCasa(): void {
    $utente = utenteAutenticato();
    $db     = getDB();

    $stmt = $db->prepare("
        SELECT cs.id, cs.nome, cs.casa_personale, cs.colore,
               uc.ruolo, uc.casa_default
        FROM utenti_case uc
        JOIN \"case\" cs ON cs.id = uc.casa_id
        WHERE uc.utente_id = ?
        ORDER BY uc.casa_default DESC, cs.nome ASC
    ");
    $stmt->execute([$utente['id']]);
    $case = $stmt->fetchAll();

    if (!$case) rispostaErrore(404, 'Nessuna casa trovata.');

    risposta(['case' => $case]);
}

function aggiornaCasa(): void {
    [, $casaId] = richiedeAdminDiCasa();
    $dati = inputJSON();
    verificaCSRF($dati['csrf_token'] ?? null);

    $nome   = sanitizzaStringa($dati['nome'] ?? '', 100);
    $colore = preg_match('/^#[0-9a-fA-F]{6}$/', $dati['colore'] ?? '') ? $dati['colore'] : null;

    if (empty($nome)) rispostaErrore(400, 'Nome casa obbligatorio.');

    $db   = getDB();
    $stmt = $db->prepare("SELECT casa_personale FROM \"case\" WHERE id = ?");
    $stmt->execute([$casaId]);
    $casa = $stmt->fetch();
    if (!$casa) rispostaErrore(404, 'Casa non trovata.');

    $sql    = "UPDATE \"case\" SET nome=?";
    $params = [$nome];
    if ($colore !== null) {
        $sql    .= ", colore=?";
        $params[] = $colore;
    }
    $sql    .= " WHERE id=?";
    $params[] = $casaId;

    $db->prepare($sql)->execute($params);
    risposta(['messaggio' => 'Casa aggiornata.']);
}

// ============================================================
// CREA NUOVA CASA
// ============================================================
function creaCasa(): void {
    $utente = utenteAutenticato();
    $dati   = inputJSON();
    verificaCSRF($dati['csrf_token'] ?? null);

    $nome   = sanitizzaStringa($dati['nome'] ?? '', 100);
    $colore = preg_match('/^#[0-9a-fA-F]{6}$/', $dati['colore'] ?? '') ? $dati['colore'] : '#4f46e5';

    if (empty($nome)) rispostaErrore(400, 'Nome casa obbligatorio.');

    $db = getDB();
    $db->beginTransaction();

    try {
        $db->prepare("
            INSERT INTO \"case\" (nome, colore, casa_personale, created_by)
            VALUES (?, ?, 0, ?)
        ")->execute([$nome, $colore, $utente['id']]);

        $casaId = ultimoId('case');

        $db->prepare("
            INSERT INTO utenti_case (utente_id, casa_id, ruolo, casa_default)
            VALUES (?, ?, 'admin', 0)
        ")->execute([$utente['id'], $casaId]);

        // Categorie predefinite nella lingua di chi crea la casa (fallback inglese)
        copiaCategorieTemplate($db, $casaId, preferenzeUtente($db, (int)$utente['id'])['lingua']);

        $db->commit();
        risposta(['id' => $casaId, 'messaggio' => 'Casa creata.'], 201);

    } catch (\Throwable $e) {
        $db->rollBack();
        logErrore('creaCasa: ' . $e->getMessage());
        rispostaErrore(500, 'Errore durante la creazione della casa.');
    }
}

// ============================================================
// ELIMINA / ABBANDONA CASA
// ============================================================
function eliminaCasa(int $casaId): void {
    $utente = utenteAutenticato();
    $dati   = inputJSON();
    verificaCSRF($dati['csrf_token'] ?? null);

    $db = getDB();

    $stmtUc = $db->prepare("SELECT ruolo FROM utenti_case WHERE utente_id = ? AND casa_id = ?");
    $stmtUc->execute([$utente['id'], $casaId]);
    $uc = $stmtUc->fetch();
    if (!$uc) rispostaErrore(404, 'Casa non trovata o non appartieni a questa casa.');

    $stmtCasa = $db->prepare("SELECT casa_personale, created_by FROM \"case\" WHERE id = ?");
    $stmtCasa->execute([$casaId]);
    $casa = $stmtCasa->fetch();
    if (!$casa) rispostaErrore(404, 'Casa non trovata.');
    if ($casa['casa_personale']) rispostaErrore(400, 'La casa personale non può essere eliminata.');

    $stmtAdmin = $db->prepare("SELECT COUNT(*) FROM utenti_case WHERE casa_id = ? AND ruolo = 'admin'");
    $stmtAdmin->execute([$casaId]);
    $numAdmin = (int)$stmtAdmin->fetchColumn();

    $stmtMembri = $db->prepare("SELECT COUNT(*) FROM utenti_case WHERE casa_id = ?");
    $stmtMembri->execute([$casaId]);
    $numMembri = (int)$stmtMembri->fetchColumn();

    $db->beginTransaction();
    try {
        if ($uc['ruolo'] !== 'admin') {
            $db->prepare("DELETE FROM utenti_case WHERE utente_id = ? AND casa_id = ?")->execute([$utente['id'], $casaId]);
            $db->commit();
            risposta(['messaggio' => 'Hai abbandonato la casa.', 'azione' => 'abbandonato']);
            return;
        }

        if ($numAdmin > 1) {
            $db->prepare("DELETE FROM utenti_case WHERE utente_id = ? AND casa_id = ?")->execute([$utente['id'], $casaId]);
            $db->commit();
            risposta(['messaggio' => 'Hai abbandonato la casa.', 'azione' => 'abbandonato']);
            return;
        }

        if ($numMembri > 1) {
            $db->rollBack();
            rispostaErrore(409, 'Sei l\'unico admin. Assegna un nuovo admin prima di abbandonare.');
        }

        $db->prepare("DELETE FROM spese WHERE casa_id = ?")->execute([$casaId]);
        $db->prepare("DELETE FROM \"case\" WHERE id = ?")->execute([$casaId]);
        $db->commit();
        risposta(['messaggio' => 'Casa eliminata definitivamente.', 'azione' => 'eliminata']);

    } catch (\Throwable $e) {
        $db->rollBack();
        logErrore('eliminaCasa: ' . $e->getMessage());
        rispostaErrore(500, 'Errore durante l\'operazione.');
    }
}

// ============================================================
// IMPOSTA CASA DEFAULT
// ============================================================
function impostaCasaDefault(): void {
    $utente = utenteAutenticato();
    $dati   = inputJSON();
    verificaCSRF($dati['csrf_token'] ?? null);

    $casaId = isset($dati['casa_id']) ? (int)$dati['casa_id'] : 0;
    if ($casaId <= 0) rispostaErrore(400, 'ID casa non valido.');

    $db   = getDB();
    $stmt = $db->prepare("SELECT id FROM utenti_case WHERE utente_id = ? AND casa_id = ?");
    $stmt->execute([$utente['id'], $casaId]);
    if (!$stmt->fetch()) rispostaErrore(404, 'Casa non trovata o non appartieni a questa casa.');

    $db->beginTransaction();
    try {
        $db->prepare("UPDATE utenti_case SET casa_default = 0 WHERE utente_id = ?")->execute([$utente['id']]);
        $db->prepare("UPDATE utenti_case SET casa_default = 1 WHERE utente_id = ? AND casa_id = ?")->execute([$utente['id'], $casaId]);
        $db->commit();
        risposta(['messaggio' => 'Casa default aggiornata.']);
    } catch (\Throwable $e) {
        $db->rollBack();
        rispostaErrore(500, 'Errore durante l\'aggiornamento.');
    }
}

// ============================================================
// ASSEGNA NUOVO ADMIN (prima di abbandonare)
// ============================================================
function assegnaAdmin(): void {
    [$utente, $casaId] = richiedeAdminDiCasa();
    $dati = inputJSON();
    verificaCSRF($dati['csrf_token'] ?? null);

    $nuovoAdminId = isset($dati['utente_id']) ? (int)$dati['utente_id'] : 0;
    if ($nuovoAdminId <= 0) rispostaErrore(400, 'ID utente non valido.');
    if ($nuovoAdminId === $utente['id']) rispostaErrore(400, 'Non puoi assegnare admin a te stesso.');

    $db   = getDB();
    $stmt = $db->prepare("SELECT ruolo FROM utenti_case WHERE utente_id = ? AND casa_id = ?");
    $stmt->execute([$nuovoAdminId, $casaId]);
    $membro = $stmt->fetch();
    if (!$membro) rispostaErrore(404, 'Utente non trovato in questa casa.');

    $db->prepare("UPDATE utenti_case SET ruolo = 'admin' WHERE utente_id = ? AND casa_id = ?")
       ->execute([$nuovoAdminId, $casaId]);

    risposta(['messaggio' => 'Nuovo admin assegnato.']);
}

// ============================================================
// MEMBRI
// ============================================================

function listaMembri(): void {
    [, $casaId] = richiedeAccessoCasa();
    $db = getDB();

    $stmt = $db->prepare("
        SELECT u.id, u.nome, u.email, u.attivo, u.ultimo_accesso,
               uc.ruolo, uc.invito_accettato, uc.created_at AS membro_dal
        FROM utenti_case uc
        JOIN utenti u ON u.id = uc.utente_id
        WHERE uc.casa_id = ?
        ORDER BY uc.created_at ASC
    ");
    $stmt->execute([$casaId]);
    risposta(['membri' => $stmt->fetchAll()]);
}

function dettaglioMembro(int $id): void {
    [, $casaId] = richiedeAdminDiCasa();
    $db = getDB();

    $stmt = $db->prepare("
        SELECT u.id, u.nome, u.email, u.attivo, u.ultimo_accesso,
               uc.ruolo, uc.invito_accettato, uc.creato_il AS membro_dal
        FROM utenti_case uc
        JOIN utenti u ON u.id = uc.utente_id
        WHERE uc.casa_id = ? AND uc.utente_id = ?
    ");
    $stmt->execute([$casaId, $id]);
    $membro = $stmt->fetch();
    if (!$membro) rispostaErrore(404, 'Membro non trovato in questa casa.');

    $stmtStats = $db->prepare("
        SELECT
            COUNT(*)                                                              AS totale_spese,
            COALESCE(SUM(CASE WHEN c.tipo='uscita'    THEN s.importo    ELSE 0 END), 0) AS totale_uscite,
            COALESCE(SUM(CASE WHEN c.tipo='accredito' THEN ABS(s.importo) ELSE 0 END), 0) AS totale_entrate,
            MIN(s.data_spesa) AS prima_spesa,
            MAX(s.data_spesa) AS ultima_spesa
        FROM spese s
        JOIN categorie c ON c.id = s.id_categoria
        WHERE s.id_utente = ? AND s.casa_id = ?
    ");
    $stmtStats->execute([$id, $casaId]);

    risposta([
        'membro' => $membro,
        'stats'  => $stmtStats->fetch(),
    ]);
}

function aggiornaMembro(int $id): void {
    [$utente, $casaId] = richiedeAdminDiCasa();
    $dati = inputJSON();
    verificaCSRF($dati['csrf_token'] ?? null);

    $db = getDB();

    $stmt = $db->prepare("SELECT ruolo FROM utenti_case WHERE casa_id = ? AND utente_id = ?");
    $stmt->execute([$casaId, $id]);
    $membroCorrente = $stmt->fetch();
    if (!$membroCorrente) rispostaErrore(404, 'Membro non trovato in questa casa.');

    $nuovoRuolo = in_array($dati['ruolo'] ?? '', ['admin', 'membro']) ? $dati['ruolo'] : 'membro';

    if ($id === $utente['id'] && $nuovoRuolo !== 'admin') {
        rispostaErrore(400, 'Non puoi degradare il tuo stesso ruolo.');
    }

    if ($membroCorrente['ruolo'] === 'admin' && $nuovoRuolo !== 'admin') {
        $stmtAdminCount = $db->prepare("SELECT COUNT(*) FROM utenti_case WHERE casa_id = ? AND ruolo = 'admin'");
        $stmtAdminCount->execute([$casaId]);
        if ((int)$stmtAdminCount->fetchColumn() <= 1) {
            rispostaErrore(400, 'Impossibile: deve esistere almeno un admin nella casa.');
        }
    }

    $db->prepare("UPDATE utenti_case SET ruolo=? WHERE casa_id=? AND utente_id=?")
       ->execute([$nuovoRuolo, $casaId, $id]);

    risposta(['messaggio' => 'Ruolo membro aggiornato.']);
}

function rimuoviMembro(int $id): void {
    [$utente, $casaId] = richiedeAdminDiCasa();
    $db = getDB();

    if ($id === $utente['id']) {
        rispostaErrore(400, 'Non puoi rimuovere te stesso dalla casa.');
    }

    $stmt = $db->prepare("SELECT ruolo, casa_id FROM utenti_case WHERE casa_id = ? AND utente_id = ?");
    $stmt->execute([$casaId, $id]);
    $membro = $stmt->fetch();
    if (!$membro) rispostaErrore(404, 'Membro non trovato in questa casa.');

    if ($membro['ruolo'] === 'admin') {
        $stmtCount = $db->prepare("SELECT COUNT(*) FROM utenti_case WHERE casa_id = ? AND ruolo = 'admin'");
        $stmtCount->execute([$casaId]);
        if ((int)$stmtCount->fetchColumn() <= 1) {
            rispostaErrore(400, 'Impossibile: deve esistere almeno un admin nella casa.');
        }
    }

    $stmtCasa = $db->prepare("SELECT casa_personale FROM \"case\" WHERE id = ?");
    $stmtCasa->execute([$casaId]);
    $casaInfo = $stmtCasa->fetch();
    if ($casaInfo && $casaInfo['casa_personale']) {
        $stmtOwner = $db->prepare("SELECT created_by FROM \"case\" WHERE id = ? AND created_by = ?");
        $stmtOwner->execute([$casaId, $id]);
        if ($stmtOwner->fetch()) {
            rispostaErrore(400, 'Impossibile rimuovere il proprietario della casa personale.');
        }
    }

    $db->prepare("DELETE FROM utenti_case WHERE casa_id=? AND utente_id=?")->execute([$casaId, $id]);
    risposta(['messaggio' => 'Membro rimosso dalla casa.']);
}

// ============================================================
// INVITI
// ============================================================

/**
 * Lista inviti della casa con stato, info invitato e tempi.
 * L'admin vede: pendenti, accettati, rifiutati, scaduti.
 */
function listaInviti(): void {
    [$utente, $casaId] = richiedeAdminDiCasa();
    $db = getDB();

    $stmt = $db->prepare("
        SELECT
            i.id,
            i.email_invitato,
            i.ruolo,
            i.token,
            i.scade_il,
            i.usato,
            i.stato,
            i.risposta_il,
            i.email_inviata,
            i.ultimo_invio_il,
            i.creato_il,
            -- Può reinviare? Solo se pendente, non scaduto, e sono passate 24h dall'ultimo invio
            CASE
                WHEN i.usato = 1                                     THEN 0
                WHEN i.stato != 'in_attesa'                          THEN 0
                WHEN i.scade_il < NOW()                              THEN 0
                WHEN i.ultimo_invio_il IS NULL                       THEN 1
                WHEN i.ultimo_invio_il < NOW() - INTERVAL '24' HOUR   THEN 1
                ELSE 0
            END AS puo_reinviare,
            -- Ore rimanenti al prossimo reinvio
            CASE
                WHEN i.ultimo_invio_il IS NOT NULL AND i.usato = 0 AND i.stato = 'in_attesa'
                THEN GREATEST(0, " . sqlMinutiDaOraA("i.ultimo_invio_il + INTERVAL '24' HOUR") . ")
                ELSE 0
            END AS minuti_al_reinvio
        FROM inviti i
        WHERE i.casa_id = ?
        ORDER BY
            CASE i.stato WHEN 'in_attesa' THEN 1 WHEN 'rifiutato' THEN 2 ELSE 3 END,
            i.creato_il DESC
    ");
    $stmt->execute([$casaId]);
    risposta(['inviti' => $stmt->fetchAll()]);
}

/**
 * Crea un nuovo invito e invia l'email all'invitato.
 *
 * Flusso:
 *   1. Valida input
 *   2. Controlla se esiste già un invito pendente per questa email/casa
 *   3. Salva l'invito nel DB
 *   4. Determina se l'email è già registrata → link login vs registrazione
 *   5. Invia email
 *   6. Aggiorna flag email_inviata e ultimo_invio_il
 */
function creaInvito(): void {
    [$utente, $casaId] = richiedeAdminDiCasa();
    $dati = inputJSON();
    verificaCSRF($dati['csrf_token'] ?? null);

    $email          = !empty($dati['email']) ? strtolower(sanitizzaStringa($dati['email'], 150)) : null;
    $ruolo          = in_array($dati['ruolo'] ?? '', ['admin', 'membro']) ? $dati['ruolo'] : 'membro';
    $giorniValidita = max(1, min(30, (int)($dati['giorni_validita'] ?? 7)));

    if (empty($email)) {
        rispostaErrore(400, 'Email obbligatoria per inviare l\'invito.');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        rispostaErrore(400, 'Email non valida.');
    }

    $db = getDB();

    // L'utente è già membro attivo di questa casa?
    $stmtMembro = $db->prepare("
        SELECT COUNT(*) FROM utenti_case uc
        JOIN utenti u ON u.id = uc.utente_id
        WHERE uc.casa_id = ? AND u.email = ? AND uc.invito_accettato = 1
    ");
    $stmtMembro->execute([$casaId, $email]);
    if ((int)$stmtMembro->fetchColumn() > 0) {
        rispostaErrore(409, 'Questo utente è già membro della casa.');
    }

    // Esiste già un invito pendente per questa email/casa?
    $stmtPendente = $db->prepare("
        SELECT id, ultimo_invio_il FROM inviti
        WHERE casa_id = ? AND email_invitato = ? AND usato = 0 AND stato = 'in_attesa' AND scade_il > NOW()
    ");
    $stmtPendente->execute([$casaId, $email]);
    $invitoPendente = $stmtPendente->fetch();

    if ($invitoPendente) {
        // Blocco 24h: impedisce spam
        if (!empty($invitoPendente['ultimo_invio_il'])) {
            $prossimoInvio = strtotime($invitoPendente['ultimo_invio_il']) + 86400;
            if (time() < $prossimoInvio) {
                $oreRimanenti = ceil(($prossimoInvio - time()) / 3600);
                rispostaErrore(429, "Invito già inviato. Puoi reinviare tra {$oreRimanenti} ore.");
            }
        }
        // Se possiamo reinviare, usa il token esistente
        $token    = null; // sarà preso dall'invito esistente
        $invitoPendente = $invitoPendente; // già fetchato
    }

    // Carica info casa e invitante per l'email
    $stmtCasa = $db->prepare("SELECT nome FROM \"case\" WHERE id = ?");
    $stmtCasa->execute([$casaId]);
    $casa = $stmtCasa->fetch();

    // L'email è già registrata? Determina il link corretto
    $stmtReg = $db->prepare("SELECT COUNT(*) FROM utenti WHERE email = ?");
    $stmtReg->execute([$email]);
    $emailRegistrata = (int)$stmtReg->fetchColumn() > 0;

    if ($invitoPendente) {
        // Reinvio: aggiorna solo ultimo_invio_il (il token rimane lo stesso)
        $db->prepare("
            UPDATE inviti SET ultimo_invio_il = NOW(), email_inviata = 1
            WHERE id = ?
        ")->execute([$invitoPendente['id']]);

        // Recupera token per costruire il link
        $stmtToken = $db->prepare("SELECT token FROM inviti WHERE id = ?");
        $stmtToken->execute([$invitoPendente['id']]);
        $tokenInvito = $stmtToken->fetchColumn();
        $invId       = $invitoPendente['id'];
        $scadenza    = null; // non cambia

    } else {
        // Nuovo invito
        $tokenInvito = bin2hex(random_bytes(32));
        $scadenza    = date('Y-m-d H:i:s', strtotime("+{$giorniValidita} days"));

        $db->prepare("
            INSERT INTO inviti
                (casa_id, email_invitato, ruolo, creato_da, token, scade_il, stato, email_inviata, ultimo_invio_il)
            VALUES
                (?, ?, ?, ?, ?, ?, 'in_attesa', 1, NOW())
        ")->execute([$casaId, $email, $ruolo, $utente['id'], $tokenInvito, $scadenza]);
        $invId = ultimoId('inviti');
    }

    // Costruisce link (login o registrazione in base allo stato email)
    $pagina = $emailRegistrata ? 'index.html' : 'index.html';
    $link   = APP_URL . "/{$pagina}?azione=invito&token=" . urlencode($tokenInvito);

    // Lingua dell'email: quella del destinatario se registrato, altrimenti di chi invita
    $lingua = linguaUtentePerEmail($db, $email) ?? preferenzeUtente($db, (int)$utente['id'])['lingua'];

    $ruoloLabel    = tr($ruolo === 'admin' ? 'Amministratore' : 'Membro', [], $lingua);
    $azioneLabel   = $emailRegistrata
        ? tr('Accedi al tuo account e poi accetta l\'invito dalla notifica.', [], $lingua)
        : tr('Registrati con questo indirizzo email e poi accetta l\'invito dalla notifica.', [], $lingua);
    $btnLabel      = tr($emailRegistrata ? 'Accedi e accetta l\'invito' : 'Registrati e accetta l\'invito', [], $lingua);
    $validita      = $invitoPendente
        ? tr('la durata originale dell\'invito', [], $lingua)
        : tr('{0} giorni', [$giorniValidita], $lingua);

    $nomeInvitante = htmlspecialchars($utente['nome'], ENT_QUOTES, 'UTF-8');
    $nomeCasa      = htmlspecialchars($casa['nome'], ENT_QUOTES, 'UTF-8');

    $corpo = "
        <p>" . tr('Ciao,', [], $lingua) . "</p>
        <p>" . tr('{0} ti ha invitato a far parte della casa {1} con il ruolo di {2}.', [
                "<strong>{$nomeInvitante}</strong>",
                "<strong style='color:#4f46e5;'>{$nomeCasa}</strong>",
                "<strong>{$ruoloLabel}</strong>",
            ], $lingua) . "</p>
        <p style='margin:24px 0;'>{$azioneLabel}</p>
        <p style='text-align:center;margin:32px 0;'>
            <a href='{$link}'
               style='background:#4f46e5;color:#fff;padding:14px 28px;border-radius:8px;
                      text-decoration:none;font-weight:700;font-size:15px;display:inline-block;'>
                {$btnLabel}
            </a>
        </p>
        <p style='color:#6b7280;font-size:13px;'>
            " . tr('Il link è valido per {0}.', ["<strong>{$validita}</strong>"], $lingua) . "<br>
            " . tr('Se non conosci il mittente o non vuoi accettare, ignora questa email.', [], $lingua) . "
        </p>
        <p style='color:#6b7280;font-size:13px;'>
            " . tr('Oppure copia questo link nel browser:', [], $lingua) . "<br>
            <a href='{$link}' style='color:#4f46e5;word-break:break-all;'>{$link}</a>
        </p>
    ";

    $oggettoEmail = tr('Sei stato invitato in "{0}"', [$casa['nome']], $lingua) . ' - ' . APP_NOME_DEFAULT;
    $html         = emailTemplate(tr('Invito in "{0}"', [$nomeCasa], $lingua), $corpo, $lingua);
    $esito        = inviaEmail($email, '', $oggettoEmail, $html);

    if (!$esito) {
        logErrore("creaInvito: inviaEmail fallita per {$email}");
        // Non blocchiamo: l'invito è salvato, si può reinviare
    }

    risposta([
        'id'           => $invId,
        'token'        => $tokenInvito,
        'scadenza'     => $scadenza,
        'email_inviata'=> $esito,
        'messaggio'    => $esito
            ? 'Invito creato e email inviata.'
            : 'Invito creato, ma l\'invio email ha avuto un problema. Usa il link manuale.',
    ], 201);
}

/**
 * Reinvia l'email per un invito esistente (endpoint separato per il pulsante UI).
 * Blocco 24h: come in creaInvito.
 */
function reinviaEmail(?int $id): void {
    [$utente, $casaId] = richiedeAdminDiCasa();

    if (!$id) rispostaErrore(400, 'ID invito mancante.');

    $dati = inputJSON();
    verificaCSRF($dati['csrf_token'] ?? null);

    $db   = getDB();
    $stmt = $db->prepare("
        SELECT i.id, i.token, i.email_invitato, i.ruolo, i.scade_il, i.stato, i.usato, i.ultimo_invio_il,
               c.nome AS casa_nome
        FROM inviti i
        JOIN \"case\" c ON c.id = i.casa_id
        WHERE i.id = ? AND i.casa_id = ?
    ");
    $stmt->execute([$id, $casaId]);
    $invito = $stmt->fetch();

    if (!$invito) rispostaErrore(404, 'Invito non trovato.');
    if ($invito['usato'] || $invito['stato'] !== 'in_attesa') {
        rispostaErrore(400, 'L\'invito non è più in attesa, non può essere reinviato.');
    }
    if (strtotime($invito['scade_il']) < time()) {
        rispostaErrore(400, 'L\'invito è scaduto. Crea un nuovo invito.');
    }

    // Blocco 24h
    if (!empty($invito['ultimo_invio_il'])) {
        $prossimoInvio = strtotime($invito['ultimo_invio_il']) + 86400;
        if (time() < $prossimoInvio) {
            $oreRimanenti = ceil(($prossimoInvio - time()) / 3600);
            rispostaErrore(429, "Puoi reinviare tra {$oreRimanenti} ore.");
        }
    }

    // L'email è già registrata?
    $stmtReg = $db->prepare("SELECT COUNT(*) FROM utenti WHERE email = ?");
    $stmtReg->execute([$invito['email_invitato']]);
    $emailRegistrata = (int)$stmtReg->fetchColumn() > 0;

    $link       = APP_URL . '/index.html?azione=invito&token=' . urlencode($invito['token']);

    // Lingua e formato data: del destinatario se registrato, altrimenti di chi invita
    $prefInvitante = preferenzeUtente($db, (int)$utente['id']);
    $lingua        = linguaUtentePerEmail($db, $invito['email_invitato']) ?? $prefInvitante['lingua'];
    $formatoData   = preferenzeDefault($lingua)['formato_data'];

    $ruoloLabel = tr($invito['ruolo'] === 'admin' ? 'Amministratore' : 'Membro', [], $lingua);
    $btnLabel   = tr($emailRegistrata ? 'Accedi e accetta l\'invito' : 'Registrati e accetta l\'invito', [], $lingua);
    $azioneLabel = $emailRegistrata
        ? tr('Accedi al tuo account e poi accetta l\'invito dalla notifica.', [], $lingua)
        : tr('Registrati con questo indirizzo email e poi accetta l\'invito dalla notifica.', [], $lingua);

    $nomeInvitante = htmlspecialchars($utente['nome'], ENT_QUOTES, 'UTF-8');
    $nomeCasa      = htmlspecialchars($invito['casa_nome'], ENT_QUOTES, 'UTF-8');

    $corpo = "
        <p>" . tr('Ciao,', [], $lingua) . "</p>
        <p>" . tr('{0} ti ha invitato a far parte della casa {1} con il ruolo di {2}.', [
                "<strong>{$nomeInvitante}</strong>",
                "<strong style='color:#4f46e5;'>{$nomeCasa}</strong>",
                "<strong>{$ruoloLabel}</strong>",
            ], $lingua) . "</p>
        <p style='margin:24px 0;'>" . tr('Questo è un promemoria — il tuo invito è ancora valido.', [], $lingua) . "</p>
        <p style='margin:24px 0;'>{$azioneLabel}</p>
        <p style='text-align:center;margin:32px 0;'>
            <a href='{$link}'
               style='background:#4f46e5;color:#fff;padding:14px 28px;border-radius:8px;
                      text-decoration:none;font-weight:700;font-size:15px;display:inline-block;'>
                {$btnLabel}
            </a>
        </p>
        <p style='color:#6b7280;font-size:13px;'>
            " . tr('Scade il: {0}', ['<strong>' . formattaDataUtente($invito['scade_il'], $formatoData, true) . '</strong>'], $lingua) . "
        </p>
        <p style='color:#6b7280;font-size:13px;'>
            <a href='{$link}' style='color:#4f46e5;word-break:break-all;'>{$link}</a>
        </p>
    ";

    $oggettoEmail = tr('Promemoria: sei stato invitato in "{0}"', [$invito['casa_nome']], $lingua) . ' - ' . APP_NOME_DEFAULT;
    $html         = emailTemplate(tr('Promemoria invito in "{0}"', [$nomeCasa], $lingua), $corpo, $lingua);
    $esito        = inviaEmail($invito['email_invitato'], '', $oggettoEmail, $html);

    // Aggiorna timestamp ultimo invio
    $db->prepare("UPDATE inviti SET ultimo_invio_il = NOW(), email_inviata = 1 WHERE id = ?")
       ->execute([$id]);

    risposta([
        'messaggio'     => $esito ? 'Email reinviata.' : 'Reinvio fallito. Usa il link manuale.',
        'email_inviata' => $esito,
    ]);
}

function revocaInvito(int $id): void {
    [, $casaId] = richiedeAdminDiCasa();

    $db   = getDB();
    $stmt = $db->prepare("SELECT id FROM inviti WHERE id = ? AND casa_id = ?");
    $stmt->execute([$id, $casaId]);
    if (!$stmt->fetch()) rispostaErrore(404, 'Invito non trovato.');

    $db->prepare("DELETE FROM inviti WHERE id = ? AND casa_id = ?")->execute([$id, $casaId]);
    risposta(['messaggio' => 'Invito revocato.']);
}

// ============================================================
// STATS CASA
// ============================================================

function statsCasa(): void {
    [, $casaId] = richiedeAdminDiCasa();
    $db   = getDB();
    $anno = (int)($_GET['anno'] ?? date('Y'));

    $totali = $db->prepare("
        SELECT
            COUNT(DISTINCT s.id)                                                           AS totale_spese,
            COUNT(DISTINCT s.id_utente)                                                    AS utenti_attivi,
            COALESCE(SUM(CASE WHEN c.tipo='uscita'    THEN s.importo       ELSE 0 END), 0) AS totale_uscite,
            COALESCE(SUM(CASE WHEN c.tipo='accredito' THEN ABS(s.importo)  ELSE 0 END), 0) AS totale_entrate,
            SUM(CASE WHEN s.privata = 1 THEN 1 ELSE 0 END)                                 AS spese_private
        FROM spese s
        JOIN categorie c ON c.id = s.id_categoria
        WHERE s.casa_id = ? AND " . sqlAnno('s.data_spesa') . " = ?
    ");
    $totali->execute([$casaId, $anno]);

    $perMembro = $db->prepare("
        SELECT u.id, u.nome,
               COUNT(*)                                                               AS spese,
               COALESCE(SUM(CASE WHEN c.tipo='uscita'    THEN s.importo    ELSE 0 END), 0) AS uscite,
               COALESCE(SUM(CASE WHEN c.tipo='accredito' THEN ABS(s.importo) ELSE 0 END), 0) AS entrate
        FROM spese s
        JOIN utenti u    ON u.id = s.id_utente
        JOIN categorie c ON c.id = s.id_categoria
        WHERE s.casa_id = ? AND " . sqlAnno('s.data_spesa') . " = ?
        GROUP BY u.id, u.nome
        ORDER BY uscite DESC
    ");
    $perMembro->execute([$casaId, $anno]);

    risposta([
        'anno'       => $anno,
        'totali'     => $totali->fetch(),
        'per_membro' => $perMembro->fetchAll(),
    ]);
}