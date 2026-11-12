<?php
ob_start();
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(0);
// ============================================================
// AUTH.PHP - Login / Logout / Registrazione / Sessione
//            + Reset password (v4)
//            + Accetta / Rifiuta invito (v4)
// ============================================================
require_once __DIR__ . '/config.php';
inizializzaAPI();

$metodo = $_SERVER['REQUEST_METHOD'];
$azione = $_GET['azione'] ?? '';

match(true) {
    $metodo === 'POST' && $azione === 'login'                  => login(),
    $metodo === 'POST' && $azione === 'logout'                 => logout(),
    $metodo === 'POST' && $azione === 'registrazione'          => registrazione(),
    $metodo === 'POST' && $azione === 'cambia_password'        => cambiaPassword(),
    $metodo === 'POST' && $azione === 'cambia_casa'            => cambiaCasa(),
    $metodo === 'POST' && $azione === 'richiedi_reset'         => richiediResetPassword(),   // NUOVO v4
    $metodo === 'POST' && $azione === 'reset_password'         => resetPassword(),           // NUOVO v4
    $metodo === 'POST' && $azione === 'accetta_invito'         => accettaInvito(),           // NUOVO v4
    $metodo === 'POST' && $azione === 'rifiuta_invito'         => rifiutaInvito(),           // NUOVO v4
    $metodo === 'PUT'  && $azione === 'aggiorna_profilo'       => aggiornaProfilo(),
    $metodo === 'PUT'  && $azione === 'cambia_email'           => cambiaEmail(),
    $metodo === 'PUT'  && $azione === 'preferenze'             => aggiornaPreferenze(),
    $metodo === 'POST' && $azione === 'elimina_account'        => eliminaAccount(),
    $metodo === 'GET'  && $azione === 'profilo'                => profilo(),
    $metodo === 'GET'  && $azione === 'csrf'                   => csrf(),
    $metodo === 'GET'  && $azione === 'verifica_invito'        => verificaInvito(),
    $metodo === 'GET'  && $azione === 'verifica_token_reset'   => verificaTokenReset(),      // NUOVO v4
    $metodo === 'GET'  && $azione === 'inviti_pendenti'        => invitiPendenti(),          // NUOVO v4
    default => rispostaErrore(404, 'Azione non trovata.')
};

