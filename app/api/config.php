<?php
// ============================================================
// CONFIG.PHP - Global configuration of HouseFunds
//
// Every setting is read, in this order, from:
//   1. environment variables (Docker, hosting panels)
//   2. the configuration file DATA_DIR/config.php
//      (written by the web installer or by the Docker entrypoint)
//   3. the defaults below
// The same names are used in both places (DB_HOST, MAIL_HOST, ...).
// See .env.example for the full list.
// ============================================================

// Blocca subito qualsiasi output accidentale (warning PHP, ecc.)
// che romperebbe le risposte JSON
ob_start();

// ------------------------------------------------------------
// PERCORSI
// DATA_DIR holds everything that must survive updates:
// config.php, logs/, tmp/. Docker sets HF_DATA_DIR=/data.
// ------------------------------------------------------------
define('BASE_PATH', dirname(__DIR__));
define('DATA_DIR',  rtrim((getenv('HF_DATA_DIR') ?: BASE_PATH . '/data'), '/'));
define('CONFIG_FILE', DATA_DIR . '/config.php');
define('LOG_DIR',   DATA_DIR . '/logs');
define('UPLOAD_PATH', DATA_DIR . '/tmp/');
define('MAX_UPLOAD_MB', 10);

/** Values from the configuration file (empty array if not installed yet). */
function configFile(): array {
    static $dati = null;
    if ($dati === null) {
        $dati = [];
        if (is_readable(CONFIG_FILE)) {
            $letto = include CONFIG_FILE;
            if (is_array($letto)) $dati = $letto;
        }
    }
    return $dati;
}

/** Setting: environment variable, then configuration file, then default. */
function cfg(string $nome, string $default = ''): string {
    $v = getenv($nome);
    if ($v !== false && $v !== '') return $v;
    $f = configFile()[$nome] ?? null;
    if ($f !== null && $f !== '') return is_bool($f) ? ($f ? '1' : '0') : (string)$f;
    return $default;
}

// Kept for compatibility with older code
function envOr(string $nome, string $default = ''): string {
    return cfg($nome, $default);
}

define('AMBIENTE', cfg('APP_ENV', 'production'));

$tz = cfg('APP_TIMEZONE', cfg('TZ', 'UTC'));
if (!@date_default_timezone_set($tz)) date_default_timezone_set('UTC');
unset($tz);

// ------------------------------------------------------------
// DATABASE: MySQL/MariaDB (DB_DRIVER=mysql) or PostgreSQL (pgsql)
// ------------------------------------------------------------
define('DB_DRIVER', strtolower(cfg('DB_DRIVER', 'mysql')) === 'pgsql' ? 'pgsql' : 'mysql');
define('DB_HOST',   cfg('DB_HOST'));
define('DB_PORT',   cfg('DB_PORT'));                        // empty = 3306 / 5432
define('DB_NAME',   cfg('DB_NAME', 'housefunds'));          // case-sensitive on PostgreSQL
define('DB_SCHEMA', cfg('DB_SCHEMA', 'public'));            // PostgreSQL only
define('DB_USER',   cfg('DB_USER', 'housefunds'));
define('DB_PASS',   cfg('DB_PASS'));

/** True when a database has been configured (otherwise the installer runs). */
function dbConfigurato(): bool {
    return DB_HOST !== '';
}

// ------------------------------------------------------------
// SICUREZZA SESSIONI
// ------------------------------------------------------------
define('SESSION_NAME',     'hf_sess');
define('SESSION_DURATA',   60 * 60 * 8);   // 8 ore in secondi
define('SESSION_RINNOVO',  60 * 60 * 2);   // rinnova token ogni 2 ore

// ------------------------------------------------------------
// IMPOSTAZIONI APPLICAZIONE (override da DB a runtime)
// ------------------------------------------------------------
define('APP_NOME_DEFAULT', cfg('APP_NAME', 'HouseFunds'));
define('LINGUA_PREDEFINITA', cfg('APP_DEFAULT_LANGUAGE', 'en'));

/**
 * Public URL of the app, without trailing slash (used in email links).
 * Set APP_URL in production: when missing it is derived from the
 * request, which a client can influence through the Host header.
 */
