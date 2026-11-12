// ============================================================
// APP.JS - Logica frontend app utente (multi-casa)
// ============================================================

let _loadingCount = 0;
function mostraLoading() {
    _loadingCount++;
    const el = document.getElementById('appLoading');
    if (el) el.classList.remove('hidden');
}

function nascondiLoading() {
    _loadingCount = Math.max(0, _loadingCount - 1);
    if (_loadingCount === 0) {
        const el = document.getElementById('appLoading');
        if (el) el.classList.add('hidden');
    }
}

// ── Stato globale ────────────────────────────────────────────
const S = {
  utente:         null,
  casaAttiva:     null,   // { id, nome, ruolo, casa_personale }
  csrfToken:      null,
  categorie:      [],
  paginaCorrente: 1,
  graficoTorta:   null,
  graficoBar:     null,
  privataOn:      false,
  spesaEditId:    null,
  ricEditId:      null,
  ricPrivataOn:   false,
  ricAttivaOn:    true,
  initPronto:     false,
  sortBy:         'data',
  sortOrder:      'desc',
};

// ── Helper ruolo ─────────────────────────────────────────────
function isAdmin() {
  return S.casaAttiva?.ruolo === 'admin';
}

// ── Init ─────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', async () => {
  const raw = sessionStorage.getItem('utente');
  if (!raw) { window.location.href = (window.BASE_URL || '') + '/index.html'; return; }
  S.utente      = JSON.parse(raw);
  S.csrfToken   = sessionStorage.getItem('csrf_token');
  _csrfTimestamp = S.csrfToken ? Date.now() : 0;

  // Casa attiva: prima da sessionStorage, poi dal server
  const casaRaw = sessionStorage.getItem('casa_attiva');
  if (casaRaw) {
    try { S.casaAttiva = JSON.parse(casaRaw); } catch(_) {}
  }

  aggiornaNomeCasa();
  document.getElementById('utenteNome').textContent = S.utente.nome;

  // Link admin: visibile solo se admin nella casa corrente
  aggiornaLinkAdmin();

  // Popola select anni
  const annoCorrente = new Date().getFullYear();
  ['filtroAnno','filtroAnnoSpese','pivotAnno','exportAnno'].forEach(id => {
    const el = document.getElementById(id);
    if (!el) return;
    for (let a = annoCorrente; a >= annoCorrente - 3; a--) {
      el.innerHTML += `<option value="${a}"${a === annoCorrente ? ' selected' : ''}>${a}</option>`;
    }
  });

  document.getElementById('filtroMese').value = new Date().getMonth() + 1;
  document.getElementById('spesaData').value  = oggi();
  document.getElementById('formSpesa').addEventListener('submit', salvaSpesa);
  const formRic = document.getElementById('formRicorrente');
  if (formRic) formRic.addEventListener('submit', salvaRicorrente);

  const nomeCache = localStorage.getItem('app_nome') || 'HouseFunds';
  document.title = nomeCache;
  document.getElementById('appNomeTop').textContent = nomeCache;

  // ── Avvia TUTTE le chiamate in parallelo ──────────────────
  const [catResult, , , dashResult, impResult] = await Promise.allSettled([
    caricaCategorie(),

    api('/api/eventi.php?action=list')
      .then(dati => {
        const sel = document.getElementById('filtroEventoSpese');
        if (sel && dati) {
          dati.forEach(e => {
            sel.innerHTML += `<option value="${e.id}">${esc(e.nome)}</option>`;
          });
        }
      })
      .catch(() => {}),

    caricaListaPivot(),
    caricaDashboard(),
    api('/api/install.php?azione=stato'),
  ]);

  // Lingua e formati dal profilo (ricarica la pagina se cambiati su un altro dispositivo)
  api('/api/auth.php?azione=profilo')
    .then(p => { if (p && p.utente) I18N.sincronizzaUtente(p.utente); })
    .catch(() => {});

  // Carica lista case per il switcher; dopo aggiorna il link admin col ruolo reale
  caricaCaseUtente().then(() => aggiornaLinkAdmin()).catch(() => {});

  // ── Listener filtri lista spese ──────────────────────────
  const sortSel = document.getElementById('sortSpese');
  if (sortSel) {
    sortSel.value = S.sortBy;
    sortSel.addEventListener('change', () => {
      S.sortBy = sortSel.value;
      caricaSpese(1);
    });
  }
  const sortBtn = document.getElementById('sortDirBtn');
  if (sortBtn) {
    sortBtn.dataset.order = S.sortOrder;
    sortBtn.querySelector('.sort-dir-text').textContent = S.sortOrder === 'asc' ? 'Cresc.' : 'Decr.';
    sortBtn.addEventListener('click', () => {
      S.sortOrder = (S.sortOrder === 'asc') ? 'desc' : 'asc';
      sortBtn.dataset.order = S.sortOrder;
      sortBtn.querySelector('.sort-dir-text').textContent = S.sortOrder === 'asc' ? 'Cresc.' : 'Decr.';
      caricaSpese(1);
    });
  }

  const catFiltro = document.getElementById('filtroCatSpese');
  if (catFiltro) {
    catFiltro.addEventListener('change', () => {
      aggiornaFiltroSottocategorie();
      caricaSpese(1);
    });
  }
  const scFiltro = document.getElementById('filtroSottocategoriaSpese');
  if (scFiltro) scFiltro.addEventListener('change', () => caricaSpese(1));

  ['filtroAnnoSpese','filtroMeseSpese','filtroCatSpese','filtroPrivataSpese','filtroPerPagina'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.addEventListener('change', () => caricaSpese(1));
  });

  const cercaEl = document.getElementById('cercaSpesa');
  if (cercaEl) cercaEl.addEventListener('input', () => caricaSpese(1));

  S.initPronto = true;

  // Instance name (APP_NAME) from the server; the cached copy is shown meanwhile
  if (impResult.status === 'fulfilled') {
    const nome = impResult.value?.nome || nomeCache;
    if (nome !== nomeCache) {
      document.title = nome;
      document.getElementById('appNomeTop').textContent = nome;
      localStorage.setItem('app_nome', nome);
    }
  }
});

// ── Aggiorna UI nome casa ─────────────────────────────────────
function aggiornaNomeCasa() {
  const el = document.getElementById('nomeCasaAttiva');
  if (el) el.textContent = S.casaAttiva?.nome || '—';
}

function aggiornaLinkAdmin() {
  // Il link Admin è sempre visibile: tutti possono accedere alla pagina
  // (i controlli sui singoli tab/pulsanti avvengono in admin.js in base al ruolo)
  const linkAdmin = document.getElementById('linkAdmin');
  if (linkAdmin) linkAdmin.classList.remove('hidden');
}

// ── Casa-switcher ─────────────────────────────────────────────
async function caricaCaseUtente() {
  try {
    const dati = await api('/api/admin.php?risorsa=casa');
    const case_ = dati?.case || [];

    // Se non abbiamo ancora una casa attiva, usa la prima
    if (!S.casaAttiva && case_.length) {
      S.casaAttiva = case_[0];
      sessionStorage.setItem('casa_attiva', JSON.stringify(S.casaAttiva));
      aggiornaNomeCasa();
      aggiornaLinkAdmin();
    }

    // Aggiorna badge ruolo
    const badgeRuolo = document.getElementById('badgeRuoloCasa');
    if (badgeRuolo) badgeRuolo.textContent = S.casaAttiva?.ruolo || 'membro';

    // Popola il <select id="switchCasa">
    const sel = document.getElementById('switchCasa');
    if (sel) {
      // Salva la lista case per usarla nel cambio
      sel._caseList = case_;
      sel.innerHTML = case_.map(c => {
        const label = c.nome + (c.casa_personale ? ' (personale)' : '') + (c.ruolo === 'admin' ? ' ★' : '');
        return `<option value="${c.id}" ${c.id === S.casaAttiva?.id ? 'selected' : ''}>${esc(label)}</option>`;
      }).join('');
    }

    // Compatibilità: se esiste ancora il vecchio container dropdown, popolalo
    const container = document.getElementById('casaSwitcherList');
    if (container) {
    container.innerHTML = case_.map(c => `
      <button type="button"
              data-casa-id="${c.id}"
              class="casa-switch-btn w-full text-left px-4 py-3 text-sm hover:bg-indigo-50 flex items-center gap-3
                     ${c.id === S.casaAttiva?.id ? 'bg-indigo-50 font-semibold text-indigo-700' : 'text-gray-700'}">
        <span class="flex-1">${esc(c.nome)}</span>
        ${c.casa_personale ? '<span class="text-xs text-gray-400">personale</span>' : ''}
        ${c.ruolo === 'admin' ? '<span class="text-xs bg-indigo-100 text-indigo-600 px-1.5 py-0.5 rounded">admin</span>' : ''}
        ${c.id === S.casaAttiva?.id ? '<span class="text-indigo-500">✓</span>' : ''}
      </button>`).join('');

    container.querySelectorAll('.casa-switch-btn').forEach(btn => {
      btn.addEventListener('click', () => cambiaCasa(parseInt(btn.dataset.casaId), case_));
    });
    } // fine if (container)

    // Mostra bottone aggiungi casa se abbiamo più di una casa o siamo admin
    const btnNuovaCasa = document.getElementById('btnNuovaCasa');
    if (btnNuovaCasa) btnNuovaCasa.classList.remove('hidden');

  } catch(e) {
    console.error('caricaCaseUtente:', e.message);
  }
}

async function cambiaCasa(casaId, caseList = null) {
  // Normalizza a intero (il select passa stringhe)
  casaId = parseInt(casaId, 10);
  if (isNaN(casaId)) return;
  if (casaId === S.casaAttiva?.id) {
    document.getElementById('casaSwitcherDropdown')?.classList.add('hidden');
    return;
  }
  try {
    await api('/api/auth.php?azione=cambia_casa', {
      method: 'POST',
      body: JSON.stringify({ casa_id: casaId }),
    });

    // Recupera lista case: dalla caseList passata, oppure dal select
    const sel = document.getElementById('switchCasa');
    const lista = caseList || sel?._caseList || null;

    // Aggiorna la casa attiva in stato e sessionStorage
    if (lista) {
      S.casaAttiva = lista.find(c => c.id === casaId) || { id: casaId };
    } else {
      S.casaAttiva = { id: casaId };
    }
    sessionStorage.setItem('casa_attiva', JSON.stringify(S.casaAttiva));

    // Reset categorie (sono per-casa)
    S.categorie = [];

    // Chiudi dropdown se esiste
    document.getElementById('casaSwitcherDropdown')?.classList.add('hidden');

    // Aggiorna UI
    aggiornaNomeCasa();
    aggiornaLinkAdmin();

    // Aggiorna badge ruolo
    const badgeRuolo = document.getElementById('badgeRuoloCasa');
    if (badgeRuolo) badgeRuolo.textContent = S.casaAttiva?.ruolo || 'membro';

    // Ricarica tutto
    await Promise.allSettled([
      caricaCategorie(),
      caricaDashboard(),
      caricaCaseUtente(),
    ]);
    caricaSpese(1);

  } catch(e) {
    // Ripristina la selezione precedente nel select
    const selRipristina = document.getElementById('switchCasa');
    if (selRipristina && S.casaAttiva?.id) selRipristina.value = S.casaAttiva.id;
    alert('Errore cambio casa: ' + e.message);
  }
}

function toggleCasaSwitcher() {
  const dd = document.getElementById('casaSwitcherDropdown');
  if (dd) dd.classList.toggle('hidden');
}

// ── Navigazione tab ──────────────────────────────────────────
function mostraTab(nome) {
  document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
  document.getElementById(`tab-${nome}`).classList.add('active');
  document.getElementById(`nav-${nome}`).classList.add('active');

  if (nome === 'spese')      caricaSpese();
  if (nome === 'dashboard')  caricaDashboard();
  if (nome === 'eventi')     caricaEventi();
  if (nome === 'ricorrenti') caricaRicorrenti();
}

// ── CSRF con cache 10 minuti ──────────────────────────────────
let _csrfTimestamp = 0;
async function csrfFresco() {
  const ora = Date.now();
  if (S.csrfToken && (ora - _csrfTimestamp) < 10 * 60 * 1000) {
    return S.csrfToken;
  }
  try {
    const res  = await fetch((window.BASE_URL || '') + '/api/auth.php?azione=csrf', { credentials: 'same-origin' });
    const data = await res.json();
    if (data.successo) {
      S.csrfToken    = data.dati.csrf_token;
      _csrfTimestamp = ora;
      sessionStorage.setItem('csrf_token', S.csrfToken);
    }
  } catch(_) {}
  return S.csrfToken;
}

