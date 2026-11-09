<?php
// ============================================================
// I18N.PHP - Traduzioni lato server (messaggi API ed email)
//
// I dizionari sono in /i18n/<lingua>.json, condivisi con il frontend.
// Le chiavi sono il testo italiano originale; i segmenti variabili
// sono indicati con {0}, {1}, ... sia nella chiave sia nella traduzione.
// L'italiano è la lingua sorgente: non ha un dizionario.
//
// Lingua della richiesta (in ordine di priorità):
//   1. cookie "hf_lang" impostato dal frontend (preferenza utente)
//   2. intestazione Accept-Language del browser
//   3. italiano
// Richiede config.php già incluso.
// ============================================================

const LINGUE_SUPPORTATE = ['it', 'en', 'es', 'fr', 'de', 'pt', 'nl', 'pl'];
const LINGUA_SORGENTE   = 'it';
const LINGUA_FALLBACK   = 'en';

const VALUTE_SUPPORTATE  = ['EUR', 'USD', 'GBP', 'CHF', 'PLN', 'SEK', 'NOK', 'DKK', 'CZK', 'HUF', 'RON', 'BRL', 'CAD', 'AUD', 'JPY', 'CNY', 'INR', 'MXN', 'ARS'];
const FORMATI_NUMERO     = ['1.234,56', '1,234.56', '1 234,56', "1'234.56"];
const FORMATI_DATA       = ['dd/mm/yyyy', 'mm/dd/yyyy', 'yyyy-mm-dd', 'dd.mm.yyyy', 'dd-mm-yyyy'];

/** Codice lingua valido (2 lettere) oppure null. */
function normalizzaLingua(?string $l): ?string {
    $l = strtolower(substr(trim((string)$l), 0, 2));
    return in_array($l, LINGUE_SUPPORTATE, true) ? $l : null;
}

