# HouseFunds

Gestione delle spese di casa per famiglie e coinquilini, da installare sul
proprio server. Web app progressiva (funziona da telefono, installabile), in 8 lingue.

[English](README.md)

## Funzionalità

- **Più utenti, più case**: ognuno ha una casa personale, può creare case
  condivise e invitare altre persone via email o link. Ruoli per casa: admin, membro.
- **Spese ed entrate** con categorie, sottocategorie e voci private.
- **Eventi** (vacanze, matrimoni, lavori...) con le proprie voci di budget,
  collegati a categorie e sottocategorie.
- **Dashboard e tabelle pivot** configurabili, anche per evento.
- **Import / export**: import CSV ed Excel, export CSV / Excel / PDF.
- **8 lingue**: English, Italiano, Español, Français, Deutsch, Português,
  Nederlands, Polski. Lingua, valuta, formato importi e date per utente.
- **Email** (inviti, reset password) nella lingua di ogni utente.
- **Database**: MySQL / MariaDB o PostgreSQL. Le tabelle vengono create e
  aggiornate in automatico.

## Avvio rapido con Docker

```bash
mkdir housefunds && cd housefunds
curl -O https://raw.githubusercontent.com/AngelC112/housefunds/main/docker-compose.yml
docker compose up -d
```

Apri <http://localhost:8080> e registrati: il primo account è subito operativo.

Fine. Senza impostazioni del database il container avvia un suo MariaDB,
genera la password al primo avvio e salva tutto in `./data`:

| Percorso          | Contenuto                                              |
|-------------------|--------------------------------------------------------|
| `data/config.php` | impostazioni generate, compresa la password del database |
| `data/mysql/`     | database interno                                       |
| `data/logs/`      | log dell'app, delle email e del database               |

Fai il backup dell'intera cartella `data`.

### TrueNAS, Portainer, Unraid, CasaOS

Incolla [docker-compose.yml](docker-compose.yml) come app personalizzata / stack.
Collega un dataset o una cartella persistente a `/data` e la porta web a `80`.

### Impostazioni consigliate

Sotto `environment:` nel file compose:

```yaml
    environment:
      APP_URL: "https://spese.example.com"   # usato nei link delle email
      APP_TIMEZONE: "Europe/Rome"
      MAIL_HOST: "smtp.example.com"
      MAIL_PORT: "587"
      MAIL_USERNAME: "noreply@example.com"
      MAIL_PASSWORD: "cambiami"
```

Senza SMTP le email non partono: vengono scritte in `data/logs/errors.log`.

### Database esterno

Per usare un tuo server MySQL/MariaDB o PostgreSQL imposta `DB_HOST`
(il MariaDB interno non viene avviato):

```yaml
    environment:
      DB_DRIVER: "pgsql"        # mysql | pgsql
      DB_HOST: "192.0.2.10"
      DB_PORT: "5432"
      DB_NAME: "housefunds"
      DB_USER: "housefunds"
      DB_PASS: "cambiami"
      DB_SCHEMA: "public"       # solo PostgreSQL
```

Il database deve esistere e l'utente deve poter creare tabelle.

## Hosting condiviso senza Docker (es. Aruba)

Requisiti: PHP 8.2+ con `pdo_mysql` o `pdo_pgsql`, `mbstring`, `intl`, `gd`,
`zip`; un database MySQL/MariaDB o PostgreSQL.

1. Scarica `housefunds-vX.Y.Z.zip` dalle
   [release](https://github.com/AngelC112/housefunds/releases).
   Contiene già le dipendenze PHP.
2. Carica via FTP il contenuto della cartella `housefunds`, nella root del sito
   o in una sottocartella (es. `/spese`).
3. Crea un database dal pannello dell'hosting.
4. Apri il sito: l'installer chiede i dati del database e dell'email, crea le
   tabelle e salva `data/config.php`.

Email: scegli **Email integrata dell'hosting** per usare la posta dell'hosting
(PHP `mail()`, come su Aruba), oppure **Server SMTP**. Imposta un mittente del
tuo dominio, altrimenti molti provider rifiutano i messaggi.

Se la cartella `data` non è scrivibile, l'installer mostra il contenuto di
`data/config.php` da creare a mano. La cartella è protetta da `.htaccess`.

Per reinstallare elimina `data/config.php`.

Problemi frequenti:

- Scegli PHP 8.2 o superiore dal pannello dell'hosting (su Aruba: versione
  PHP dell'hosting); con versioni più vecchie compare una pagina bianca o un errore.
- "500 Internal Server Error" su tutte le pagine: alcuni hosting non permettono
  `Options` nel `.htaccess`. Metti un `#` davanti a `Options -Indexes`.

## Configurazione

Niente è obbligatorio. Ogni impostazione può essere una variabile d'ambiente o
una chiave in `data/config.php` (vincono le variabili d'ambiente). Elenco
completo in [.env.example](.env.example).

## Aggiornamenti

- **Docker**: `docker compose pull && docker compose up -d`.
- **Hosting condiviso**: carica la nuova release sopra i vecchi file,
  mantenendo la cartella `data`.

Le modifiche al database vengono applicate da sole alla prima richiesta dopo l'aggiornamento.

## Licenza

Copyright (C) Angelo Carfora.

HouseFunds è software libero, distribuito con la licenza
[GNU Affero General Public License v3.0](LICENSE). Puoi usarlo gratis per
qualsiasi scopo. Se lo modifichi e lo rendi disponibile ad altri, anche come
servizio online, devi pubblicare il codice con la stessa licenza.

Per usarlo senza questi obblighi (prodotti a codice chiuso, servizi online che
non pubblicano il codice) è disponibile una [licenza commerciale](COMMERCIAL.md).