// ── API helper ───────────────────────────────────────────────
async function api(path, opzioni = {}) {
  const metodo = (opzioni.method || 'GET').toUpperCase();
  if (['POST','PUT','DELETE'].includes(metodo) && opzioni.body) {
    const token = await csrfFresco();
    const body = JSON.parse(opzioni.body);
    body.csrf_token = token;
    opzioni = { ...opzioni, body: JSON.stringify(body) };
  }

  const ctrl = new AbortController();
  const tid = setTimeout(() => ctrl.abort(), 30000);

  mostraLoading();
  try {
    const url = (window.BASE_URL || '') + path;
    const res = await fetch(url, {
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', ...(opzioni.headers || {}) },
      signal: ctrl.signal,
      ...opzioni,
    });

    let data;
    try { data = await res.json(); }
    catch (_) { throw new Error('Risposta non valida dal server (HTML o vuota).'); }

    if (!data.successo) {
      if (res.status === 401) {
        sessionStorage.clear();
        window.location.href = (window.BASE_URL || '') + '/index.html';
      }
      throw new Error(data.errore || 'Errore sconosciuto');
    }
    return data.dati;
  } catch (e) {
    if (e.name === 'AbortError') throw new Error('Server troppo lento, riprova.');
    throw e;
  } finally {
    clearTimeout(tid);
    nascondiLoading();
  }
}

// ── Logout ───────────────────────────────────────────────────
async function logout() {
  try { await api('/api/auth.php?azione=logout', { method: 'POST', body: JSON.stringify({}) }); } catch(_) {}
  sessionStorage.clear();
  window.location.href = (window.BASE_URL || '') + '/index.html';
}

// ── Categorie ────────────────────────────────────────────────
async function caricaCategorie() {
  const sel    = document.getElementById('spesaCategoria');
  const filtro = document.getElementById('filtroCatSpese');

  try {
    const dati = await api('/api/categorie.php?risorsa=categorie&con_sottocategorie=1');
    S.categorie = dati.categorie || [];

    sel.innerHTML = '<option value="">Seleziona…</option>';
    S.categorie.filter(c => c.attiva).forEach(c => {
      sel.innerHTML += `<option value="${c.id}" data-tipo="${c.tipo}">${esc(I18N.nomeCategoria(c.nome))}</option>`;
    });

    filtro.innerHTML = '<option value="">Tutte le categorie</option>';
    S.categorie.forEach(c => {
      filtro.innerHTML += `<option value="${c.id}">${esc(I18N.nomeCategoria(c.nome))}</option>`;
    });

  } catch(e) {
    console.error('caricaCategorie fallita:', e.message);
    sel.innerHTML = '<option value="">⚠ Errore caricamento categorie</option>';
  }
}

function aggiornaSottocategorie() {
  const idCat = document.getElementById('spesaCategoria').value;
  const sel   = document.getElementById('spesaSottocategoria');
  const cat   = idCat ? S.categorie.find(c => c.id == idCat) : null;

  // Categoria di sistema EVENTI: la sottocategoria è l'evento ed è obbligatoria
  const eventi = !!cat?.sistema;
  const label  = sel.closest('div')?.querySelector('label');
  if (label) label.textContent = eventi ? 'Evento *' : 'Sottocategoria';
  sel.required = eventi;

  sel.innerHTML = eventi
    ? '<option value="">Seleziona l\'evento…</option>'
    : '<option value="">Nessuna</option>';
  if (!cat) return;

  (cat.sottocategorie || []).filter(s => s.attiva).forEach(s => {
    sel.innerHTML += `<option value="${s.id}">${esc(s.nome)}</option>`;
  });
}

// ── Dashboard ────────────────────────────────────────────────
async function caricaDashboard() {
  ['kpiUscite','kpiEntrate','kpiSaldo'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.innerHTML = '<span class="skeleton inline-block" style="width:80px;height:20px"></span>';
  });
  const noDataCat = document.getElementById('noDataCat');
  if (noDataCat) {
    noDataCat.style.display = 'block';
    noDataCat.innerHTML = '<div class="spinner-center"><div class="spinner"></div><span>Caricamento dati…</span></div>';
  }
  const ultimeEl = document.getElementById('listaUltimeSpese');
  if (ultimeEl) ultimeEl.innerHTML = '<div class="spinner-center"><div class="spinner"></div><span>Caricamento spese…</span></div>';

  const anno = document.getElementById('filtroAnno').value;
  const mese = document.getElementById('filtroMese').value;
  let url = `/api/pivot.php?azione=dashboard&anno=${anno}`;
  if (mese) url += `&mese=${mese}`;

  try {
    const dati = await api(url);
    if (noDataCat) noDataCat.innerHTML = 'Nessuna spesa';
    aggiornaKPI(dati.kpi);
    aggiornaGraficoCat(dati.per_categoria);
    aggiornaGraficoMensile(dati.mensile);
    aggiornaUltimeSpese(dati.ultime_spese);
  } catch(e) {
    console.error('Dashboard error:', e.message);
    ['kpiUscite','kpiEntrate','kpiSaldo'].forEach(id => {
      const el = document.getElementById(id);
      if (el) el.textContent = '—';
    });
    if (ultimeEl) ultimeEl.innerHTML = `<div class="text-red-600 text-sm p-4">Errore: ${esc(e.message)} — <button onclick="caricaDashboard()" class="underline">Riprova</button></div>`;
  }
}

function aggiornaKPI(kpi) {
  const val = (v) => formatEuro(v);
  document.getElementById('kpiUscite').textContent  = val(kpi.uscite);
  document.getElementById('kpiEntrate').textContent = val(kpi.entrate);
  // Quota delle uscite collegate agli eventi
  const evEl = document.getElementById('kpiUsciteEventi');
  if (evEl) {
    const ev = parseFloat(kpi.uscite_eventi || 0);
    evEl.textContent = ev > 0 ? `di cui eventi ${val(ev)}` : '';
    evEl.classList.toggle('hidden', !(ev > 0));
  }
  const saldoEl = document.getElementById('kpiSaldo');
  saldoEl.textContent = val(Math.abs(kpi.saldo));
  saldoEl.className   = `font-bold text-lg ${kpi.saldo >= 0 ? 'text-green-600' : 'text-red-600'}`;
}

function aggiornaGraficoCat(dati) {
  const ctx    = document.getElementById('graficoCat').getContext('2d');
  const noData = document.getElementById('noDataCat');

  if (S.graficoTorta) S.graficoTorta.destroy();
  if (!dati || !dati.length) { noData.style.display = 'block'; return; }
  noData.style.display = 'none';

  S.graficoTorta = new Chart(ctx, {
    type: 'doughnut',
    data: {
      labels: dati.map(d => I18N.nomeCategoria(d.nome)),
      datasets: [{ data: dati.map(d => Math.abs(parseFloat(d.totale))), backgroundColor: dati.map(d => d.colore), borderWidth: 2 }]
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      plugins: { legend: { position: 'bottom', labels: { font: { size: 11 }, padding: 8 } } }
    }
  });
}

function aggiornaGraficoMensile(dati) {
  const ctx  = document.getElementById('graficoMensile').getContext('2d');
  const mesi = I18N.nomiMesi('short');

  if (S.graficoBar) S.graficoBar.destroy();

  const mapUscite = {}, mapEntrate = {};
  (dati || []).forEach(d => {
    mapUscite[d.mese]  = parseFloat(d.uscite);
    mapEntrate[d.mese] = parseFloat(d.entrate);
  });

  S.graficoBar = new Chart(ctx, {
    type: 'bar',
    data: {
      labels: mesi,
      datasets: [
        { label: t('Uscite'),  data: Array.from({length:12},(_,i)=>mapUscite[i+1]||0),  backgroundColor: '#ef444480', borderColor: '#ef4444', borderWidth: 1 },
        { label: t('Entrate'), data: Array.from({length:12},(_,i)=>mapEntrate[i+1]||0), backgroundColor: '#22c55e80', borderColor: '#22c55e', borderWidth: 1 },
      ]
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      plugins: { legend: { position: 'bottom', labels: { font: { size: 11 }, padding: 8 } } },
      scales: { x: { grid: { display: false } }, y: { ticks: { callback: v => formatEuro(v) } } }
    }
  });
}

function aggiornaUltimeSpese(spese) {
  const el = document.getElementById('listaUltimeSpese');
  if (!spese?.length) {
    el.innerHTML = '<div class="text-gray-500 text-sm py-4 text-center">Nessuna spesa</div>';
    return;
  }
  el.innerHTML = spese.map(s => rigaSpesaCompleta(s)).join('');
  bindEventiSpese();
}

// ── Lista spese ──────────────────────────────────────────────
const caricaSpese = debounce(async function(pagina = 1) {
  S.paginaCorrente = pagina;
  const el = document.getElementById('listaSpese');
  el.innerHTML = '<div class="spinner-center"><div class="spinner"></div><span>Caricamento spese…</span></div>';

  const anno      = document.getElementById('filtroAnnoSpese').value;
  const mese      = document.getElementById('filtroMeseSpese').value;
  const cat       = document.getElementById('filtroCatSpese').value;
  const priv      = document.getElementById('filtroPrivataSpese').value;
  const cerca     = document.getElementById('cercaSpesa').value.trim();
  const sottocat  = document.getElementById('filtroSottocategoriaSpese')?.value || '';
  const eventoFiltro = document.getElementById('filtroEventoSpese')?.value || '';

  const perPaginaEl = document.getElementById('filtroPerPagina');
  const perPagina   = perPaginaEl ? parseInt(perPaginaEl.value, 10) || 50 : 50;

  let url = `/api/spese.php?per_pagina=${perPagina}&pagina=${pagina}`;
  url += `&sort=${encodeURIComponent(S.sortBy)}&order=${S.sortOrder}`;
  if (anno) url += `&anno=${anno}`;
  if (mese) url += `&mese=${mese}`;
  if (cat)  url += `&id_categoria=${cat}`;
  if (sottocat) url += `&id_sottocategoria=${sottocat}`;
  if (priv !== '') url += `&privata=${priv}`;
  if (cerca) url += `&cerca=${encodeURIComponent(cerca)}`;
  if (eventoFiltro) url += `&evento_id=${eventoFiltro}`;

  try {
    const dati = await api(url);
    if (!dati.spese?.length) {
      el.innerHTML = '<div class="text-gray-500 text-sm py-8 text-center">Nessuna spesa trovata</div>';
      document.getElementById('paginazione').innerHTML = '';
      aggiornaTotali(dati.totali, dati.paginazione.totale);
      return;
    }
    el.innerHTML = dati.spese.map(s => rigaSpesaCompleta(s)).join('');
    aggiornaPaginazione(dati.paginazione);
    aggiornaTotali(dati.totali, dati.paginazione.totale);
    bindEventiSpese();
  } catch(e) {
    el.innerHTML = `<div class="text-red-600 text-sm p-4">Errore: ${esc(e.message)} — <button onclick="caricaSpese()" class="underline">Riprova</button></div>`;
    console.error(e);
  }
}, 300);

function rigaSpesaCompleta(s) {
  const tipo   = s.categoria_tipo === 'accredito';
  const colore = tipo ? 'text-green-600' : 'text-red-600';
  const segno  = tipo ? '+' : '-';
  // Può modificare: il proprio autore O qualsiasi spesa se è admin della casa
  const isMio  = S.utente && s.utente_id != null
    ? (s.utente_id === S.utente.id || isAdmin())
    : false;

  const meta = `${formatData(s.data_spesa)} · ${esc(I18N.nomeCategoria(s.categoria))}${s.sottocategoria ? ' / ' + esc(s.sottocategoria) : ''}`;
  const eventoHtml = s.evento_nome ? `
  <span class="inline-flex items-center gap-1 text-xs mt-0.5 px-2 py-0.5 rounded-full"
        style="background:${s.evento_colore}22;color:${s.evento_colore}">
    🎯 ${esc(s.evento_nome)} · ${esc(s.evento_categoria)}
  </span>` : '';

  const azioniHtml = isMio ? `
    <button type="button"
            class="btn-modifica-spesa p-2 rounded hover:bg-gray-100"
            data-action="modifica"
            data-spesa-id="${esc(s.id)}"
            title="Modifica">✎</button>
    <button type="button"
            class="btn-elimina-spesa p-2 rounded hover:bg-red-50 text-red-600"
            data-action="elimina"
            data-spesa-id="${esc(s.id)}"
            title="Elimina">✕</button>` : '';

  return `
  <div class="spesa-row p-3 border border-gray-200 rounded-lg mb-2 bg-white">
    <div style="display:flex;align-items:flex-start;gap:12px;width:100%">
      <div style="width:40px;height:40px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;color:white;font-weight:700;background:${esc(s.categoria_colore || '#999')}">
        ${esc((s.categoria || '?').charAt(0))}
      </div>
      <div style="flex:1;min-width:0">
        <div style="font-weight:500;word-break:break-word">${esc(s.descrizione || '—')}</div>
        <div style="font-size:12px;color:#6b7280;margin-top:2px">${meta}${s.privata ? ' · 🔒' : ''}${s.id_ricorrenza ? ' · 🔁' : ''}</div>${eventoHtml}
      </div>
      <div style="flex-shrink:0;display:flex;align-items:center;gap:6px">
        <span style="font-weight:700;white-space:nowrap" class="${colore}">${segno}${formatEuro(Math.abs(s.importo))}</span>
        ${azioniHtml}
      </div>
    </div>
  </div>`;
}

