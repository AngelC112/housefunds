// ============================================================
// ADMIN.JS - Logica frontend area amministratore
// ============================================================
const A = {
  utente: null,
  csrfToken: null,
  categorie: [],
  editTarget: null,
  case: [],
  casaAttiva: null,
  ruolo: null,         // ruolo dell'utente nella casa attiva
};

let _csrfTimestamp = 0;
A.cacheSottocategorie = [];

// ── Init ─────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', async () => {

  const raw = sessionStorage.getItem('utente');

  if (!raw) {
    window.location.href = (window.BASE_URL || '') + '/index.html';
    return;
  }

  try {
    A.utente = JSON.parse(raw);
  } catch (_) {
    window.location.href = (window.BASE_URL || '') + '/index.html';
    return;
  }

  A.csrfToken = sessionStorage.getItem('csrf_token');

  // Instance name (APP_NAME), cached by the login page and the app
  try {
    const nomeApp = localStorage.getItem('app_nome');
    if (nomeApp) document.title = `Admin — ${nomeApp}`;
  } catch (_) {}

  await csrfFresco();
  await caricaCase();

  // Carica sempre (sia admin che membro)
  await caricaListaCase();

  // Le altre sezioni si caricano on-demand via mostraTab
});

// ============================================================
// SWITCH CASA
// ============================================================

document.addEventListener('change', (e) => {
  if (e.target.id === 'switchCasa') {
    cambiaCasa(e.target.value);
  }
});

async function caricaCase() {

  try {

    const dati = await api('/api/admin.php?risorsa=casa');
    A.case = dati.case || [];

    // La casa attiva è quella che il server ha in sessione:
    // leggiamo dal profilo che restituisce casa_attiva con id
    let casaIdAttiva = null;
    try {
      const profilo = await api('/api/auth.php?azione=profilo');
      casaIdAttiva  = String(profilo.casa_attiva?.id || '');
      // Lingua e formati dal profilo (ricarica la pagina se cambiati su un altro dispositivo)
      if (profilo.utente) I18N.sincronizzaUtente(profilo.utente);
    } catch(_) {}

    // Fallback: sessionStorage (potrebbe essere stale ma meglio di niente)
    if (!casaIdAttiva) {
      const raw = sessionStorage.getItem('casa_attiva');
      if (raw) {
        try { casaIdAttiva = String(JSON.parse(raw).id || ''); } catch(_) {}
      }
    }

    // Fallback finale: prima casa della lista
    if (!casaIdAttiva && A.case.length) {
      casaIdAttiva = String(A.case[0].id);
    }

    const casaCompleta = A.case.find(c => String(c.id) === casaIdAttiva) || A.case[0];
    A.casaAttiva = casaCompleta || null;
    A.ruolo      = casaCompleta?.ruolo || null;

    if (A.casaAttiva) {
      sessionStorage.setItem('casa_attiva', JSON.stringify(A.casaAttiva));
    }

    // Popola il select
    const sel = document.getElementById('switchCasa');
    if (!sel) return;

    sel.innerHTML = A.case.map(c => `
      <option value="${c.id}" ${String(c.id) === String(A.casaAttiva?.id) ? 'selected' : ''}>
        ${esc(c.nome)}
      </option>
    `).join('');

    aggiornaUIPerRuolo();

  } catch (e) {
    console.error(e);
  }
}

// Mostra/nasconde elementi UI in base al ruolo nella casa attiva
function aggiornaUIPerRuolo() {
  const isAdmin = A.ruolo === 'admin';

  // Bottone invita membro: solo admin
  const btnInvita = document.getElementById('btnInvitaMembro');
  if (btnInvita) btnInvita.classList.toggle('hidden', !isAdmin);

  // Tab: SEMPRE tutti visibili (il non-admin vede i dati ma non può modificarli)
  ['nav-categorie', 'nav-pivot'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.classList.remove('hidden');
  });

  // Pulsanti "+ Nuova" su Pivot, tab Utenti: solo admin
  ['btnInvitaMembro'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.classList.toggle('hidden', !isAdmin);
  });

  // Header categorie: mostra versione giusta
  const catAdmin  = document.getElementById('catHeaderAdmin');
  const catMembro = document.getElementById('catHeaderMembro');
  if (catAdmin)  catAdmin.classList.toggle('hidden', !isAdmin);
  if (catMembro) catMembro.classList.toggle('hidden', isAdmin);

  // Pulsante + Nuova Sottocategoria nel pannello drill-down: solo admin
  const btnNuovaSc = document.getElementById('btnNuovaSottocategoria');
  if (btnNuovaSc) btnNuovaSc.classList.toggle('hidden', !isAdmin);

  // Pulsanti "+ Nuova" nei tab pivot ed eventi
  const btnNuovaPivot = document.querySelector('[onclick="apriModalPivot()"]');
  if (btnNuovaPivot) btnNuovaPivot.classList.toggle('hidden', !isAdmin);

  const btnNuovoEvento = document.querySelector('[onclick="apriModalEvento()"]');
  if (btnNuovoEvento) btnNuovoEvento.classList.toggle('hidden', !isAdmin);
}

async function cambiaCasa(casaId) {
  casaId = String(casaId);

  // Già attiva — non fare nulla
  if (casaId === String(A.casaAttiva?.id)) return;

  try {
    await api('/api/auth.php?azione=cambia_casa', {
      method: 'POST',
      body: JSON.stringify({ casa_id: casaId })
    });

    // Aggiorna stato in memoria
    const nuovaCasa = A.case.find(c => String(c.id) === casaId);
    if (!nuovaCasa) {
      // Casa non in lista (caso raro): forza reload completo
      location.reload();
      return;
    }

    A.casaAttiva = nuovaCasa;
    A.ruolo      = nuovaCasa.ruolo;
    sessionStorage.setItem('casa_attiva', JSON.stringify(A.casaAttiva));

    // Aggiorna il select in alto
    const sel = document.getElementById('switchCasa');
    if (sel) sel.value = casaId;

    // Aggiorna UI permessi
    aggiornaUIPerRuolo();

    // Ricarica il tab attualmente visibile
    const tabAttivo = document.querySelector('.tab-panel.active')?.id?.replace('tab-', '');
    if (tabAttivo) {
      // Forza ricarica del tab corrente
      if (tabAttivo === 'case')         caricaListaCase();
      if (tabAttivo === 'utenti')       caricaUtenti();
      if (tabAttivo === 'categorie')    caricaCategorie();
      if (tabAttivo === 'pivot')        caricaPivot();
      if (tabAttivo === 'eventi')       caricaEventi();
    }

  } catch (e) {
    alert(e.message);
  }
}

