-- ============================================================
-- HouseFunds - PostgreSQL schema
-- Applied automatically by the app (api/db.php). The tables are
-- created in the schema set by DB_SCHEMA (default: public).
-- Booleans are SMALLINT 0/1, as on MySQL.
-- ============================================================

CREATE TABLE utenti (
    id                     SERIAL PRIMARY KEY,
    nome                   VARCHAR(100) NOT NULL,
    email                  VARCHAR(150) NOT NULL,
    password_hash          VARCHAR(255) NOT NULL,
    attivo                 SMALLINT     NOT NULL DEFAULT 1,
    creato_il              TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    ultimo_accesso         TIMESTAMP,
    token_reset            VARCHAR(128),
    token_reset_scade      TIMESTAMP,
    deve_cambiare_password SMALLINT     NOT NULL DEFAULT 0,
    lingua                 VARCHAR(5)   NOT NULL DEFAULT 'en',
    valuta                 VARCHAR(3)   NOT NULL DEFAULT 'EUR',
    formato_numero         VARCHAR(10)  NOT NULL DEFAULT '1,234.56',
    formato_data           VARCHAR(10)  NOT NULL DEFAULT 'dd/mm/yyyy'
);
CREATE UNIQUE INDEX idx_utenti_email ON utenti (email);
CREATE INDEX idx_utenti_token_reset ON utenti (token_reset);

