-- The per-house settings (app name, currency symbol and code) are no longer
-- used: the app name comes from APP_NAME and the currency is a per-user
-- preference (utenti.valuta).
DROP TABLE IF EXISTS impostazioni;