function aggiornaPaginazione(pag) {
  const el = document.getElementById('paginazione');
  if (!el) return;
  if (!pag || pag.pagine <= 1) { el.innerHTML = ''; return; }

  const cur = pag.pagina;
  const tot = pag.pagine;

  const pages = [];
  const add = (p) => { if (!pages.includes(p)) pages.push(p); };
  add(1);
  for (let i = cur - 1; i <= cur + 1; i++) {
    if (i > 1 && i < tot) add(i);
  }
  if (tot > 1) add(tot);
  pages.sort((a, b) => a - b);

  const items = [];
  let prev = 0;
  for (const p of pages) {
    if (prev && p - prev > 1) items.push({ ellipsis: true });
    items.push({ page: p });
    prev = p;
  }

  const btn = (label, page, disabled = false, active = false, isEllipsis = false) => {
    if (isEllipsis) return `<span class="pag-ellipsis">…</span>`;
    const cls = active ? 'pag-btn pag-btn-active' : 'pag-btn';
    const dis = disabled ? 'disabled' : '';
    return `<button type="button" class="${cls}" ${dis} data-pag-page="${page}">${label}</button>`;
  };

  let html = '';
  html += btn('‹', cur - 1, cur === 1);
  items.forEach(it => {
    if (it.ellipsis) html += btn('…', null, true, false, true);
    else html += btn(String(it.page), it.page, false, it.page === cur);
  });
  html += btn('›', cur + 1, cur === tot);
  html += `<span class="pag-info">Pagina ${cur} di ${tot}</span>`;

  el.innerHTML = html;

  if (!el._pagBound) {
    el.addEventListener('click', (ev) => {
      const b = ev.target.closest('button[data-pag-page]');
      if (!b || b.disabled) return;
      const page = parseInt(b.dataset.pagPage, 10);
      if (!isNaN(page)) caricaSpese(page);
    });
    el._pagBound = true;
  }
}

function aggiornaTotali(totali, numeroVoci) {
  const bar = document.getElementById('totaliSpese');
  if (!bar) return;
  if (!totali) { bar.classList.add('hidden'); return; }
  bar.classList.remove('hidden');
  document.getElementById('totaleVoci').textContent    = numeroVoci ?? 0;
  document.getElementById('totaleUscite').textContent  = formatEuro(totali.uscite);
  document.getElementById('totaleEntrate').textContent = formatEuro(totali.entrate);
  const saldoEl = document.getElementById('totaleSaldo');
  saldoEl.textContent = formatEuro(totali.saldo);
  saldoEl.className   = 'totale-value ' + (totali.saldo >= 0 ? 'text-green-600' : 'text-red-600');
}

// ── Modal spesa ──────────────────────────────────────────────
function apriModalSpesa(spesa = null) {
  S.spesaEditId = spesa?.id || null;
  S.privataOn   = spesa?.privata || false;

  document.getElementById('modalTitolo').textContent = spesa ? 'Modifica spesa' : 'Nuova spesa';
  document.getElementById('spesaId').value           = spesa?.id || '';
  document.getElementById('spesaData').value         = spesa?.data_spesa || oggi();
  document.getElementById('spesaImporto').value      = spesa ? Math.abs(spesa.importo) : '';
  document.getElementById('spesaDescrizione').value  = spesa?.descrizione || '';
  document.getElementById('modalErrore').classList.add('hidden');

  if (spesa) {
    const selCat = document.getElementById('spesaCategoria');
    if (spesa.categoria_id && !selCat.querySelector(`option[value="${spesa.categoria_id}"]`)) {
      selCat.insertAdjacentHTML('beforeend',
        `<option value="${spesa.categoria_id}">${esc(spesa.categoria || '')} (disattiva)</option>`);
    }
    selCat.value = spesa.categoria_id;
    aggiornaSottocategorie();
    const selSub = document.getElementById('spesaSottocategoria');
    if (spesa.sottocategoria_id && !selSub.querySelector(`option[value="${spesa.sottocategoria_id}"]`)) {
      selSub.insertAdjacentHTML('beforeend',
        `<option value="${spesa.sottocategoria_id}">${esc(spesa.sottocategoria || '')} (disattiva)</option>`);
    }
    selSub.value = spesa.sottocategoria_id || '';
  } else {
    document.getElementById('spesaCategoria').value      = '';
    aggiornaSottocategorie();
  }

  aggiornaTogglePrivata();
  document.getElementById('modalSpesa').classList.remove('hidden');
  document.body.style.overflow = 'hidden';
}

function chiudiModalSpesa() {
  document.getElementById('modalSpesa').classList.add('hidden');
  document.body.style.overflow = '';
  S.spesaEditId = null;
  document.getElementById('modalSpesa').dispatchEvent(new Event('chiusuraSpesa'));
}

function togglePrivata() {
  S.privataOn = !S.privataOn;
  aggiornaTogglePrivata();
}

function aggiornaTogglePrivata() {
  const btn   = document.getElementById('togglePrivata');
  const thumb = document.getElementById('toggleThumb');
  btn.setAttribute('aria-checked', S.privataOn);
  btn.classList.toggle('bg-indigo-600', S.privataOn);
  btn.classList.toggle('bg-gray-200', !S.privataOn);
  thumb.style.transform = S.privataOn ? 'translateX(16px)' : 'translateX(0)';
}

async function salvaSpesa(e) {
  e.preventDefault();

  const errEl   = document.getElementById('modalErrore');
  const spinner = document.getElementById('btnSalvaSpinner');
  const btn     = document.getElementById('btnSalvaSpesa');

  errEl.classList.add('hidden');

  const data        = document.getElementById('spesaData').value;
  const importo     = parseFloat(document.getElementById('spesaImporto').value);
  const idCategoria = document.getElementById('spesaCategoria').value;
  const idSottoCat  = document.getElementById('spesaSottocategoria').value;
  const descrizione = document.getElementById('spesaDescrizione').value.trim();

  if (!data || !importo || !idCategoria) {
    errEl.textContent = 'Data, importo e categoria sono obbligatori.';
    errEl.classList.remove('hidden');
    return;
  }
  if (S.categorie.find(c => c.id == idCategoria)?.sistema && !idSottoCat) {
    errEl.textContent = "Seleziona l'evento a cui associare la spesa.";
    errEl.classList.remove('hidden');
    return;
  }

  spinner.classList.remove('hidden');
  btn.disabled = true;

  const payload = {
    data_spesa:        data,
    importo,
    id_categoria:      parseInt(idCategoria),
    id_sottocategoria: idSottoCat ? parseInt(idSottoCat) : null,
    descrizione,
    privata:           S.privataOn,
    csrf_token:        S.csrfToken,
  };

  try {
    const isEdit    = !!S.spesaEditId;
    const spesaID   = S?.spesaEditId;
    const risultato = await api(
      isEdit ? `/api/spese.php?id=${S.spesaEditId}` : '/api/spese.php',
      { method: isEdit ? 'PUT' : 'POST', body: JSON.stringify(payload) }
    );

    // Notifica il salvataggio riuscito (usato dalla modifica aperta dalla popup evento)
    document.getElementById('modalSpesa').dispatchEvent(new Event('spesaSalvata'));
    chiudiModalSpesa();
    caricaSpese(isEdit ? (S.paginaCorrente || 1) : 1);
    caricaDashboard();

    // Popup evento: anche senza sottocategoria (categoria collegata per intero)
    const spesaId = isEdit ? spesaID : (risultato?.id || risultato?.spesa?.id);
    if (spesaId) {
      await checkEventoDopoSpesa(spesaId, parseInt(idCategoria), idSottoCat ? parseInt(idSottoCat) : null);
    }

  } catch(err) {
    errEl.textContent = err.message;
    errEl.classList.remove('hidden');
  } finally {
    spinner.classList.add('hidden');
    btn.disabled = false;
  }
}

async function modificaSpesa(id) {
  try {
    const dati = await api(`/api/spese.php?id=${encodeURIComponent(id)}`);
    if (!dati || !dati.spesa) { alert('Spesa non trovata.'); return; }
    apriModalSpesa(dati.spesa);
  } catch(e) {
    console.error('modificaSpesa:', e);
    alert('Errore nel caricamento della spesa: ' + e.message);
  }
}

async function eliminaSpesa(id) {
  if (!confirm('Eliminare questa spesa? L\'operazione è irreversibile.')) return;
  try {
    await api(`/api/spese.php?id=${encodeURIComponent(id)}`, { method: 'DELETE' });
    if (typeof caricaSpese === 'function') caricaSpese(S.paginaCorrente || 1);
    if (typeof caricaDashboard === 'function') caricaDashboard();
  } catch(e) {
    console.error('eliminaSpesa:', e);
    alert('Errore: ' + e.message);
  }
}

// ── Spese ricorrenti ─────────────────────────────────────────
function etichettaFrequenza(r) {
  const singolare = { giorni: 'ogni giorno',     settimane: 'ogni settimana',     mesi: 'ogni mese',     anni: 'ogni anno' };
  const plurale   = { giorni: 'ogni {0} giorni', settimane: 'ogni {0} settimane', mesi: 'ogni {0} mesi', anni: 'ogni {0} anni' };
  const n = r.intervallo;
  return n === 1 ? t(singolare[r.unita] || r.unita) : t(plurale[r.unita] || r.unita, n);
}

async function caricaRicorrenti() {
  const cont = document.getElementById('ricorrentiLista');
  if (!cont) return;
  cont.innerHTML = '<div class="text-center text-gray-400 text-sm py-8">Caricamento…</div>';
  try {
    const dati  = await api('/api/ricorrenti.php');
    const lista = dati.ricorrenti || [];
    if (!lista.length) {
      cont.innerHTML = '<div class="text-center text-gray-400 text-sm py-10">Nessuna spesa ricorrente. Tocca "Nuova" per crearne una.</div>';
      return;
    }
    cont.innerHTML = lista.map(rigaRicorrente).join('');
  } catch(e) {
    cont.innerHTML = `<div class="text-center text-red-500 text-sm py-8">Errore: ${esc(e.message)}</div>`;
  }
}

function rigaRicorrente(r) {
  const tipo     = r.categoria_tipo === 'accredito';
  const colore   = tipo ? 'text-green-600' : 'text-red-600';
  const segno    = tipo ? '+' : '-';
  const meta     = `${esc(I18N.nomeCategoria(r.categoria))}${r.sottocategoria ? ' / ' + esc(r.sottocategoria) : ''}`;
  const prossima = r.attiva ? t('prossima: {0}', formatData(r.prossima_data)) : t('in pausa');
  const fine     = r.data_fine ? ' · ' + t('fino al {0}', formatData(r.data_fine)) : '';
  const opacita  = r.attiva ? '' : 'opacity-60';
  return `
  <div class="p-3 border border-gray-200 rounded-lg bg-white ${opacita}" onclick="modificaRicorrente(${r.id})" style="cursor:pointer">
    <div style="display:flex;align-items:flex-start;gap:12px;width:100%">
      <div style="width:40px;height:40px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;color:white;font-weight:700;background:${esc(r.categoria_colore || '#999')}">🔁</div>
      <div style="flex:1;min-width:0">
        <div style="font-weight:500;word-break:break-word">${esc(r.descrizione || meta)}</div>
        <div style="font-size:12px;color:#6b7280;margin-top:2px">${meta} · ${esc(etichettaFrequenza(r))}${r.privata ? ' · 🔒' : ''}</div>
        <div style="font-size:12px;color:#9ca3af;margin-top:2px">${esc(prossima)}${esc(fine)}</div>
      </div>
      <div style="flex-shrink:0;display:flex;align-items:center">
        <span style="font-weight:700;white-space:nowrap" class="${colore}">${segno}${formatEuro(Math.abs(r.importo))}</span>
      </div>
    </div>
  </div>`;
}

function popolaCategorieRic() {
  const sel = document.getElementById('ricorrenteCategoria');
  if (!sel) return;
  sel.innerHTML = '<option value="">Seleziona…</option>';
  S.categorie.filter(c => c.attiva).forEach(c => {
    sel.innerHTML += `<option value="${c.id}" data-tipo="${c.tipo}">${esc(I18N.nomeCategoria(c.nome))}</option>`;
  });
}

