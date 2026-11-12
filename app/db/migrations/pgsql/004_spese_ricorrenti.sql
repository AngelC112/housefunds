-- ============================================================
-- Spese ricorrenti
--
-- Un modello in spese_ricorrenti genera spese reali nella tabella
-- spese a intervalli regolari (ogni N giorni/settimane/mesi/anni).
-- La generazione è "pigra": la esegue l'app quando qualcuno apre le
-- spese o la sezione ricorrenti (api/ricorrenti_gen.php), senza bisogno
-- di uno scheduler/cron nel container.
--
-- prossima_data è il cursore: la prossima data ancora da generare.
-- Ogni spesa generata porta spese.id_ricorrenza = modello, con un
-- indice unico (id_ricorrenza, data_spesa) che impedisce i duplicati.
-- ============================================================

CREATE TABLE spese_ricorrenti (
    id                SERIAL PRIMARY KEY,
    casa_id           INTEGER       NOT NULL,
    id_utente         INTEGER       NOT NULL,
    id_categoria      INTEGER       NOT NULL,
    id_sottocategoria INTEGER,
    descrizione       VARCHAR(255),
    importo           NUMERIC(10,2) NOT NULL,
    privata           SMALLINT      NOT NULL DEFAULT 0,
    intervallo        INTEGER       NOT NULL DEFAULT 1,
    unita             VARCHAR(12)   NOT NULL DEFAULT 'mesi',
    data_inizio       DATE          NOT NULL,
    data_fine         DATE,
    prossima_data     DATE          NOT NULL,
    attiva            SMALLINT      NOT NULL DEFAULT 1,
    creato_il         TIMESTAMP     DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_ricorrenti_casa          ON spese_ricorrenti (casa_id);
CREATE INDEX idx_ricorrenti_categoria     ON spese_ricorrenti (id_categoria);
CREATE INDEX idx_ricorrenti_sottocategoria ON spese_ricorrenti (id_sottocategoria);
CREATE INDEX idx_ricorrenti_utente        ON spese_ricorrenti (id_utente);
CREATE INDEX idx_ricorrenti_prossima      ON spese_ricorrenti (attiva, prossima_data);

ALTER TABLE spese_ricorrenti ADD CONSTRAINT fk_ricorrente_casa           FOREIGN KEY (casa_id)           REFERENCES "case"(id)         ON UPDATE CASCADE ON DELETE CASCADE;
ALTER TABLE spese_ricorrenti ADD CONSTRAINT fk_ricorrente_utente         FOREIGN KEY (id_utente)         REFERENCES utenti(id)         ON UPDATE CASCADE ON DELETE RESTRICT;
ALTER TABLE spese_ricorrenti ADD CONSTRAINT fk_ricorrente_categoria      FOREIGN KEY (id_categoria)      REFERENCES categorie(id)      ON UPDATE CASCADE ON DELETE RESTRICT;
ALTER TABLE spese_ricorrenti ADD CONSTRAINT fk_ricorrente_sottocategoria FOREIGN KEY (id_sottocategoria) REFERENCES sottocategorie(id) ON UPDATE CASCADE ON DELETE SET NULL;

-- Collega ogni spesa generata al suo modello (NULL = spesa normale).
ALTER TABLE spese ADD COLUMN id_ricorrenza INTEGER;
ALTER TABLE spese ADD CONSTRAINT fk_spesa_ricorrenza FOREIGN KEY (id_ricorrenza) REFERENCES spese_ricorrenti(id) ON UPDATE CASCADE ON DELETE SET NULL;
CREATE UNIQUE INDEX idx_spese_ricorrenza_data ON spese (id_ricorrenza, data_spesa);
