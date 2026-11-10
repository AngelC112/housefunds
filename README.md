# HouseFunds

Self-hosted household expense tracker for families and shared homes.
A progressive web app (works on phones, installable), in 8 languages.

[Italiano](README.it.md)

## Features

- **Multi-user, multi-house**: everyone gets a personal house, can create shared
  houses and invite other people by email or link. Roles per house: admin, member.
- **Expenses and income** with categories, subcategories and private entries.
- **Events** (holidays, weddings, renovations...) with their own budget items,
  linked to categories and subcategories.
- **Dashboard and pivot tables** you configure yourself, with event dimensions.
- **Import / export**: CSV and Excel import, CSV / Excel / PDF export.
- **8 languages**: English, Italiano, Español, Français, Deutsch, Português,
  Nederlands, Polski. Language, currency, number and date format per user.
- **Emails** (invitations, password reset) in each user's language.
- **Databases**: MySQL / MariaDB or PostgreSQL. Tables are created and updated
  automatically.

## Quick start with Docker

```bash
mkdir housefunds && cd housefunds
curl -O https://raw.githubusercontent.com/AngelC112/housefunds/main/docker-compose.yml
docker compose up -d
```

Open <http://localhost:8080> and register: the first account is ready to use.

That's all. With no database settings the container starts its own MariaDB,
generates a password on the first start and stores everything in `./data`:

| Path              | Content                                              |
|-------------------|------------------------------------------------------|
| `data/config.php` | generated settings, including the database password  |
| `data/mysql/`     | embedded database                                    |
| `data/logs/`      | application, email and database logs                 |

Back up the whole `data` folder.

### TrueNAS, Portainer, Unraid, CasaOS

Paste [docker-compose.yml](docker-compose.yml) as a custom app / stack. Map a
persistent dataset or folder to `/data` and the web port to `80`.

### Direct port or reverse proxy

Both ways work, pick one:

- **Direct port** (default): the site answers on `http://<host>:8080`. Nothing
  else to set up. Use it on a trusted network or for a first try; for real
  use put a proxy with HTTPS in front.
- **Only through a reverse proxy**: the port is not published and only the
  proxy can reach the container, over a shared Docker network:

  ```bash
  docker network create proxy
  curl -O https://raw.githubusercontent.com/AngelC112/housefunds/main/docker-compose.proxy.yml
  docker compose -f docker-compose.yml -f docker-compose.proxy.yml up -d
  ```

  Point the proxy to `http://housefunds:80` and set `APP_URL` to the public
  `https://` address. Where you paste a single YAML (TrueNAS, Portainer),
  remove the `ports:` lines, add `networks: [default, proxy]` to the service
  and this block at the end:

  ```yaml
  networks:
    proxy:
      external: true
  ```

### Recommended settings

Set them under `environment:` in the compose file:

```yaml
    environment:
      APP_URL: "https://expenses.example.com"   # used in email links
      APP_TIMEZONE: "Europe/Rome"
      MAIL_HOST: "smtp.example.com"
      MAIL_PORT: "587"
      MAIL_USERNAME: "noreply@example.com"
      MAIL_PASSWORD: "change-me"
```

Without SMTP the emails are not sent: they are written to `data/logs/errors.log`.

### External database

To use your own MySQL/MariaDB or PostgreSQL server, set `DB_HOST` (the
embedded MariaDB is then not started):

```yaml
    environment:
      DB_DRIVER: "pgsql"        # mysql | pgsql
      DB_HOST: "192.0.2.10"
      DB_PORT: "5432"
      DB_NAME: "housefunds"
      DB_USER: "housefunds"
      DB_PASS: "change-me"
      DB_SCHEMA: "public"       # PostgreSQL only
```

The database must exist and the user must be able to create tables.

### Build the image yourself

```bash
git clone https://github.com/AngelC112/housefunds.git
cd housefunds
docker build -t housefunds .
```

## Shared hosting without Docker (e.g. Aruba, SiteGround, OVH)

Requirements: PHP 8.2+ with `pdo_mysql` or `pdo_pgsql`, `mbstring`, `intl`,
`gd`, `zip`; a MySQL/MariaDB or PostgreSQL database.

1. Download `housefunds-vX.Y.Z.zip` from the
   [releases](https://github.com/AngelC112/housefunds/releases).
   It already contains the PHP dependencies.
2. Upload the content of the `housefunds` folder by FTP, in the web root or in a
   subfolder (e.g. `/expenses`).
3. Create a database from the hosting control panel.
4. Open the site: the installer asks for the database details and the email
   settings, creates the tables and saves `data/config.php`.

Email: choose **Hosting's built-in email** to use the hosting's own mail
service (PHP `mail()`, as on Aruba), or **SMTP server**. Set a sender address
of your domain, otherwise many providers reject the messages.

If the `data` folder is not writable, the installer shows the content of
`data/config.php` to create by hand. The folder is protected by `.htaccess`.

To reinstall, delete `data/config.php`.

Troubleshooting:

- Choose PHP 8.2 or newer in the hosting control panel (on Aruba: PHP
  version of the hosting); older versions show a blank page or an error.
- "500 Internal Server Error" on every page: some hostings do not allow
  `Options` in `.htaccess`. Put a `#` in front of `Options -Indexes`.

## Configuration

Nothing is required. Every setting can be an environment variable or a key in
`data/config.php` (environment variables win). Full list in
[.env.example](.env.example).

| Variable | Default | Description |
|---|---|---|
| `APP_URL` | detected from the request | Public address, used in email links. **Set it in production.** |
| `APP_NAME` | `HouseFunds` | Name in pages and emails |
| `APP_DEFAULT_LANGUAGE` | `en` | Used when the browser language is not supported |
| `APP_TIMEZONE` | `UTC` | PHP time zone, e.g. `Europe/Rome` |
| `DB_DRIVER` | `mysql` | `mysql` (MySQL/MariaDB) or `pgsql` |
| `DB_HOST` | empty | Empty in Docker = embedded MariaDB |
| `DB_PORT` | `3306` / `5432` | |
| `DB_NAME` / `DB_USER` / `DB_PASS` | `housefunds` / `housefunds` / empty | |
| `DB_SCHEMA` | `public` | PostgreSQL schema |
| `MAIL_MAILER` | automatic | `smtp`, `mail` (PHP mail) or `log` |
| `MAIL_HOST` / `MAIL_PORT` / `MAIL_ENCRYPTION` | empty / `587` / `tls` | SMTP server (`ssl` for port 465) |
| `MAIL_USERNAME` / `MAIL_PASSWORD` | empty | SMTP credentials |
| `MAIL_FROM_ADDRESS` / `MAIL_FROM_NAME` | SMTP user / `APP_NAME` | Sender |
| `MAIL_SENT_COPY` | `0` | `1` saves a copy in the mailbox "Sent" folder via IMAP |

## Updates

- **Docker**: `docker compose pull && docker compose up -d`.
- **Shared hosting**: upload the new release over the old files, keeping the
  `data` folder.

Database changes are applied automatically on the first request after the update.

## License

Copyright (C) Angelo Carfora.

HouseFunds is free software: you can redistribute it and/or modify it under
the terms of the [GNU Affero General Public License v3.0](LICENSE). If you
modify it and make it available to others, including as an online service, you
must publish your source code under the same license.

To use it without these obligations (closed-source products, hosted services
that do not publish their code) a [commercial license](COMMERCIAL.md) is
available.