function aggiornaSottocategorieRic() {
  const idCat  = document.getElementById('ricorrenteCategoria').value;
  const sel    = document.getElementById('ricorrenteSottocategoria');
  const cat    = idCat ? S.categorie.find(c => c.id == idCat) : null;
  const eventi = !!cat?.sistema;
  sel.innerHTML = eventi ? '<option value="">Seleziona l\'evento…</option>' : '<option value="">Nessuna</option>';
  sel.required  = eventi;
  if (!cat) return;
  (cat.sottocategorie || []).filter(s => s.attiva).forEach(s => {
    sel.innerHTML += `<option value="${s.id}">${esc(s.nome)}</option>`;
  });
}

function apriModalRicorrente(r = null) {
  S.ricEditId    = r?.id || null;
  S.ricPrivataOn = r?.privata || false;
  S.ricAttivaOn  = r ? !!r.attiva : true;
  popolaCategorieRic();

  document.getElementById('modalRicorrenteTitolo').textContent = r ? 'Modifica spesa ricorrente' : 'Nuova spesa ricorrente';
  document.getElementById('ricorrenteId').value          = r?.id || '';
  document.getElementById('ricorrenteImporto').value     = r ? Math.abs(r.importo) : '';
  document.getElementById('ricorrenteDescrizione').value = r?.descrizione || '';
  document.getElementById('ricorrenteIntervallo').value  = r?.intervallo || 1;
  document.getElementById('ricorrenteUnita').value       = r?.unita || 'mesi';
  document.getElementById('ricorrenteDataInizio').value  = r?.data_inizio || oggi();
  document.getElementById('ricorrenteDataFine').value    = r?.data_fine || '';
  document.getElementById('ricorrenteErrore').classList.add('hidden');

  const selCat = document.getElementById('ricorrenteCategoria');
  if (r) {
    if (r.categoria_id && !selCat.querySelector(`option[value="${r.categoria_id}"]`)) {
      selCat.insertAdjacentHTML('beforeend', `<option value="${r.categoria_id}">${esc(r.categoria || '')} (disattiva)</option>`);
    }
    selCat.value = r.categoria_id;
    aggiornaSottocategorieRic();
    const selSub = document.getElementById('ricorrenteSottocategoria');
    if (r.sottocategoria_id && !selSub.querySelector(`option[value="${r.sottocategoria_id}"]`)) {
      selSub.insertAdjacentHTML('beforeend', `<option value="${r.sottocategoria_id}">${esc(r.sottocategoria || '')} (disattiva)</option>`);
    }
    selSub.value = r.sottocategoria_id || '';
  } else {
    selCat.value = '';
    aggiornaSottocategorieRic();
  }

  const attivaRow = document.getElementById('ricorrenteAttivaRow');
  attivaRow.classList.toggle('hidden', !r);
  attivaRow.classList.toggle('flex', !!r);
  document.getElementById('btnEliminaRic').classList.toggle('hidden', !r);

  aggiornaToggleRic();
  document.getElementById('modalRicorrente').classList.remove('hidden');
  document.body.style.overflow = 'hidden';
}

function chiudiModalRicorrente() {
  document.getElementById('modalRicorrente').classList.add('hidden');
  document.body.style.overflow = '';
  S.ricEditId = null;
}

function toggleRicPrivata() { S.ricPrivataOn = !S.ricPrivataOn; aggiornaToggleRic(); }
function toggleRicAttiva()  { S.ricAttivaOn  = !S.ricAttivaOn;  aggiornaToggleRic(); }

function aggiornaToggleRic() {
  const p  = document.getElementById('toggleRicPrivata');
  const pt = document.getElementById('toggleRicPrivataThumb');
  if (p) {
    p.setAttribute('aria-checked', S.ricPrivataOn);
    p.classList.toggle('bg-indigo-600', S.ricPrivataOn);
    p.classList.toggle('bg-gray-200', !S.ricPrivataOn);
    pt.style.transform = S.ricPrivataOn ? 'translateX(16px)' : 'translateX(0)';
  }
  const a  = document.getElementById('toggleRicAttiva');
  const at = document.getElementById('toggleRicAttivaThumb');
  if (a) {
    a.setAttribute('aria-checked', S.ricAttivaOn);
    a.classList.toggle('bg-indigo-600', S.ricAttivaOn);
    a.classList.toggle('bg-gray-200', !S.ricAttivaOn);
    at.style.transform = S.ricAttivaOn ? 'translateX(16px)' : 'translateX(0)';
  }
}

async function salvaRicorrente(e) {
  e.preventDefault();
  const errEl   = document.getElementById('ricorrenteErrore');
  const spinner = document.getElementById('btnSalvaRicSpinner');
  const btn     = document.getElementById('btnSalvaRic');
  errEl.classList.add('hidden');

  const importo     = parseFloat(document.getElementById('ricorrenteImporto').value);
  const idCategoria = document.getElementById('ricorrenteCategoria').value;
  const idSottoCat  = document.getElementById('ricorrenteSottocategoria').value;
  const descrizione = document.getElementById('ricorrenteDescrizione').value.trim();
  const intervallo  = parseInt(document.getElementById('ricorrenteIntervallo').value);
  const unita       = document.getElementById('ricorrenteUnita').value;
  const dataInizio  = document.getElementById('ricorrenteDataInizio').value;
  const dataFine    = document.getElementById('ricorrenteDataFine').value;

  if (!importo || !idCategoria || !intervallo || !dataInizio) {
    errEl.textContent = 'Importo, categoria, intervallo e data di inizio sono obbligatori.';
    errEl.classList.remove('hidden'); return;
  }
  if (S.categorie.find(c => c.id == idCategoria)?.sistema && !idSottoCat) {
    errEl.textContent = "Seleziona l'evento a cui associare la spesa.";
    errEl.classList.remove('hidden'); return;
  }
  if (dataFine && dataFine < dataInizio) {
    errEl.textContent = 'La data di fine non può precedere la data di inizio.';
    errEl.classList.remove('hidden'); return;
  }

  spinner.classList.remove('hidden'); btn.disabled = true;
  const payload = {
    importo,
    id_categoria:      parseInt(idCategoria),
    id_sottocategoria: idSottoCat ? parseInt(idSottoCat) : null,
    descrizione,
    intervallo,
    unita,
    data_inizio:       dataInizio,
    data_fine:         dataFine || null,
    privata:           S.ricPrivataOn,
    attiva:            S.ricAttivaOn,
  };

  try {
    const isEdit = !!S.ricEditId;
    await api(
      isEdit ? `/api/ricorrenti.php?id=${S.ricEditId}` : '/api/ricorrenti.php',
      { method: isEdit ? 'PUT' : 'POST', body: JSON.stringify(payload) }
    );
    chiudiModalRicorrente();
    caricaRicorrenti();
    // La ricorrenza può aver generato spese: aggiorna anche le altre viste.
    if (typeof caricaSpese === 'function')     caricaSpese(1);
    if (typeof caricaDashboard === 'function') caricaDashboard();
  } catch(err) {
    errEl.textContent = err.message;
    errEl.classList.remove('hidden');
  } finally {
    spinner.classList.add('hidden'); btn.disabled = false;
  }
}

async function modificaRicorrente(id) {
  try {
    const dati = await api(`/api/ricorrenti.php?id=${encodeURIComponent(id)}`);
    if (!dati || !dati.ricorrente) { alert('Ricorrenza non trovata.'); return; }
    apriModalRicorrente(dati.ricorrente);
  } catch(e) {
    alert('Errore nel caricamento: ' + e.message);
  }
}

async function eliminaRicorrente() {
  if (!S.ricEditId) return;
  if (!confirm('Eliminare questa ricorrenza? Le spese già registrate restano, ma non ne verranno create altre.')) return;
  try {
    await api(`/api/ricorrenti.php?id=${encodeURIComponent(S.ricEditId)}`, { method: 'DELETE' });
    chiudiModalRicorrente();
    caricaRicorrenti();
  } catch(e) {
    alert('Errore: ' + e.message);
  }
}

// ── Pivot ────────────────────────────────────────────────────
async function caricaListaPivot() {
  try {
    const dati = await api('/api/pivot.php?azione=lista');
    const sel  = document.getElementById('selPivot');
    (dati.pivot || []).forEach(p => {
      sel.innerHTML += `<option value="${p.id}">${p.nome}</option>`;
    });
    if (dati.pivot?.length) eseguiPivot();
  } catch(e) {}
}

async function eseguiPivot() {
  const id   = document.getElementById('selPivot').value;
  const anno = document.getElementById('pivotAnno').value;
  const mese = document.getElementById('pivotMese').value;
  const el   = document.getElementById('tabellaPivot');

  if (!id) { el.innerHTML = '<div class="text-gray-500 text-sm py-8 text-center">Seleziona una pivot per iniziare</div>'; return; }
  el.innerHTML = '<div class="spinner-center"><div class="spinner"></div><span>Calcolo pivot in corso…</span></div>';

  try {
    let url = `/api/pivot.php?azione=esegui&id=${id}&anno=${anno}`;
    if (mese) url += `&mese=${mese}`;
    const dati = await api(url);
    el.innerHTML = costruisciTabellaPivot(dati);
    bindPivotToggle();
  } catch(e) {
    el.innerHTML = `<div class="text-red-600 text-sm p-4">Errore: ${esc(e.message)} — <button onclick="eseguiPivot()" class="underline">Riprova</button></div>`;
  }
}

// Etichette generate dal server (mesi, giorni, tipi, voci fisse): vanno tradotte;
// le altre (categorie, utenti, eventi) sono dati inseriti dagli utenti.
const ETICHETTE_PIVOT = new Set([
  'Gennaio','Febbraio','Marzo','Aprile','Maggio','Giugno','Luglio','Agosto','Settembre','Ottobre','Novembre','Dicembre',
  'Lunedì','Martedì','Mercoledì','Giovedì','Venerdì','Sabato','Domenica',
  'Entrate','Uscite','EVENTI','Nessun evento','Voce non assegnata','Senza sottocategoria',
]);
function etichettaPivot(l) {
  return ETICHETTE_PIVOT.has(String(l)) ? t(String(l)) : String(l);
}

