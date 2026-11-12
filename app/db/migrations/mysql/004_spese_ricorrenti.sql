-- ============================================================
-- Spese ricorrenti (vedi la versione pgsql per la descrizione).
-- MySQL fa commit implicito della DDL, quindi il file deve poter essere
-- rieseguito dopo un'interruzione: CREATE TABLE IF NOT EXISTS, poi un'unica
-- ALTER TABLE (sintassi standard, valida su MySQL e MariaDB).
-- La sessione gira con ANSI_QUOTES, quindi "case" è un identificatore.
-- ============================================================

CREATE TABLE IF NOT EXISTS spese_ricorrenti (
    id                INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    casa_id           INT           NOT NULL,
    id_utente         INT           NOT NULL,
    id_categoria      INT           NOT NULL,
    id_sottocategoria INT,
    descrizione       VARCHAR(255),
    importo           DECIMAL(10,2) NOT NULL,
    privata           SMALLINT      NOT NULL DEFAULT 0,
    intervallo        INT           NOT NULL DEFAULT 1,
    unita             VARCHAR(12)   NOT NULL DEFAULT 'mesi',
    data_inizio       DATE          NOT NULL,
    data_fine         DATE,
    prossima_data     DATE          NOT NULL,
    attiva            SMALLINT      NOT NULL DEFAULT 1,
    creato_il         DATETIME      DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ricorrenti_casa (casa_id),
    INDEX idx_ricorrenti_categoria (id_categoria),
    INDEX idx_ricorrenti_sottocategoria (id_sottocategoria),
    INDEX idx_ricorrenti_utente (id_utente),
    INDEX idx_ricorrenti_prossima (attiva, prossima_data),
    CONSTRAINT fk_ricorrente_casa           FOREIGN KEY (casa_id)           REFERENCES "case"(id)         ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_ricorrente_utente         FOREIGN KEY (id_utente)         REFERENCES utenti(id)         ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_ricorrente_categoria      FOREIGN KEY (id_categoria)      REFERENCES categorie(id)      ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_ricorrente_sottocategoria FOREIGN KEY (id_sottocategoria) REFERENCES sottocategorie(id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Collega ogni spesa generata al suo modello (NULL = spesa normale).
-- L'indice unico (id_ricorrenza, data_spesa) impedisce i duplicati; i NULL
-- restano distinti, quindi le spese normali non sono toccate.
-- Una sola istruzione: o viene applicata per intero o per niente.
ALTER TABLE spese
    ADD COLUMN id_ricorrenza INT NULL,
    ADD UNIQUE INDEX idx_spese_ricorrenza_data (id_ricorrenza, data_spesa),
    ADD CONSTRAINT fk_spesa_ricorrenza FOREIGN KEY (id_ricorrenza) REFERENCES spese_ricorrenti(id) ON UPDATE CASCADE ON DELETE SET NULL;