// ============================================================
// LISTA CASE (tab Case)
// ============================================================
async function caricaListaCase() {
  const el = document.getElementById('listaCase');
  if (!el) return;
  el.innerHTML = '<div class="text-gray-400 text-sm py-4 text-center">Caricamento…</div>';

  try {
    const case_ = A.case;
    if (!case_.length) {
      el.innerHTML = '<div class="text-gray-400 text-sm py-8 text-center">Nessuna casa trovata.</div>';
      return;
    }

    el.innerHTML = case_.map(c => {
      const isAdmin    = c.ruolo === 'admin';
      const isAttiva   = String(c.id) === String(A.casaAttiva?.id);
      const isDefault  = !!c.casa_default;

      const badgeRuolo    = isAdmin
        ? '<span class="badge badge-indigo">admin</span>'
        : '<span class="badge badge-gray">membro</span>';
      const badgePersonale = c.casa_personale
        ? '<span class="badge badge-green ml-1">personale</span>' : '';
      const badgeAttiva    = isAttiva
        ? '<span class="badge badge-indigo ml-1">attiva</span>'  : '';
      const badgeDefault   = isDefault
        ? '<span class="badge badge-green ml-1">⭐ default</span>' : '';

      // Bottone "Imposta default" — solo se non è già default
      const btnDefault = !isDefault ? `
        <button onclick="impostaCasaDefault(${c.id})"
          class="text-xs text-gray-500 hover:text-yellow-600 border border-gray-200 rounded-lg px-2 py-1">
          ⭐ Default
        </button>` : '';

      // Bottone seleziona casa attiva
      const btnSeleziona = !isAttiva ? `
        <button onclick="cambiaCasa(${c.id})"
          class="text-xs text-indigo-500 hover:text-indigo-700 font-medium border border-indigo-200 rounded-lg px-2 py-1">
          Seleziona
        </button>` : '';

      // Bottone rinomina — solo admin
      const btnRinomina = isAdmin ? `
        <button onclick="apriModalRinomina(${c.id}, '${esc(c.nome)}')"
          class="text-xs text-gray-500 hover:text-indigo-600 border border-gray-200 rounded-lg px-2 py-1">
          ✎ Rinomina
        </button>` : '';

      // Bottone elimina/abbandona — non per casa personale
      const btnElimina = !c.casa_personale ? `
        <button onclick="apriModalElimina(${c.id}, '${esc(c.nome)}', ${isAdmin ? 1 : 0})"
          class="text-xs text-red-400 hover:text-red-600 border border-red-200 rounded-lg px-2 py-1">
          ${isAdmin ? '🗑 Elimina' : '↩ Abbandona'}
        </button>` : '';

      return `
        <div class="bg-white rounded-xl border border-gray-100 p-4 flex items-center gap-3">
          <div class="w-10 h-10 rounded-full flex items-center justify-center text-xl flex-shrink-0"
               style="background:${c.colore || '#4f46e5'}22">🏠</div>
          <div class="flex-1 min-w-0">
            <div class="flex items-center gap-1 flex-wrap">
              <span class="font-semibold text-gray-800">${esc(c.nome)}</span>
              ${badgeRuolo}${badgePersonale}${badgeAttiva}${badgeDefault}
            </div>
            <p class="text-xs text-gray-400 mt-0.5">ID: ${c.id}</p>
          </div>
          <div class="flex items-center gap-1.5 flex-wrap justify-end flex-shrink-0">
            ${btnSeleziona}${btnDefault}${btnRinomina}${btnElimina}
          </div>
        </div>`;
    }).join('');

  } catch (e) {
    el.innerHTML = `<div class="text-red-600 text-sm py-4 text-center">Errore: ${esc(e.message)}</div>`;
  }
}

// ── Modal rinomina casa ──────────────────────────────────────
function apriModalRinomina(id, nome) {
  nascondiErr('rinominaErrore');
  document.getElementById('rinominaCasaId').value  = id;
  document.getElementById('rinominaCasaNome').value = nome;
  apriModal('modalRinominaCasa');
}

async function salvaRinominaCasa() {
  nascondiErr('rinominaErrore');
  const id   = document.getElementById('rinominaCasaId').value;
  const nome = document.getElementById('rinominaCasaNome').value.trim();
  if (!nome) { mostraErr('rinominaErrore', 'Nome obbligatorio.'); return; }

  // Temporaneamente imposta la casa da rinominare come attiva per la chiamata API
  const casaOriginale = A.casaAttiva;
  const casaDaRinominare = A.case.find(c => String(c.id) === String(id));

  try {
    // Se la casa da rinominare non è quella attiva, facciamo la chiamata con casa_id nell'header
    // Il backend legge casa_id da sessione, quindi cambiamo temporaneamente
    if (String(id) !== String(A.casaAttiva?.id)) {
      await api('/api/auth.php?azione=cambia_casa', {
        method: 'POST',
        body: JSON.stringify({ casa_id: id })
      });
    }

    await api('/api/admin.php?risorsa=casa', {
      method: 'PUT',
      body: JSON.stringify({ nome, csrf_token: A.csrfToken })
    });

    // Ripristina casa attiva se l'avevamo cambiata
    if (String(id) !== String(casaOriginale?.id)) {
      await api('/api/auth.php?azione=cambia_casa', {
        method: 'POST',
        body: JSON.stringify({ casa_id: casaOriginale.id })
      });
    }

    chiudi('modalRinominaCasa');

    // Aggiorna nome in memoria
    if (casaDaRinominare) casaDaRinominare.nome = nome;
    if (String(id) === String(A.casaAttiva?.id)) {
      A.casaAttiva.nome = nome;
      sessionStorage.setItem('casa_attiva', JSON.stringify(A.casaAttiva));
    }

    // Aggiorna select e lista
    const sel = document.getElementById('switchCasa');
    if (sel) {
      Array.from(sel.options).forEach(opt => {
        if (String(opt.value) === String(id)) opt.text = nome;
      });
    }
    caricaListaCase();

  } catch (e) {
    mostraErr('rinominaErrore', e.message);
    // Ripristina casa attiva in caso di errore parziale
    if (String(id) !== String(casaOriginale?.id)) {
      try {
        await api('/api/auth.php?azione=cambia_casa', {
          method: 'POST',
          body: JSON.stringify({ casa_id: casaOriginale.id })
        });
      } catch(_) {}
    }
  }
}

// ── Navigazione tab ──────────────────────────────────────────
function mostraTab(nome) {

  document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.nav-item').forEach(n => {
    n.classList.remove('active');
    n.style.borderBottom = '';
  });

  document.getElementById(`tab-${nome}`).classList.add('active');

  const navEl = document.getElementById(`nav-${nome}`);

  if (navEl) {
    navEl.classList.add('active');
  }

  if (nome === 'case')        caricaListaCase();
  if (nome === 'utenti')      caricaUtenti();
  if (nome === 'categorie')   caricaCategorie();
  if (nome === 'pivot')       caricaPivot();
  if (nome === 'eventi')      caricaEventi();
  if (nome === 'profilo')     mostraTab_profilo();
}


// ── CSRF: recupera sempre un token fresco dal server ─────────
async function csrfFresco() {

  const ora = Date.now();

  if (A.csrfToken && (ora - _csrfTimestamp) < 10 * 60 * 1000) {
    return A.csrfToken;
  }

  try {

    const res = await fetch((window.BASE_URL || '') + '/api/auth.php?azione=csrf', {
      credentials: 'same-origin'
    });

    const data = await res.json();

    if (data.successo) {
      A.csrfToken = data.dati.csrf_token;
      _csrfTimestamp = ora;
      sessionStorage.setItem('csrf_token', A.csrfToken);
    }

  } catch (_) {}

  return A.csrfToken;
}

// ── API helper ───────────────────────────────────────────────
async function api(url, opzioni = {}) {

  try {

    const metodo = (opzioni.method || 'GET').toUpperCase();

    if (['POST', 'PUT', 'DELETE'].includes(metodo) && opzioni.body) {

      const token = await csrfFresco();

      let bodyData = JSON.parse(opzioni.body);
      bodyData.csrf_token = token;

      opzioni = {
        ...opzioni,
        body: JSON.stringify(bodyData)
      };
    }

    const res = await fetch((window.BASE_URL || '') + url, {
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        ...(opzioni.headers || {})
      },
      ...opzioni,
    });

    const data = await res.json();

    if (!data.successo) {

      if (res.status === 401) {
        sessionStorage.clear();
        window.location.href = (window.BASE_URL || '') + '/index.html';
      }

      const err = new Error(data.errore || 'Errore sconosciuto');
      err.status = res.status;
      throw err;
    }

    return data.dati;

  } catch (e) {

    if (e instanceof SyntaxError) {
      throw new Error('Risposta non valida dal server.');
    }

    throw e;
  }
}