function rilevaUrlApp(): string {
    $https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
           || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/');
    $dir    = rtrim(dirname($script), '/');
    if (str_ends_with($dir, '/api')) $dir = substr($dir, 0, -4);
    return ($https ? 'https' : 'http') . '://' . $host . $dir;
}
define('APP_URL', rtrim(cfg('APP_URL') ?: rilevaUrlApp(), '/'));

// ------------------------------------------------------------
// EMAIL
//
//   MAIL_MAILER        smtp | mail | log
//                      default: smtp if MAIL_HOST is set, otherwise mail when the
//                      server has a local mail program (PHP mail(): the hosting's
//                      own mail service, e.g. Aruba), otherwise log
//   MAIL_HOST          SMTP server
//   MAIL_PORT          587 (STARTTLS) or 465 (SSL)  (default 587)
//   MAIL_ENCRYPTION    tls | ssl | none            (default tls; ssl with port 465)
//   MAIL_USERNAME      SMTP user
//   MAIL_PASSWORD      SMTP password
//   MAIL_FROM_ADDRESS  sender (default MAIL_USERNAME if it is an address)
//   MAIL_FROM_NAME     sender name (default APP_NAME)
//   MAIL_TIMEOUT       timeout in seconds (default 15)
//
// Copy in the "Sent" folder (via IMAP, after each successful SMTP send):
//   MAIL_SENT_COPY      1 | 0     (default 0)
//   MAIL_IMAP_HOST      default MAIL_HOST
//   MAIL_IMAP_PORT      default 993 (IMAP over SSL)
//   MAIL_IMAP_USERNAME  default MAIL_USERNAME
//   MAIL_IMAP_PASSWORD  default MAIL_PASSWORD
//   MAIL_SENT_FOLDER    default: the folder flagged \Sent, otherwise "Sent Items"
// ------------------------------------------------------------
/** True when PHP mail() has a local mail program to hand messages to. */
function mailLocaleDisponibile(): bool {
    if (!function_exists('mail')) return false;
    $sendmail = trim((string)ini_get('sendmail_path'));
    if ($sendmail === '') return PHP_OS_FAMILY === 'Windows';
    return is_executable(strtok($sendmail, ' '));
}
define('MAIL_MAILER',     strtolower(cfg('MAIL_MAILER', cfg('MAIL_HOST') !== '' ? 'smtp' : (mailLocaleDisponibile() ? 'mail' : 'log'))));
define('MAIL_HOST',       cfg('MAIL_HOST'));
define('MAIL_PORT',       (int) cfg('MAIL_PORT', '587'));
define('MAIL_ENCRYPTION', strtolower(cfg('MAIL_ENCRYPTION', MAIL_PORT === 465 ? 'ssl' : 'tls')));
define('MAIL_USERNAME',   cfg('MAIL_USERNAME'));
define('MAIL_PASSWORD',   cfg('MAIL_PASSWORD'));
define('MAIL_TIMEOUT',    (int) cfg('MAIL_TIMEOUT', '15'));
define('MAIL_FROM',       cfg('MAIL_FROM_ADDRESS', strpos(MAIL_USERNAME, '@') !== false
                              ? MAIL_USERNAME
                              : 'noreply@' . preg_replace('/^www\./', '', (string)(parse_url(APP_URL, PHP_URL_HOST) ?: 'localhost'))));
define('MAIL_FROM_NOME',  cfg('MAIL_FROM_NAME', APP_NOME_DEFAULT));
define('MAIL_SENT_COPY',  filter_var(cfg('MAIL_SENT_COPY', '0'), FILTER_VALIDATE_BOOLEAN));
define('MAIL_IMAP_HOST',  cfg('MAIL_IMAP_HOST', MAIL_HOST));
define('MAIL_IMAP_PORT',  (int) cfg('MAIL_IMAP_PORT', '993'));
define('MAIL_IMAP_USERNAME', cfg('MAIL_IMAP_USERNAME', MAIL_USERNAME));
define('MAIL_IMAP_PASSWORD', cfg('MAIL_IMAP_PASSWORD', MAIL_PASSWORD));
define('MAIL_SENT_FOLDER', cfg('MAIL_SENT_FOLDER'));