CREATE TABLE "case" (
    id             SERIAL PRIMARY KEY,
    nome           VARCHAR(100) NOT NULL,
    descrizione    TEXT,
    colore         VARCHAR(7)   NOT NULL DEFAULT '#4f46e5',
    icona          VARCHAR(50),
    casa_personale SMALLINT     NOT NULL DEFAULT 0,
    created_by     INTEGER      NOT NULL,
    created_at     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_case_created_by ON "case" (created_by);

CREATE TABLE utenti_case (
    id               SERIAL PRIMARY KEY,
    utente_id        INTEGER     NOT NULL,
    casa_id          INTEGER     NOT NULL,
    ruolo            VARCHAR(40) NOT NULL DEFAULT 'membro',
    casa_default     SMALLINT    NOT NULL DEFAULT 0,
    invito_accettato SMALLINT    NOT NULL DEFAULT 1,
    invitato_da      INTEGER,
    created_at       TIMESTAMP   DEFAULT CURRENT_TIMESTAMP
);
CREATE UNIQUE INDEX idx_utenti_case_utente_casa ON utenti_case (utente_id, casa_id);
CREATE INDEX idx_utenti_case_casa ON utenti_case (casa_id);
CREATE INDEX idx_utenti_case_invitato_da ON utenti_case (invitato_da);

CREATE TABLE sessioni (
    id             VARCHAR(128) PRIMARY KEY,
    id_utente      INTEGER      NOT NULL,
    casa_id_attiva INTEGER,
    ip             VARCHAR(45),
    user_agent     VARCHAR(255),
    creata_il      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    scade_il       TIMESTAMP
);
CREATE INDEX idx_sessioni_utente ON sessioni (id_utente);
CREATE INDEX idx_sessioni_casa ON sessioni (casa_id_attiva);
CREATE INDEX idx_sessioni_scade_il ON sessioni (scade_il);

CREATE TABLE inviti (
    id              SERIAL PRIMARY KEY,
    token           VARCHAR(128) NOT NULL,
    casa_id         INTEGER      NOT NULL,
    email_invitato  VARCHAR(150),
    ruolo           VARCHAR(40)  NOT NULL DEFAULT 'membro',
    creato_da       INTEGER      NOT NULL,
    creato_il       TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    scade_il        TIMESTAMP,
    usato           SMALLINT     NOT NULL DEFAULT 0,
    usato_da        INTEGER,
    usato_il        TIMESTAMP,
    stato           VARCHAR(40)  NOT NULL DEFAULT 'in_attesa',
    risposta_il     TIMESTAMP,
    email_inviata   SMALLINT     NOT NULL DEFAULT 0,
    ultimo_invio_il TIMESTAMP
);
CREATE UNIQUE INDEX idx_inviti_token ON inviti (token);
CREATE INDEX idx_inviti_casa ON inviti (casa_id);
CREATE INDEX idx_inviti_creato_da ON inviti (creato_da);
CREATE INDEX idx_inviti_email_casa ON inviti (email_invitato, casa_id);

CREATE TABLE impostazioni (
    chiave  VARCHAR(100) NOT NULL,
    casa_id INTEGER      NOT NULL,
    valore  TEXT,
    PRIMARY KEY (chiave, casa_id)
);

CREATE TABLE categorie_template (
    id          SERIAL PRIMARY KEY,
    nome        VARCHAR(100) NOT NULL,
    tipo        VARCHAR(40)  NOT NULL DEFAULT 'uscita',
    colore      VARCHAR(7)   NOT NULL DEFAULT '#6366f1',
    icona       VARCHAR(50),
    ordinamento INTEGER      NOT NULL DEFAULT 0,
    lingua      VARCHAR(5)   NOT NULL DEFAULT 'en'
);
CREATE UNIQUE INDEX idx_categorie_template_nome_lingua ON categorie_template (nome, lingua);
CREATE INDEX idx_categorie_template_lingua ON categorie_template (lingua);

CREATE TABLE categorie (
    id          SERIAL PRIMARY KEY,
    casa_id     INTEGER      NOT NULL,
    nome        VARCHAR(100) NOT NULL,
    tipo        VARCHAR(40)  NOT NULL DEFAULT 'uscita',
    colore      VARCHAR(7)   NOT NULL DEFAULT '#6366f1',
    icona       VARCHAR(50),
    ordinamento INTEGER      NOT NULL DEFAULT 0,
    attiva      SMALLINT     NOT NULL DEFAULT 1,
    sistema     VARCHAR(20)  DEFAULT NULL
);
CREATE UNIQUE INDEX idx_categorie_nome_casa ON categorie (nome, casa_id);
CREATE UNIQUE INDEX idx_categorie_sistema_casa ON categorie (casa_id, sistema);
CREATE INDEX idx_categorie_casa ON categorie (casa_id);

CREATE TABLE sottocategorie (
    id           SERIAL PRIMARY KEY,
    id_categoria INTEGER      NOT NULL,
    nome         VARCHAR(100) NOT NULL,
    ordinamento  INTEGER      NOT NULL DEFAULT 0,
    attiva       SMALLINT     NOT NULL DEFAULT 1
);
CREATE INDEX idx_sottocategorie_categoria ON sottocategorie (id_categoria);

CREATE TABLE spese (
    id                SERIAL PRIMARY KEY,
    casa_id           INTEGER       NOT NULL,
    id_utente         INTEGER       NOT NULL,
    data_spesa        DATE          NOT NULL,
    id_categoria      INTEGER       NOT NULL,
    id_sottocategoria INTEGER,
    descrizione       VARCHAR(255),
    importo           NUMERIC(10,2) NOT NULL,
    privata           SMALLINT      NOT NULL DEFAULT 0,
    creato_il         TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    modificato_il     TIMESTAMP
);
CREATE INDEX idx_spese_casa ON spese (casa_id);
CREATE INDEX idx_spese_categoria ON spese (id_categoria);
CREATE INDEX idx_spese_sottocategoria ON spese (id_sottocategoria);
CREATE INDEX idx_spese_utente ON spese (id_utente);
CREATE INDEX idx_spese_data ON spese (data_spesa);

CREATE TABLE eventi (
    id                SERIAL PRIMARY KEY,
    casa_id           INTEGER      NOT NULL,
    nome              VARCHAR(255) NOT NULL,
    descrizione       TEXT,
    colore            VARCHAR(7)   DEFAULT '#4f46e5',
    attivo            SMALLINT     DEFAULT 1,
    created_at        TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    sottocategoria_id INTEGER
);
CREATE INDEX idx_eventi_casa ON eventi (casa_id);
CREATE UNIQUE INDEX idx_eventi_sottocategoria ON eventi (sottocategoria_id);

CREATE TABLE eventi_categorie (
    id              SERIAL PRIMARY KEY,
    evento_id       INTEGER       NOT NULL,
    nome            VARCHAR(255)  NOT NULL,
    importo_stimato NUMERIC(10,2) DEFAULT 0.00,
    ordine          INTEGER       DEFAULT 0
);
CREATE INDEX idx_eventi_categorie_evento ON eventi_categorie (evento_id);

CREATE TABLE spese_eventi (
    spesa_id            INTEGER NOT NULL PRIMARY KEY,
    evento_categoria_id INTEGER NOT NULL
);
CREATE INDEX idx_spese_eventi_categoria ON spese_eventi (evento_categoria_id);

CREATE TABLE categorie_eventi (
    categoria_id INTEGER NOT NULL,
    evento_id    INTEGER NOT NULL,
    PRIMARY KEY (categoria_id, evento_id)
);
CREATE INDEX idx_categorie_eventi_evento ON categorie_eventi (evento_id);

CREATE TABLE sottocategorie_eventi (
    sottocategoria_id INTEGER  NOT NULL,
    evento_id         INTEGER  NOT NULL,
    escluso           SMALLINT NOT NULL DEFAULT 0,
    PRIMARY KEY (sottocategoria_id, evento_id)
);
CREATE INDEX idx_sottocategorie_eventi_evento ON sottocategorie_eventi (evento_id);

CREATE TABLE pivot_configurazioni (
    id            SERIAL PRIMARY KEY,
    casa_id       INTEGER      NOT NULL,
    nome          VARCHAR(100) NOT NULL,
    descrizione   VARCHAR(255),
    riga          VARCHAR(40)  NOT NULL,
    riga_2        VARCHAR(40),
    riga_3        VARCHAR(40),
    colonna       VARCHAR(40)  NOT NULL,
    valore        VARCHAR(40)  NOT NULL DEFAULT 'somma',
    filtro_tipo   VARCHAR(40)  NOT NULL DEFAULT 'tutti',
    filtro_evento VARCHAR(20)  NOT NULL DEFAULT 'tutti',
    visibile      SMALLINT     NOT NULL DEFAULT 1,
    ordinamento   INTEGER      NOT NULL DEFAULT 0,
    creato_il     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_pivot_casa ON pivot_configurazioni (casa_id);

-- ── Foreign keys ─────────────────────────────────────────────
ALTER TABLE "case"       ADD CONSTRAINT fk_casa_created_by   FOREIGN KEY (created_by)  REFERENCES utenti(id) ON UPDATE CASCADE ON DELETE RESTRICT;
ALTER TABLE utenti_case  ADD CONSTRAINT fk_uc_utente         FOREIGN KEY (utente_id)   REFERENCES utenti(id) ON UPDATE CASCADE ON DELETE CASCADE;
ALTER TABLE utenti_case  ADD CONSTRAINT fk_uc_casa           FOREIGN KEY (casa_id)     REFERENCES "case"(id) ON UPDATE CASCADE ON DELETE CASCADE;
ALTER TABLE utenti_case  ADD CONSTRAINT fk_uc_invitato_da    FOREIGN KEY (invitato_da) REFERENCES utenti(id) ON UPDATE CASCADE ON DELETE SET NULL;
ALTER TABLE sessioni     ADD CONSTRAINT fk_sessione_utente   FOREIGN KEY (id_utente)   REFERENCES utenti(id) ON UPDATE CASCADE ON DELETE CASCADE;
ALTER TABLE sessioni     ADD CONSTRAINT fk_sessione_casa     FOREIGN KEY (casa_id_attiva) REFERENCES "case"(id) ON UPDATE CASCADE ON DELETE SET NULL;
ALTER TABLE inviti       ADD CONSTRAINT fk_invito_casa       FOREIGN KEY (casa_id)     REFERENCES "case"(id) ON UPDATE CASCADE ON DELETE CASCADE;
ALTER TABLE inviti       ADD CONSTRAINT fk_invito_creato_da  FOREIGN KEY (creato_da)   REFERENCES utenti(id) ON UPDATE CASCADE ON DELETE RESTRICT;
ALTER TABLE categorie    ADD CONSTRAINT fk_categoria_casa    FOREIGN KEY (casa_id)     REFERENCES "case"(id) ON UPDATE CASCADE ON DELETE CASCADE;
ALTER TABLE sottocategorie ADD CONSTRAINT fk_sottocat_categoria FOREIGN KEY (id_categoria) REFERENCES categorie(id) ON UPDATE CASCADE ON DELETE CASCADE;
ALTER TABLE spese        ADD CONSTRAINT fk_spesa_casa        FOREIGN KEY (casa_id)     REFERENCES "case"(id) ON UPDATE CASCADE ON DELETE RESTRICT;
ALTER TABLE spese        ADD CONSTRAINT fk_spesa_utente      FOREIGN KEY (id_utente)   REFERENCES utenti(id) ON UPDATE CASCADE ON DELETE RESTRICT;
ALTER TABLE spese        ADD CONSTRAINT fk_spesa_categoria   FOREIGN KEY (id_categoria) REFERENCES categorie(id) ON UPDATE CASCADE ON DELETE RESTRICT;
ALTER TABLE spese        ADD CONSTRAINT fk_spesa_sottocategoria FOREIGN KEY (id_sottocategoria) REFERENCES sottocategorie(id) ON UPDATE CASCADE ON DELETE SET NULL;
ALTER TABLE eventi       ADD CONSTRAINT fk_evento_casa       FOREIGN KEY (casa_id)     REFERENCES "case"(id) ON UPDATE CASCADE ON DELETE CASCADE;
ALTER TABLE eventi       ADD CONSTRAINT fk_evento_sottocategoria FOREIGN KEY (sottocategoria_id) REFERENCES sottocategorie(id) ON DELETE SET NULL;
ALTER TABLE eventi_categorie ADD CONSTRAINT fk_evcat_evento  FOREIGN KEY (evento_id)   REFERENCES eventi(id) ON DELETE CASCADE;
ALTER TABLE spese_eventi ADD CONSTRAINT fk_spev_evcat        FOREIGN KEY (evento_categoria_id) REFERENCES eventi_categorie(id) ON DELETE CASCADE;
ALTER TABLE categorie_eventi ADD CONSTRAINT fk_catev_categoria FOREIGN KEY (categoria_id) REFERENCES categorie(id) ON DELETE CASCADE;
ALTER TABLE categorie_eventi ADD CONSTRAINT fk_catev_evento  FOREIGN KEY (evento_id)   REFERENCES eventi(id) ON DELETE CASCADE;
ALTER TABLE sottocategorie_eventi ADD CONSTRAINT fk_sottev_sottocategoria FOREIGN KEY (sottocategoria_id) REFERENCES sottocategorie(id) ON DELETE CASCADE;
ALTER TABLE sottocategorie_eventi ADD CONSTRAINT fk_sottev_evento FOREIGN KEY (evento_id) REFERENCES eventi(id) ON DELETE CASCADE;
ALTER TABLE pivot_configurazioni ADD CONSTRAINT fk_pivot_casa FOREIGN KEY (casa_id)    REFERENCES "case"(id) ON UPDATE CASCADE ON DELETE CASCADE;

-- ── Last-modified timestamp of an expense ────────────────────
CREATE FUNCTION set_modificato_il() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    NEW.modificato_il := NOW();
    RETURN NEW;
END $$;

CREATE TRIGGER trg_spese_modificato BEFORE UPDATE ON spese
    FOR EACH ROW EXECUTE FUNCTION set_modificato_il();
