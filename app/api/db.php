<?php
// ============================================================
// DB.PHP - Database connection, SQL dialect helpers, migrations
//
// HouseFunds runs on MySQL/MariaDB and on PostgreSQL with the same
// code. Queries are written in portable SQL; the few constructs that
// differ between the two engines go through the helpers below.
//
// MySQL sessions run with ANSI_QUOTES, so "double quotes" are
// identifiers on both engines (the table "case" is a reserved word).
//
// Requires config.php (constants DB_*, logErrore, rispostaErrore).
// ============================================================

const DB_DRIVER_SUPPORTATI = ['mysql', 'pgsql'];

/** Active driver: 'mysql' or 'pgsql'. */
function dbDriver(): string {
    return DB_DRIVER;
}

/** True when running on MySQL/MariaDB. */
function dbMysql(): bool {
    return DB_DRIVER === 'mysql';
}

/**
 * Opens a PDO connection with the given parameters and prepares the
 * session (encoding, time zone, schema / SQL mode). Throws PDOException.
 */
function apriConnessione(array $p): PDO {
    $driver = $p['driver'];
    if (!in_array($driver, DB_DRIVER_SUPPORTATI, true)) {
        throw new PDOException('Unsupported DB_DRIVER: ' . $driver);
    }
    if (!in_array($driver, PDO::getAvailableDrivers(), true)) {
        throw new PDOException("PHP extension pdo_{$driver} is not installed.");
    }

    $opzioni = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_TIMEOUT            => 10,
    ];

    if ($driver === 'mysql') {
        $porta = $p['port'] !== '' ? $p['port'] : '3306';
        $dsn   = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $p['host'], $porta, $p['name']);
        $pdo   = new PDO($dsn, $p['user'], $p['pass'], $opzioni);
        // ANSI_QUOTES: "x" is an identifier, as in PostgreSQL.
        // ONLY_FULL_GROUP_BY is left out: PostgreSQL already enforces it on the same queries.
        $pdo->exec("SET SESSION sql_mode = 'ANSI_QUOTES,PIPES_AS_CONCAT,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        // Offset of the application time zone (named zones need the MySQL tz tables)
        $pdo->exec("SET time_zone = '" . (new DateTime('now'))->format('P') . "'");
        return $pdo;
    }

    $porta = $p['port'] !== '' ? $p['port'] : '5432';
    $dsn   = sprintf('pgsql:host=%s;port=%s;dbname=%s', $p['host'], $porta, $p['name']);
    $pdo   = new PDO($dsn, $p['user'], $p['pass'], $opzioni);
    $pdo->exec("SET client_encoding TO 'UTF8'");
    $pdo->exec("SET TIME ZONE " . $pdo->quote(date_default_timezone_get()));
    $schema = str_replace('"', '', $p['schema'] !== '' ? $p['schema'] : 'public');
    // Create the schema only when missing (CREATE needs a privilege the app may not have)
    $chk = $pdo->prepare('SELECT COUNT(*) FROM pg_namespace WHERE nspname = ?');
    $chk->execute([$schema]);
    if ((int)$chk->fetchColumn() === 0) {
        $pdo->exec('CREATE SCHEMA "' . $schema . '"');
    }
    $pdo->exec('SET search_path TO "' . $schema . '", public');
    return $pdo;
}

/** Connection parameters from the configuration. */
function parametriConnessione(): array {
    return [
        'driver' => DB_DRIVER,
        'host'   => DB_HOST,
        'port'   => DB_PORT,
        'name'   => DB_NAME,
        'user'   => DB_USER,
        'pass'   => DB_PASS,
        'schema' => DB_SCHEMA,
    ];
}

// ------------------------------------------------------------
// CONNESSIONE PDO (singleton)
// On first use it also brings the schema up to date.
// ------------------------------------------------------------
function getDB(): PDO {
    static $pdo = null;

    if ($pdo === null) {
        if (!dbConfigurato()) {
            rispostaErrore(503, 'Installazione non completata.');
        }
        try {
            $pdo = apriConnessione(parametriConnessione());
        } catch (PDOException $e) {
            logErrore('Connessione DB fallita: ' . $e->getMessage());
            rispostaErrore(500, 'Errore di connessione al database.');
        }
        try {
            aggiornaSchema($pdo);
        } catch (Throwable $e) {
            logErrore('Aggiornamento schema fallito: ' . $e->getMessage());
            rispostaErrore(500, 'Aggiornamento del database non riuscito. Controlla il log.');
        }
    }

    return $pdo;
}

// ------------------------------------------------------------
// ID dell'ultimo INSERT
// ------------------------------------------------------------
function ultimoId(string $tabella): int {
    $db = getDB();
    if (dbMysql()) {
        return (int)$db->lastInsertId();
    }
    $seq = $db->query("SELECT pg_get_serial_sequence('\"" . $tabella . "\"', 'id')")->fetchColumn();
    if ($seq) {
        return (int)$db->query("SELECT currval('" . $seq . "')")->fetchColumn();
    }
    return (int)$db->lastInsertId($tabella . '_id_seq');
}