// ------------------------------------------------------------
// ERRORI
// In production: log su file, nessun output a schermo
// In development: mostra errori
// ------------------------------------------------------------
if (AMBIENTE === 'development') {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    ini_set('display_startup_errors', 0);
    error_reporting(E_ALL);
    ini_set('log_errors', 1);
    if (is_dir(LOG_DIR) && is_writable(LOG_DIR)) {
        ini_set('error_log', LOG_DIR . '/errors.log');
    }
}

// ------------------------------------------------------------
// DATABASE: connection, SQL dialect helpers, migrations
// ------------------------------------------------------------
require_once __DIR__ . '/db.php';

// ------------------------------------------------------------
// INVIO SMTP (client minimale, senza dipendenze)
// Supporta SSL implicito (465), STARTTLS (587) e AUTH LOGIN.
// Ritorna true se il server accetta il messaggio (250 dopo DATA).
// In $messaggioInviato restituisce il messaggio completo (per la copia in "Inviate").
// ------------------------------------------------------------
function smtpInvia(string $destinatario, string $oggettoCodificato, string $body, string $headers, ?string &$messaggioInviato = null): bool {
    $schema = MAIL_ENCRYPTION === 'ssl' ? 'ssl://' : 'tcp://';
    $ctx    = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => MAIL_HOST]]);
    $errno  = 0;
    $errstr = '';
    $fp = @stream_socket_client($schema . MAIL_HOST . ':' . MAIL_PORT, $errno, $errstr, MAIL_TIMEOUT, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) {
        logErrore('SMTP: connessione a ' . MAIL_HOST . ':' . MAIL_PORT . " fallita ({$errno} {$errstr})");
        return false;
    }
    stream_set_timeout($fp, MAIL_TIMEOUT);

    // Legge una risposta (anche multilinea) e ritorna [codice, testo]
    $leggi = function () use ($fp): array {
        $testo = '';
        while (($riga = fgets($fp, 1024)) !== false) {
            $testo .= $riga;
            if (strlen($riga) < 4 || $riga[3] !== '-') {
                break;
            }
        }
        return [(int) substr($testo, 0, 3), trim($testo)];
    };
    // Invia un comando e controlla il codice di risposta; $nascondi evita di loggare credenziali
    $cmd = function (string $comando, array $attesi, bool $nascondi = false) use ($fp, $leggi): bool {
        fwrite($fp, $comando . "\r\n");
        [$codice, $testo] = $leggi();
        if (!in_array($codice, $attesi, true)) {
            logErrore('SMTP: risposta inattesa a "' . ($nascondi ? '***' : $comando) . "\": {$testo}");
            return false;
        }
        return true;
    };
    $chiudi = function (bool $esito) use ($fp): bool {
        @fwrite($fp, "QUIT\r\n");
        @fclose($fp);
        return $esito;
    };

    [$codice, $testo] = $leggi();
    if ($codice !== 220) {
        logErrore("SMTP: banner inatteso: {$testo}");
        return $chiudi(false);
    }

    $ehlo = parse_url(APP_URL, PHP_URL_HOST) ?: 'localhost';
    if (!$cmd("EHLO {$ehlo}", [250])) return $chiudi(false);

    if (MAIL_ENCRYPTION === 'tls') {
        if (!$cmd('STARTTLS', [220])) return $chiudi(false);
        if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            logErrore('SMTP: handshake TLS fallito con ' . MAIL_HOST);
            return $chiudi(false);
        }
        if (!$cmd("EHLO {$ehlo}", [250])) return $chiudi(false);
    }

    if (MAIL_USERNAME !== '') {
        if (!$cmd('AUTH LOGIN', [334])) return $chiudi(false);
        if (!$cmd(base64_encode(MAIL_USERNAME), [334], true)) return $chiudi(false);
        if (!$cmd(base64_encode(MAIL_PASSWORD), [235], true)) return $chiudi(false);
    }

    if (!$cmd('MAIL FROM:<' . MAIL_FROM . '>', [250])) return $chiudi(false);
    if (!$cmd('RCPT TO:<' . $destinatario . '>', [250, 251])) return $chiudi(false);
    if (!$cmd('DATA', [354])) return $chiudi(false);

    $messaggio  = 'Date: ' . date('r') . "\r\n";
    $messaggio .= 'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $ehlo . ">\r\n";
    $messaggio .= "Subject: {$oggettoCodificato}\r\n";
    $messaggio .= $headers . "\r\n" . $body;
    // Normalizza i fine riga in CRLF e raddoppia i punti a inizio riga (dot-stuffing)
    $messaggio  = preg_replace("/\r?\n/", "\r\n", $messaggio);
    $originale  = $messaggio;
    $messaggio  = preg_replace('/^\./m', '..', $messaggio);

    fwrite($fp, $messaggio . "\r\n.\r\n");
    [$codice, $testo] = $leggi();
    if ($codice !== 250) {
        logErrore("SMTP: messaggio rifiutato: {$testo}");
        return $chiudi(false);
    }
    $messaggioInviato = $originale;
    return $chiudi(true);
}