// ============================================================
// LOGIN
// ============================================================
function login(): void {
    $dati  = inputJSON();
    $email = sanitizzaStringa($dati['email'] ?? '');
    $pass  = $dati['password'] ?? '';

    if (empty($email) || empty($pass)) {
        rispostaErrore(400, 'Email e password obbligatorie.');
    }

    $db   = getDB();
    $stmt = $db->prepare("
        SELECT id, nome, email, password_hash, attivo, deve_cambiare_password
        FROM utenti WHERE email = ?
    ");
    $stmt->execute([mb_strtolower($email)]);
    $utente = $stmt->fetch();

    if (!$utente || !password_verify($pass, $utente['password_hash'])) {
        rispostaErrore(401, 'Credenziali non valide.');
    }

    if (!$utente['attivo']) {
        rispostaErrore(403, 'Account disabilitato.');
    }

    // Recupera l'ultima casa attiva da sessioni precedenti
    $stmtUltima = $db->prepare("
        SELECT casa_id_attiva FROM sessioni
        WHERE id_utente = ? AND casa_id_attiva IS NOT NULL
        ORDER BY creata_il DESC LIMIT 1
    ");
    $stmtUltima->execute([$utente['id']]);
    $casaId = $stmtUltima->fetchColumn();

    // Fallback: casa default dell'utente
    if (!$casaId) {
        $stmtDefault = $db->prepare("
            SELECT casa_id FROM utenti_case
            WHERE utente_id = ? AND casa_default = 1 LIMIT 1
        ");
        $stmtDefault->execute([$utente['id']]);
        $casaId = $stmtDefault->fetchColumn();
    }

    // Elimina sessioni vecchie (max 3 dispositivi attivi)
    $db->prepare("
        DELETE FROM sessioni WHERE id_utente = ? AND id NOT IN (
            SELECT id FROM (
                SELECT id FROM sessioni WHERE id_utente = ? ORDER BY creata_il DESC LIMIT 2
            ) t
        )
    ")->execute([$utente['id'], $utente['id']]);

    // Crea nuova sessione
    $tokenId = bin2hex(random_bytes(32));
    $scadeIl = date('Y-m-d H:i:s', time() + SESSION_DURATA);
    $ip      = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? null;
    $ua      = mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

    $db->prepare("
        INSERT INTO sessioni (id, id_utente, casa_id_attiva, ip, user_agent, scade_il)
        VALUES (?, ?, ?, ?, ?, ?)
    ")->execute([$tokenId, $utente['id'], $casaId ?: null, $ip, $ua, $scadeIl]);

    $db->prepare("UPDATE utenti SET ultimo_accesso = NOW() WHERE id = ?")
       ->execute([$utente['id']]);

    if (password_needs_rehash($utente['password_hash'], PASSWORD_BCRYPT)) {
        $db->prepare("UPDATE utenti SET password_hash = ? WHERE id = ?")
           ->execute([password_hash($pass, PASSWORD_BCRYPT), $utente['id']]);
    }

    avviaSessione();
    $_SESSION['utente_id'] = $utente['id'];
    $_SESSION['token']     = $tokenId;
    session_regenerate_id(true);

    $case = caricaCaseUtente($utente['id'], $db);

    $casaAttivaObj = null;
    if ($casaId) {
        foreach ($case as $c) {
            if ((int)$c['id'] === (int)$casaId) {
                $casaAttivaObj = $c;
                break;
            }
        }
        if (!$casaAttivaObj) $casaAttivaObj = ['id' => (int)$casaId];
    }

    // Controlla inviti pendenti da mostrare al frontend dopo il login
    $invitiPendenti = caricaInvitiPendenti($utente['id'], $utente['email'], $db);

    risposta([
        'utente' => [
            'id'                     => $utente['id'],
            'nome'                   => $utente['nome'],
            'email'                  => $utente['email'],
            'deve_cambiare_password' => (bool)$utente['deve_cambiare_password'],
        ] + preferenzeUtente($db, (int)$utente['id']),
        'casa_attiva'      => $casaAttivaObj,
        'case'             => $case,
        'csrf_token'       => generaCSRF(),
        'inviti_pendenti'  => $invitiPendenti,   // il frontend mostrerà popup se non vuoto
    ]);
}

// ============================================================
// LOGOUT
// ============================================================
function logout(): void {
    distruggiSessione();
    risposta(['messaggio' => 'Logout effettuato.']);
}

// ============================================================
// REGISTRAZIONE PUBBLICA
// ============================================================
function registrazione(): void {
    avviaSessione();
    $dati = inputJSON();
    verificaCSRF($dati['csrf_token'] ?? null);

    $nome        = sanitizzaStringa($dati['nome']             ?? '', 100);
    $email       = strtolower(sanitizzaStringa($dati['email'] ?? '', 150));
    $pass        = $dati['password']          ?? '';
    $pass2       = $dati['password_conferma'] ?? '';
    $tokenInvito = sanitizzaStringa($dati['token_invito']     ?? '', 128);
    // Lingua, valuta e formati scelti nel form (predefiniti dalla lingua se mancanti)
    $pref        = validaPreferenze($dati);

    if (empty($nome))                                         rispostaErrore(400, 'Nome obbligatorio.');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL))           rispostaErrore(400, 'Email non valida.');
    if (strlen($pass) < 8)                                    rispostaErrore(400, 'Password: minimo 8 caratteri.');
    if (!preg_match('/[A-Z]/', $pass))                        rispostaErrore(400, 'Password: almeno una lettera maiuscola.');
    if (!preg_match('/[0-9]/', $pass))                        rispostaErrore(400, 'Password: almeno un numero.');
    if (!preg_match('/[^a-zA-Z0-9]/', $pass))                 rispostaErrore(400, 'Password: almeno un carattere speciale.');
    if ($pass !== $pass2)                                     rispostaErrore(400, 'Le password non coincidono.');

    $db = getDB();

    $chk = $db->prepare("SELECT COUNT(*) FROM utenti WHERE email = ?");
    $chk->execute([$email]);
    if ((int)$chk->fetchColumn() > 0) rispostaErrore(409, 'Email già registrata.');

    $invito = null;
    if (!empty($tokenInvito)) {
        $invito = verificaTokenInvito($tokenInvito, $db);
        if ($invito['email_invitato'] !== null && $invito['email_invitato'] !== $email) {
            rispostaErrore(403, 'Questo invito è destinato a un\'altra email.');
        }
    }

    $db->beginTransaction();
    try {
        $hash = password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]);

        if (multilinguaAttivo($db)) {
            $db->prepare("
                INSERT INTO utenti (nome, email, password_hash, attivo, lingua, valuta, formato_numero, formato_data)
                VALUES (?, ?, ?, 1, ?, ?, ?, ?)
            ")->execute([$nome, $email, $hash, $pref['lingua'], $pref['valuta'], $pref['formato_numero'], $pref['formato_data']]);
        } else {
            $db->prepare("INSERT INTO utenti (nome, email, password_hash, attivo) VALUES (?, ?, ?, 1)")
               ->execute([$nome, $email, $hash]);
        }
        $utenteId = ultimoId('utenti');

        $nomeCasa = mb_substr(tr('Casa di {0}', [$nome], $pref['lingua']), 0, 100);
        $db->prepare("INSERT INTO \"case\" (nome, colore, casa_personale, created_by) VALUES (?, '#4f46e5', 1, ?)")
           ->execute([$nomeCasa, $utenteId]);
        $casaId = ultimoId('case');

        $db->prepare("
            INSERT INTO utenti_case (utente_id, casa_id, ruolo, casa_default, invito_accettato)
            VALUES (?, ?, 'admin', 1, 1)
        ")->execute([$utenteId, $casaId]);

        // Categorie predefinite nella lingua scelta (fallback inglese)
        copiaCategorieTemplate($db, $casaId, $pref['lingua']);

        // Se la registrazione avviene tramite link invito:
        // NON accettiamo automaticamente — l'utente vedrà la popup dopo il login
        if ($invito) {
            // Segna solo che l'email è stata verificata; l'accettazione avviene via popup
            // (la riga utenti_case viene creata quando accetta)
        }

        $db->commit();
    } catch (\Exception $e) {
        $db->rollBack();
        registraErrore('Registrazione fallita: ' . $e->getMessage());
        rispostaErrore(500, 'Errore durante la registrazione. Riprova.');
    }

    risposta([
        'messaggio'    => 'Registrazione completata. Effettua il login per continuare.',
        'redirect'     => 'index.html',
        'token_invito' => $tokenInvito ?: null,   // il frontend lo passa al login
    ]);
}

// ============================================================
// CAMBIA CASA ATTIVA
// ============================================================
function cambiaCasa(): void {
    $utente = utenteAutenticato();
    $dati   = inputJSON();
    $casaId = (int)($dati['casa_id'] ?? 0);

    if (!$casaId) rispostaErrore(400, 'casa_id mancante.');

    $db   = getDB();
    $stmt = $db->prepare("
        SELECT ruolo FROM utenti_case
        WHERE utente_id = ? AND casa_id = ? AND invito_accettato = 1
    ");
    $stmt->execute([$utente['id'], $casaId]);
    $uc = $stmt->fetch();

    if (!$uc) rispostaErrore(403, 'Accesso a questa casa non consentito.');

    avviaSessione();
    $tokenId = $_SESSION['token'] ?? '';
    $db->prepare("UPDATE sessioni SET casa_id_attiva = ? WHERE id = ?")
       ->execute([$casaId, $tokenId]);

    risposta([
        'messaggio'   => 'Casa attiva aggiornata.',
        'casa_attiva' => $casaId,
        'ruolo'       => $uc['ruolo'],
    ]);
}

// ============================================================
// PROFILO UTENTE AUTENTICATO
// ============================================================
function profilo(): void {
    $utente = utenteAutenticato();
    $db     = getDB();
    $case   = caricaCaseUtente($utente['id'], $db);

    avviaSessione();
    $tokenId  = $_SESSION['token'] ?? '';
    $stmtCasa = $db->prepare("SELECT casa_id_attiva FROM sessioni WHERE id = ?");
    $stmtCasa->execute([$tokenId]);
    $casaAttiva = $stmtCasa->fetchColumn();

    $casaAttivaObj = null;
    if ($casaAttiva) {
        foreach ($case as $c) {
            if ((int)$c['id'] === (int)$casaAttiva) {
                $casaAttivaObj = $c;
                break;
            }
        }
        if (!$casaAttivaObj) $casaAttivaObj = ['id' => (int)$casaAttiva];
    }

    risposta([
        'utente'      => $utente + preferenzeUtente($db, (int)$utente['id']),
        'casa_attiva' => $casaAttivaObj,
        'case'        => $case,
    ]);
}

// ============================================================
// CSRF TOKEN
// ============================================================
function csrf(): void {
    avviaSessione();
    risposta(['csrf_token' => generaCSRF()]);
}

// ============================================================
// VERIFICA TOKEN INVITO (endpoint pubblico GET)
// Usato dal frontend per mostrare i dettagli prima di login/registrazione
// ============================================================
function verificaInvito(): void {
    avviaSessione();
    $token = sanitizzaStringa($_GET['token'] ?? '', 128);
    if (empty($token)) rispostaErrore(400, 'Token mancante.');

    $db     = getDB();
    $invito = verificaTokenInvito($token, $db);

    $stmtCasa = $db->prepare("SELECT nome, colore FROM \"case\" WHERE id = ?");
    $stmtCasa->execute([$invito['casa_id']]);
    $casa = $stmtCasa->fetch();

    // Recupera il nome di chi ha invitato
    $stmtInvitante = $db->prepare("SELECT nome FROM utenti WHERE id = ?");
    $stmtInvitante->execute([$invito['creato_da']]);
    $invitante = $stmtInvitante->fetch();

    // L'email è già registrata?
    $emailRegistrata = false;
    if (!empty($invito['email_invitato'])) {
        $stmtCheck = $db->prepare("SELECT COUNT(*) FROM utenti WHERE email = ?");
        $stmtCheck->execute([$invito['email_invitato']]);
        $emailRegistrata = (int)$stmtCheck->fetchColumn() > 0;
    }

    risposta([
        'valido'           => true,
        'casa_nome'        => $casa['nome'],
        'casa_colore'      => $casa['colore'],
        'ruolo'            => $invito['ruolo'],
        'email_invitato'   => $invito['email_invitato'],
        'invitante_nome'   => $invitante['nome'] ?? 'Amministratore',
        'email_registrata' => $emailRegistrata,   // true → mostra login, false → registrazione
        'csrf_token'       => generaCSRF(),
    ]);
}

// ============================================================
// INVITI PENDENTI (endpoint autenticato GET)
// Restituisce gli inviti in attesa per l'utente corrente
// ============================================================
function invitiPendenti(): void {
    $utente = utenteAutenticato();
    $db     = getDB();

    $pendenti = caricaInvitiPendenti($utente['id'], $utente['email'], $db);
    risposta(['inviti' => $pendenti]);
}

// ============================================================
// ACCETTA INVITO (endpoint autenticato POST)
// ============================================================
function accettaInvito(): void {
    $utente = utenteAutenticato();
    $dati   = inputJSON();
    verificaCSRF($dati['csrf_token'] ?? null);

    $token = sanitizzaStringa($dati['token'] ?? '', 128);
    if (empty($token)) rispostaErrore(400, 'Token mancante.');

    $db     = getDB();
    $invito = verificaTokenInvitoPerUtente($token, $utente['email'], $db);

    $db->beginTransaction();
    try {
        // Aggiungi l'utente alla casa (o aggiorna se già presente con invito non accettato)
        $db->prepare("
            INSERT INTO utenti_case (utente_id, casa_id, ruolo, casa_default, invito_accettato, invitato_da)
            VALUES (?, ?, ?, 0, 1, ?)
            " . sqlSuConflitto(['utente_id', 'casa_id'], 'ruolo = EXCLUDED.ruolo, invito_accettato = 1, invitato_da = EXCLUDED.invitato_da') . "
        ")->execute([$utente['id'], $invito['casa_id'], $invito['ruolo'], $invito['creato_da']]);

        // Segna invito come usato + accettato
        $db->prepare("
            UPDATE inviti
            SET usato       = 1,
                usato_da    = ?,
                usato_il    = NOW(),
                stato       = 'accettato',
                risposta_il = NOW()
            WHERE token = ?
        ")->execute([$utente['id'], $token]);

        $db->commit();
    } catch (\Exception $e) {
        $db->rollBack();
        logErrore('accettaInvito: ' . $e->getMessage());
        rispostaErrore(500, 'Errore durante l\'accettazione dell\'invito.');
    }

    // Ricarica case aggiornate per il frontend
    $case = caricaCaseUtente($utente['id'], $db);

    risposta([
        'messaggio' => 'Hai accettato l\'invito. La casa è ora disponibile nel tuo account.',
        'case'      => $case,
    ]);
}

// ============================================================
// RIFIUTA INVITO (endpoint autenticato POST)
// ============================================================
function rifiutaInvito(): void {
    $utente = utenteAutenticato();
    $dati   = inputJSON();
    verificaCSRF($dati['csrf_token'] ?? null);

    $token = sanitizzaStringa($dati['token'] ?? '', 128);
    if (empty($token)) rispostaErrore(400, 'Token mancante.');

    $db     = getDB();
    $invito = verificaTokenInvitoPerUtente($token, $utente['email'], $db);

    // Segna come rifiutato (non eliminare: serve per mostrare lo stato a chi ha invitato)
    $db->prepare("
        UPDATE inviti
        SET stato       = 'rifiutato',
            risposta_il = NOW(),
            usato       = 1,
            usato_da    = ?,
            usato_il    = NOW()
        WHERE token = ?
    ")->execute([$utente['id'], $token]);

    risposta(['messaggio' => 'Invito rifiutato.']);
}

// ============================================================
// PREFERENZE UTENTE (lingua, valuta, formati)
// ============================================================
function aggiornaPreferenze(): void {
    $utente = utenteAutenticato();
    $dati   = inputJSON();
    verificaCSRF($dati['csrf_token'] ?? null);

    $db = getDB();
    if (!multilinguaAttivo($db)) {
        rispostaErrore(503, 'Funzione non ancora disponibile: aggiornamento del database mancante.');
    }

    $pref = validaPreferenze($dati);
    $db->prepare("
        UPDATE utenti SET lingua = ?, valuta = ?, formato_numero = ?, formato_data = ?
        WHERE id = ?
    ")->execute([$pref['lingua'], $pref['valuta'], $pref['formato_numero'], $pref['formato_data'], $utente['id']]);

    // Il messaggio va nella NUOVA lingua
    risposta(['messaggio' => tr('Preferenze salvate.', [], $pref['lingua'])] + $pref);
}

// ============================================================
// RICHIEDI RESET PASSWORD (endpoint pubblico POST)
// Genera token, lo salva su DB, invia email con link
// ============================================================
function richiediResetPassword(): void {
    avviaSessione();
    $dati  = inputJSON();
    verificaCSRF($dati['csrf_token'] ?? null);

    $email = strtolower(sanitizzaStringa($dati['email'] ?? '', 150));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        rispostaErrore(400, 'Email non valida.');
    }

    $db   = getDB();
    $stmt = $db->prepare("SELECT id, nome, attivo FROM utenti WHERE email = ?");
    $stmt->execute([$email]);
    $utente = $stmt->fetch();
    $lingua = $utente ? preferenzeUtente($db, (int)$utente['id'])['lingua'] : linguaCorrente();

    // Per sicurezza rispondiamo sempre con successo, anche se l'email non esiste
    // (evita user enumeration attack)
    if (!$utente || !$utente['attivo']) {
        risposta(['messaggio' => 'Se l\'email è registrata, riceverai le istruzioni a breve.']);
        return;
    }

    // Controlla se esiste già un token recente (blocco anti-spam: max 1 ogni 5 minuti)
    $stmtCheck = $db->prepare("
        SELECT token_reset_scade FROM utenti
        WHERE id = ? AND token_reset IS NOT NULL AND token_reset_scade > NOW() + INTERVAL '55' MINUTE
    ");
    $stmtCheck->execute([$utente['id']]);
    if ($stmtCheck->fetchColumn()) {
        risposta(['messaggio' => 'Se l\'email è registrata, riceverai le istruzioni a breve.']);
        return;
    }

    // Genera token sicuro (64 char hex = 256 bit)
    $tokenReset = bin2hex(random_bytes(32));
    $scadeIl    = date('Y-m-d H:i:s', time() + 3600); // 1 ora

    $db->prepare("
        UPDATE utenti
        SET token_reset = ?, token_reset_scade = ?
        WHERE id = ?
    ")->execute([$tokenReset, $scadeIl, $utente['id']]);

    // Costruisce il link di reset
    $link = APP_URL . '/index.html?azione=reset_password&token=' . urlencode($tokenReset);

    // Prepara email
    $nomeHtml = htmlspecialchars($utente['nome'], ENT_QUOTES, 'UTF-8');
    $corpo = "
        <p>" . tr('Ciao {0},', ["<strong>{$nomeHtml}</strong>"], $lingua) . "</p>
        <p>" . tr('Abbiamo ricevuto una richiesta di reset della password per il tuo account.', [], $lingua) . "</p>
        <p>" . tr('Clicca il pulsante qui sotto per impostare una nuova password:', [], $lingua) . "</p>
        <p style='text-align:center;margin:32px 0;'>
            <a href='{$link}'
               style='background:#4f46e5;color:#fff;padding:14px 28px;border-radius:8px;
                      text-decoration:none;font-weight:700;font-size:15px;display:inline-block;'>
                " . tr('Reimposta password', [], $lingua) . "
            </a>
        </p>
        <p style='color:#6b7280;font-size:13px;'>
            " . tr('Il link è valido per {0}.', ['<strong>' . tr('1 ora', [], $lingua) . '</strong>'], $lingua) . "<br>
            " . tr('Se non hai richiesto il reset, ignora questa email — il tuo account è al sicuro.', [], $lingua) . "
        </p>
        <p style='color:#6b7280;font-size:13px;'>
            " . tr('Oppure copia questo link nel browser:', [], $lingua) . "<br>
            <a href='{$link}' style='color:#4f46e5;word-break:break-all;'>{$link}</a>
        </p>
    ";

    $titolo = tr('Reimposta la tua password', [], $lingua);
    $html   = emailTemplate($titolo, $corpo, $lingua);
    inviaEmail($email, $utente['nome'], $titolo . ' – ' . APP_NOME_DEFAULT, $html);

    risposta(['messaggio' => 'Se l\'email è registrata, riceverai le istruzioni a breve.']);
}

// ============================================================
// VERIFICA TOKEN RESET (endpoint pubblico GET)
// Usato dal frontend per validare il token prima di mostrare il form
// ============================================================
function verificaTokenReset(): void {
    $token = sanitizzaStringa($_GET['token'] ?? '', 128);
    if (empty($token)) rispostaErrore(400, 'Token mancante.');

    $db   = getDB();
    $stmt = $db->prepare("
        SELECT id FROM utenti
        WHERE token_reset = ? AND token_reset_scade > NOW()
    ");
    $stmt->execute([$token]);

    if (!$stmt->fetch()) {
        rispostaErrore(404, 'Link non valido o scaduto. Richiedi un nuovo reset.');
    }

    avviaSessione();
    risposta(['valido' => true, 'csrf_token' => generaCSRF()]);
}

// ============================================================
// RESET PASSWORD (endpoint pubblico POST)
// Completa il reset: verifica token, aggiorna password
// ============================================================
function resetPassword(): void {
    avviaSessione();
    $dati  = inputJSON();
    verificaCSRF($dati['csrf_token'] ?? null);

    $token  = sanitizzaStringa($dati['token']             ?? '', 128);
    $nuova  = $dati['password_nuova']   ?? '';
    $nuova2 = $dati['password_conferma'] ?? '';

    if (empty($token))                                         rispostaErrore(400, 'Token mancante.');
    if (strlen($nuova) < 8)                                    rispostaErrore(400, 'Password: minimo 8 caratteri.');
    if (!preg_match('/[A-Z]/', $nuova))                        rispostaErrore(400, 'Password: almeno una lettera maiuscola.');
    if (!preg_match('/[0-9]/', $nuova))                        rispostaErrore(400, 'Password: almeno un numero.');
    if (!preg_match('/[^a-zA-Z0-9]/', $nuova))                 rispostaErrore(400, 'Password: almeno un carattere speciale.');
    if ($nuova !== $nuova2)                                    rispostaErrore(400, 'Le password non coincidono.');

    $db   = getDB();
    $stmt = $db->prepare("
        SELECT id, nome, email FROM utenti
        WHERE token_reset = ? AND token_reset_scade > NOW()
    ");
    $stmt->execute([$token]);
    $utente = $stmt->fetch();

    if (!$utente) {
        rispostaErrore(404, 'Link non valido o scaduto. Richiedi un nuovo reset.');
    }

    $hash = password_hash($nuova, PASSWORD_BCRYPT, ['cost' => 12]);

    // Aggiorna password, azzera token reset, rimuovi flag cambio obbligatorio
    $db->prepare("
        UPDATE utenti
        SET password_hash            = ?,
            token_reset              = NULL,
            token_reset_scade        = NULL,
            deve_cambiare_password   = 0
        WHERE id = ?
    ")->execute([$hash, $utente['id']]);

    // Invalida tutte le sessioni attive (sicurezza)
    $db->prepare("DELETE FROM sessioni WHERE id_utente = ?")
       ->execute([$utente['id']]);

    risposta(['messaggio' => 'Password aggiornata con successo. Ora puoi effettuare il login.']);
}

// ============================================================
// CAMBIA PASSWORD (utente autenticato)
// Invalida tutte le altre sessioni attive per sicurezza.
// ============================================================
function cambiaPassword(): void {
    $utente = utenteAutenticato();
    $dati   = inputJSON();
    verificaCSRF($dati['csrf_token'] ?? null);

    $vecchia = $dati['password_vecchia'] ?? '';
    $nuova   = $dati['password_nuova']   ?? '';
    $nuova2  = $dati['password_conferma'] ?? '';

    if (empty($vecchia) || empty($nuova))         rispostaErrore(400, 'Dati mancanti.');
    if (strlen($nuova) < 8)                        rispostaErrore(400, 'Password: minimo 8 caratteri.');
    if (!preg_match('/[A-Z]/', $nuova))            rispostaErrore(400, 'Password: almeno una lettera maiuscola.');
    if (!preg_match('/[0-9]/', $nuova))            rispostaErrore(400, 'Password: almeno un numero.');
    if (!preg_match('/[^a-zA-Z0-9]/', $nuova))     rispostaErrore(400, 'Password: almeno un carattere speciale.');
    if ($nuova !== $nuova2)                        rispostaErrore(400, 'Le password non coincidono.');

    $db   = getDB();
    $stmt = $db->prepare("SELECT password_hash FROM utenti WHERE id = ?");
    $stmt->execute([$utente['id']]);
    $row  = $stmt->fetch();

    if (!password_verify($vecchia, $row['password_hash'])) {
        rispostaErrore(401, 'Password attuale non corretta.');
    }

    $hash = password_hash($nuova, PASSWORD_BCRYPT, ['cost' => 12]);
    $db->prepare("
        UPDATE utenti
        SET password_hash          = ?,
            deve_cambiare_password = 0
        WHERE id = ?
    ")->execute([$hash, $utente['id']]);

    avviaSessione();
    $tokenAttuale = $_SESSION['token'] ?? '';
    $db->prepare("DELETE FROM sessioni WHERE id_utente = ? AND id != ?")
       ->execute([$utente['id'], $tokenAttuale]);

    risposta(['messaggio' => 'Password aggiornata con successo.']);
}

// ============================================================
// AGGIORNA NOME PROFILO
// ============================================================
function aggiornaProfilo(): void {
    $utente = utenteAutenticato();
    $dati   = inputJSON();
    verificaCSRF($dati['csrf_token'] ?? null);

    $nome = sanitizzaStringa($dati['nome'] ?? '', 100);
    if (empty($nome)) rispostaErrore(400, 'Nome obbligatorio.');

    getDB()->prepare("UPDATE utenti SET nome = ? WHERE id = ?")
           ->execute([$nome, $utente['id']]);

    risposta(['messaggio' => 'Nome aggiornato.', 'nome' => $nome]);
}

// ============================================================
// CAMBIA EMAIL (con verifica password corrente)
// ============================================================
function cambiaEmail(): void {
    $utente = utenteAutenticato();
    $dati   = inputJSON();
    verificaCSRF($dati['csrf_token'] ?? null);

    $nuovaEmail = strtolower(sanitizzaStringa($dati['email'] ?? '', 150));
    $password   = $dati['password'] ?? '';

    if (empty($nuovaEmail))                                     rispostaErrore(400, 'Email obbligatoria.');
    if (!filter_var($nuovaEmail, FILTER_VALIDATE_EMAIL))        rispostaErrore(400, 'Email non valida.');
    if (empty($password))                                       rispostaErrore(400, 'Password obbligatoria per confermare il cambio email.');

    $db   = getDB();
    $stmt = $db->prepare("SELECT password_hash FROM utenti WHERE id = ?");
    $stmt->execute([$utente['id']]);
    $row  = $stmt->fetch();
    if (!password_verify($password, $row['password_hash'])) {
        rispostaErrore(401, 'Password non corretta.');
    }

    $stmtCheck = $db->prepare("SELECT id FROM utenti WHERE email = ? AND id != ?");
    $stmtCheck->execute([$nuovaEmail, $utente['id']]);
    if ($stmtCheck->fetch()) rispostaErrore(409, 'Email già in uso da un altro account.');

    $db->prepare("UPDATE utenti SET email = ? WHERE id = ?")
       ->execute([$nuovaEmail, $utente['id']]);

    risposta(['messaggio' => 'Email aggiornata.', 'email' => $nuovaEmail]);
}

// ============================================================
// ELIMINA ACCOUNT (definitivo, solo il proprio)
// Richiede password attuale + conferma testuale "ELIMINA".
// - Case dove l'utente è l'unico membro: eliminate con tutti i dati
// - Case condivise: l'utente esce; se era l'unico admin blocca (409)
// - Spese rimaste nelle case condivise: conservate, attribuite a
//   un utente anonimo "Utente eliminato" (dati personali cancellati)
// ============================================================
function eliminaAccount(): void {
    $utente = utenteAutenticato();
    $dati   = inputJSON();
    verificaCSRF($dati['csrf_token'] ?? null);

    $password = (string)($dati['password'] ?? '');
    $conferma = trim((string)($dati['conferma'] ?? ''));

    if ($password === '')     rispostaErrore(400, 'Password obbligatoria per eliminare l\'account.');
    if ($conferma !== 'ELIMINA') rispostaErrore(400, 'Scrivi ELIMINA per confermare.');

    $db  = getDB();
    $uid = (int)$utente['id'];

    $stmt = $db->prepare("SELECT nome, email, password_hash FROM utenti WHERE id = ?");
    $stmt->execute([$uid]);
    $row = $stmt->fetch();
    if (!$row || !password_verify($password, $row['password_hash'])) {
        // 403 e non 401: il frontend con 401 rimanda al login
        rispostaErrore(403, 'Password non corretta.');
    }

    $lingua    = preferenzeUtente($db, $uid)['lingua'];   // prima che l'account venga eliminato
    $bloccanti = caseConUnicoAdmin($uid, $db);
    if ($bloccanti) {
        rispostaErrore(409, 'Sei l\'unico admin di: ' . implode(', ', $bloccanti)
            . '. Nomina un altro admin o rimuovi gli altri membri prima di eliminare l\'account.');
    }

    $db->beginTransaction();
    try {
        $esito = eseguiEliminazioneAccount($uid, $row['email'], $db);
        $db->commit();
    } catch (\Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        logErrore('eliminaAccount: ' . $e->getMessage());
        rispostaErrore(500, 'Errore durante l\'eliminazione dell\'account.');
    }

    distruggiSessione();

    // Email di conferma (best effort: l'account è già eliminato)
    $nome  = htmlspecialchars($row['nome'], ENT_QUOTES, 'UTF-8');
    $corpo = "
        <p>" . tr('Ciao {0},', ["<strong>{$nome}</strong>"], $lingua) . "</p>
        <p>" . tr('il tuo account è stato eliminato definitivamente.', [], $lingua) . "</p>
        <p>" . tr('Le case di cui eri l\'unico membro sono state eliminate con tutti i loro dati. Le spese che avevi inserito in case condivise restano visibili agli altri membri come "Utente eliminato", senza il tuo nome né la tua email.', [], $lingua) . "</p>
        <p style='color:#6b7280;font-size:13px;'>" . tr('Se non sei stato tu, contatta subito gli altri membri della tua casa.', [], $lingua) . "</p>
    ";
    try {
        $titolo = tr('Account eliminato', [], $lingua);
        inviaEmail($row['email'], $row['nome'], $titolo . ' – ' . APP_NOME_DEFAULT,
                   emailTemplate($titolo, $corpo, $lingua));
    } catch (\Throwable $e) {
        logErrore('eliminaAccount: email di conferma non inviata: ' . $e->getMessage());
    }

    risposta(['messaggio' => 'Account eliminato definitivamente.'] + $esito);
}

/**
 * Nomi delle case in cui l'utente è l'unico admin ma ci sono altri membri.
 */
function caseConUnicoAdmin(int $uid, \PDO $db): array {
    $stmt = $db->prepare("
        SELECT c.nome
        FROM utenti_case uc
        JOIN \"case\" c ON c.id = uc.casa_id
        WHERE uc.utente_id = ? AND uc.ruolo = 'admin'
          AND EXISTS (SELECT 1 FROM utenti_case x
                      WHERE x.casa_id = uc.casa_id AND x.utente_id <> uc.utente_id AND x.invito_accettato = 1)
          AND NOT EXISTS (SELECT 1 FROM utenti_case x
                          WHERE x.casa_id = uc.casa_id AND x.utente_id <> uc.utente_id
                            AND x.invito_accettato = 1 AND x.ruolo = 'admin')
        ORDER BY c.nome
    ");
    $stmt->execute([$uid]);
    return $stmt->fetchAll(\PDO::FETCH_COLUMN);
}

/**
 * Esegue le modifiche al DB. Va chiamata dentro una transazione aperta.
 * Ritorna un riepilogo: case eliminate e se l'utente è stato anonimizzato.
 */
function eseguiEliminazioneAccount(int $uid, string $email, \PDO $db): array {
    // 1. Case da eliminare: quelle dove nessun altro membro ha accettato,
    //    più le case create dall'utente rimaste senza membri
    $stmt = $db->prepare("
        SELECT c.id FROM \"case\" c
        WHERE (
                EXISTS (SELECT 1 FROM utenti_case uc WHERE uc.casa_id = c.id AND uc.utente_id = ?)
             OR c.created_by = ?
              )
          AND NOT EXISTS (SELECT 1 FROM utenti_case x
                          WHERE x.casa_id = c.id AND x.utente_id <> ? AND x.invito_accettato = 1)
    ");
    $stmt->execute([$uid, $uid, $uid]);
    $caseDaEliminare = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));

    $delSpese = $db->prepare("DELETE FROM spese WHERE casa_id = ?");
    $delCasa  = $db->prepare("DELETE FROM \"case\" WHERE id = ?");
    foreach ($caseDaEliminare as $casaId) {
        $delSpese->execute([$casaId]);   // spese.casa_id è RESTRICT: prima le spese
        $delCasa->execute([$casaId]);    // il resto va in cascata
    }

    // 2. Case condivise create dall'utente: passa la proprietà a un altro membro (admin per primi)
    $db->prepare("
        UPDATE \"case\" c SET created_by = (
            SELECT x.utente_id FROM utenti_case x
            WHERE x.casa_id = c.id AND x.utente_id <> ? AND x.invito_accettato = 1
            ORDER BY (x.ruolo = 'admin') DESC, x.id ASC
            LIMIT 1
        )
        WHERE c.created_by = ?
    ")->execute([$uid, $uid]);

    // 3. Inviti creati dall'utente o destinati alla sua email
    $db->prepare("DELETE FROM inviti WHERE creato_da = ? OR LOWER(email_invitato) = LOWER(?)")
       ->execute([$uid, $email]);
    $db->prepare("UPDATE inviti SET usato_da = NULL WHERE usato_da = ?")->execute([$uid]);

    // 4. Appartenenze e sessioni
    $db->prepare("DELETE FROM utenti_case WHERE utente_id = ?")->execute([$uid]);
    $db->prepare("DELETE FROM sessioni WHERE id_utente = ?")->execute([$uid]);
    // Ricorrenze dell'utente nelle case condivise: le spese già generate restano
    $db->prepare("DELETE FROM spese_ricorrenti WHERE id_utente = ?")->execute([$uid]);

    // 5. Spese rimaste in case condivise? Allora anonimizza, altrimenti elimina la riga
    $stmt = $db->prepare("SELECT COUNT(*) FROM spese WHERE id_utente = ?");
    $stmt->execute([$uid]);
    $speseRimaste = (int)$stmt->fetchColumn();

    if ($speseRimaste > 0) {
        $db->prepare("
            UPDATE utenti SET
                nome = 'Utente eliminato',
                email = ?,
                password_hash = ?,
                attivo = 0,
                token_reset = NULL,
                token_reset_scade = NULL,
                ultimo_accesso = NULL,
                deve_cambiare_password = 0
            WHERE id = ?
        ")->execute([
            "eliminato-{$uid}@utente-eliminato.invalid",
            password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
            $uid,
        ]);
    } else {
        $db->prepare("DELETE FROM utenti WHERE id = ?")->execute([$uid]);
    }

    return [
        'case_eliminate' => count($caseDaEliminare),
        'anonimizzato'   => $speseRimaste > 0,
    ];
}

// ============================================================
// HELPERS PRIVATI
// ============================================================

function caricaCaseUtente(int $utenteId, \PDO $db): array {
    $stmt = $db->prepare("
        SELECT
            c.id,
            c.nome,
            c.colore,
            c.icona,
            c.casa_personale,
            uc.ruolo,
            uc.casa_default
        FROM \"case\" c
        JOIN utenti_case uc ON c.id = uc.casa_id
        WHERE uc.utente_id = ? AND uc.invito_accettato = 1
        ORDER BY uc.casa_default DESC, c.nome ASC
    ");
    $stmt->execute([$utenteId]);
    return $stmt->fetchAll();
}

/**
 * Carica gli inviti pendenti per l'utente corrente:
 * - inviti nominali (per la sua email) non ancora risposti
 * - che non siano già stati usati/accettati/rifiutati
 */
function caricaInvitiPendenti(int $utenteId, string $email, \PDO $db): array {
    $stmt = $db->prepare("
        SELECT
            i.token,
            i.ruolo,
            i.scade_il,
            c.nome        AS casa_nome,
            c.colore      AS casa_colore,
            u.nome        AS invitante_nome
        FROM inviti i
        JOIN \"case\" c  ON c.id = i.casa_id
        JOIN utenti u  ON u.id = i.creato_da
        WHERE i.email_invitato = ?
          AND i.usato          = 0
          AND i.stato          = 'in_attesa'
          AND i.scade_il       > NOW()
          AND NOT EXISTS (
              SELECT 1 FROM utenti_case uc
              WHERE uc.utente_id = ? AND uc.casa_id = i.casa_id AND uc.invito_accettato = 1
          )
        ORDER BY i.creato_il DESC
    ");
    $stmt->execute([$email, $utenteId]);
    return $stmt->fetchAll();
}

/**
 * Verifica token invito standard (per registrazione/verifica pubblica).
 * Non controlla a chi è destinato — quello lo fa il chiamante.
 */
function verificaTokenInvito(string $token, \PDO $db): array {
    $stmt = $db->prepare("
        SELECT id, token, casa_id, email_invitato, ruolo, creato_da, scade_il
        FROM inviti
        WHERE token = ? AND usato = 0 AND stato = 'in_attesa' AND scade_il > NOW()
    ");
    $stmt->execute([$token]);
    $invito = $stmt->fetch();

    if (!$invito) {
        rispostaErrore(404, 'Invito non valido o scaduto.');
    }

    return $invito;
}

/**
 * Verifica token invito per utente autenticato (accetta/rifiuta).
 * Controlla che l'invito sia destinato all'email dell'utente loggato.
 */
function verificaTokenInvitoPerUtente(string $token, string $emailUtente, \PDO $db): array {
    $stmt = $db->prepare("
        SELECT id, token, casa_id, email_invitato, ruolo, creato_da, scade_il
        FROM inviti
        WHERE token = ? AND usato = 0 AND stato = 'in_attesa' AND scade_il > NOW()
    ");
    $stmt->execute([$token]);
    $invito = $stmt->fetch();

    if (!$invito) {
        rispostaErrore(404, 'Invito non valido o scaduto.');
    }

    // L'invito nominale deve corrispondere all'utente loggato
    if ($invito['email_invitato'] !== null && $invito['email_invitato'] !== $emailUtente) {
        rispostaErrore(403, 'Questo invito non è destinato al tuo account.');
    }

    return $invito;
}