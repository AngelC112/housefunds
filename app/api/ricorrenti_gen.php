<?php
// ============================================================
// RICORRENTI_GEN.PHP - Generatore delle spese ricorrenti
//
// Un modello in spese_ricorrenti descrive una spesa che si ripete ogni
// N giorni/settimane/mesi/anni. La generazione è "pigra": viene chiamata
// quando qualcuno apre le spese o la sezione ricorrenti, quindi non serve
// uno scheduler/cron nel container.
//
// prossima_data è il cursore (prima data ancora da generare). Il generatore
// inserisce una spesa per ogni scadenza <= oggi (e <= data_fine), poi fa
// avanzare il cursore. L'indice unico spese(id_ricorrenza, data_spesa) rende
// l'operazione idempotente: eseguirla due volte non crea doppioni.
//
// Richiede config.php (e quindi db.php) già incluso.
// ============================================================

/** Unità di ricorrenza ammesse. */
const RICORRENZA_UNITA = ['giorni', 'settimane', 'mesi', 'anni'];

/**
 * Data della scadenza successiva a $cursore, secondo l'ancora $inizio.
 *
 * Per mesi/anni il giorno è "agganciato" al giorno di data_inizio e viene
 * ridotto all'ultimo del mese quando quel giorno non esiste (es. il 31
 * diventa 30/28). Così una ricorrenza mensile partita il 31 gennaio cade
 * il 28/29 febbraio, poi di nuovo il 31 marzo.
 */
function avanzaDataRicorrenza(string $cursore, string $inizio, string $unita, int $intervallo): string {
    $intervallo = max(1, $intervallo);
    $d          = new DateTimeImmutable($cursore);
    $dataInizio = new DateTimeImmutable($inizio);
    $giornoAncora = (int)$dataInizio->format('j');

    switch ($unita) {
        case 'giorni':
            return $d->modify('+' . $intervallo . ' day')->format('Y-m-d');

        case 'settimane':
            return $d->modify('+' . ($intervallo * 7) . ' day')->format('Y-m-d');

        case 'mesi':
            $totale = ((int)$d->format('Y')) * 12 + ((int)$d->format('n') - 1) + $intervallo;
            $anno   = intdiv($totale, 12);
            $mese   = $totale % 12 + 1;
            return componiData($anno, $mese, $giornoAncora);

        case 'anni':
            $mese = (int)$dataInizio->format('n'); // l'annuale mantiene il mese di partenza
            $anno = (int)$d->format('Y') + $intervallo;
            return componiData($anno, $mese, $giornoAncora);

        default:
            // Unità sconosciuta: non avanzare (il chiamante interrompe il ciclo).
            return $d->format('Y-m-d');
    }
}

/** Compone una data agganciando il giorno all'ultimo del mese se necessario. */
function componiData(int $anno, int $mese, int $giorno): string {
    $giorniNelMese = (int)(new DateTimeImmutable(sprintf('%04d-%02d-01', $anno, $mese)))->format('t');
    $giorno        = min($giorno, $giorniNelMese);
    return sprintf('%04d-%02d-%02d', $anno, $mese, $giorno);
}

/**
 * Materializza nella tabella spese tutte le scadenze arretrate dei modelli
 * attivi della casa. Idempotente. Non lancia eccezioni verso il chiamante:
 * un errore viene loggato e la generazione interrotta, così la pagina che
 * la invoca continua a funzionare.
 */
function generaRicorrenzeScadute(PDO $db, int $casaId): void {
    try {
        $oggi = (new DateTimeImmutable('today'))->format('Y-m-d');

        // Solo i modelli il cui autore è ancora membro della casa: chi l'ha
        // lasciata non genera più spese a suo nome.
        $sel = $db->prepare("
            SELECT r.id, r.id_utente, r.id_categoria, r.id_sottocategoria, r.descrizione,
                   r.importo, r.privata, r.intervallo, r.unita, r.data_inizio, r.data_fine, r.prossima_data
            FROM spese_ricorrenti r
            WHERE r.casa_id = ? AND r.attiva = 1 AND r.prossima_data <= ?
              AND (r.data_fine IS NULL OR r.prossima_data <= r.data_fine)
              AND EXISTS (SELECT 1 FROM utenti_case uc
                          WHERE uc.utente_id = r.id_utente AND uc.casa_id = r.casa_id AND uc.invito_accettato = 1)
        ");
        $sel->execute([$casaId, $oggi]);
        $modelli = $sel->fetchAll();
        if (!$modelli) return;

        $conflitto = dbMysql()
            ? 'ON DUPLICATE KEY UPDATE id = id'
            : 'ON CONFLICT (id_ricorrenza, data_spesa) DO NOTHING';

        $ins = $db->prepare("
            INSERT INTO spese
                (casa_id, id_utente, data_spesa, id_categoria, id_sottocategoria, descrizione, importo, privata, id_ricorrenza)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            $conflitto
        ");
        $aggiorna = $db->prepare("UPDATE spese_ricorrenti SET prossima_data = ?, attiva = ? WHERE id = ?");

        foreach ($modelli as $m) {
            if (!in_array($m['unita'], RICORRENZA_UNITA, true)) continue;

            $cursore = $m['prossima_data'];
            $fine    = $m['data_fine']; // string o null
            $attiva  = 1;
            $giri    = 0;

            while (
                $cursore <= $oggi
                && ($fine === null || $cursore <= $fine)
                && $giri < 1000
            ) {
                $ins->execute([
                    $casaId,
                    (int)$m['id_utente'],
                    $cursore,
                    (int)$m['id_categoria'],
                    $m['id_sottocategoria'] !== null ? (int)$m['id_sottocategoria'] : null,
                    $m['descrizione'],
                    $m['importo'],
                    (int)$m['privata'],
                    (int)$m['id'],
                ]);

                $cursore = avanzaDataRicorrenza($cursore, $m['data_inizio'], $m['unita'], (int)$m['intervallo']);
                $giri++;
            }

            // Modello concluso quando il cursore supera la data di fine.
            if ($fine !== null && $cursore > $fine) $attiva = 0;

            $aggiorna->execute([$cursore, $attiva, (int)$m['id']]);
        }
    } catch (Throwable $e) {
        if (function_exists('logErrore')) {
            logErrore('Generazione ricorrenti fallita: ' . $e->getMessage());
        }
    }
}