// ============================================================
// SQL DIALECT HELPERS
// ============================================================

/** Year of a date column, as integer. */
function sqlAnno(string $colonna): string {
    return dbMysql() ? "YEAR($colonna)" : "CAST(EXTRACT(YEAR FROM $colonna) AS INTEGER)";
}

/** Month (1-12) of a date column, as integer. */
function sqlMese(string $colonna): string {
    return dbMysql() ? "MONTH($colonna)" : "CAST(EXTRACT(MONTH FROM $colonna) AS INTEGER)";
}

/** ISO day of week (1 = Monday ... 7 = Sunday), as integer. */
function sqlGiornoSettimana(string $colonna): string {
    return dbMysql() ? "(WEEKDAY($colonna) + 1)" : "CAST(EXTRACT(ISODOW FROM $colonna) AS INTEGER)";
}

/** Maps an integer expression 1..n to fixed labels (portable CASE). */
function sqlEtichette(string $espressione, array $etichette): string {
    $sql = "CASE $espressione";
    foreach (array_values($etichette) as $i => $e) {
        $sql .= ' WHEN ' . ($i + 1) . " THEN '" . str_replace("'", "''", $e) . "'";
    }
    return $sql . ' END';
}

/** Case-insensitive LIKE operator (MySQL collations are already case-insensitive). */
function sqlIlike(): string {
    return dbMysql() ? 'LIKE' : 'ILIKE';
}

/** Comma-separated aggregate of an expression, ordered. */
function sqlConcatGruppo(string $espressione, string $ordine): string {
    return dbMysql()
        ? "GROUP_CONCAT($espressione ORDER BY $ordine SEPARATOR ',')"
        : "string_agg(CAST($espressione AS TEXT), ',' ORDER BY $ordine)";
}

/**
 * Upsert clause appended to an INSERT.
 *   $chiavi  columns of the unique key (used by PostgreSQL)
 *   $set     SET list; use EXCLUDED.col for the value being inserted
 *   $conId   true when the caller then reads the row id with idUpsert()
 */
function sqlSuConflitto(array $chiavi, string $set, bool $conId = false): string {
    if (dbMysql()) {
        $set = preg_replace('/\bEXCLUDED\.(\w+)/i', 'VALUES($1)', $set);
        // LAST_INSERT_ID(id) makes lastInsertId() return the existing row on update
        if ($conId) $set .= ', id = LAST_INSERT_ID(id)';
        return 'ON DUPLICATE KEY UPDATE ' . $set;
    }
    return 'ON CONFLICT (' . implode(', ', $chiavi) . ') DO UPDATE SET ' . $set . ($conId ? ' RETURNING id' : '');
}

/** Id of the row written by an INSERT with sqlSuConflitto(..., true) or sqlRitornaId(). */
function idUpsert(PDOStatement $stmt): int {
    return dbMysql() ? (int)getDB()->lastInsertId() : (int)$stmt->fetchColumn();
}

/** RETURNING id clause for a plain INSERT (read it back with idUpsert()). */
function sqlRitornaId(): string {
    return dbMysql() ? '' : ' RETURNING id';
}

/** Whole minutes from now until a timestamp expression (negative if past). */
function sqlMinutiDaOraA(string $espressione): string {
    return dbMysql()
        ? "FLOOR(TIMESTAMPDIFF(SECOND, NOW(), $espressione) / 60)"
        : "CAST(FLOOR(EXTRACT(EPOCH FROM ($espressione - NOW())) / 60) AS INTEGER)";
}

// ============================================================
// MIGRATIONS
//
// db/migrations/<driver>/NNN_name.sql are applied in order and recorded
// in the table schema_migrazioni. A database created before migrations
// existed (tables already there, no schema_migrazioni) is adopted as is:
// the migrations up to BASELINE_MIGRAZIONI are marked as applied.
// ============================================================

const BASELINE_MIGRAZIONI = '002';

/** Migration files for the active driver: ['001' => path, ...] sorted. */
function fileMigrazioni(string $driver): array {
    $dir   = BASE_PATH . '/db/migrations/' . $driver;
    $lista = [];
    foreach (glob($dir . '/*.sql') ?: [] as $file) {
        if (preg_match('/^(\d{3})_/', basename($file), $m)) $lista[$m[1]] = $file;
    }
    ksort($lista, SORT_STRING);
    return $lista;
}

/** Latest migration version available for the driver ('' if none). */
function ultimaVersioneSchema(string $driver): string {
    $lista = fileMigrazioni($driver);
    return $lista ? (string)array_key_last($lista) : '';
}

/**
 * Splits an SQL script into statements. Handles 'strings', "identifiers",
 * -- comments and PostgreSQL $$ bodies.
 */
