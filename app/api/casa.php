<?php
require_once __DIR__ . '/config.php';

$azione = $_GET['azione'] ?? '';
match($azione) {
  'lista' => gestisciLista(),
  'imposta' => gestisciImposta(),
  'condividi' => gestisciCondivisione(),
  default => rispostaErrore(400, 'Azione non valida.')
};

function gestisciLista(): void {
  $utente = utenteAutenticatoLettura();
  $db = getDB();
  $stmt = $db->prepare("
    SELECT c.id, c.nome, cu.ruolo, cu.casa_default
    FROM utenti_case cu
    JOIN \"case\" c ON c.id = cu.casa_id
    WHERE cu.utente_id = ?
    ORDER BY c.nome ASC
  ");
  $stmt->execute([$utente['id']]);
  risposta(['casa' => $stmt->fetchAll()]);
}

function gestisciImposta(): void {
  $utente = utenteAutenticato();
  $dati = inputJSON();
  verificaCSRF($dati['csrf_token'] ?? null);
  $casaId = validaIntero($dati['casa_id'] ?? 0, 'ID casa');

  $db = getDB();
  $stmt = $db->prepare("SELECT id FROM utenti_case WHERE utente_id = ? AND casa_id = ?");
  $stmt->execute([$utente['id'], $casaId]);
  if (!$stmt->fetch()) rispostaErrore(403, 'Casa non accessibile.');

  // Reset
  $db->prepare("UPDATE utenti_case SET casa_default = 0 WHERE utente_id = ?")->execute([$utente['id']]);
  $db->prepare("UPDATE utenti_case SET casa_default = 1 WHERE utente_id = ? AND casa_id = ?")
    ->execute([$utente['id'], $casaId]);

  $_SESSION['casa_default'] = $casaId;

  $stmt2 = $db->prepare("SELECT * FROM \"case\" WHERE id = ?");
  $stmt2->execute([$casaId]);
  $casa = $stmt2->fetch();

  risposta(['casa' => $casa, 'messaggio' => 'Casa selezionata.']);
}

function gestisciCondivisione(): void {
  $utente = utenteAutenticato();
  $dati = inputJSON();
  verificaCSRF($dati['csrf_token'] ?? null);

  $casaId = validaIntero($dati['casa_id'] ?? 0, 'ID casa');
  $nuovoUtenteEmail = strtolower(sanitizzaStringa($dati['email'] ?? '', 150));
  $nuovoRuolo = in_array($dati['ruolo'] ?? 'membro', ['admin','membro']) ? $dati['ruolo'] : 'membro';

  $db = getDB();

  // Controlla che l'utente corrente sia admin della casa
  $stmt = $db->prepare("SELECT ruolo FROM utenti_case WHERE utente_id = ? AND casa_id = ?");
  $stmt->execute([$utente['id'], $casaId]);
  if ($stmt->fetchColumn() !== 'admin') {
    rispostaErrore(403, 'Solo l’amministratore può condividere la casa.');
  }

  // Cerca utente esistente
  $stmtE = $db->prepare("SELECT id FROM utenti WHERE email = ?");
  $stmtE->execute([$nuovoUtenteEmail]);
  $altroUtente = $stmtE->fetch();
  if (!$altroUtente) rispostaErrore(404, 'Utente non trovato.');

  // Verifica se già connesso
  $stmtA = $db->prepare("SELECT id FROM utenti_case WHERE utente_id = ? AND casa_id = ?");
  $stmtA->execute([$altroUtente['id'], $casaId]);
  if ($stmtA->fetch()) rispostaErrore(409, 'L\'utente ha già accesso a questa casa.');

  // Inserisci relazione
  $db->prepare("INSERT INTO utenti_case (utente_id, casa_id, ruolo, casa_default) VALUES (?, ?, ?, 0)")
    ->execute([$altroUtente['id'], $casaId, $nuovoRuolo]);

  risposta(['messaggio' => 'Utente aggiunto con successo.', 'ruolo' => $nuovoRuolo]);
}