// Corretto: Inserito all'interno di una funzione async per risolvere l'errore SyntaxError
async function logout() {

  try {
    await api('/api/auth.php?azione=logout', {
      method: 'POST',
      body: JSON.stringify({})
    });
  } catch (_) {}

  sessionStorage.clear();

  window.location.href = (window.BASE_URL || '') + '/index.html';
}

function chiudi(id) {
  document.getElementById(id).classList.add('hidden');
  document.body.style.overflow = '';
}
function apriModal(id) {
  document.getElementById(id).classList.remove('hidden');
  document.body.style.overflow = 'hidden';
}
function mostraErr(id, msg) {
  const el = document.getElementById(id);
  el.textContent = msg;
  el.classList.remove('hidden');
}
function nascondiErr(id) {
  document.getElementById(id).classList.add('hidden');
}
function esc(s) {
  // I dati degli utenti non vanno tradotti dal motore i18n
  if (window.I18N && s != null && s !== '') I18N.datoUtente(s);
  return String(s || '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

// ── Modal nuova casa ─────────────────────────────────────────
function apriModalNuovaCasa() {
  nascondiErr('nuovaCasaErrore');
  document.getElementById('nuovaCasaNome').value   = '';
  document.getElementById('nuovaCasaColore').value = '#4f46e5';
  apriModal('modalNuovaCasa');
}

async function salvaNuovaCasa() {
  nascondiErr('nuovaCasaErrore');
  const nome   = document.getElementById('nuovaCasaNome').value.trim();
  const colore = document.getElementById('nuovaCasaColore').value;
  if (!nome) { mostraErr('nuovaCasaErrore', 'Nome obbligatorio.'); return; }

  try {
    await api('/api/admin.php?risorsa=case', {
      method: 'POST',
      body: JSON.stringify({ nome, colore, csrf_token: A.csrfToken })
    });
    chiudi('modalNuovaCasa');
    // Ricarica tutte le case e aggiorna UI
    const dati = await api('/api/admin.php?risorsa=casa');
    A.case = dati.case || [];
    aggiornaSelectCasa();
    caricaListaCase();
  } catch (e) {
    mostraErr('nuovaCasaErrore', e.message);
  }
}

// ── Imposta casa default ─────────────────────────────────────
async function impostaCasaDefault(casaId) {
  try {
    await api('/api/admin.php?risorsa=casa&azione=default', {
      method: 'PUT',
      body: JSON.stringify({ casa_id: casaId, csrf_token: A.csrfToken })
    });
    // Aggiorna in memoria
    A.case.forEach(c => { c.casa_default = String(c.id) === String(casaId) ? 1 : 0; });
    caricaListaCase();
  } catch (e) {
    alert('Errore: ' + e.message);
  }
}

// ── Elimina / Abbandona casa ─────────────────────────────────
function apriModalElimina(casaId, nome, isAdmin) {
  nascondiErr('eliminaCasaErrore');
  document.getElementById('eliminaCasaId').value = casaId;
  document.getElementById('eliminaCasaWarning').classList.add('hidden');

  const titolo = document.getElementById('eliminaCasaTitolo');
  const testo  = document.getElementById('eliminaCasaTesto');
  const btnConf = document.getElementById('btnConfermaElimina');

  if (isAdmin) {
    // Conta quanti admin/membri ci sono
    titolo.textContent = 'Elimina / Abbandona casa';
    testo.textContent  = `Vuoi abbandonare o eliminare la casa "${nome}"? Se sei l'unico admin ti verrà chiesto di assegnare un nuovo admin o di eliminare tutto.`;
    btnConf.textContent = 'Procedi';
    btnConf.className   = 'w-full bg-red-600 text-white font-semibold py-3 rounded-xl text-sm';
  } else {
    titolo.textContent = 'Abbandona casa';
    testo.textContent  = `Vuoi abbandonare la casa "${nome}"? Potrai rientrare solo tramite un nuovo invito.`;
    btnConf.textContent = 'Abbandona';
    btnConf.className   = 'w-full bg-red-600 text-white font-semibold py-3 rounded-xl text-sm';
  }

  apriModal('modalEliminaCasa');
}

async function confermaEliminaCasa() {
  nascondiErr('eliminaCasaErrore');
  const casaId = document.getElementById('eliminaCasaId').value;

  try {
    const dati = await api(`/api/admin.php?risorsa=casa&id=${casaId}`, {
      method: 'DELETE',
      body: JSON.stringify({ csrf_token: A.csrfToken })
    });

    chiudi('modalEliminaCasa');

    if (dati.azione === 'eliminata' || dati.azione === 'abbandonato') {
      // Rimuovi dalla lista in memoria
      A.case = A.case.filter(c => String(c.id) !== String(casaId));

      // Se era la casa attiva, switcha alla prima disponibile
      if (String(casaId) === String(A.casaAttiva?.id) && A.case.length) {
        await cambiaCasa(A.case[0].id);
        // cambiaCasa aggiorna già il select e ricarica il tab, aggiorniamo anche la lista case
        caricaListaCase();
        return;
      }

      aggiornaSelectCasa();
      caricaListaCase();
    }

  } catch (e) {
    if (e.status === 409) {
      // Unico admin con altri membri: apri modal assegna admin
      chiudi('modalEliminaCasa');
      await apriModalAssegnaAdmin(casaId);
    } else {
      mostraErr('eliminaCasaErrore', e.message);
    }
  }
}

// ── Assegna nuovo admin ──────────────────────────────────────
async function apriModalAssegnaAdmin(casaId) {
  nascondiErr('assegnaAdminErrore');
  document.getElementById('assegnaAdminCasaId').value = casaId;

  // Carica i membri della casa (temporaneamente switcha se necessario)
  try {
    // Carica membri della casa da abbandonare
    let membri;
    if (String(casaId) !== String(A.casaAttiva?.id)) {
      await api('/api/auth.php?azione=cambia_casa', {
        method: 'POST', body: JSON.stringify({ casa_id: casaId })
      });
    }
    const dati = await api('/api/admin.php?risorsa=membri');
    if (String(casaId) !== String(A.casaAttiva?.id)) {
      await api('/api/auth.php?azione=cambia_casa', {
        method: 'POST', body: JSON.stringify({ casa_id: A.casaAttiva?.id })
      });
    }
    membri = (dati.membri || []).filter(m => String(m.id) !== String(A.utente.id));

    const sel = document.getElementById('assegnaAdminSelect');
    sel.innerHTML = membri.map(m =>
      `<option value="${m.id}">${esc(m.nome)} (${esc(m.email)})</option>`
    ).join('');

    apriModal('modalAssegnaAdmin');
  } catch (e) {
    alert('Errore nel caricamento membri: ' + e.message);
  }
}

async function confermaAssegnaAdmin() {
  nascondiErr('assegnaAdminErrore');
  const casaId      = document.getElementById('assegnaAdminCasaId').value;
  const nuovoAdminId = document.getElementById('assegnaAdminSelect').value;
  if (!nuovoAdminId) { mostraErr('assegnaAdminErrore', 'Seleziona un membro.'); return; }

  try {
    // Switcha alla casa da abbandonare se non è quella attiva
    if (String(casaId) !== String(A.casaAttiva?.id)) {
      await api('/api/auth.php?azione=cambia_casa', {
        method: 'POST', body: JSON.stringify({ casa_id: casaId })
      });
    }

    // Assegna admin
    await api('/api/admin.php?risorsa=casa&azione=assegna_admin', {
      method: 'PUT',
      body: JSON.stringify({ utente_id: parseInt(nuovoAdminId), csrf_token: A.csrfToken })
    });

    // Ora abbandona
    await api(`/api/admin.php?risorsa=casa&id=${casaId}`, {
      method: 'DELETE',
      body: JSON.stringify({ csrf_token: A.csrfToken })
    });

    // Ripristina casa attiva originale se diversa
    if (String(casaId) === String(A.casaAttiva?.id) && A.case.length) {
      const altra = A.case.find(c => String(c.id) !== String(casaId));
      if (altra) {
        await api('/api/auth.php?azione=cambia_casa', {
          method: 'POST', body: JSON.stringify({ casa_id: altra.id })
        });
      }
    }

    chiudi('modalAssegnaAdmin');
    location.reload();

  } catch (e) {
    mostraErr('assegnaAdminErrore', e.message);
    // Ripristina casa attiva in caso di errore
    if (String(casaId) !== String(A.casaAttiva?.id)) {
      try {
        await api('/api/auth.php?azione=cambia_casa', {
          method: 'POST', body: JSON.stringify({ casa_id: A.casaAttiva?.id })
        });
      } catch(_) {}
    }
  }
}

// ── Helper: aggiorna select casa senza reload ────────────────
function aggiornaSelectCasa() {
  const sel = document.getElementById('switchCasa');
  if (!sel) return;
  sel.innerHTML = A.case.map(c => `
    <option value="${c.id}" ${String(c.id) === String(A.casaAttiva?.id) ? 'selected' : ''}>
      ${esc(c.nome)}
    </option>`).join('');
}

// ============================================================
// PROFILO UTENTE
// ============================================================
function mostraTab_profilo() {
  // Precompila il nome corrente
  const nomeEl = document.getElementById('profiloNome');
  if (nomeEl && A.utente?.nome) nomeEl.value = A.utente.nome;
  inizializzaPreferenze();
}

// ── Lingua e formati (preferenze del singolo utente) ─────────
function _esempioData() {
  const oggi = new Date();
  const p = n => String(n).padStart(2, '0');
  return `${oggi.getFullYear()}-${p(oggi.getMonth() + 1)}-${p(oggi.getDate())}`;
}

function aggiornaAnteprimaPref() {
  const v = document.getElementById('prefValuta').value;
  const n = document.getElementById('prefFormatoNumero').value;
  const d = document.getElementById('prefFormatoData').value;
  const g = _esempioData().split('-');
  const data = d.replace('yyyy', g[0]).replace('mm', g[1]).replace('dd', g[2]);
  document.getElementById('prefAnteprima').textContent = `${I18N.fmtImportoCon(1234.56, v, n)} · ${data}`;
}

function inizializzaPreferenze() {
  const selL = document.getElementById('prefLingua');
  if (!selL) return;
  const selV = document.getElementById('prefValuta');
  const selN = document.getElementById('prefFormatoNumero');
  const selD = document.getElementById('prefFormatoData');
  if (!selL.options.length) {
    selL.innerHTML = Object.entries(I18N.LINGUE).map(([k, v]) => `<option value="${k}">${v}</option>`).join('');
    selV.innerHTML = I18N.VALUTE.map(c => `<option value="${c}">${c}</option>`).join('');
    selN.innerHTML = I18N.FORMATI_NUMERO.map(f => `<option value="${f}">${f}</option>`).join('');
    selD.innerHTML = I18N.FORMATI_DATA.map(f => `<option value="${f}">${f}</option>`).join('');
    [selV, selN, selD].forEach(el => el.addEventListener('change', aggiornaAnteprimaPref));
    // Cambiando lingua propone valuta e formati tipici di quella lingua
    selL.addEventListener('change', ripristinaPrefLingua);
  }
  const p = I18N.prefs;
  selL.value = p.lingua; selV.value = p.valuta; selN.value = p.formato_numero; selD.value = p.formato_data;
  aggiornaAnteprimaPref();
}

function ripristinaPrefLingua() {
  const lingua = document.getElementById('prefLingua').value;
  const loc    = I18N.localeBrowser();
  const def    = I18N.predefinite(lingua, loc.slice(0, 2).toLowerCase() === lingua ? loc : '');
  document.getElementById('prefValuta').value        = def.valuta;
  document.getElementById('prefFormatoNumero').value = def.formato_numero;
  document.getElementById('prefFormatoData').value   = def.formato_data;
  aggiornaAnteprimaPref();
}

async function salvaPreferenze() {
  nascondiErr('profiloPrefErrore');
  const pref = {
    lingua:         document.getElementById('prefLingua').value,
    valuta:         document.getElementById('prefValuta').value,
    formato_numero: document.getElementById('prefFormatoNumero').value,
    formato_data:   document.getElementById('prefFormatoData').value,
  };
  try {
    const dati = await api('/api/auth.php?azione=preferenze', {
      method: 'PUT',
      body: JSON.stringify({ ...pref, csrf_token: A.csrfToken }),
    });
    if (A.utente) {
      Object.assign(A.utente, pref);
      sessionStorage.setItem('utente', JSON.stringify(A.utente));
    }
    // Ricarica la pagina con la nuova lingua e i nuovi formati
    I18N.impostaPreferenze(dati || pref, true);
  } catch (e) {
    mostraErr('profiloPrefErrore', e.message);
  }
}

async function salvaNome() {
  nascondiErr('profiloNomeErrore');
  document.getElementById('profiloNomeSuccesso').classList.add('hidden');
  const nome = document.getElementById('profiloNome').value.trim();
  if (!nome) { mostraErr('profiloNomeErrore', 'Nome obbligatorio.'); return; }

  try {
    const dati = await api('/api/auth.php?azione=aggiorna_profilo', {
      method: 'PUT',
      body: JSON.stringify({ nome, csrf_token: A.csrfToken })
    });
    A.utente.nome = dati.nome || nome;
    sessionStorage.setItem('utente', JSON.stringify(A.utente));
    document.getElementById('profiloNomeSuccesso').textContent = 'Nome aggiornato!';
    document.getElementById('profiloNomeSuccesso').classList.remove('hidden');
  } catch (e) {
    mostraErr('profiloNomeErrore', e.message);
  }
}

async function salvaEmail() {
  nascondiErr('profiloEmailErrore');
  document.getElementById('profiloEmailSuccesso').classList.add('hidden');
  const email    = document.getElementById('profiloEmail').value.trim();
  const password = document.getElementById('profiloEmailPassword').value;
  if (!email)    { mostraErr('profiloEmailErrore', 'Email obbligatoria.'); return; }
  if (!password) { mostraErr('profiloEmailErrore', 'Password obbligatoria.'); return; }

  try {
    const dati = await api('/api/auth.php?azione=cambia_email', {
      method: 'PUT',
      body: JSON.stringify({ email, password, csrf_token: A.csrfToken })
    });
    A.utente.email = dati.email || email;
    sessionStorage.setItem('utente', JSON.stringify(A.utente));
    document.getElementById('profiloEmailPassword').value = '';
    document.getElementById('profiloEmailSuccesso').textContent = 'Email aggiornata!';
    document.getElementById('profiloEmailSuccesso').classList.remove('hidden');
  } catch (e) {
    mostraErr('profiloEmailErrore', e.message);
  }
}

async function salvaPassword() {
  nascondiErr('profiloPassErrore');
  document.getElementById('profiloPassSuccesso').classList.add('hidden');
  const vecchia  = document.getElementById('profiloPassVecchia').value;
  const nuova    = document.getElementById('profiloPassNuova').value;
  const conferma = document.getElementById('profiloPassConferma').value;
  if (!vecchia || !nuova) { mostraErr('profiloPassErrore', 'Compila tutti i campi.'); return; }

  try {
    await api('/api/auth.php?azione=cambia_password', {
      method: 'POST',
      body: JSON.stringify({
        password_vecchia: vecchia,
        password_nuova:   nuova,
        password_conferma: conferma,
        csrf_token: A.csrfToken
      })
    });
    document.getElementById('profiloPassVecchia').value  = '';
    document.getElementById('profiloPassNuova').value    = '';
    document.getElementById('profiloPassConferma').value = '';
    document.getElementById('profiloPassSuccesso').textContent = 'Password aggiornata!';
    document.getElementById('profiloPassSuccesso').classList.remove('hidden');
  } catch (e) {
    mostraErr('profiloPassErrore', e.message);
  }
}
async function eliminaAccount() {
  nascondiErr('profiloEliminaErrore');
  const password = document.getElementById('profiloEliminaPassword').value;
  const conferma = document.getElementById('profiloEliminaConferma').value.trim();
  if (!password)               { mostraErr('profiloEliminaErrore', 'Password obbligatoria.'); return; }
  if (conferma !== 'ELIMINA')  { mostraErr('profiloEliminaErrore', 'Scrivi ELIMINA per confermare.'); return; }
  if (!confirm('Vuoi eliminare definitivamente il tuo account? L\'operazione non si può annullare.')) return;

  const btn = document.getElementById('btnEliminaAccount');
  btn.disabled = true;
  try {
    await api('/api/auth.php?azione=elimina_account', {
      method: 'POST',
      body: JSON.stringify({ password, conferma, csrf_token: A.csrfToken })
    });
    sessionStorage.clear();
    alert('Account eliminato definitivamente.');
    window.location.href = (window.BASE_URL || '') + '/index.html';
  } catch (e) {
    btn.disabled = false;
    mostraErr('profiloEliminaErrore', e.message);
  }
}

async function caricaUtenti() {
  const el = document.getElementById('listaUtenti');
  if (!el) return;
  el.innerHTML = '<div class="text-gray-400 text-sm py-4 text-center">Caricamento…</div>';

  const isAdmin = A.ruolo === 'admin';

  try {
    const dati = await api('/api/admin.php?risorsa=membri');
    if (!dati?.membri?.length) {
      el.innerHTML = '<div class="text-gray-400 text-sm py-4 text-center">Nessun membro.</div>';
    } else {
      el.innerHTML = dati.membri.map(u => {
        const isSelf = String(u.id) === String(A.utente.id);
        const badgeRuolo = u.ruolo === 'admin'
          ? '<span class="badge badge-indigo text-xs px-2 py-1 rounded-full">admin</span>'
          : '<span class="badge badge-gray text-xs px-2 py-1 rounded-full">membro</span>';

        // Selettore cambio ruolo (solo admin, non su se stesso)
        const selectorRuolo = (isAdmin && !isSelf) ? `
          <select
            data-action="cambia-ruolo"
            data-utente-id="${esc(u.id)}"
            class="text-xs border border-gray-200 rounded-lg px-2 py-1 bg-white">
            <option value="membro" ${u.ruolo === 'membro' ? 'selected' : ''}>membro</option>
            <option value="admin"  ${u.ruolo === 'admin'  ? 'selected' : ''}>admin</option>
          </select>` : '';

        // Bottone rimuovi (solo admin, non su se stesso)
        const btnRimuovi = (isAdmin && !isSelf) ? `
          <button
            type="button"
            class="p-2 rounded hover:bg-red-50 text-red-500 text-xs"
            data-action="elimina"
            data-utente-id="${esc(u.id)}"
            title="Rimuovi dalla casa">✕</button>` : '';

        return `
          <div class="utente-card flex items-center gap-3 p-3 border-b border-gray-50 last:border-0">
            <div class="w-9 h-9 rounded-full bg-indigo-100 text-indigo-700 font-bold flex items-center justify-center flex-shrink-0 text-sm">
              ${esc(u.nome.charAt(0).toUpperCase())}
            </div>
            <div class="flex-1 min-w-0">
              <div class="font-semibold text-sm text-gray-800 truncate">${esc(u.nome)}</div>
              <div class="text-xs text-gray-400 truncate">${esc(u.email)}</div>
            </div>
            ${badgeRuolo}
            ${selectorRuolo}
            ${btnRimuovi}
          </div>`;
      }).join('');
    }

    if (!el._utentiBound) {
      el.addEventListener('click', async (ev) => {
        const btn = ev.target.closest('button[data-action][data-utente-id]');
        if (!btn) return;
        ev.preventDefault();
        if (btn.dataset.action === 'elimina') eliminaUtente(btn.dataset.utenteId);
      });

      el.addEventListener('change', async (ev) => {
        const sel = ev.target.closest('select[data-action="cambia-ruolo"]');
        if (!sel) return;
        await cambiaRuoloMembro(sel.dataset.utenteId, sel.value);
      });

      el._utentiBound = true;
    }

  } catch (e) {
    el.innerHTML = `<div class="text-red-600 text-sm py-4 text-center">Errore: ${esc(e.message)}</div>`;
  }

  // Carica inviti in attesa (solo admin)
  if (isAdmin) {
    await caricaInvitiPendenti();
  } else {
    const sez = document.getElementById('sezioneInviti');
    if (sez) sez.classList.add('hidden');
  }
}

async function cambiaRuoloMembro(id, nuovoRuolo) {
  try {
    await api(`/api/admin.php?risorsa=membri&id=${encodeURIComponent(id)}`, {
      method: 'PUT',
      body: JSON.stringify({ ruolo: nuovoRuolo, csrf_token: A.csrfToken })
    });
  } catch (e) {
    alert('Errore cambio ruolo: ' + e.message);
    caricaUtenti(); // ricarica per ripristinare il valore corretto
  }
}

// ── Inviti in attesa ─────────────────────────────────────────
async function caricaInvitiPendenti() {
  const sez = document.getElementById('sezioneInviti');
  const el  = document.getElementById('listaInvitiPendenti');
  if (!sez || !el) return;

  try {
    const dati = await api('/api/admin.php?risorsa=inviti');
    const inviti = (dati.inviti || []).filter(i => !i.usato);

    if (!inviti.length) {
      sez.classList.add('hidden');
      return;
    }

    sez.classList.remove('hidden');
    el.innerHTML = inviti.map(i => {
      const scaduto = new Date(i.scade_il) < new Date();
      return `
        <div class="flex items-center gap-3 p-3 border-b border-gray-50 last:border-0">
          <div class="w-9 h-9 rounded-full bg-yellow-100 text-yellow-700 font-bold flex items-center justify-center flex-shrink-0 text-sm">
            ✉
          </div>
          <div class="flex-1 min-w-0">
            <div class="font-semibold text-sm text-gray-800 truncate">${esc(i.email_invitato || '— link generico —')}</div>
            <div class="text-xs text-gray-400">
              Ruolo: ${esc(i.ruolo)} ·
              Scade: ${formatData(i.scade_il)}
              ${scaduto ? '<span class="text-red-500 font-medium ml-1">⚠ scaduto</span>' : ''}
            </div>
          </div>
          <button
            type="button"
            onclick="revocaInvito(${i.id})"
            class="text-xs text-red-400 hover:text-red-600 border border-red-200 rounded-lg px-2 py-1 flex-shrink-0">
            Revoca
          </button>
        </div>`;
    }).join('');

  } catch (e) {
    sez.classList.add('hidden');
  }
}

async function revocaInvito(id) {
  if (!confirm('Revocare questo invito?')) return;
  try {
    await api(`/api/admin.php?risorsa=inviti&id=${encodeURIComponent(id)}`, { method: 'DELETE' });
    await caricaInvitiPendenti();
  } catch (e) {
    alert('Errore: ' + e.message);
  }
}


function apriModalUtente() {

  nascondiErr('utenteErrore');

  document.getElementById('utenteEmail').value = '';
  document.getElementById('utenteRuolo').value = 'membro';

  apriModal('modalUtente');
}

async function modificaUtente(id) {
	try {
        const dati = await api(`/api/admin.php?risorsa=membri&id=${encodeURIComponent(id)}`);
        if (!dati || !dati.utente) {
            alert('Utente non trovato.');
            return;
        }
        apriModalUtente(dati.utente);
    } catch (e) {
        console.error('modificaUtente error:', e);
        alert('Errore: ' + e.message);
    }
}

async function salvaUtente() {

  nascondiErr('utenteErrore');

  const email = document.getElementById('utenteEmail').value.trim();
  const ruolo = document.getElementById('utenteRuolo').value;

  if (!email) {
    mostraErr('utenteErrore', 'Email obbligatoria');
    return;
  }

  try {

    const dati = await api('/api/admin.php?risorsa=inviti', {
      method: 'POST',
      body: JSON.stringify({
        email,
        ruolo
      })
    });

    chiudi('modalUtente');

    if (dati.link_invito) {
      prompt('Link invito generato:', dati.link_invito);
    }

    caricaUtenti();

  } catch (e) {
    mostraErr('utenteErrore', e.message);
  }
}


async function eliminaUtente(id) {

  if (String(id) === String(A.utente.id)) {
    alert('Non puoi rimuovere te stesso dalla casa.');
    return;
  }

  if (!confirm('Rimuovere questo membro dalla casa?')) {
    return;
  }

  try {

    await api(`/api/admin.php?risorsa=membri&id=${encodeURIComponent(id)}`, {
      method: 'DELETE'
    });

    caricaUtenti();

  } catch (e) {
    alert(e.message);
  }
}

// ============================================================
// 

// ============================================================
async function caricaCategorieLookup() {
  try {
    const dati = await api('/api/categorie.php?risorsa=categorie&includi_disattive=1');
    A.categorie = dati.categorie || [];
  } catch(e) {}
}

async function caricaCategorie() {
  try {
    const dati = await api('/api/categorie.php?risorsa=categorie&includi_disattive=1');
    A.categorie = dati.categorie || [];
    const el      = document.getElementById('listaCategorie');
    const isAdmin = A.ruolo === 'admin';

    el.innerHTML = A.categorie.map(c => {
      // Categoria di sistema EVENTI: gestita dagli eventi, non modificabile
      const btnAzioni = c.sistema ? `
        <span class="text-[11px] text-gray-400" title="Gestita automaticamente dagli eventi">🔒 automatica</span>` : isAdmin ? `
        <button data-cat-mod="${c.id}" class="btn-modifica-cat text-xs text-indigo-500 hover:text-indigo-700 p-1" title="Modifica">✎</button>
        <button data-cat-del="${c.id}" class="btn-elimina-cat text-xs text-red-400 hover:text-red-600 p-1" title="Elimina">✕</button>` : '';

      return `
        <div class="flex items-center px-4 py-3 gap-3 cursor-pointer hover:bg-gray-50 transition-colors cat-row"
             data-cat-id="${c.id}" data-cat-nome="${esc(I18N.nomeCategoria(c.nome))}" title="Clicca per vedere le sottocategorie">
          <div class="w-4 h-4 rounded-full flex-shrink-0" style="background:${esc(c.colore)}"></div>
          <div class="flex-1 min-w-0">
            <p class="text-sm font-medium text-gray-800">${esc(I18N.nomeCategoria(c.nome))}</p>
            <p class="text-xs text-gray-400">${c.sistema ? 'Una sottocategoria per ogni evento · visibile solo con eventi attivi' : `Tipo: ${c.tipo} · Ordine: ${c.ordinamento}`}</p>
          </div>
          <div class="flex items-center gap-2">
            <span class="badge ${c.tipo === 'accredito' ? 'badge-green' : 'badge-red'}">${c.tipo}</span>
            <span class="badge ${c.attiva ? 'badge-green' : 'badge-gray'}">${c.attiva ? 'Attiva' : 'Inattiva'}</span>
            <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5 text-gray-300 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
            </svg>
            ${btnAzioni}
          </div>
        </div>`;
    }).join('');

    // Click su riga → apre pannello sottocategorie
    el.querySelectorAll('.cat-row').forEach(row => {
      row.addEventListener('click', (e) => {
        // Ignora se il click è su un pulsante azione
        if (e.target.closest('button')) return;
        apriPannelloSottocategorie(parseInt(row.dataset.catId), row.dataset.catNome);
      });
    });

    // Pulsanti modifica/elimina categoria (solo admin)
    el.querySelectorAll('.btn-modifica-cat').forEach(btn => {
      btn.addEventListener('click', (e) => { e.stopPropagation(); modificaCategoria(btn.dataset.catMod); });
    });
    el.querySelectorAll('.btn-elimina-cat').forEach(btn => {
      btn.addEventListener('click', (e) => { e.stopPropagation(); eliminaCategoria(btn.dataset.catDel); });
    });

    // Chiudi il pannello sottocategorie solo se non c'è una categoria selezionata
    if (!A._catSelezionata) chiudiPannelloSottocategorie();

  } catch(e) { console.error(e); }
}

function apriModalCategoria(cat = null) {
  nascondiErr('catErrore');
  document.getElementById('modalCatTitolo').textContent = cat ? 'Modifica categoria' : 'Nuova categoria';
  document.getElementById('catId').value          = cat?.id || '';
  document.getElementById('catNome').value        = cat?.nome || '';
  document.getElementById('catTipo').value        = cat?.tipo || 'uscita';
  document.getElementById('catColore').value      = cat?.colore || '#6366f1';
  document.getElementById('catOrdinamento').value = cat?.ordinamento ?? 0;
  document.getElementById('catAttiva').checked    = cat ? !!cat.attiva : true;
  apriModal('modalCategoria');
}

async function modificaCategoria(id) {
  const cat = A.categorie.find(c => c.id == id);
  if (cat) apriModalCategoria(cat);
}

async function salvaCategoria() {
  nascondiErr('catErrore');
  const id   = document.getElementById('catId').value;
  const nome = document.getElementById('catNome').value.trim();
  if (!nome) { mostraErr('catErrore', 'Nome obbligatorio.'); return; }
  const payload = {
    nome,
    tipo:        document.getElementById('catTipo').value,
    colore:      document.getElementById('catColore').value,
    ordinamento: parseInt(document.getElementById('catOrdinamento').value) || 0,
    attiva:      document.getElementById('catAttiva').checked,
    csrf_token:  A.csrfToken,
  };
  try {
    await api(
      id ? `/api/categorie.php?risorsa=categorie&id=${id}` : '/api/categorie.php?risorsa=categorie',
      { method: id ? 'PUT' : 'POST', body: JSON.stringify(payload) }
    );
    chiudi('modalCategoria');
    caricaCategorie();
  } catch(e) { mostraErr('catErrore', e.message); }
}

async function eliminaCategoria(id) {
  if (!confirm('Eliminare questa categoria?')) return;
  try {
    // Corretto template literal
    await api(`/api/categorie.php?risorsa=categorie&id=${id}`, { method: 'DELETE' });
    caricaCategorie();
  } catch(e) { alert(e.message); }
}

// ── Pannello sottocategorie (drill-down) ─────────────────────

// Tiene traccia della categoria selezionata
A._catSelezionata = null;

function apriPannelloSottocategorie(catId, catNome) {
  A._catSelezionata = catId;
  document.getElementById('scNomeCatPadre').textContent = catNome;

  document.getElementById('pannelloSottocategorie').classList.remove('hidden');
  document.getElementById('pannelloSottocategorie').classList.add('slide-up');

  caricaSottocategorie(catId);
}

function chiudiPannelloSottocategorie() {
  A._catSelezionata = null;
  document.getElementById('pannelloSottocategorie').classList.add('hidden');
  document.getElementById('listaSottocategorie').innerHTML = '';
}

// ── Sottocategorie ───────────────────────────────────────────
A.cacheSottocategorie = [];
async function caricaSottocategorie(catId = null) {
  const el = document.getElementById('listaSottocategorie');
  if (!el) return;
  el.innerHTML = '<div class="text-gray-400 text-sm py-4 text-center">Caricamento…</div>';

  const catSistema = !!(A.categorie || []).find(c => c.id == catId)?.sistema;
  const isAdmin    = A.ruolo === 'admin' && !catSistema;
  const btnNuova   = document.getElementById('btnNuovaSottocategoria');
  if (btnNuova) btnNuova.classList.toggle('hidden', A.ruolo !== 'admin' || catSistema);

  try {
    const url  = catId
      ? `/api/categorie.php?risorsa=sottocategorie&includi_disattive=1&id_categoria=${catId}`
      : '/api/categorie.php?risorsa=sottocategorie&includi_disattive=1';
    const dati = await api(url);
    A.cacheSottocategorie = dati.sottocategorie || [];

    if (!A.cacheSottocategorie.length) {
      el.innerHTML = '<div class="text-gray-400 text-sm py-6 text-center">Nessuna sottocategoria. ' +
        (isAdmin ? 'Clicca "+ Nuova" per aggiungerne una.' : '') +
        (catSistema ? 'Crea un evento per aggiungerne una.' : '') + '</div>';
      return;
    }

    el.innerHTML = A.cacheSottocategorie.map(s => {
      const btnAzioni = isAdmin ? `
        <button data-sc-id="${s.id}" class="btn-modifica-sc text-xs text-indigo-500 hover:text-indigo-700 p-1" title="Modifica">✎</button>
        <button data-sc-del="${s.id}" class="btn-elimina-sc text-xs text-red-400 hover:text-red-600 p-1" title="Elimina">✕</button>` : '';

      return `
        <div class="flex items-center px-4 py-3 gap-3">
          <div class="flex-1 min-w-0">
            <p class="text-sm font-medium text-gray-800">${esc(s.nome)}</p>
            <p class="text-xs text-gray-400">${catSistema ? '🔒 Gestita dall\'evento' : `Ordine: ${s.ordinamento}`}</p>
          </div>
          <div class="flex items-center gap-2">
            <span class="badge ${s.attiva ? 'badge-green' : 'badge-gray'}">${s.attiva ? 'Attiva' : 'Inattiva'}</span>
            ${btnAzioni}
          </div>
        </div>`;
    }).join('');

    el.querySelectorAll('.btn-modifica-sc').forEach(btn => {
      btn.addEventListener('click', () => {
        const sc = A.cacheSottocategorie.find(s => s.id == btn.dataset.scId);
        if (sc) apriModalSottocategoria(sc);
      });
    });
    el.querySelectorAll('.btn-elimina-sc').forEach(btn => {
      btn.addEventListener('click', () => eliminaSottocategoria(btn.dataset.scDel));
    });
  } catch(e) {
    el.innerHTML = `<div class="text-red-600 text-sm py-4 text-center">Errore: ${esc(e.message)}</div>`;
  }
}

async function apriModalSottocategoria(sc = null) {
  nascondiErr('scErrore');

  // Guardia: ricarica le categorie se il lookup è vuoto
  if (!A.categorie.length) await caricaCategorieLookup();

  document.getElementById('modalScTitolo').textContent = sc ? 'Modifica sottocategoria' : 'Nuova sottocategoria';
  document.getElementById('scId').value          = sc?.id || '';
  document.getElementById('scNome').value        = sc?.nome || '';
  document.getElementById('scOrdinamento').value = sc?.ordinamento ?? 0;
  document.getElementById('scAttiva').checked    = sc ? !!sc.attiva : true;

  // Popola select categorie
  const selCat = document.getElementById('scCategoria');
  selCat.innerHTML = A.categorie.map(c => `<option value="${c.id}">${esc(c.nome)}</option>`).join('');

  // Preseleziona: se modifica usa id_categoria, altrimenti usa la categoria del pannello aperto
  if (sc) {
    selCat.value = sc.id_categoria;
  } else if (A._catSelezionata) {
    selCat.value = A._catSelezionata;
  }

  apriModal('modalSottocategoria');
}

async function salvaSottocategoria() {
  nascondiErr('scErrore');
  const id      = document.getElementById('scId').value;
  const nome    = document.getElementById('scNome').value.trim();
  const catId   = parseInt(document.getElementById('scCategoria').value);
  if (!nome) { mostraErr('scErrore', 'Nome obbligatorio.'); return; }
  const payload = {
    id_categoria: catId,
    nome,
    ordinamento:  parseInt(document.getElementById('scOrdinamento').value) || 0,
    attiva:       document.getElementById('scAttiva').checked,
    csrf_token:   A.csrfToken,
  };
  try {
    await api(
      id ? `/api/categorie.php?risorsa=sottocategorie&id=${id}` : '/api/categorie.php?risorsa=sottocategorie',
      { method: id ? 'PUT' : 'POST', body: JSON.stringify(payload) }
    );
    chiudi('modalSottocategoria');
    // Ricarica il pannello della categoria corrente
    caricaSottocategorie(A._catSelezionata || catId);
  } catch(e) { mostraErr('scErrore', e.message); }
}

async function eliminaSottocategoria(id) {
  if (!confirm('Eliminare questa sottocategoria?')) return;
  try {
    await api(`/api/categorie.php?risorsa=sottocategorie&id=${id}`, { method: 'DELETE' });
    caricaSottocategorie(A._catSelezionata);
  } catch(e) { alert(e.message); }
}

// ============================================================
// PIVOT
// ============================================================
async function caricaPivot() {	
  try {
    const endpoint = A.ruolo === 'admin' ? '/api/pivot.php?azione=listaADM' : '/api/pivot.php?azione=lista';
    const dati = await api(endpoint);
    const el   = document.getElementById('listaPivotADM');
	if (!el) return; // guard
    const etichette = { categoria:'Categoria', sottocategoria:'Sottocategoria', utente:'Utente', mese:'Mese', anno:'Anno', giorno_settimana:'Giorno', tipo:'Tipo (Entrate/Uscite)',
                        categoria_eventi:'Categoria (eventi raggruppati)', evento:'Evento', voce_evento:'Voce evento',
                        sottocategoria_evento:'Sottocategoria / Evento' };
    const filtriEv  = { esclusi:' · Eventi esclusi', solo:' · Solo eventi' };
    const valori    = { somma:'Somma €', conteggio:'Conteggio', media:'Media €' };
    const filtri    = { tutti:'Tutti', uscita:'Uscite', accredito:'Entrate' };
    if (!dati?.pivot?.length) {
      el.innerHTML = '<p class="text-sm text-gray-400 text-center py-8">Nessuna pivot configurata</p>';
      return;
    }
    el.innerHTML = dati.pivot.map(p => `
      <div class="flex items-center px-4 py-3 gap-3">
        <div class="flex-1 min-w-0">
          <p class="text-sm font-medium text-gray-800">${esc(p.nome)}</p>
          <p class="text-xs text-gray-400">
            Riga: ${etichette[p.riga]||p.riga} · Colonna: ${etichette[p.colonna]||p.colonna} ·
            Valore: ${valori[p.valore]||p.valore} · Filtro: ${filtri[p.filtro_tipo]||p.filtro_tipo}${filtriEv[p.filtro_evento] || ''}
          </p>
        </div>
        <div class="flex items-center gap-2">
          <span class="badge ${p.visibile ? 'badge-green' : 'badge-gray'}">${p.visibile ? 'Visibile' : 'Nascosta'}</span>
          ${A.ruolo === 'admin' ? `
          <button data-pivot-id="${p.id}" class="btn-modifica-pivot text-xs text-indigo-500 hover:text-indigo-700">✎</button>
          <button data-pivot-del="${p.id}" class="btn-elimina-pivot text-xs text-red-400 hover:text-red-600">✕</button>
          ` : ''}
        </div>
      </div>`).join('');
    el.querySelectorAll('.btn-modifica-pivot').forEach(btn => {
      btn.addEventListener('click', () => modificaPivot(btn.dataset.pivotId));
    });
    el.querySelectorAll('.btn-elimina-pivot').forEach(btn => {
      btn.addEventListener('click', () => eliminaPivot(btn.dataset.pivotDel));
    });
  } catch(e) { console.error(e); }
}

function apriModalPivot(pivot = null) {
  nascondiErr('pivotErrore');
  document.getElementById('modalPivotTitolo').textContent  = pivot ? 'Modifica pivot' : 'Nuova pivot';
  document.getElementById('pivotId').value                 = pivot?.id || '';
  document.getElementById('pivotNome').value               = pivot?.nome || '';
  document.getElementById('pivotDesc').value               = pivot?.descrizione || '';
  document.getElementById('pivotRiga').value               = pivot?.riga || 'categoria';
  document.getElementById('pivotRiga2').value = pivot?.riga_2 || '';
  document.getElementById('pivotRiga3').value = pivot?.riga_3 || '';
  document.getElementById('pivotColonna').value            = pivot?.colonna || 'mese';
  document.getElementById('pivotValore').value             = pivot?.valore || 'somma';
  document.getElementById('pivotFiltro').value             = pivot?.filtro_tipo || 'tutti';
  document.getElementById('pivotFiltroEvento').value       = pivot?.filtro_evento || 'tutti';
  document.getElementById('pivotVisibile').checked         = pivot ? !!pivot.visibile : true;
  document.getElementById('pivotOrdinamento').value        = pivot?.ordinamento ?? 0;
  apriModal('modalPivot');
}

async function modificaPivot(id) {
  if (A.ruolo !== 'admin') return;
  try {
    const dati  = await api('/api/pivot.php?azione=listaADM');
    const pivot = dati.pivot?.find(p => p.id == id);
    if (pivot) apriModalPivot(pivot);
  } catch(e) { alert(e.message); }
}

async function salvaPivot() {
  nascondiErr('pivotErrore');
  const id   = document.getElementById('pivotId').value;
  const nome = document.getElementById('pivotNome').value.trim();
  const riga = document.getElementById('pivotRiga').value;
  const col  = document.getElementById('pivotColonna').value;
  if (!nome) { mostraErr('pivotErrore', 'Nome obbligatorio.'); return; }
  if (riga === col) { mostraErr('pivotErrore', 'Riga e colonna non possono essere uguali.'); return; }

  const payload = {
    nome,
    descrizione:  document.getElementById('pivotDesc').value.trim(),
    riga,
	riga_2: document.getElementById('pivotRiga2').value || null,
    riga_3: document.getElementById('pivotRiga3').value || null,
    colonna:      col,
    valore:       document.getElementById('pivotValore').value,
    filtro_tipo:  document.getElementById('pivotFiltro').value,
    filtro_evento: document.getElementById('pivotFiltroEvento').value,
    visibile:     document.getElementById('pivotVisibile').checked,
    ordinamento:  parseInt(document.getElementById('pivotOrdinamento').value) || 0,
    csrf_token:   A.csrfToken,
  };
  const r2 = payload.riga_2, r3 = payload.riga_3;
if (r2 && (r2 === riga || r2 === col)) { mostraErr('pivotErrore', 'Riga livello 2 deve essere diversa.'); return; }
if (r3 && (r3 === riga || r3 === col || r3 === r2)) { mostraErr('pivotErrore', 'Riga livello 3 deve essere diversa.'); return; }
  try {
    await api(
      id ? `/api/pivot.php?azione=aggiorna&id=${id}` : '/api/pivot.php?azione=crea',
      { method: id ? 'PUT' : 'POST', body: JSON.stringify(payload) }
    );
    chiudi('modalPivot');
    caricaPivot();
  } catch(e) { mostraErr('pivotErrore', e.message); }
}

async function eliminaPivot(id) {
  if (!confirm('Eliminare questa configurazione pivot?')) return;
  try {
    // Corretto template literal
    await api(`/api/pivot.php?id=${id}`, { method: 'DELETE' });
    caricaPivot();
  } catch(e) { alert(e.message); }
}

// ── Utility ──────────────────────────────────────────────────
function formatData(s) {
  if (!s) return '—';
  return I18N.fmtData(s, true);
}

// ============================================================
// EVENTI
// ============================================================

// Chiamata da mostraTab('eventi')
async function caricaEventi() {
  const el = document.getElementById('listaEventi');
  if (!el) return;
  el.innerHTML = '<div class="text-gray-500 text-sm py-4">Caricamento…</div>';
  try {
    const dati = await api('/api/eventi.php?action=list');
    if (!dati || !dati.length) {
      el.innerHTML = '<div class="text-gray-400 text-sm py-4 text-center">Nessun evento creato.</div>';
      return;
    }
    el.innerHTML = dati.map(e => {
      const speso   = parseFloat(e.totale_speso   || 0);
      const stimato = parseFloat(e.totale_stimato || 0);
      const rest    = stimato - speso;
      const perc    = stimato > 0 ? Math.min(100, (speso / stimato) * 100).toFixed(1) : 0;
      return `
        <div class="bg-white rounded-xl p-4 border border-gray-100 flex items-center gap-3"
             style="border-left:4px solid ${esc(e.colore || '#4f46e5')}">
          <div class="flex-1 min-w-0">
            <p class="font-semibold text-gray-800">${esc(e.nome)}</p>
            <p class="text-xs text-gray-400 mb-2">${esc(e.descrizione || '')}</p>
            ${typeof renderChipCollegamenti === 'function' ? renderChipCollegamenti(e.collegamenti, e.colore) : ''}
            <div class="flex gap-3 text-xs mb-2">
              <span>🎯 Stimato: <strong>${I18N.fmtImporto(stimato)}</strong></span>
              <span>💸 Speso: <strong>${I18N.fmtImporto(speso)}</strong></span>
              <span class="${rest < 0 ? 'text-red-600' : 'text-green-600'}">
                ${rest < 0 ? '⚠️' : '✅'} Restante: <strong>${I18N.fmtImporto(Math.abs(rest))}</strong>
              </span>
            </div>
            <div class="w-full bg-gray-100 rounded-full h-1.5">
              <div class="h-1.5 rounded-full" style="width:${perc}%;background:${esc(e.colore || '#4f46e5')}"></div>
            </div>
          </div>
          <div class="flex flex-col gap-2 flex-shrink-0">
            ${A.ruolo === 'admin' ? `
            <button data-ev-mod="${e.id}" class="text-xs text-indigo-500 hover:text-indigo-700">✎ Modifica</button>
            <button data-ev-del="${e.id}" class="text-xs text-red-400 hover:text-red-600">✕ Elimina</button>
            ` : ''}
          </div>
        </div>`;
    }).join('');

    el.querySelectorAll('[data-ev-mod]').forEach(btn =>
      btn.addEventListener('click', () => apriModalEvento(parseInt(btn.dataset.evMod)))
    );
    el.querySelectorAll('[data-ev-del]').forEach(btn =>
      btn.addEventListener('click', () => eliminaEvento(parseInt(btn.dataset.evDel)))
    );
  } catch(e) {
    el.innerHTML = `<div class="text-red-600 text-sm py-4">Errore: ${esc(e.message)}</div>`;
  }
}