function costruisciTabellaPivot(dati) {
  const { colonne, tree, totali_colonne, config } = dati;
  if (!tree || !tree.length) return '<div class="p-4 text-center text-gray-500">Nessun dato</div>';

  const isEntrateUscite = config.riga === 'tipo';

  const getTipoRadice = (label) => {
    if (!isEntrateUscite) return null;
    if (label === 'Entrate') return 'entrata';
    if (label === 'Uscite')  return 'uscita';
    return null;
  };

  const getClassi = (tipoRadice) => {
    if (!tipoRadice) return { riga: '', label: 'text-gray-700', cella: 'text-gray-600', totale: 'text-indigo-700' };
    const e = tipoRadice === 'entrata';
    return {
      riga:   e ? 'bg-green-50/40' : 'bg-red-50/40',
      label:  e ? 'text-green-700' : 'text-red-600',
      cella:  e ? 'text-green-600' : 'text-red-600',
      totale: e ? 'text-green-700' : 'text-red-600',
    };
  };

  const segno = (tipoRadice, v) => {
    if (!tipoRadice || v === null || v === undefined || v === 0) return '';
    return tipoRadice === 'entrata' ? '+' : '−';
  };

  let html = `
    <div class="pivot-header">
      <h3 class="font-bold">${esc(config.nome)}</h3>
      ${config.descrizione ? `<p class="text-sm text-gray-500">${esc(config.descrizione)}</p>` : ''}
    </div>
    <table class="pivot-tree">
      <thead><tr>
        <th class="pivot-th-label">Voce</th>
        ${colonne.map(c => `<th class="pivot-th-num">${esc(etichettaPivot(c))}</th>`).join('')}
        <th class="pivot-th-num pivot-th-totale">Totale</th>
      </tr></thead>
      <tbody>
  `;

  const renderNodi = (nodi, percorso = '', tipoEreditato = null) => {
    let out = '';
    nodi.forEach((n, i) => {
      const id       = `${percorso}/${i}`;
      const haFigli  = n.figli && n.figli.length > 0;
      const tipoNodo = n.livello === 0 ? getTipoRadice(n.label) : tipoEreditato;
      const classi   = getClassi(tipoNodo);
      const nascosto = n.livello >= 2 ? 'style="display:none"' : '';
      const espanso  = n.livello < 1;

      out += `
        <tr class="pivot-row pivot-lvl-${n.livello} ${classi.riga}" data-tree-id="${id}" ${nascosto}>
          <td class="pivot-td-label ${classi.label}" style="padding-left:${12 + n.livello * 24}px">
            ${haFigli
              ? `<button type="button" class="pivot-toggle" data-toggle="${id}" aria-expanded="${espanso}">${espanso ? '▼' : '▶'}</button>`
              : '<span class="pivot-bullet">•</span>'}
            <span class="pivot-label">${esc(etichettaPivot(n.label))}</span>
          </td>
          ${colonne.map(c => {
            const v = n.valori[c];
            const hasVal = v !== null && v !== undefined && v !== 0;
            return `<td class="pivot-td-num ${classi.cella}">
              ${hasVal ? `${segno(tipoNodo, v)}${formatEuro(v)}` : '<span class="text-gray-300">—</span>'}
            </td>`;
          }).join('')}
          <td class="pivot-td-num pivot-td-totale ${classi.totale}">
            ${n.totale ? `${segno(tipoNodo, n.totale)}${formatEuro(n.totale)}` : '<span class="text-gray-300">—</span>'}
          </td>
        </tr>
      `;
      if (haFigli) out += renderNodi(n.figli, id, tipoNodo);
    });
    return out;
  };

  const treeOrdinato = isEntrateUscite
    ? [...tree].sort(a => a.label === 'Entrate' ? -1 : 1)
    : tree;

  html += renderNodi(treeOrdinato);

  if (isEntrateUscite) {
    const saldoColonne = {};
    colonne.forEach(c => { saldoColonne[c] = 0; });
    tree.forEach(n => {
      if (n.livello !== 0) return;
      const tipo = getTipoRadice(n.label);
      if (!tipo) return;
      colonne.forEach(c => {
        saldoColonne[c] += tipo === 'entrata' ? (n.valori[c] || 0) : -(n.valori[c] || 0);
      });
    });
    const granSaldo = colonne.reduce((a, c) => a + (saldoColonne[c] || 0), 0);
    html += `
      <tr class="pivot-row-saldo border-t-2 border-gray-300">
        <td class="pivot-td-label font-bold text-gray-700">Saldo</td>
        ${colonne.map(c => {
          const s = saldoColonne[c];
          const col = s >= 0 ? 'text-green-700' : 'text-red-600';
          return `<td class="pivot-td-num font-bold ${col} bg-gray-100">${s >= 0 ? '+' : ''}${formatEuro(s)}</td>`;
        }).join('')}
        <td class="pivot-td-num pivot-td-totale font-bold ${granSaldo >= 0 ? 'text-green-700' : 'text-red-600'} bg-gray-200">
          ${granSaldo >= 0 ? '+' : ''}${formatEuro(granSaldo)}
        </td>
      </tr>`;
  } else {
    const granTotale = colonne.reduce((a, c) => a + (totali_colonne[c] || 0), 0);
    html += `
      <tr class="pivot-row-totale">
        <td class="pivot-td-label">Totale complessivo</td>
        ${colonne.map(c => `<td class="pivot-td-num">${formatEuro(totali_colonne[c] || 0)}</td>`).join('')}
        <td class="pivot-td-num pivot-td-totale">${formatEuro(granTotale)}</td>
      </tr>`;
  }

  html += `</tbody></table>`;
  return html;
}

// ── Import ───────────────────────────────────────────────────
const IMP = { righe: [], intestazioni: [], file: null, mapping: {} };

const CAMPI_IMPORT = [
  { id: 'data',           label: 'Data *',           required: true  },
  { id: 'categoria',      label: 'Categoria *',      required: true  },
  { id: 'importo',        label: 'Importo *',        required: true  },
  { id: 'descrizione',    label: 'Descrizione',      required: false },
  { id: 'sottocategoria', label: 'Sottocategoria',   required: false },
  { id: 'privata',        label: 'Privata (sì/no)',  required: false },
];

function handleDrop(e) {
  e.preventDefault();
  document.getElementById('dropZone').classList.remove('border-indigo-400','bg-indigo-50');
  const file = e.dataTransfer.files[0];
  if (file) leggiFileImport(file);
}

async function leggiFileImport(file) {
  if (!file) return;
  IMP.file = file;

  const loadingEl = document.getElementById('importLoading');
  const dropEl    = document.getElementById('dropZone');

  if (loadingEl) loadingEl.classList.remove('hidden');
  if (dropEl)    dropEl.classList.add('hidden');

  try {
    const { intestazioni, righe } = await leggiFileLocale(file);
    IMP.intestazioni = intestazioni;
    IMP.righe        = righe;

    if (loadingEl) loadingEl.classList.add('hidden');
    document.getElementById('importStep1').classList.add('hidden');
    document.getElementById('importStep2').classList.remove('hidden');
    document.getElementById('importNomeFile').textContent = `📄 ${file.name} — ${righe.length} righe trovate`;

    costruisciMapping(intestazioni);
    costruisciAnteprima(intestazioni, righe.slice(0, 5));

  } catch(e) {
    if (loadingEl) loadingEl.classList.add('hidden');
    if (dropEl)    dropEl.classList.remove('hidden');

    const ris = document.getElementById('importRisultato');
    ris.className = 'mt-4 text-sm rounded-lg p-4 bg-red-50 border border-red-200 text-red-700';
    ris.innerHTML = `✗ Errore lettura file: ${esc(e.message)}`;
    ris.classList.remove('hidden');
  }
}

function leggiFileLocale(file) {
  return new Promise((resolve, reject) => {
    const reader     = new FileReader();
    const estensione = file.name.split('.').pop().toLowerCase();

    reader.onload = (e) => {
      try {
        let intestazioni = [], righe = [];

        if (estensione === 'csv') {
          const testo    = e.target.result;
          const righeRaw = testo.split(/\r?\n/).filter(r => r.trim());
          const sep      = righeRaw[0].includes(';') ? ';' : ',';
          intestazioni   = righeRaw[0].split(sep).map(h => h.trim().replace(/^"|"$/g,''));
          righe          = righeRaw.slice(1).map(r =>
            r.split(sep).map(c => c.trim().replace(/^"|"$/g,''))
          ).filter(r => r.some(c => c));
        } else {
          if (typeof XLSX === 'undefined') {
            reject(new Error('Libreria Excel non caricata. Ricarica la pagina o usa CSV.'));
            return;
          }
          const wb   = XLSX.read(e.target.result, { type: 'array', cellDates: true });
          const ws   = wb.Sheets[wb.SheetNames[0]];
          const dati = XLSX.utils.sheet_to_json(ws, { header: 1, defval: '' });
          intestazioni = (dati[0] || []).map(h => String(h).trim());
          righe = dati.slice(1).filter(r => r.some(c => c !== '')).map(r =>
            r.map(c => {
              if (c instanceof Date) return c.toISOString().split('T')[0];
              return String(c ?? '').trim();
            })
          );
        }
        resolve({ intestazioni, righe });
      } catch(err) {
        reject(err);
      }
    };

    reader.onerror = () => reject(new Error('Lettura file fallita'));

    if (estensione === 'csv') reader.readAsText(file, 'UTF-8');
    else reader.readAsArrayBuffer(file);
  });
}

function costruisciMapping(intestazioni) {
  const container = document.getElementById('mappingColonne');
  const autoMap = {
    data:           ['data','date','data spesa','data_spesa'],
    categoria:      ['categoria','category','cat'],
    importo:        ['importo','amount','valore','euro','importo (€)','importo (euro)'],
    descrizione:    ['descrizione','description','nota','note','desc'],
    sottocategoria: ['sottocategoria','subcategory','sotto','sottocat'],
    privata:        ['privata','private','nascosta'],
  };

  IMP.mapping = {};
  CAMPI_IMPORT.forEach(campo => {
    const nomiPossibili = autoMap[campo.id] || [];
    const trovata = intestazioni.findIndex(h =>
      nomiPossibili.includes(h.toLowerCase().trim())
    );
    IMP.mapping[campo.id] = trovata >= 0 ? trovata : '';
  });

  container.innerHTML = CAMPI_IMPORT.map(campo => `
    <div>
      <label class="block text-xs font-medium text-gray-600 mb-1">${campo.label}</label>
      <select data-campo="${campo.id}" onchange="IMP.mapping['${campo.id}']=this.value===''?'':parseInt(this.value)"
        class="w-full border border-gray-200 rounded-lg px-2 py-2 text-xs bg-white">
        <option value="">— non presente —</option>
        ${intestazioni.map((h, i) =>
          `<option value="${i}" ${IMP.mapping[campo.id] === i ? 'selected' : ''}>${h || `Colonna ${i+1}`}</option>`
        ).join('')}
      </select>
    </div>
  `).join('');
}

function costruisciAnteprima(intestazioni, righe) {
  const t = document.getElementById('tabellaAnteprima');
  t.innerHTML = `
    <thead class="bg-gray-50">
      <tr>${intestazioni.map(h => `<th class="px-3 py-2 text-left text-xs font-semibold text-gray-600 whitespace-nowrap">${esc(h)}</th>`).join('')}</tr>
    </thead>
    <tbody class="divide-y divide-gray-50">
      ${righe.map(r => `<tr>${r.map(c => `<td class="px-3 py-2 text-xs text-gray-600 whitespace-nowrap">${esc(c)}</td>`).join('')}</tr>`).join('')}
    </tbody>`;
}

function resetImport() {
  IMP.file = null; IMP.righe = []; IMP.intestazioni = []; IMP.mapping = {};
  document.getElementById('importStep1').classList.remove('hidden');
  document.getElementById('importStep2').classList.add('hidden');
  document.getElementById('importRisultato').classList.add('hidden');
  document.getElementById('dropZone').classList.remove('hidden');
  document.getElementById('importLoading').classList.add('hidden');
  document.getElementById('fileImport').value = '';
  document.getElementById('mappingErrore').classList.add('hidden');
}

async function avviaImport() {
  const errEl   = document.getElementById('mappingErrore');
  const btn     = document.getElementById('btnImport');
  const spinner = document.getElementById('btnImportSpinner');
  const testo   = document.getElementById('btnImportTesto');

  errEl.classList.add('hidden');

  const mancanti = CAMPI_IMPORT.filter(c => c.required && IMP.mapping[c.id] === '').map(c => c.label.replace(' *',''));
  if (mancanti.length) {
    errEl.textContent = 'Associa i campi obbligatori: ' + mancanti.join(', ');
    errEl.classList.remove('hidden');
    return;
  }

  btn.disabled = true;
  spinner.classList.remove('hidden');
  testo.textContent = 'Importazione…';

  const righeFormattate = IMP.righe.map(r => {
    const obj = {};
    CAMPI_IMPORT.forEach(c => {
      if (IMP.mapping[c.id] !== '') obj[c.id] = r[IMP.mapping[c.id]] || '';
    });
    return obj;
  }).filter(r => r.data || r.importo);

  const token = await csrfFresco();

  try {
    const res = await fetch((window.BASE_URL || '') + '/api/import.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Csrf-Token': token,
      },
      body: JSON.stringify({ righe: righeFormattate, csrf_token: token }),
      credentials: 'same-origin',
    });
    const data = await res.json();
    if (!data.successo) throw new Error(data.errore);

    const d = data.dati;
    const ris = document.getElementById('importRisultato');
    ris.className = 'mt-4 text-sm rounded-lg p-4 bg-green-50 border border-green-200 text-green-800';
    ris.innerHTML = `<strong>✓ ${d.messaggio}</strong>
      ${d.errori?.length ? `<ul class="mt-2 text-xs text-orange-700 list-disc list-inside">${d.errori.slice(0,10).map(e=>`<li>${esc(e)}</li>`).join('')}${d.errori.length>10?`<li>…e altri ${d.errori.length-10}</li>`:''}</ul>` : ''}
      <button onclick="resetImport()" class="mt-3 text-xs text-indigo-600 underline block">Importa un altro file</button>`;
    ris.classList.remove('hidden');
    caricaDashboard();

  } catch(e) {
    const ris = document.getElementById('importRisultato');
    ris.className = 'mt-4 text-sm rounded-lg p-4 bg-red-50 border border-red-200 text-red-700';
    ris.innerHTML = `✗ ${esc(e.message)}`;
    ris.classList.remove('hidden');
  } finally {
    btn.disabled = false;
    spinner.classList.add('hidden');
    testo.textContent = 'Importa dati';
  }
}

// ── Export ───────────────────────────────────────────────────
function esporta(formato) {
  const anno = document.getElementById('exportAnno').value;
  const mese = document.getElementById('exportMese').value;
  let url    = (window.BASE_URL || '') + `/api/export.php?formato=${formato}&anno=${anno}`;
  if (mese) url += `&mese=${mese}`;
  window.location.href = url;
}

// ── Utility ──────────────────────────────────────────────────
// Importi e date secondo le preferenze dell'utente (vedi assets/i18n.js)
function formatEuro(v) {
  return I18N.fmtImporto(v || 0);
}

function formatData(s) {
  if (!s) return '—';
  return I18N.fmtData(s);
}

function oggi() {
  return new Date().toISOString().split('T')[0];
}