/** Lingua della richiesta corrente. */
function linguaCorrente(): string {
    static $lingua = null;
    if ($lingua !== null) return $lingua;

    $l = normalizzaLingua($_COOKIE['hf_lang'] ?? null);
    if (!$l && !empty($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
        foreach (explode(',', $_SERVER['HTTP_ACCEPT_LANGUAGE']) as $parte) {
            $l = normalizzaLingua(trim(explode(';', $parte)[0]));
            if ($l) break;
        }
    }
    return $lingua = ($l ?: (normalizzaLingua(LINGUA_PREDEFINITA) ?? LINGUA_FALLBACK));
}

/** Dizionario di una lingua: ['esatte' => [...], 'modelli' => [[regex, traduzione], ...]]. */
function dizionarioLingua(string $lingua): array {
    static $cache = [];
    if (isset($cache[$lingua])) return $cache[$lingua];

    $vuoto = ['esatte' => [], 'modelli' => []];
    if ($lingua === LINGUA_SORGENTE) return $cache[$lingua] = $vuoto;

    $file = BASE_PATH . '/i18n/' . $lingua . '.json';
    $dati = is_readable($file) ? json_decode((string)file_get_contents($file), true) : null;
    if (!is_array($dati)) return $cache[$lingua] = $vuoto;

    $esatte = []; $modelli = [];
    foreach ($dati as $chiave => $valore) {
        if (!is_string($valore) || $valore === '' || str_starts_with((string)$chiave, '@')) continue;
        $esatte[$chiave] = $valore;
        if (preg_match('/\{\d+\}/', (string)$chiave)) {
            $re = '/^' . preg_replace('/\\\\\{(\d+)\\\\\}/', '(?<p$1>.+?)', preg_quote((string)$chiave, '/')) . '$/us';
            $modelli[] = [$re, $valore];
        }
    }
    return $cache[$lingua] = ['esatte' => $esatte, 'modelli' => $modelli];
}

/** Sostituisce {0}, {1}, ... con gli argomenti. */
function sostituisciSegnaposti(string $testo, array $argomenti): string {
    foreach ($argomenti as $i => $v) $testo = str_replace('{' . $i . '}', (string)$v, $testo);
    return $testo;
}

/**
 * Traduce un testo italiano (chiave) nella lingua indicata o corrente.
 * Esempio: tr('Pagina {0} di {1}', [2, 5]).
 */
function tr(string $testo, array $argomenti = [], ?string $lingua = null): string {
    $lingua = normalizzaLingua($lingua) ?? linguaCorrente();
    $diz    = dizionarioLingua($lingua);
    $trad   = $diz['esatte'][$testo] ?? $testo;
    return sostituisciSegnaposti($trad, $argomenti);
}

/**
 * Traduce un messaggio già composto (es. "Puoi reinviare tra 5 ore.")
 * cercando prima la chiave esatta e poi i modelli con segnaposti.
 */
function traduciMessaggio(string $messaggio, ?string $lingua = null): string {
    $lingua = normalizzaLingua($lingua) ?? linguaCorrente();
    if ($lingua === LINGUA_SORGENTE || $messaggio === '') return $messaggio;

    $diz = dizionarioLingua($lingua);
    if (isset($diz['esatte'][$messaggio])) return $diz['esatte'][$messaggio];

    foreach ($diz['modelli'] as [$re, $trad]) {
        if (preg_match($re, $messaggio, $m)) {
            return preg_replace_callback('/\{(\d+)\}/', fn($x) => $m['p' . $x[1]] ?? $x[0], $trad);
        }
    }
    return $messaggio;
}

/**
 * Preferenze di visualizzazione predefinite per una lingua
 * (usate quando l'utente non le ha ancora scelte).
 */
function preferenzeDefault(string $lingua): array {
    $mappa = [
        'it' => ['EUR', '1.234,56', 'dd/mm/yyyy'],
        'en' => ['GBP', '1,234.56', 'dd/mm/yyyy'],
        'es' => ['EUR', '1.234,56', 'dd/mm/yyyy'],
        'fr' => ['EUR', '1 234,56', 'dd/mm/yyyy'],
        'de' => ['EUR', '1.234,56', 'dd.mm.yyyy'],
        'pt' => ['EUR', '1 234,56', 'dd/mm/yyyy'],
        'nl' => ['EUR', '1.234,56', 'dd-mm-yyyy'],
        'pl' => ['PLN', '1 234,56', 'dd.mm.yyyy'],
    ];
    [$valuta, $numero, $data] = $mappa[$lingua] ?? $mappa['en'];
    return ['lingua' => $lingua, 'valuta' => $valuta, 'formato_numero' => $numero, 'formato_data' => $data];
}

/**
 * Valida le preferenze ricevute dal client; i valori non validi
 * vengono sostituiti dai predefiniti della lingua.
 */
function validaPreferenze(array $dati, ?string $linguaFallback = null): array {
    $lingua = normalizzaLingua($dati['lingua'] ?? null) ?? normalizzaLingua($linguaFallback) ?? linguaCorrente();
    $def    = preferenzeDefault($lingua);
    $valuta = strtoupper(substr(trim((string)($dati['valuta'] ?? '')), 0, 3));
    return [
        'lingua'         => $lingua,
        'valuta'         => in_array($valuta, VALUTE_SUPPORTATE, true) ? $valuta : $def['valuta'],
        'formato_numero' => in_array($dati['formato_numero'] ?? '', FORMATI_NUMERO, true) ? $dati['formato_numero'] : $def['formato_numero'],
        'formato_data'   => in_array($dati['formato_data'] ?? '', FORMATI_DATA, true) ? $dati['formato_data'] : $def['formato_data'],
    ];
}

/** Formatta una data (Y-m-d o timestamp) secondo un formato utente. */
function formattaDataUtente(string $data, string $formato, bool $conOra = false): string {
    $ts = strtotime($data);
    if ($ts === false) return $data;
    $mappa = ['dd/mm/yyyy' => 'd/m/Y', 'mm/dd/yyyy' => 'm/d/Y', 'yyyy-mm-dd' => 'Y-m-d', 'dd.mm.yyyy' => 'd.m.Y', 'dd-mm-yyyy' => 'd-m-Y'];
    return date(($mappa[$formato] ?? 'd/m/Y') . ($conOra ? ' H:i' : ''), $ts);
}

// ------------------------------------------------------------
// PREFERENZE UTENTE SU DB
// The columns are created by the migrations, which run automatically.
// ------------------------------------------------------------

/** Always true: kept for older callers (the migrations create the columns). */
function multilinguaAttivo(PDO $db): bool {
    return true;
}

/** Always true: kept for older callers (the migrations create the column). */
function templatePerLinguaAttivi(PDO $db): bool {
    return true;
}

/** Preferenze salvate di un utente (o predefinite della lingua corrente). */
function preferenzeUtente(PDO $db, int $utenteId): array {
    if (!multilinguaAttivo($db)) return preferenzeDefault(linguaCorrente());
    $stmt = $db->prepare("SELECT lingua, valuta, formato_numero, formato_data FROM utenti WHERE id = ?");
    $stmt->execute([$utenteId]);
    $p = $stmt->fetch();
    return $p ? validaPreferenze($p) : preferenzeDefault(linguaCorrente());
}

/** Lingua salvata di un utente cercato per email (null se non registrato). */
function linguaUtentePerEmail(PDO $db, string $email): ?string {
    if (!multilinguaAttivo($db)) return null;
    $stmt = $db->prepare("SELECT lingua FROM utenti WHERE email = ?");
    $stmt->execute([mb_strtolower($email)]);
    $l = $stmt->fetchColumn();
    return $l ? normalizzaLingua($l) : null;
}

/**
 * Copia le categorie predefinite nella casa, nella lingua indicata;
 * se per quella lingua non ci sono template usa l'inglese.
 */
function copiaCategorieTemplate(PDO $db, int $casaId, string $lingua): void {
    $chk = $db->prepare("SELECT COUNT(*) FROM categorie_template WHERE lingua = ?");
    $chk->execute([$lingua]);
    if ((int)$chk->fetchColumn() === 0) $lingua = LINGUA_FALLBACK;

    // The house id is an int: written inline so both engines type it correctly
    $db->prepare("
        INSERT INTO categorie (casa_id, nome, tipo, colore, icona, ordinamento, attiva)
        SELECT " . (int)$casaId . ", nome, tipo, colore, icona, ordinamento, 1
        FROM categorie_template WHERE lingua = ?
    ")->execute([$lingua]);
}
