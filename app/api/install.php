<?php
// ============================================================
// INSTALL.PHP - Web installer
//
// Used when the app is copied to a hosting (e.g. Aruba) without Docker.
// It works only while no database is configured: it tests the
// connection, creates the tables and writes DATA_DIR/config.php.
// After that every call answers "already installed".
//
//   GET  install.php?azione=stato     → installation status
//   POST install.php?azione=installa  → configure and install
// ============================================================

require_once __DIR__ . '/config.php';
inizializzaAPI();

$azione = $_GET['azione'] ?? '';

match (true) {
    $_SERVER['REQUEST_METHOD'] === 'GET'  && $azione === 'stato'    => statoInstallazione(),
    $_SERVER['REQUEST_METHOD'] === 'POST' && $azione === 'installa' => installa(),
    default => rispostaErrore(404, 'Endpoint non trovato.'),
};

/** PHP extensions the app needs, with the missing ones. */
function estensioniMancanti(): array {
    $richieste = ['pdo', 'mbstring', 'json', 'openssl', 'intl', 'zip', 'gd'];
    return array_values(array_filter($richieste, fn ($e) => !extension_loaded($e)));
}

function statoInstallazione(): never {
    if (dbConfigurato()) {
        risposta(['installato' => true]);
    }
    $driver = array_values(array_intersect(DB_DRIVER_SUPPORTATI, PDO::getAvailableDrivers()));
    risposta([
        'installato'          => false,
        'driver_disponibili'  => $driver,
        'estensioni_mancanti' => estensioniMancanti(),
        'php'                 => PHP_VERSION,
        'config_scrivibile'   => cartellaDatiScrivibile(),
        'url_rilevato'        => rilevaUrlApp(),
        'mail_disponibile'    => mailLocaleDisponibile(),
    ]);
}

/** True if DATA_DIR exists (or can be created) and is writable. */
function cartellaDatiScrivibile(): bool {
    if (!is_dir(DATA_DIR)) @mkdir(DATA_DIR, 0755, true);
    return is_dir(DATA_DIR) && is_writable(DATA_DIR);
}

function installa(): never {
    if (dbConfigurato()) {
        rispostaErrore(403, 'Applicazione già installata.');
    }

    $d = inputJSON();
    $s = fn (string $k, int $max = 255) => mb_substr(trim((string)($d[$k] ?? '')), 0, $max);

    $driver = $s('db_driver', 10);
    if (!in_array($driver, DB_DRIVER_SUPPORTATI, true)) rispostaErrore(400, 'Tipo di database non valido.');

    $param = [
        'driver' => $driver,
        'host'   => $s('db_host'),
        'port'   => preg_replace('/\D/', '', $s('db_port', 5)),
        'name'   => $s('db_name', 100),
        'user'   => $s('db_user', 100),
        'pass'   => (string)($d['db_pass'] ?? ''),
        'schema' => $driver === 'pgsql' ? ($s('db_schema', 63) ?: 'public') : '',
    ];
    if ($param['host'] === '' || $param['name'] === '' || $param['user'] === '') {
        rispostaErrore(400, 'Host, nome database e utente sono obbligatori.');
    }
    if ($param['schema'] !== '' && !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $param['schema'])) {
        rispostaErrore(400, 'Nome schema non valido.');
    }

    $appUrl = rtrim($s('app_url', 255), '/');
    if ($appUrl !== '' && !filter_var($appUrl, FILTER_VALIDATE_URL)) rispostaErrore(400, 'URL dell\'app non valido.');

    $mailer = $s('mail_mailer', 10) ?: 'mail';
    if (!in_array($mailer, ['mail', 'smtp', 'log'], true)) rispostaErrore(400, 'Modalità email non valida.');
    if ($mailer === 'smtp' && $s('mail_host') === '') rispostaErrore(400, 'Indica il server SMTP.');
    $mailFrom = $s('mail_from_address', 150);
    if ($mailFrom !== '' && !filter_var($mailFrom, FILTER_VALIDATE_EMAIL)) rispostaErrore(400, 'Indirizzo mittente non valido.');

    // 1. Connection test
    try {
        $pdo = apriConnessione($param);
    } catch (Throwable $e) {
        rispostaErrore(400, 'Connessione al database non riuscita: ' . $e->getMessage());
    }

    // 2. Tables
    try {
        aggiornaSchema($pdo, true);
    } catch (Throwable $e) {
        logErrore('Installazione: creazione tabelle fallita: ' . $e->getMessage());
        rispostaErrore(500, 'Creazione delle tabelle non riuscita: ' . $e->getMessage());
    }

    // 3. Configuration file
    $config = [
        'DB_DRIVER' => $driver,
        'DB_HOST'   => $param['host'],
        'DB_PORT'   => $param['port'],
        'DB_NAME'   => $param['name'],
        'DB_USER'   => $param['user'],
        'DB_PASS'   => $param['pass'],
    ];
    if ($driver === 'pgsql') $config['DB_SCHEMA'] = $param['schema'];
    if ($appUrl !== '') $config['APP_URL'] = $appUrl;
    if ($s('app_name', 100) !== '') $config['APP_NAME'] = $s('app_name', 100);
    $lingua = normalizzaLingua($s('lingua', 5));
    if ($lingua) $config['APP_DEFAULT_LANGUAGE'] = $lingua;
    $config['MAIL_MAILER'] = $mailer;
    if ($mailFrom !== '') $config['MAIL_FROM_ADDRESS'] = $mailFrom;
    if ($mailer === 'smtp') {
        $config['MAIL_HOST']       = $s('mail_host');
        $config['MAIL_PORT']       = preg_replace('/\D/', '', $s('mail_port', 5)) ?: '587';
        $config['MAIL_ENCRYPTION'] = in_array($s('mail_encryption', 5), ['tls', 'ssl', 'none'], true) ? $s('mail_encryption', 5) : 'tls';
        $config['MAIL_USERNAME']   = $s('mail_username', 150);
        $config['MAIL_PASSWORD']   = (string)($d['mail_password'] ?? '');
    }

    $contenuto = generaFileConfig($config);

    if (cartellaDatiScrivibile() && @file_put_contents(CONFIG_FILE, $contenuto, LOCK_EX) !== false) {
        @chmod(CONFIG_FILE, 0640);
        proteggiCartellaDati();
        risposta(['installato' => true, 'messaggio' => 'Installazione completata.']);
    }

    // Folder not writable: the user creates the file by hand
    risposta([
        'installato'      => false,
        'config_manuale'  => $contenuto,
        'percorso_config' => CONFIG_FILE,
        'messaggio'       => 'Tabelle create. Crea il file di configurazione a mano.',
    ]);
}

/** PHP source of the configuration file. */
function generaFileConfig(array $config): string {
    return "<?php\n"
         . "// HouseFunds configuration, written by the installer on " . date('Y-m-d H:i') . ".\n"
         . "// Environment variables with the same names take precedence.\n"
         . "return " . var_export($config, true) . ";\n";
}

/** Denies web access to DATA_DIR on Apache hostings. */
function proteggiCartellaDati(): void {
    $ht = DATA_DIR . '/.htaccess';
    if (!file_exists($ht)) {
        @file_put_contents($ht, "Require all denied\n<IfModule !mod_authz_core.c>\n  Deny from all\n</IfModule>\n");
    }
}