function esc(s) {
  // I dati degli utenti non vanno tradotti dal motore i18n
  if (window.I18N && s != null && s !== '') I18N.datoUtente(s);
  return String(s || '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

function debounce(fn, ms) {
  let t;
  return (...args) => { clearTimeout(t); t = setTimeout(() => fn(...args), ms); };
}

function bindEventiSpese() {
  ['listaSpese', 'listaUltimeSpese'].forEach(containerId => {
    const el = document.getElementById(containerId);
    if (!el || el._speseBound) return;
    el.addEventListener('click', (ev) => {
      const btn = ev.target.closest('button[data-action][data-spesa-id]');
      if (!btn) return;
      ev.preventDefault();
      const id     = btn.dataset.spesaId;
      const action = btn.dataset.action;
      if (action === 'modifica') modificaSpesa(id);
      else if (action === 'elimina') eliminaSpesa(id);
    });
    el._speseBound = true;
  });
}

function aggiornaFiltroSottocategorie() {
  const sel = document.getElementById('filtroSottocategoriaSpese');
  if (!sel) return;
  const idCat = document.getElementById('filtroCatSpese').value;
  sel.innerHTML = '<option value="">Tutte le sottocategorie</option>';
  if (!idCat) return;
  const cat = S.categorie.find(c => String(c.id) === String(idCat));
  (cat?.sottocategorie || []).filter(s => s.attiva).forEach(s => {
    sel.innerHTML += `<option value="${s.id}">${esc(s.nome)}</option>`;
  });
}

function bindPivotToggle() {
  const el = document.getElementById('tabellaPivot');
  if (!el || el._pivotBound) return;
  el.addEventListener('click', (ev) => {
    const btn = ev.target.closest('button.pivot-toggle');
    if (!btn) return;
    const id       = btn.dataset.toggle;
    const expanded = btn.getAttribute('aria-expanded') === 'true';
    btn.setAttribute('aria-expanded', expanded ? 'false' : 'true');
    btn.textContent = expanded ? '▶' : '▼';
    el.querySelectorAll(`tr[data-tree-id^="${id}/"]`).forEach(tr => {
      tr.style.display = expanded ? 'none' : '';
      if (expanded) {
        const childBtn = tr.querySelector('.pivot-toggle');
        if (childBtn) {
          childBtn.setAttribute('aria-expanded', 'true');
          childBtn.textContent = '▼';
        }
      }
    });
  });
  el._pivotBound = true;
}

// ─── SEZIONE EVENTI ────────────────────────────────────────────
async function caricaEventi() {
  const lista     = document.getElementById('eventiLista');
  const dettaglio = document.getElementById('eventoDettaglio');
  if (!lista) return;

  lista.classList.remove('hidden');
  dettaglio.classList.add('hidden');
  lista.innerHTML = '<div class="text-gray-500 text-sm py-4 text-center">Caricamento…</div>';

  try {
    const dati = await api('/api/eventi.php?action=list');
    if (!dati || !dati.length) {
      lista.innerHTML = `<div class="text-gray-400 text-sm py-8 text-center">Nessun evento creato.</div>`;
      return;
    }

    lista.innerHTML = dati.map(e => {
      const speso   = parseFloat(e.totale_speso   || 0);
      const stimato = parseFloat(e.totale_stimato || 0);
      const rest    = stimato - speso;
      const perc    = stimato > 0 ? Math.min(100, (speso / stimato) * 100).toFixed(1) : 0;
      const colore  = e.colore || '#4f46e5';

      // Bottone modifica: solo admin della casa
      const btnModifica = isAdmin() ? `
        <button class="btn-modifica-evento flex-shrink-0 text-xs text-indigo-500 hover:text-indigo-700
                       bg-white border border-indigo-200 rounded-lg px-2 py-1 transition-colors"
                data-ev-mod="${e.id}">✎</button>` : '';

      return `
        <div class="bg-white rounded-xl p-4 border border-gray-100 hover:shadow-md transition"
             style="border-left:4px solid ${colore};position:relative">
          <div class="flex justify-between items-start mb-2 gap-2">
            <h3 class="font-semibold text-gray-800 flex-1 cursor-pointer" data-evento-id="${e.id}">
              ${esc(e.nome)}
            </h3>
            <div class="flex items-center gap-2 flex-shrink-0">
              ${btnModifica}
              <span class="text-xs text-gray-400">${esc(e.descrizione || '')}</span>
            </div>
          </div>
          ${typeof renderChipCollegamenti === 'function' ? renderChipCollegamenti(e.collegamenti, colore) : ''}
          <div class="flex flex-wrap gap-3 text-sm mb-3 cursor-pointer" data-evento-id="${e.id}">
            <span>🎯 Stimato: <strong>${I18N.fmtImporto(stimato)}</strong></span>
            <span>💸 Speso: <strong>${I18N.fmtImporto(speso)}</strong></span>
            <span class="${rest < 0 ? 'text-red-600' : 'text-green-600'}">
              ${rest < 0 ? '⚠️ Sforato' : '✅ Restante'}: <strong>${I18N.fmtImporto(Math.abs(rest))}</strong>
            </span>
          </div>
          <div class="w-full bg-gray-200 rounded-full h-2 cursor-pointer" data-evento-id="${e.id}">
            <div class="h-2 rounded-full" style="width:${perc}%;background:${colore}"></div>
          </div>
          <p class="text-xs text-gray-400 mt-1 cursor-pointer" data-evento-id="${e.id}">
            ${perc}% utilizzato
          </p>
        </div>`;
    }).join('');

    lista.querySelectorAll('[data-evento-id]').forEach(el =>
      el.addEventListener('click', () => apriEvento(parseInt(el.dataset.eventoId)))
    );

    lista.querySelectorAll('.btn-modifica-evento').forEach(btn =>
      btn.addEventListener('click', (ev) => {
        ev.stopPropagation();
        apriModalEvento(parseInt(btn.dataset.evMod));
      })
    );

  } catch(e) {
    lista.innerHTML = `<div class="text-red-600 text-sm py-4">Errore: ${esc(e.message)}</div>`;
  }
}

async function apriEvento(id) {
  const lista     = document.getElementById('eventiLista');
  const dettaglio = document.getElementById('eventoDettaglio');
  const content   = document.getElementById('eventoDettaglioContent');

  lista.classList.add('hidden');
  dettaglio.classList.remove('hidden');
  content.innerHTML = '<div class="text-gray-500 text-sm py-4 text-center">Caricamento…</div>';

  try {
    const e      = await api(`/api/eventi.php?action=get&id=${id}`);
    const colore = e.colore || '#4f46e5';

    const totStimato = (e.categorie || []).reduce((s, c) => s + parseFloat(c.importo_stimato || 0), 0);
    const totSpeso   = (e.categorie || []).reduce((s, c) => s + parseFloat(c.speso || 0), 0);
    const restante   = totStimato - totSpeso;

    // Bottone modifica evento: solo admin della casa
    const btnModificaEvento = isAdmin() ? `
      <button id="btnModificaEvento"
              class="mb-4 text-xs text-indigo-500 hover:text-indigo-700 bg-white border border-indigo-200
                     rounded-lg px-3 py-1.5 transition-colors">
        ✎ Modifica evento
      </button>` : '';

    content.innerHTML = `
      ${btnModificaEvento}
      <h2 class="text-xl font-bold mb-1" style="color:${colore}">${esc(e.nome)}</h2>
      <p class="text-gray-500 text-sm mb-4">${esc(e.descrizione || '')}</p>

      <div class="grid grid-cols-3 gap-3 mb-6">
        <div class="bg-indigo-50 rounded-lg p-3 text-center">
          <p class="text-xs text-gray-500">Stimato</p>
          <p class="text-lg font-bold text-indigo-700">${I18N.fmtImporto(totStimato)}</p>
        </div>
        <div class="bg-orange-50 rounded-lg p-3 text-center">
          <p class="text-xs text-gray-500">Speso</p>
          <p class="text-lg font-bold text-orange-600">${I18N.fmtImporto(totSpeso)}</p>
        </div>
        <div class="rounded-lg p-3 text-center ${restante < 0 ? 'bg-red-50' : 'bg-green-50'}">
          <p class="text-xs text-gray-500">Restante</p>
          <p class="text-lg font-bold ${restante < 0 ? 'text-red-600' : 'text-green-700'}">
            ${I18N.fmtImporto(restante)}
          </p>
        </div>
      </div>

      <canvas id="eventoChart" height="120" class="mb-6"></canvas>

      <div class="space-y-3">
        ${(e.categorie || []).map(c => {
          const sp   = parseFloat(c.speso || 0);
          const st   = parseFloat(c.importo_stimato || 0);
          const rest = st - sp;
          const prc  = st > 0 ? Math.min(100, (sp / st) * 100).toFixed(1) : 0;

          // Bottone modifica mini-cat: solo admin della casa
          const btnModificaVoce = isAdmin() ? `
            <button class="btn-modifica-minicat text-xs text-indigo-500 hover:text-indigo-700
                           border border-indigo-200 rounded px-2 py-0.5 bg-white transition-colors"
                    data-minicat-id="${c.id}" data-evento-id="${e.id}">✎</button>` : '';

          return `
            <div class="border border-gray-100 rounded-lg p-3 hover:bg-gray-50 transition"
                 data-cat-id="${c.id}" data-evento-id="${e.id}">
              <div class="flex justify-between items-center text-sm mb-1 gap-2">
                <span class="font-medium cursor-pointer flex-1">${esc(c.nome)}</span>
                <div class="flex items-center gap-2 flex-shrink-0">
                  ${btnModificaVoce}
                  <span class="text-gray-500">${I18N.fmtImporto(sp)} / ${I18N.fmtImporto(st)}</span>
                </div>
              </div>
              <div class="w-full bg-gray-200 rounded-full h-1.5 mb-1 cursor-pointer">
                <div class="h-1.5 rounded-full" style="width:${prc}%;background:${colore}"></div>
              </div>
              <div class="flex justify-between text-xs text-gray-400 cursor-pointer">
                <span>${prc}% utilizzato</span>
                <span class="${rest < 0 ? 'text-red-500' : 'text-green-600'}">
                  ${rest < 0 ? '⚠️ Sforato' : '✅ Restante'}: ${I18N.fmtImporto(Math.abs(rest))}
                </span>
              </div>
              <div data-spese-cat="${c.id}" class="hidden mt-3 space-y-1"></div>
            </div>`;
        }).join('')}
      </div>`;

    document.getElementById('btnModificaEvento')?.addEventListener('click', () => apriModalEvento(id));

    content.querySelectorAll('.btn-modifica-minicat').forEach(btn =>
      btn.addEventListener('click', (ev) => {
        ev.stopPropagation();
        apriModalMiniCat(parseInt(btn.dataset.minicatId), parseInt(btn.dataset.eventoId));
      })
    );

    content.querySelectorAll('[data-cat-id]').forEach(card => {
      card.addEventListener('click', (ev) => {
        if (ev.target.closest('.btn-modifica-minicat')) return;
        const catId    = parseInt(card.dataset.catId);
        const eventoId = parseInt(card.dataset.eventoId);
        const nome     = card.querySelector('.font-medium')?.textContent?.trim() || '';
        apriPopupSpeseCategoria(catId, eventoId, nome);
      });
    });

    const ctx = document.getElementById('eventoChart').getContext('2d');
    if (window._eventoChart) window._eventoChart.destroy();
    window._eventoChart = new Chart(ctx, {
      type: 'bar',
      data: {
        labels: (e.categorie || []).map(c => c.nome),
        datasets: [
          { label: t('Stimato'), data: (e.categorie || []).map(c => parseFloat(c.importo_stimato || 0)), backgroundColor: colore + '55', borderColor: colore, borderWidth: 1 },
          { label: t('Speso'),   data: (e.categorie || []).map(c => parseFloat(c.speso || 0)),           backgroundColor: colore,        borderColor: colore, borderWidth: 1 },
        ]
      },
      options: {
        responsive: true,
        plugins: { legend: { position: 'top' } },
        scales: { y: { beginAtZero: true } }
      }
    });

  } catch(err) {
    content.innerHTML = `<div class="text-red-600 text-sm py-4">Errore: ${esc(err.message)}</div>`;
    console.error('apriEvento:', err);
  }
}

function chiudiEventoDettaglio() {
  document.getElementById('eventoDettaglio').classList.add('hidden');
  document.getElementById('eventiLista').classList.remove('hidden');
}

// ─── POPUP ASSEGNAZIONE EVENTO ─────────────────────────────────
// Cerca gli eventi collegati alla categoria/sottocategoria della spesa:
// 0 eventi → niente; 1 evento → mini-categorie; 2+ eventi → scelta evento.
async function checkEventoDopoSpesa(spesaId, categoriaId, sottocategoriaId = null) {
  if (!categoriaId && !sottocategoriaId) return;
  try {
    const qs = new URLSearchParams();
    if (categoriaId)      qs.set('categoria_id', categoriaId);
    if (sottocategoriaId) qs.set('sottocategoria_id', sottocategoriaId);
    const eventi = await api(`/api/eventi.php?action=check_spesa&${qs.toString()}`);
    if (!Array.isArray(eventi) || !eventi.length) return;

    let assegnazione = null;
    try {
      assegnazione = await api(`/api/eventi.php?action=get_assegnazione&spesa_id=${spesaId}`);
    } catch(e) { console.error('ERRORE get_assegnazione:', e); }

    if (eventi.length === 1) {
      mostraPopupEvento(spesaId, eventi[0], assegnazione?.evento_categoria_id || null);
    } else {
      mostraSceltaEvento(spesaId, eventi, assegnazione);
    }
  } catch(e) { console.error('ERRORE checkEventoDopoSpesa:', e); }
}

// Popup di scelta tra più eventi collegati alla stessa categoria/sottocategoria
function mostraSceltaEvento(spesaId, eventi, assegnazione = null) {
  document.getElementById('modalAssegnaEvento')?.remove();
  const evAttuale = assegnazione?.evento_id || null;

  const overlay = document.createElement('div');
  overlay.id = 'modalAssegnaEvento';
  overlay.className = 'fixed inset-0 z-50 flex items-end sm:items-center justify-center';
  overlay.innerHTML = `
    <div class="modal-overlay absolute inset-0" data-chiudi="1"></div>
    <div class="relative bg-white rounded-t-2xl sm:rounded-2xl w-full sm:max-w-sm p-6 z-10 slide-up">
      <div class="flex items-center justify-between mb-2">
        <h3 class="font-semibold text-gray-800">🎯 Scegli l'evento</h3>
        <button data-chiudi="1" class="text-gray-400 hover:text-gray-600">✕</button>
      </div>
      <p class="text-xs text-gray-400 mb-4">
        Questa spesa può appartenere a ${eventi.length} eventi. Scegli quello pertinente, oppure salta.
      </p>
      <div class="space-y-2 mb-4 max-h-72 overflow-y-auto">
        ${eventi.map((ev, i) => {
          const colore  = esc(ev.colore || '#4f46e5');
          const stimato = (ev.categorie || []).reduce((t, c) => t + parseFloat(c.importo_stimato || 0), 0);
          const speso   = (ev.categorie || []).reduce((t, c) => t + parseFloat(c.speso || 0), 0);
          const attuale = evAttuale && evAttuale == ev.evento_id;
          return `
            <button data-ev-idx="${i}"
              class="w-full text-left border rounded-xl px-3 py-2.5 hover:bg-gray-50 transition
                     ${attuale ? 'border-indigo-400 bg-indigo-50' : 'border-gray-200'}"
              style="border-left:4px solid ${colore}">
              <div class="flex items-center justify-between gap-2">
                <span class="font-medium text-sm" style="color:${colore}">${esc(ev.evento_nome)}</span>
                ${attuale ? '<span class="text-indigo-500 text-xs">✓ attuale</span>' : '<span class="text-gray-300">›</span>'}
              </div>
              <div class="text-[11px] text-gray-500 mt-0.5">
                ${(ev.categorie || []).length} voci · Stimato ${I18N.fmtImporto(stimato)} · Speso ${I18N.fmtImporto(speso)}
              </div>
            </button>`;
        }).join('')}
      </div>
      <div class="flex gap-2">
        <button data-chiudi="1" class="flex-1 text-center text-sm text-gray-400 hover:text-gray-600 py-2">
          Salta${evAttuale ? ' — mantieni' : ''}
        </button>
        ${evAttuale ? `
        <button data-rimuovi="1" class="flex-1 text-center text-sm text-red-400 hover:text-red-600 py-2">
          🗑 Rimuovi da evento
        </button>` : ''}
      </div>
    </div>`;

  document.body.appendChild(overlay);

  overlay.querySelectorAll('[data-chiudi]').forEach(el =>
    el.addEventListener('click', () => overlay.remove())
  );
  overlay.querySelector('[data-rimuovi]')?.addEventListener('click', async () => {
    await api('/api/eventi.php?action=unassign_spesa', {
      method: 'POST',
      body: JSON.stringify({ spesa_id: spesaId }),
    });
    overlay.remove();
    _aggiornaVisteDopoEvento();
  });
  overlay.querySelectorAll('[data-ev-idx]').forEach(btn =>
    btn.addEventListener('click', () => {
      const ev = eventi[parseInt(btn.dataset.evIdx)];
      mostraPopupEvento(spesaId, ev, assegnazione?.evento_categoria_id || null, { eventi, assegnazione });
    })
  );
}

// ritorno: { eventi, assegnazione } se si arriva dalla scelta evento (mostra "← Altri eventi")
function mostraPopupEvento(spesaId, dati, assegnazioneCorrente = null, ritorno = null) {
  document.getElementById('modalAssegnaEvento')?.remove();

  const overlay = document.createElement('div');
  overlay.id = 'modalAssegnaEvento';
  overlay.className = 'fixed inset-0 z-50 flex items-end sm:items-center justify-center';
  overlay.innerHTML = `
    <div class="modal-overlay absolute inset-0" onclick="document.getElementById('modalAssegnaEvento').remove()"></div>
    <div class="relative bg-white rounded-t-2xl sm:rounded-2xl w-full sm:max-w-sm p-6 z-10 slide-up">
      <div class="flex items-center justify-between mb-2">
        <h3 class="font-semibold text-gray-800" style="color:${dati.colore}">🎯 ${esc(dati.evento_nome)}</h3>
        <button onclick="document.getElementById('modalAssegnaEvento').remove()" class="text-gray-400 hover:text-gray-600">✕</button>
      </div>
      <p class="text-xs text-gray-400 mb-4">
        ${assegnazioneCorrente ? 'Questa spesa è già assegnata (evidenziata). Puoi cambiarla o saltare per mantenerla.' : "Assegna questa spesa a una voce dell'evento, oppure salta."}
      </p>
      <div class="space-y-2 mb-4 max-h-64 overflow-y-auto">
        ${(dati.categorie || []).map(c => {
          const isCorrente = assegnazioneCorrente && assegnazioneCorrente == c.id;
          return `
            <button data-cat-id="${c.id}"
              class="btn-assegna-evento w-full text-left border rounded-lg px-3 py-2 hover:bg-indigo-50 text-sm transition
                     ${isCorrente ? 'border-indigo-400 bg-indigo-50' : 'border-gray-200'}">
              <span class="font-medium">${esc(c.nome)}</span>
              ${isCorrente ? '<span class="text-indigo-500 text-xs ml-1">✓ attuale</span>' : ''}
              <span class="text-gray-400 float-right">${I18N.fmtImporto(parseFloat(c.importo_stimato || 0))}</span>
            </button>`;
        }).join('')}
      </div>
      <div class="flex gap-2">
        ${ritorno ? `
        <button data-indietro="1"
          class="flex-1 text-center text-sm text-indigo-500 hover:text-indigo-700 py-2">
          ← Altri eventi
        </button>` : ''}
        <button onclick="document.getElementById('modalAssegnaEvento').remove()"
          class="flex-1 text-center text-sm text-gray-400 hover:text-gray-600 py-2">
          Salta${assegnazioneCorrente ? ' — mantieni' : ''}
        </button>
        ${assegnazioneCorrente ? `
        <button data-rimuovi="1"
          class="flex-1 text-center text-sm text-red-400 hover:text-red-600 py-2">
          🗑 Rimuovi da evento
        </button>` : ''}
      </div>
    </div>`;

  document.body.appendChild(overlay);

  overlay.querySelector('[data-rimuovi]')?.addEventListener('click', async () => {
    await api('/api/eventi.php?action=unassign_spesa', {
      method: 'POST',
      body: JSON.stringify({ spesa_id: spesaId }),
    });
    document.getElementById('modalAssegnaEvento').remove();
    _aggiornaVisteDopoEvento();
  });

  overlay.querySelector('[data-indietro]')?.addEventListener('click', () =>
    mostraSceltaEvento(spesaId, ritorno.eventi, ritorno.assegnazione)
  );

  overlay.querySelectorAll('.btn-assegna-evento').forEach(btn =>
    btn.addEventListener('click', () => assegnaSpesaEvento(spesaId, parseInt(btn.dataset.catId)))
  );
}

async function assegnaSpesaEvento(spesaId, catId) {
  document.getElementById('modalAssegnaEvento')?.remove();
  await verificaSforamentoEAssegna(spesaId, catId);
}

async function apriPopupSpeseCategoria(catId, eventoId, nomeCategoria) {
  document.getElementById('modalSpeseCategoria')?.remove();

  const overlay = document.createElement('div');
  overlay.id = 'modalSpeseCategoria';
  overlay.className = 'fixed inset-0 z-50 flex items-end sm:items-center justify-center';
  overlay.innerHTML = `
    <div class="modal-overlay absolute inset-0"
         onclick="document.getElementById('modalSpeseCategoria')?.remove()"></div>
    <div class="relative bg-white rounded-t-2xl sm:rounded-2xl w-full sm:max-w-lg z-10 slide-up flex flex-col"
         style="max-height:85vh">
      <div class="flex items-center justify-between px-5 pt-5 pb-3 border-b border-gray-100 flex-shrink-0">
        <h3 class="font-semibold text-gray-800">📋 ${esc(nomeCategoria)}</h3>
        <button onclick="document.getElementById('modalSpeseCategoria')?.remove()"
                class="text-gray-400 hover:text-gray-600 p-1 text-lg">✕</button>
      </div>
      <div id="modalSpeseCatBody" class="overflow-y-auto flex-1 px-5 py-3">
        <div class="spinner-center"><div class="spinner"></div><span>Caricamento…</span></div>
      </div>
    </div>`;

  document.body.appendChild(overlay);
  await caricaSpeseCategoriaInPopup(catId, eventoId);
}

async function caricaSpeseCategoriaInPopup(catId, eventoId) {
  const body = document.getElementById('modalSpeseCatBody');
  if (!body) return;

  body.innerHTML = '<div class="spinner-center"><div class="spinner"></div><span>Caricamento…</span></div>';

  try {
    const dati  = await api(`/api/spese.php?id_sottocategoria_evento=${catId}&per_pagina=200&pagina=1`);
    const spese = dati?.spese || [];

    if (!spese.length) {
      body.innerHTML = '<div class="text-gray-400 text-sm py-8 text-center">Nessuna spesa assegnata a questa voce.</div>';
      return;
    }

    const totale = spese.reduce((acc, s) => acc + Math.abs(parseFloat(s.importo)), 0);

    body.innerHTML = `
      <div class="mb-3 flex items-center justify-between">
        <span class="text-xs text-gray-500">${spese.length} spese</span>
        <span class="text-sm font-bold text-gray-800">${formatEuro(totale)}</span>
      </div>
      <div class="space-y-2" id="speseCatList">
        ${spese.map(s => rigaSpesaInPopup(s)).join('')}
      </div>`;

    body.querySelectorAll('button[data-action]').forEach(btn => {
      btn.addEventListener('click', async (ev) => {
        ev.stopPropagation();
        const id     = btn.dataset.spesaId;
        const action = btn.dataset.action;
        if (action === 'modifica') await modificaSpesaDaPopup(id, catId, eventoId);
        else if (action === 'elimina') await eliminaSpesaDaPopup(id, catId, eventoId);
      });
    });

  } catch(e) {
    body.innerHTML = `<div class="text-red-500 text-sm py-4 text-center">${esc(e.message)}</div>`;
  }
}

function rigaSpesaInPopup(s) {
  const tipo   = s.categoria_tipo === 'accredito';
  const colore = tipo ? 'text-green-600' : 'text-red-600';
  const segno  = tipo ? '+' : '-';
  // Può modificare: il proprio autore O admin della casa
  const isMio  = S.utente && s.utente_id != null
    ? (s.utente_id === S.utente.id || isAdmin())
    : false;

  const azioniHtml = isMio ? `
    <button type="button"
            class="btn-modifica-spesa p-2 rounded hover:bg-gray-100 flex-shrink-0"
            data-action="modifica"
            data-spesa-id="${esc(s.id)}"
            title="Modifica">✎</button>
    <button type="button"
            class="btn-elimina-spesa p-2 rounded hover:bg-red-50 text-red-600 flex-shrink-0"
            data-action="elimina"
            data-spesa-id="${esc(s.id)}"
            title="Elimina">✕</button>` : '';

  return `
    <div class="flex items-center gap-3 p-2 rounded-lg border border-gray-100 bg-gray-50">
      <div class="w-8 h-8 rounded-full flex-shrink-0 flex items-center justify-center text-white text-xs font-bold"
           style="background:${esc(s.categoria_colore || '#999')}">
        ${esc((s.categoria || '?').charAt(0))}
      </div>
      <div class="flex-1 min-w-0">
        <p class="text-sm font-medium text-gray-800" style="word-break:break-word;white-space:normal">${esc(s.descrizione || '—')}</p>
        <p class="text-xs text-gray-400" style="word-break:break-word;white-space:normal">${formatData(s.data_spesa)} · ${esc(I18N.nomeCategoria(s.categoria))}${s.sottocategoria ? ' / ' + esc(s.sottocategoria) : ''}${s.privata ? ' · 🔒' : ''}</p>
      </div>
      <div class="flex items-center gap-1 flex-shrink-0">
        <span class="text-sm font-semibold ${colore} whitespace-nowrap">${segno}${formatEuro(Math.abs(s.importo))}</span>
        ${azioniHtml}
      </div>
    </div>`;
}

async function modificaSpesaDaPopup(spesaId, catId, eventoId) {
  try {
    const dati = await api(`/api/spese.php?id=${encodeURIComponent(spesaId)}`);
    if (!dati?.spesa) { alert('Spesa non trovata.'); return; }

    S._popupCatId    = catId;
    S._popupEventoId = eventoId;

    const popupSpese = document.getElementById('modalSpeseCategoria');
    if (popupSpese) popupSpese.style.zIndex = '40';

    apriModalSpesa(dati.spesa);

    // Ricarica la popup solo a salvataggio RIUSCITO: se la validazione fallisce
    // il modal spesa deve restare sopra la popup per correggere i dati.
    const modalSpesa = document.getElementById('modalSpesa');
    if (modalSpesa._salvataHandler) modalSpesa.removeEventListener('spesaSalvata', modalSpesa._salvataHandler);
    modalSpesa._salvataHandler = () => {
      setTimeout(async () => {
        if (!document.getElementById('modalSpeseCategoria')) return;
        await caricaSpeseCategoriaInPopup(S._popupCatId, S._popupEventoId);
        if (S._popupEventoId) apriEvento(S._popupEventoId);
      }, 400);
    };
    modalSpesa.addEventListener('spesaSalvata', modalSpesa._salvataHandler, { once: true });

    // Alla chiusura del modal (salvata o annullata) la popup torna in primo piano
    if (modalSpesa._chiusuraHandler) modalSpesa.removeEventListener('chiusuraSpesa', modalSpesa._chiusuraHandler);
    modalSpesa._chiusuraHandler = () => {
      _ripristinaPopupSpese();
      modalSpesa.removeEventListener('spesaSalvata', modalSpesa._salvataHandler);
    };
    modalSpesa.addEventListener('chiusuraSpesa', modalSpesa._chiusuraHandler, { once: true });

  } catch(e) {
    alert('Errore nel caricamento della spesa: ' + e.message);
  }
}

function _ripristinaPopupSpese() {
  const popupSpese = document.getElementById('modalSpeseCategoria');
  if (popupSpese) popupSpese.style.zIndex = '50';
}

async function eliminaSpesaDaPopup(spesaId, catId, eventoId) {
  if (!confirm('Eliminare questa spesa? L\'operazione è irreversibile.')) return;
  try {
    await api(`/api/spese.php?id=${encodeURIComponent(spesaId)}`, { method: 'DELETE' });
    await caricaSpeseCategoriaInPopup(catId, eventoId);
    if (eventoId) apriEvento(eventoId);
  } catch(e) {
    alert('Errore: ' + e.message);
  }
}

async function apriModalMiniCat(catId, eventoId) {
  let cat;
  try {
    const e = await api(`/api/eventi.php?action=get&id=${eventoId}`);
    cat = (e.categorie || []).find(c => c.id === catId);
    if (!cat) { alert('Voce non trovata.'); return; }
  } catch(err) { alert('Errore: ' + err.message); return; }

  const speso    = parseFloat(cat.speso || 0);
  const stimato  = parseFloat(cat.importo_stimato || 0);
  const restante = stimato - speso;

  document.getElementById('modalMiniCat')?.remove();

  const overlay = document.createElement('div');
  overlay.id = 'modalMiniCat';
  overlay.className = 'fixed inset-0 z-50 flex items-end sm:items-center justify-center';
  overlay.innerHTML = `
    <div class="modal-overlay absolute inset-0"
         onclick="document.getElementById('modalMiniCat')?.remove()"></div>
    <div class="relative bg-white rounded-t-2xl sm:rounded-2xl w-full sm:max-w-sm p-6 z-10 slide-up">
      <div class="flex items-center justify-between mb-4">
        <h3 class="font-semibold text-gray-800">✎ Modifica voce</h3>
        <button onclick="document.getElementById('modalMiniCat')?.remove()"
                class="text-gray-400 hover:text-gray-600 text-lg">✕</button>
      </div>
      <div class="grid grid-cols-2 gap-2 mb-4">
        <div class="bg-orange-50 rounded-lg p-2 text-center">
          <p class="text-xs text-gray-500">Già speso</p>
          <p class="text-sm font-bold text-orange-600">${I18N.fmtImporto(speso)}</p>
        </div>
        <div class="rounded-lg p-2 text-center ${restante < 0 ? 'bg-red-50' : 'bg-green-50'}">
          <p class="text-xs text-gray-500">Restante</p>
          <p class="text-sm font-bold ${restante < 0 ? 'text-red-600' : 'text-green-600'}">
            ${restante < 0 ? '⚠️ ' : ''}${I18N.fmtImporto(restante)}
          </p>
        </div>
      </div>
      <div id="miniCatErrore" class="hidden bg-red-50 border border-red-200 text-red-700 text-sm rounded-lg px-3 py-2 mb-3"></div>
      <div class="mb-3">
        <label class="block text-xs font-medium text-gray-600 mb-1">Nome voce</label>
        <input type="text" id="miniCatNome" value="${esc(cat.nome)}" maxlength="255"
               class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
      </div>
      <div class="mb-5">
        <label class="block text-xs font-medium text-gray-600 mb-1">Importo stimato (€)</label>
        <input type="number" id="miniCatImporto" value="${stimato.toFixed(2)}"
               step="0.01" min="0"
               class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
      </div>
      <button onclick="salvaMiniCat(${catId}, ${eventoId})"
              class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-semibold py-3 rounded-xl text-sm transition-colors">
        Salva modifiche
      </button>
    </div>`;

  document.body.appendChild(overlay);
}

async function salvaMiniCat(catId, eventoId) {
  const errEl   = document.getElementById('miniCatErrore');
  const nome    = document.getElementById('miniCatNome').value.trim();
  const importo = parseFloat(document.getElementById('miniCatImporto').value);

  errEl.classList.add('hidden');
  if (!nome) { errEl.textContent = 'Nome obbligatorio.'; errEl.classList.remove('hidden'); return; }
  if (isNaN(importo) || importo < 0) { errEl.textContent = 'Importo non valido.'; errEl.classList.remove('hidden'); return; }

  try {
    await api('/api/eventi.php?action=cat_update', {
      method: 'POST',
      body: JSON.stringify({ id: catId, nome, importo_stimato: importo, evento_id: eventoId }),
    });
    document.getElementById('modalMiniCat')?.remove();
    apriEvento(eventoId);
  } catch(e) {
    errEl.textContent = e.message;
    errEl.classList.remove('hidden');
  }
}

// ── Popup sforamento budget ───────────────────────────────────
async function verificaSforamentoEAssegna(spesaId, catId) {
  let cat, evento;
  try {
    const datiCheck = await api(`/api/eventi.php?action=get_cat_info&cat_id=${catId}&spesa_id=${spesaId}`);
    cat    = datiCheck.categoria;
    evento = datiCheck.evento;
  } catch(e) {
    await _eseguiAssegnazioneSpesa(spesaId, catId);
    return;
  }

  const speso        = parseFloat(cat.speso || 0);
  const importoSpesa = parseFloat(cat.importo_spesa_corrente || 0);
  const stimato      = parseFloat(cat.importo_stimato || 0);
  const nuovoTotale  = speso + importoSpesa;
  const sforamento   = nuovoTotale - stimato;

  if (sforamento <= 0) {
    await _eseguiAssegnazioneSpesa(spesaId, catId);
    return;
  }

  _mostraPopupSforamento(spesaId, catId, { stimato, speso, importoSpesa, nuovoTotale, sforamento, evento });
}

function _mostraPopupSforamento(spesaId, catId, dati) {
  document.getElementById('modalSforamento')?.remove();

  const overlay = document.createElement('div');
  overlay.id = 'modalSforamento';
  overlay.className = 'fixed inset-0 z-[60] flex items-end sm:items-center justify-center';
  overlay.innerHTML = `
    <div class="modal-overlay absolute inset-0"></div>
    <div class="relative bg-white rounded-t-2xl sm:rounded-2xl w-full sm:max-w-sm p-6 z-10 slide-up">
      <div class="text-center mb-4">
        <div class="text-4xl mb-2">⚠️</div>
        <h3 class="font-bold text-gray-800 text-lg">Budget superato!</h3>
        <p class="text-xs text-gray-500 mt-1">Stai sforando il budget di questa voce</p>
      </div>
      <div class="bg-red-50 border border-red-200 rounded-xl p-4 mb-5 space-y-2">
        <div class="flex justify-between text-sm"><span class="text-gray-600">Budget stimato</span><span class="font-semibold">${I18N.fmtImporto(dati.stimato)}</span></div>
        <div class="flex justify-between text-sm"><span class="text-gray-600">Già speso</span><span class="font-semibold text-orange-600">${I18N.fmtImporto(dati.speso)}</span></div>
        <div class="flex justify-between text-sm"><span class="text-gray-600">Questa spesa</span><span class="font-semibold text-orange-600">+ ${I18N.fmtImporto(dati.importoSpesa)}</span></div>
        <div class="border-t border-red-200 pt-2 flex justify-between text-sm font-bold">
          <span class="text-red-700">Sforamento</span>
          <span class="text-red-700">${I18N.fmtImporto(dati.sforamento)}</span>
        </div>
      </div>
      <div class="flex gap-3">
        <button onclick="_rifiutaSforamento(${spesaId})"
                class="flex-1 border border-gray-200 rounded-xl py-3 text-sm font-semibold text-gray-600 hover:bg-gray-50 transition-colors">
          ✕ Annulla
        </button>
        <button onclick="_confermaSforamento(${spesaId}, ${catId})"
                class="flex-1 bg-red-600 hover:bg-red-700 text-white rounded-xl py-3 text-sm font-semibold transition-colors">
          Conferma lo stesso
        </button>
      </div>
    </div>`;

  document.body.appendChild(overlay);
}

async function _confermaSforamento(spesaId, catId) {
  document.getElementById('modalSforamento')?.remove();
  await _eseguiAssegnazioneSpesa(spesaId, catId);
}

async function _rifiutaSforamento(spesaId) {
  document.getElementById('modalSforamento')?.remove();
  try {
    const dati = await api(`/api/spese.php?id=${spesaId}`);
    // Riapre la scelta (evento o mini-categoria) per la stessa spesa
    const categoriaId      = dati?.spesa?.categoria_id || null;
    const sottocategoriaId = dati?.spesa?.sottocategoria_id || null;
    if (categoriaId || sottocategoriaId) {
      await checkEventoDopoSpesa(spesaId, categoriaId, sottocategoriaId);
    }
  } catch(e) { console.error('Errore riapertura popup:', e); }
}

async function _eseguiAssegnazioneSpesa(spesaId, catId) {
  try {
    await api('/api/eventi.php?action=assign_spesa', {
      method: 'POST',
      body: JSON.stringify({ spesa_id: spesaId, evento_categoria_id: catId }),
    });
  } catch(e) { console.error('Errore assegnazione evento:', e.message); }
  finally {
    document.getElementById('modalAssegnaEvento')?.remove();
    _aggiornaVisteDopoEvento();
  }
}

// Ricarica le viste che mostrano l'evento associato alla spesa (badge 🎯, totali evento)
function _aggiornaVisteDopoEvento() {
  if (typeof caricaSpese === 'function') caricaSpese(S.paginaCorrente || 1);
  if (typeof caricaDashboard === 'function') caricaDashboard();
  // Popup "spese della mini-categoria" aperta dal dettaglio evento
  if (document.getElementById('modalSpeseCategoria') && S._popupCatId) {
    caricaSpeseCategoriaInPopup(S._popupCatId, S._popupEventoId);
    if (S._popupEventoId) apriEvento(S._popupEventoId);
  }
  const lista = document.getElementById('eventiLista');
  const dettaglio = document.getElementById('eventoDettaglio');
  if (lista && !lista.classList.contains('hidden') && dettaglio?.classList.contains('hidden')) {
    caricaEventi();
  }
}