// ------------------------------------------------------------
// COPIA IN "INVIATE" (client IMAP minimale su SSL, senza estensione imap)
// Salva il messaggio già inviato nella cartella "Inviate" della casella,
// segnato come letto. Un errore viene solo registrato nel log: l'email
// al destinatario è già partita.
// ------------------------------------------------------------
function imapSalvaInviata(string $messaggio): bool {
    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => MAIL_IMAP_HOST]]);
    $errno  = 0;
    $errstr = '';
    $fp = @stream_socket_client('ssl://' . MAIL_IMAP_HOST . ':' . MAIL_IMAP_PORT, $errno, $errstr, MAIL_TIMEOUT, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) {
        logErrore('IMAP: connessione a ' . MAIL_IMAP_HOST . ':' . MAIL_IMAP_PORT . " fallita ({$errno} {$errstr})");
        return false;
    }
    stream_set_timeout($fp, MAIL_TIMEOUT);

    $n = 0;
    $quota = fn (string $v): string => '"' . addcslashes(str_replace(["\r", "\n"], '', $v), '"\\') . '"';
    // Legge fino alla risposta con il tag indicato; ritorna [ok, testo completo]
    $leggiFinoA = function (string $tag) use ($fp): array {
        $testo = '';
        while (($riga = fgets($fp)) !== false) {
            $testo .= $riga;
            if (preg_match('/\{(\d+)\}\r?\n$/', $riga, $lit)) {
                $testo .= stream_get_contents($fp, (int) $lit[1]);
                continue;
            }
            if (str_starts_with($riga, $tag . ' ')) {
                return [str_starts_with($riga, $tag . ' OK'), $testo];
            }
        }
        return [false, $testo . ' (connessione interrotta)'];
    };
    $cmd = function (string $comando, bool $nascondi = false) use ($fp, &$n, $leggiFinoA): array {
        $tag = 'S' . (++$n);
        fwrite($fp, "{$tag} {$comando}\r\n");
        [$ok, $testo] = $leggiFinoA($tag);
        if (!$ok) {
            logErrore('IMAP: comando "' . ($nascondi ? 'LOGIN ***' : $comando) . '" non riuscito: ' . trim($testo));
        }
        return [$ok, $testo];
    };
    $chiudi = function (bool $esito) use ($fp, $cmd): bool {
        @$cmd('LOGOUT');
        @fclose($fp);
        return $esito;
    };

    $saluto = fgets($fp);
    if ($saluto === false || !str_starts_with($saluto, '* OK')) {
        logErrore('IMAP: saluto inatteso: ' . trim((string) $saluto));
        @fclose($fp);
        return false;
    }

    [$ok] = $cmd('LOGIN ' . $quota(MAIL_IMAP_USERNAME) . ' ' . $quota(MAIL_IMAP_PASSWORD), true);
    if (!$ok) return $chiudi(false);

    // Cartella: quella configurata, altrimenti quella con attributo \Sent, altrimenti "Sent Items"
    $cartella = MAIL_SENT_FOLDER;
    if ($cartella === '') {
        $cartella = 'Sent Items';
        [$ok, $lista] = $cmd('LIST "" "*"');
        foreach (explode("\n", $lista) as $riga) {
            if (preg_match('/^\* LIST \(([^)]*)\) \S+ (.+)$/i', trim($riga), $m) && stripos($m[1], '\\Sent') !== false) {
                $cartella = stripcslashes(trim($m[2], '"'));
                break;
            }
        }
    }

    $messaggio = rtrim($messaggio, "\r\n") . "\r\n";
    $tag = 'S' . (++$n);
    fwrite($fp, "{$tag} APPEND " . $quota($cartella) . ' (\\Seen) {' . strlen($messaggio) . "}\r\n");
    $continua = fgets($fp);
    if ($continua === false || !str_starts_with($continua, '+')) {
        logErrore("IMAP: APPEND in \"{$cartella}\" rifiutato: " . trim((string) $continua));
        return $chiudi(false);
    }
    fwrite($fp, $messaggio . "\r\n");
    [$ok, $testo] = $leggiFinoA($tag);
    if (!$ok) {
        logErrore("IMAP: APPEND in \"{$cartella}\" non riuscito: " . trim($testo));
    }
    return $chiudi($ok);
}