function dividiSQL(string $sql): array {
    $istruzioni = [];
    $corrente   = '';
    $len        = strlen($sql);
    $i          = 0;
    while ($i < $len) {
        $c = $sql[$i];
        if ($c === '-' && ($sql[$i + 1] ?? '') === '-') {           // line comment
            $fine = strpos($sql, "\n", $i);
            $i    = $fine === false ? $len : $fine + 1;
            $corrente .= "\n";
            continue;
        }
        if ($c === "'" || $c === '"') {                              // quoted text
            $j = $i + 1;
            while ($j < $len) {
                if ($sql[$j] === $c) {
                    if (($sql[$j + 1] ?? '') === $c) { $j += 2; continue; }
                    break;
                }
                $j++;
            }
            $corrente .= substr($sql, $i, $j - $i + 1);
            $i = $j + 1;
            continue;
        }
        if ($c === '$' && preg_match('/\G\$(\w*)\$/', $sql, $m, 0, $i)) { // dollar quoting
            $tag  = $m[0];
            $fine = strpos($sql, $tag, $i + strlen($tag));
            $fine = $fine === false ? $len : $fine + strlen($tag);
            $corrente .= substr($sql, $i, $fine - $i);
            $i = $fine;
            continue;
        }
        if ($c === ';') {
            if (trim($corrente) !== '') $istruzioni[] = trim($corrente);
            $corrente = '';
            $i++;
            continue;
        }
        $corrente .= $c;
        $i++;
    }
    if (trim($corrente) !== '') $istruzioni[] = trim($corrente);
    return $istruzioni;
}

/** True if the table exists in the current database / schema. */
function tabellaEsiste(PDO $db, string $tabella): bool {
    if (dbMysqlDi($db)) {
        $stmt = $db->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
    } else {
        $stmt = $db->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = ?");
    }
    $stmt->execute([$tabella]);
    return (int)$stmt->fetchColumn() > 0;
}

/** Driver of a given connection. */
function dbMysqlDi(PDO $db): bool {
    return $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
}

/** File that caches the applied schema version (avoids a query per request). */
function fileVersioneSchema(): string {
    return DATA_DIR . '/schema-version.txt';
}

/**
 * Applies the pending migrations. Fast path: when the cached version
 * equals the latest file, nothing is queried.
 */
function aggiornaSchema(PDO $db, bool $forza = false): void {
    $driver  = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
    $ultima  = ultimaVersioneSchema($driver);
    if ($ultima === '') return;

    $cache = fileVersioneSchema();
    $chiaveCache = $driver . ':' . DB_HOST . ':' . DB_NAME . ':' . DB_SCHEMA . ':' . $ultima;
    if (!$forza && is_readable($cache) && trim((string)@file_get_contents($cache)) === $chiaveCache) return;

    $mysql = $driver === 'mysql';

    // One process at a time (concurrent first requests)
    if ($mysql) {
        $db->query("SELECT GET_LOCK('housefunds_migrazioni', 60)")->fetchColumn();
    } else {
        $db->exec('SELECT pg_advisory_lock(746513)');
    }

    try {
        if (!tabellaEsiste($db, 'schema_migrazioni')) {
            $db->exec($mysql
                ? 'CREATE TABLE schema_migrazioni (versione VARCHAR(20) NOT NULL PRIMARY KEY, applicata_il DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
                : 'CREATE TABLE schema_migrazioni (versione VARCHAR(20) NOT NULL PRIMARY KEY, applicata_il TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP)');

            // Existing installation from before migrations: adopt it
            if (tabellaEsiste($db, 'utenti')) {
                $ins = $db->prepare('INSERT INTO schema_migrazioni (versione) VALUES (?)');
                foreach (array_keys(fileMigrazioni($driver)) as $v) {
                    if (strcmp((string)$v, BASELINE_MIGRAZIONI) <= 0) $ins->execute([(string)$v]);
                }
            }
        }

        $applicate = $db->query('SELECT versione FROM schema_migrazioni')->fetchAll(PDO::FETCH_COLUMN);
        $applicate = array_flip(array_map('strval', $applicate));

        foreach (fileMigrazioni($driver) as $versione => $file) {
            $versione = (string)$versione;
            if (isset($applicate[$versione])) continue;

            $istruzioni = dividiSQL((string)file_get_contents($file));
            // PostgreSQL DDL is transactional: all or nothing.
            // MySQL commits DDL implicitly, so each file must be safe to re-run.
            if (!$mysql) $db->beginTransaction();
            try {
                foreach ($istruzioni as $sql) $db->exec($sql);
                $db->prepare('INSERT INTO schema_migrazioni (versione) VALUES (?)')->execute([$versione]);
                if (!$mysql) $db->commit();
            } catch (Throwable $e) {
                if (!$mysql && $db->inTransaction()) $db->rollBack();
                throw new RuntimeException('Migration ' . basename($file) . ': ' . $e->getMessage(), 0, $e);
            }
        }
    } finally {
        if ($mysql) {
            $db->query("SELECT RELEASE_LOCK('housefunds_migrazioni')")->fetchColumn();
        } else {
            $db->exec('SELECT pg_advisory_unlock(746513)');
        }
    }

    if (is_dir(DATA_DIR) && is_writable(DATA_DIR)) {
        @file_put_contents($cache, $chiaveCache);
    }
}
