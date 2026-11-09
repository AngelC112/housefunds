<?php
// ============================================================
// EVENTI_SISTEMA.PHP - Categoria di sistema "EVENTI"
//
// Ogni casa con eventi ha una categoria EVENTI (categorie.sistema = 'eventi')
// con una sottocategoria per evento (eventi.sottocategoria_id), collegata
// all'evento in sottocategorie_eventi. Così inserendo una spesa in
// EVENTI › <evento> la popup propone direttamente le mini-categorie.
//
// La categoria è attiva solo se la casa ha almeno un evento attivo.
// Nessun admin può creare, modificare o eliminare EVENTI e le sue
// sottocategorie: vengono gestite solo da qui.
// Richiede config.php già incluso.
// ============================================================

const CATEGORIA_EVENTI_NOME    = 'EVENTI';
const CATEGORIA_EVENTI_SISTEMA = 'eventi';

/** True se il nome è riservato alla categoria di sistema (case-insensitive). */
function nomeCategoriaRiservato(string $nome): bool {
    return mb_strtolower(trim($nome)) === mb_strtolower(CATEGORIA_EVENTI_NOME);
}

/** ID della categoria EVENTI della casa; la crea se manca e $crea è true. */
function categoriaEventiId(PDO $db, int $casaId, bool $crea = true): ?int {
    $stmt = $db->prepare("SELECT id FROM categorie WHERE casa_id = ? AND sistema = ?");
    $stmt->execute([$casaId, CATEGORIA_EVENTI_SISTEMA]);
    $id = $stmt->fetchColumn();
    if ($id) return (int)$id;
    if (!$crea) return null;

    // Riusa un'eventuale categoria "EVENTI" già esistente, marcandola come di sistema
    $stmt = $db->prepare("
        INSERT INTO categorie (casa_id, nome, tipo, colore, icona, ordinamento, attiva, sistema)
        VALUES (?, ?, 'uscita', '#4f46e5', NULL, 999, 1, ?)
        " . sqlSuConflitto(['nome', 'casa_id'], "sistema = EXCLUDED.sistema, tipo = 'uscita'", true) . "
    ");
    $stmt->execute([$casaId, CATEGORIA_EVENTI_NOME, CATEGORIA_EVENTI_SISTEMA]);
    return idUpsert($stmt);
}

/** True se la categoria è quella di sistema EVENTI. */
function eCategoriaSistema(PDO $db, int $categoriaId): bool {
    $stmt = $db->prepare("SELECT sistema FROM categorie WHERE id = ?");
    $stmt->execute([$categoriaId]);
    $s = $stmt->fetchColumn();
    return $s !== false && $s !== null && $s !== '';
}

/** True se la sottocategoria appartiene a una categoria di sistema. */
function eSottocategoriaSistema(PDO $db, int $sottocategoriaId): bool {
    $stmt = $db->prepare("
        SELECT c.sistema FROM sottocategorie sc
        JOIN categorie c ON c.id = sc.id_categoria
        WHERE sc.id = ?
    ");
    $stmt->execute([$sottocategoriaId]);
    $s = $stmt->fetchColumn();
    return $s !== false && $s !== null && $s !== '';
}

/** Attiva EVENTI se la casa ha eventi attivi, altrimenti la disattiva. */
function aggiornaVisibilitaCategoriaEventi(PDO $db, int $casaId): void {
    $db->prepare("
        UPDATE categorie
        SET attiva = CASE WHEN EXISTS (
                SELECT 1 FROM eventi WHERE casa_id = ? AND attivo = 1
            ) THEN 1 ELSE 0 END
        WHERE casa_id = ? AND sistema = ?
    ")->execute([$casaId, $casaId, CATEGORIA_EVENTI_SISTEMA]);
}

/**
 * Allinea la sottocategoria di sistema all'evento:
 * crea/rinomina la sottocategoria, ne copia lo stato attivo,
 * garantisce il collegamento all'evento e aggiorna la visibilità di EVENTI.
 */
function sincronizzaEventoSistema(PDO $db, int $casaId, int $eventoId): void {
    $stmt = $db->prepare("SELECT id, nome, attivo, sottocategoria_id FROM eventi WHERE id = ? AND casa_id = ?");
    $stmt->execute([$eventoId, $casaId]);
    $ev = $stmt->fetch();
    if (!$ev) return;

    $attivo = (int)($ev['attivo'] ?? 1) ? 1 : 0;
    $nome   = mb_substr((string)$ev['nome'], 0, 100);
    $subId  = $ev['sottocategoria_id'] ? (int)$ev['sottocategoria_id'] : null;

    // Evento disattivato senza sottocategoria: niente da creare
    if (!$subId && !$attivo) {
        aggiornaVisibilitaCategoriaEventi($db, $casaId);
        return;
    }

    $catId = categoriaEventiId($db, $casaId, true);

    if ($subId) {
        $chk = $db->prepare("SELECT id FROM sottocategorie WHERE id = ?");
        $chk->execute([$subId]);
        if (!$chk->fetch()) $subId = null;
    }

    if ($subId) {
        $db->prepare("UPDATE sottocategorie SET nome = ?, attiva = ?, id_categoria = ? WHERE id = ?")
           ->execute([$nome, $attivo, $catId, $subId]);
    } else {
        $ins = $db->prepare("
            INSERT INTO sottocategorie (id_categoria, nome, ordinamento, attiva)
            VALUES (?, ?, 0, ?)" . sqlRitornaId() . "
        ");
        $ins->execute([$catId, $nome, $attivo]);
        $subId = idUpsert($ins);
        $db->prepare("UPDATE eventi SET sottocategoria_id = ? WHERE id = ?")->execute([$subId, $eventoId]);
    }

    $db->prepare("
        INSERT INTO sottocategorie_eventi (sottocategoria_id, evento_id, escluso)
        VALUES (?, ?, 0)
        " . sqlSuConflitto(['sottocategoria_id', 'evento_id'], 'escluso = 0') . "
    ")->execute([$subId, $eventoId]);

    aggiornaVisibilitaCategoriaEventi($db, $casaId);
}