// ------------------------------------------------------------
// INVIO EMAIL (SMTP / mail() / log, scelto con MAIL_MAILER)
//
// Parametri:
//   $destinatario  → indirizzo email del destinatario
//   $nome_dest     → nome visualizzato del destinatario
//   $oggetto       → oggetto email
//   $corpo_html    → corpo email in HTML
//   $corpo_testo   → corpo email in testo puro (fallback client vecchi)
//
// Ritorna: true se il server accetta il messaggio, false altrimenti.
// Con MAIL_MAILER=log l'email non parte: viene scritta in logs/errori.log (test).
// ------------------------------------------------------------
function inviaEmail(
    string $destinatario,
    string $nome_dest,
    string $oggetto,
    string $corpo_html,
    string $corpo_testo = ''
): bool {
    if (empty($corpo_testo)) {
        // Genera testo puro automaticamente dall'HTML (base)
        $corpo_testo = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $corpo_html));
        $corpo_testo = preg_replace('/[ \t]+/', ' ', $corpo_testo);
        $corpo_testo = trim($corpo_testo);
    }

    $boundary = '----=_Part_' . md5(uniqid((string)mt_rand(), true));

    // Codifica sicura per header (RFC 2047)
    $oggettoCodificato  = '=?UTF-8?B?' . base64_encode($oggetto) . '?=';
    $nomeMittente       = '=?UTF-8?B?' . base64_encode(MAIL_FROM_NOME) . '?=';
    $nomeDestinatario   = '=?UTF-8?B?' . base64_encode($nome_dest) . '?=';

    $headers  = "From: {$nomeMittente} <" . MAIL_FROM . ">\r\n";
    $headers .= "Reply-To: " . MAIL_FROM . "\r\n";
    $headers .= "To: {$nomeDestinatario} <{$destinatario}>\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
    $headers .= "X-Mailer: PHP/" . PHP_VERSION . "\r\n";

    $body  = "--{$boundary}\r\n";
    $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
    $body .= chunk_split(base64_encode($corpo_testo)) . "\r\n";

    $body .= "--{$boundary}\r\n";
    $body .= "Content-Type: text/html; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
    $body .= chunk_split(base64_encode($corpo_html)) . "\r\n";

    $body .= "--{$boundary}--";

    if (MAIL_MAILER === 'log') {
        logErrore("inviaEmail [log]: a {$destinatario} - oggetto: {$oggetto}\n{$corpo_testo}");
        return true;
    }

    if (MAIL_MAILER === 'smtp') {
        $inviato = null;
        $esito   = MAIL_HOST !== '' && smtpInvia($destinatario, $oggettoCodificato, $body, $headers, $inviato);
        if ($esito && MAIL_SENT_COPY && $inviato !== null) {
            imapSalvaInviata($inviato);
        }
    } else {
        $esito = @mail($destinatario, $oggettoCodificato, $body, $headers);
    }

    if (!$esito) {
        logErrore("inviaEmail: invio (" . MAIL_MAILER . ") fallito per {$destinatario} - oggetto: {$oggetto}");
    }

    return $esito;
}

// ------------------------------------------------------------
// TEMPLATE EMAIL HTML
//
// Wrapper grafico comune per tutte le email dell'app.
// Uso: $html = emailTemplate('Titolo', '<p>Corpo...</p>');
// ------------------------------------------------------------
function emailTemplate(string $titolo, string $corpo, ?string $lingua = null): string {
    $appNome = APP_NOME_DEFAULT;
    $colore  = '#4f46e5'; // indigo — stesso colore primario dell'app
    // Lingua dell'email (default: lingua della richiesta corrente)
    $lingua  = function_exists('normalizzaLingua') ? (normalizzaLingua($lingua) ?? linguaCorrente()) : 'it';
    $footer  = function_exists('tr')
        ? tr('Questa è un\'email automatica di {0}. Non rispondere a questo messaggio.', ["<strong>{$appNome}</strong>"], $lingua)
        : "Questa è un'email automatica di <strong>{$appNome}</strong>. Non rispondere a questo messaggio.";

    return <<<HTML
<!DOCTYPE html>
<html lang="{$lingua}">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>{$titolo}</title>
</head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:32px 16px;">
    <tr>
      <td align="center">
        <table width="560" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.08);">

          <!-- HEADER -->
          <tr>
            <td style="background:{$colore};padding:28px 32px;">
              <p style="margin:0;font-size:20px;font-weight:700;color:#ffffff;letter-spacing:-.3px;">💰 {$appNome}</p>
            </td>
          </tr>

          <!-- CORPO -->
          <tr>
            <td style="padding:32px;color:#111827;font-size:15px;line-height:1.6;">
              <h2 style="margin:0 0 16px;font-size:20px;color:#111827;">{$titolo}</h2>
              {$corpo}
            </td>
          </tr>

          <!-- FOOTER -->
          <tr>
            <td style="background:#f9fafb;padding:20px 32px;border-top:1px solid #e5e7eb;">
              <p style="margin:0;font-size:12px;color:#6b7280;">
                {$footer}
              </p>
            </td>
          </tr>

        </table>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;
}

// ------------------------------------------------------------
// SESSIONE SICURA
// ------------------------------------------------------------
function avviaSessione(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_name(SESSION_NAME);

        $scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '/');
        $appPath   = rtrim(dirname($scriptDir), '/') . '/';
        if ($appPath === '//') $appPath = '/';

        session_set_cookie_params([
            'lifetime' => SESSION_DURATA,
            'path'     => $appPath,
            'secure'   => (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                          || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

// ------------------------------------------------------------
// VERIFICA AUTENTICAZIONE
// ------------------------------------------------------------
function utenteAutenticato(): array {
    avviaSessione();

    if (empty($_SESSION['utente_id']) || empty($_SESSION['token'])) {
        rispostaErrore(401, 'Non autenticato. Effettua il login.');
    }

    $db   = getDB();
    $stmt = $db->prepare("
        SELECT
            s.id            AS sessione_id,
            s.scade_il,
            s.casa_id_attiva,
            u.id            AS utente_id,
            u.nome,
            u.email,
            u.attivo,
            u.deve_cambiare_password
        FROM sessioni s
        JOIN utenti u ON u.id = s.id_utente
        WHERE s.id = ? AND s.id_utente = ?
    ");
    $stmt->execute([$_SESSION['token'], $_SESSION['utente_id']]);
    $sessione = $stmt->fetch();

    if (!$sessione) {
        distruggiSessione();
        rispostaErrore(401, 'Sessione non valida. Effettua il login.');
    }

    if (strtotime($sessione['scade_il']) < time()) {
        distruggiSessione();
        rispostaErrore(401, 'Sessione scaduta. Effettua il login.');
    }

    if (!$sessione['attivo']) {
        distruggiSessione();
        rispostaErrore(403, 'Account disabilitato. Contatta l\'amministratore.');
    }

    // Rinnova la scadenza se siamo entro la finestra di rinnovo
    $rimanente = strtotime($sessione['scade_il']) - time();
    if ($rimanente < SESSION_RINNOVO) {
        $nuovaScadenza = date('Y-m-d H:i:s', time() + SESSION_DURATA);
        $db->prepare("UPDATE sessioni SET scade_il = ? WHERE id = ?")
           ->execute([$nuovaScadenza, $_SESSION['token']]);
    }

    // Aggiorna ultimo_accesso max ogni 5 minuti
    if (empty($_SESSION['ultimo_aggiornamento']) ||
        (time() - $_SESSION['ultimo_aggiornamento']) > 300) {
        $db->prepare("UPDATE utenti SET ultimo_accesso = NOW() WHERE id = ?")
           ->execute([$sessione['utente_id']]);
        $_SESSION['ultimo_aggiornamento'] = time();
    }

    return [
        'id'                    => (int)$sessione['utente_id'],
        'nome'                  => $sessione['nome'],
        'email'                 => $sessione['email'],
        'casa_id_attiva'        => $sessione['casa_id_attiva'] ? (int)$sessione['casa_id_attiva'] : null,
        'deve_cambiare_password'=> (bool)$sessione['deve_cambiare_password'],
    ];
}

// ------------------------------------------------------------
// LEGGI CASA ATTIVA DALLA SESSIONE CORRENTE
// ------------------------------------------------------------
function getCasaAttiva(): int {
    avviaSessione();

    $tokenId = $_SESSION['token'] ?? '';
    if (empty($tokenId)) {
        rispostaErrore(401, 'Non autenticato.');
    }

    $db   = getDB();
    $stmt = $db->prepare("SELECT casa_id_attiva FROM sessioni WHERE id = ?");
    $stmt->execute([$tokenId]);
    $casaId = $stmt->fetchColumn();

    if (!$casaId) {
        rispostaErrore(400, 'Nessuna casa attiva. Seleziona una casa dal menu.');
    }

    return (int)$casaId;
}

// ------------------------------------------------------------
// CONTROLLA PERMESSI SU UNA CASA
// ------------------------------------------------------------
function checkPermesso(int $utenteId, int $casaId, string $ruoloMinimo = 'membro'): string {
    $db   = getDB();
    $stmt = $db->prepare("
        SELECT ruolo FROM utenti_case
        WHERE utente_id = ? AND casa_id = ? AND invito_accettato = 1
    ");
    $stmt->execute([$utenteId, $casaId]);
    $uc = $stmt->fetch();

    if (!$uc) {
        rispostaErrore(403, 'Non hai accesso a questa casa.');
    }

    if ($ruoloMinimo === 'admin' && $uc['ruolo'] !== 'admin') {
        rispostaErrore(403, 'Operazione riservata agli amministratori della casa.');
    }

    return $uc['ruolo'];
}

// ------------------------------------------------------------
// SHORTCUT: richiede admin della casa attiva
// ------------------------------------------------------------
function richiedeAdminDiCasa(): array {
    $utente = utenteAutenticato();
    $casaId = getCasaAttiva();
    checkPermesso($utente['id'], $casaId, 'admin');
    return [$utente, $casaId];
}

// ------------------------------------------------------------
// SHORTCUT: richiede semplice accesso alla casa attiva
// ------------------------------------------------------------
function richiedeAccessoCasa(): array {
    $utente = utenteAutenticato();
    $casaId = getCasaAttiva();
    checkPermesso($utente['id'], $casaId);
    return [$utente, $casaId];
}

// ------------------------------------------------------------
// DISTRUGGI SESSIONE
// ------------------------------------------------------------
function distruggiSessione(): void {
    avviaSessione();

    if (!empty($_SESSION['token'])) {
        try {
            getDB()->prepare("DELETE FROM sessioni WHERE id = ?")
                   ->execute([$_SESSION['token']]);
        } catch (Exception $e) {
            // ignora errori in logout
        }
    }

    $_SESSION = [];
    session_destroy();
}

// ------------------------------------------------------------
// HEADERS JSON + CORS
// ------------------------------------------------------------
function inizializzaAPI(): void {
    if (ob_get_level()) ob_clean();

    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('X-XSS-Protection: 1; mode=block');

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

// ------------------------------------------------------------
// RISPOSTA JSON STANDARD
// ------------------------------------------------------------
function risposta(mixed $dati, int $codice = 200): never {
    if (ob_get_level()) ob_clean();
    // Messaggio per l'utente nella sua lingua
    if (is_array($dati) && isset($dati['messaggio']) && is_string($dati['messaggio']) && function_exists('traduciMessaggio')) {
        $dati['messaggio'] = traduciMessaggio($dati['messaggio']);
    }
    http_response_code($codice);
    echo json_encode([
        'successo' => true,
        'dati'     => $dati,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function rispostaErrore(int $codice, string $messaggio): never {
    if (ob_get_level()) ob_clean();
    // Messaggio di errore nella lingua dell'utente
    if (function_exists('traduciMessaggio')) $messaggio = traduciMessaggio($messaggio);
    http_response_code($codice);
    echo json_encode([
        'successo' => false,
        'errore'   => $messaggio,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ------------------------------------------------------------
// VALIDAZIONE INPUT
// ------------------------------------------------------------
function inputJSON(): array {
    $raw = file_get_contents('php://input');
    if (empty($raw)) {
        rispostaErrore(400, 'Body della richiesta vuoto.');
    }
    $dati = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        rispostaErrore(400, 'JSON non valido: ' . json_last_error_msg());
    }
    return $dati;
}

function sanitizzaStringa(?string $val, int $maxLen = 255): string {
    if ($val === null) return '';
    return mb_substr(trim(strip_tags($val)), 0, $maxLen);
}

function validaData(?string $val): string {
    if (empty($val) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) {
        rispostaErrore(400, 'Data non valida. Formato atteso: YYYY-MM-DD.');
    }
    if (!checkdate(
        (int)substr($val, 5, 2),
        (int)substr($val, 8, 2),
        (int)substr($val, 0, 4)
    )) {
        rispostaErrore(400, 'Data inesistente.');
    }
    return $val;
}

function validaImporto(mixed $val): float {
    $importo = filter_var($val, FILTER_VALIDATE_FLOAT);
    if ($importo === false) {
        rispostaErrore(400, 'Importo non valido.');
    }
    if (abs($importo) > 999999.99) {
        rispostaErrore(400, 'Importo fuori range.');
    }
    return round($importo, 2);
}

function validaIntero(mixed $val, string $campo = 'Campo'): int {
    $intero = filter_var($val, FILTER_VALIDATE_INT);
    if ($intero === false || $intero <= 0) {
        rispostaErrore(400, "$campo non valido.");
    }
    return (int)$intero;
}

// ------------------------------------------------------------
// TOKEN CSRF
// ------------------------------------------------------------
function generaCSRF(): string {
    avviaSessione();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verificaCSRF(?string $token): void {
    avviaSessione();
    if (empty($token) || empty($_SESSION['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $token)) {
        rispostaErrore(403, 'Token CSRF non valido.');
    }
}

// ------------------------------------------------------------
// LOGGING ERRORI
// ------------------------------------------------------------
function logErrore(string $messaggio, array $contesto = []): void {
    $logDir = LOG_DIR . '/';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    $riga = sprintf(
        "[%s] %s %s\n",
        date('Y-m-d H:i:s'),
        $messaggio,
        !empty($contesto) ? json_encode($contesto) : ''
    );
    // Se il file di log non è scrivibile, non deve mascherare l'errore originale:
    // si ripiega sul log standard di PHP (stderr del container).
    if (@error_log($riga, 3, $logDir . 'errors.log') === false) {
        @error_log(rtrim($riga));
    }
}

function registraErrore(string $messaggio, array $contesto = []): void {
    logErrore($messaggio, $contesto);
}

// ------------------------------------------------------------
// SESSIONE IN SOLA LETTURA
// ------------------------------------------------------------
function rilasciaSessione(): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        @session_write_close();
    }
}

function utenteAutenticatoLettura(): array {
    $u = utenteAutenticato();
    rilasciaSessione();
    return $u;
}

// ------------------------------------------------------------
// PULIZIA SESSIONI SCADUTE (~1% delle richieste)
// ------------------------------------------------------------
function pulisciSessioniScadute(): void {
    if (dbConfigurato() && rand(1, 100) === 1) {
        try {
            getDB()->exec("DELETE FROM sessioni WHERE scade_il < NOW()");
        } catch (Exception $e) {
            // ignora
        }
    }
}

// Avvia pulizia automatica ad ogni richiesta (~1%)
pulisciSessioniScadute();

// ------------------------------------------------------------
// MULTILINGUA (dizionari condivisi con il frontend)
// ------------------------------------------------------------
require_once __DIR__ . '/i18n.php';
