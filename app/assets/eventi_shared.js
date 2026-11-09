// ============================================================
// EVENTI-SHARED.JS - Logica modal evento condivisa tra app.js e admin.js
// Richiede che nel contesto sia disponibile: api(), esc()
// In app.js  → usa S.utente, ricarica con caricaEventi() + apriEvento()
// In admin.js → usa A.utente, ricarica con caricaEventi()
// ============================================================

let _eventoCorrente = null;
let _miniCats       = [];

// Stato collegamenti categoria/sottocategoria dell'evento in modifica
let _linkData   = { categorie: [], eventi: [], collegamenti: { categorie: [], sottocategorie: [] } };
let _selCat     = new Set();   // categorie collegate per intero
let _selSubIncl = new Set();   // sottocategorie collegate singolarmente (categoria non intera)
let _selSubEscl = new Set();   // sottocategorie escluse da una categoria collegata per intero
let _catAperte  = new Set();   // categorie espanse nella fisarmonica
let _linkDataOk = false;       // false se il caricamento è fallito: non sovrascrivere i collegamenti

// ── Contesto: capisce se siamo in app o admin ────────────────
function _isAdmin() {
  // In admin.js lo stato è A, in app.js è S
  if (typeof A !== 'undefined' && A.ruolo) return A.ruolo === 'admin';
  if (typeof S !== 'undefined' && S.casaAttiva) return S.casaAttiva.ruolo === 'admin';
  return false;
}

// Callback di ricarica lista eventi (diversa per app e admin)
function _onEventoSalvato() {
	if (typeof caricaDashboard === 'function') {
		  caricaDashboard();
		}
  if (typeof caricaEventi === 'function') caricaEventi();
  // La categoria EVENTI e le sue sottocategorie cambiano con gli eventi
  if (typeof caricaCategorie === 'function') caricaCategorie();
}

// ── Loading overlay ──────────────────────────────────────────
function _mostraLoadingEvento(msg = 'Caricamento…') {
  document.getElementById('eventoSharedLoading')?.remove();
  const el = document.createElement('div');
  el.id = 'eventoSharedLoading';
  el.style.cssText = `
    position:fixed;inset:0;z-index:9999;
    display:flex;align-items:center;justify-content:center;
    background:rgba(0,0,0,0.4);backdrop-filter:blur(4px);
    -webkit-backdrop-filter:blur(4px);
  `;
  el.innerHTML = `
    <div style="background:#fff;border-radius:16px;padding:28px 36px;
                display:flex;flex-direction:column;align-items:center;gap:14px;
                box-shadow:0 20px 60px rgba(0,0,0,0.2);min-width:180px;text-align:center">
      <div style="width:36px;height:36px;border:3px solid #e5e7eb;
                  border-top-color:#4f46e5;border-radius:50%;
                  animation:spin .7s linear infinite"></div>
      <p style="font-size:14px;font-weight:600;color:#374151;margin:0">${msg}</p>
    </div>`;
  document.body.appendChild(el);
}

function _nascondiLoadingEvento() {
  document.getElementById('eventoSharedLoading')?.remove();
}

// ── Apri modal evento ────────────────────────────────────────
async function apriModalEvento(id = null) {
	if (!_isAdmin()) {
	  alert('Solo gli admin della casa possono gestire eventi.');
	  return;
	}
  _eventoCorrente = id || null;
  _miniCats       = [];
  _selCat         = new Set();
  _selSubIncl     = new Set();
  _selSubEscl     = new Set();
  _catAperte      = new Set();

  _mostraLoadingEvento(id ? 'Caricamento evento…' : 'Preparazione…');

  _nascondiErrEvento();
  document.getElementById('eventoId').value          = '';
  document.getElementById('eventoNome').value        = '';
  document.getElementById('eventoDescrizione').value = '';
  document.getElementById('eventoColore').value      = '#4f46e5';
  document.getElementById('miniCatList').innerHTML   = '';

  try {
    // Carica categorie/collegamenti ed evento in parallelo
    const [linkRes, eventoRes] = await Promise.allSettled([
      api('/api/eventi.php?action=link_data'),
      id ? api(`/api/eventi.php?action=get&id=${id}`) : Promise.resolve(null),
    ]);

    _linkDataOk = linkRes.status === 'fulfilled' && !!linkRes.value;
    _linkData = linkRes.status === 'fulfilled' && linkRes.value
      ? linkRes.value
      : { categorie: [], eventi: [], collegamenti: { categorie: [], sottocategorie: [] } };
    _inizializzaSelezioneLink();

    if (id) {
      if (eventoRes.status === 'rejected') {
        _nascondiLoadingEvento();
        _mostraErrEvento(eventoRes.reason.message);
        return;
      }
      const e = eventoRes.value;
      document.getElementById('eventoId').value          = e.id;
      document.getElementById('eventoNome').value        = e.nome;
      document.getElementById('eventoDescrizione').value = e.descrizione || '';
      document.getElementById('eventoColore').value      = e.colore || '#4f46e5';
      _miniCats = (e.categorie || []).map(c => ({ ...c }));
    }

    document.getElementById('modalEventoTitolo').textContent = id ? 'Modifica evento' : 'Nuovo evento';
    _renderMiniCats();
    _renderSubcatsLink();
    // I badge e i chip usano il colore dell'evento: aggiorna al cambio colore
    document.getElementById('eventoColore').oninput = _renderSubcatsLink;

    _nascondiLoadingEvento();
    const modal = document.getElementById('modalEvento');
    modal.classList.remove('hidden');
    document.body.style.overflow = 'hidden';

  } catch(err) {
    _nascondiLoadingEvento();
    _mostraErrEvento(err.message);
  }
}

function chiudiModalEvento() {
  document.getElementById('modalEvento').classList.add('hidden');
  document.body.style.overflow = '';
}

// ── Render mini-categorie ────────────────────────────────────
function _renderMiniCats() {
  document.getElementById('miniCatList').innerHTML = _miniCats.map((c, i) => {
    const speso    = parseFloat(c.speso || 0);
    const stimato  = parseFloat(c.importo_stimato || 0);
    const restante = stimato - speso;
    const spesoHtml = c.id ? `
      <div class="flex gap-3 text-xs mt-1 px-1">
        <span class="text-orange-600">💸 Speso: <strong>${I18N.fmtImporto(speso)}</strong></span>
        <span class="${restante < 0 ? 'text-red-600' : 'text-green-600'}">
          ${restante < 0 ? '⚠️ Sforato' : '✅ Restante'}: <strong>${I18N.fmtImporto(Math.abs(restante))}</strong>
        </span>
      </div>` : '';

    return `
      <div class="border border-gray-100 rounded-lg p-2 bg-gray-50">
        <div class="flex gap-2 items-center">
          <input type="text" value="${esc(c.nome)}" placeholder="Nome voce"
            oninput="_miniCats[${i}].nome = this.value"
            class="border border-gray-200 rounded-lg px-3 py-2 text-sm flex-1 bg-white">
          <input type="number" value="${stimato.toFixed(2)}"
            placeholder="€ Stimato" step="0.01" min="0"
            oninput="_miniCats[${i}].importo_stimato = parseFloat(this.value)||0"
            class="border border-gray-200 rounded-lg px-3 py-2 text-sm w-28 bg-white">
          <button onclick="_miniCats.splice(${i},1);_renderMiniCats()"
            class="text-red-400 hover:text-red-600 text-lg leading-none px-1 flex-shrink-0">×</button>
        </div>
        ${spesoHtml}
      </div>`;
  }).join('');
}

function aggiungiMiniCategoria() {
  _miniCats.push({ id: null, nome: '', importo_stimato: 0, ordine: _miniCats.length });
  _renderMiniCats();
}

// ── Collegamento categorie / sottocategorie (fisarmonica) ────
//
// Regole:
//  - Spunta sulla categoria  = "intera categoria": tutte le sottocategorie
//    (anche future) e le spese senza sottocategoria attivano la popup.
//    Le singole sottocategorie si possono poi togliere (esclusioni).
//  - Categoria non spuntata  = si scelgono le singole sottocategorie.

function _inizializzaSelezioneLink() {
  const ev = _eventoCorrente ? Number(_eventoCorrente) : null;
  if (!ev) return;
  const coll = _linkData.collegamenti || { categorie: [], sottocategorie: [] };
  coll.categorie.filter(l => l.evento_id === ev).forEach(l => _selCat.add(l.categoria_id));
  coll.sottocategorie.filter(l => l.evento_id === ev).forEach(l => {
    if (l.escluso) _selSubEscl.add(l.sottocategoria_id);
    else           _selSubIncl.add(l.sottocategoria_id);
  });
  // Apre le categorie con selezioni parziali o esclusioni, per renderle visibili
  (_linkData.categorie || []).forEach(c => {
    const st = _statoCategoria(c);
    if (st.parziale || st.escluse > 0) _catAperte.add(c.id);
  });
}

function _coloreEventoCorrente() {
  return document.getElementById('eventoColore')?.value || '#4f46e5';
}

// Stato di una categoria per l'evento in modifica
function _statoCategoria(c) {
  const subs    = c.sottocategorie || [];
  const intera  = _selCat.has(c.id);
  const escluse = intera ? subs.filter(s => _selSubEscl.has(s.id)).length : 0;
  const incluse = intera ? subs.length - escluse : subs.filter(s => _selSubIncl.has(s.id)).length;
  return { intera, escluse, incluse, totale: subs.length, parziale: !intera && incluse > 0 };
}

// Altri eventi che coprono una sottocategoria (diretta o tramite categoria intera non esclusa)
function _altriEventiSub(sub, catId) {
  const ev   = _eventoCorrente ? Number(_eventoCorrente) : null;
  const coll = _linkData.collegamenti || { categorie: [], sottocategorie: [] };
  return (_linkData.eventi || []).filter(e => {
    if (e.id === ev) return false;
    const riga = coll.sottocategorie.find(l => l.evento_id === e.id && l.sottocategoria_id === sub.id);
    if (riga) return !riga.escluso;   // collegata direttamente oppure esclusa
    return coll.categorie.some(l => l.evento_id === e.id && l.categoria_id === catId);
  });
}

// Altri eventi collegati a una categoria (intera o con alcune sottocategorie)
function _altriEventiCat(c) {
  const ev   = _eventoCorrente ? Number(_eventoCorrente) : null;
  const coll = _linkData.collegamenti || { categorie: [], sottocategorie: [] };
  const subIds = new Set((c.sottocategorie || []).map(s => s.id));
  const out = [];
  (_linkData.eventi || []).forEach(e => {
    if (e.id === ev) return;
    const intera = coll.categorie.some(l => l.evento_id === e.id && l.categoria_id === c.id);
    const nSub   = coll.sottocategorie.filter(l => l.evento_id === e.id && !l.escluso && subIds.has(l.sottocategoria_id)).length;
    if (intera)    out.push({ ...e, descr: 'intera categoria' });
    else if (nSub) out.push({ ...e, descr: `${nSub} sottocategori${nSub === 1 ? 'a' : 'e'}` });
  });
  return out;
}

function _puntiniEventi(lista) {
  return lista.map(e => `
    <span title="${esc(e.nome)}${e.descr ? ' — ' + esc(e.descr) : ''}"
          style="display:inline-block;width:9px;height:9px;border-radius:50%;
                 background:${esc(e.colore || '#9ca3af')};border:1px solid #fff;
                 box-shadow:0 0 0 1px #e5e7eb"></span>`).join('');
}

function _badgeCategoria(st) {
  const col = esc(_coloreEventoCorrente());
  if (st.intera && st.escluse === 0) {
    return `<span class="text-[10px] font-semibold text-white rounded-full px-2 py-0.5" style="background:${col}">● Tutta</span>`;
  }
  if (st.intera) {
    return `<span class="text-[10px] font-semibold text-white rounded-full px-2 py-0.5" style="background:${col}"
              title="${st.escluse} sottocategori${st.escluse === 1 ? 'a esclusa' : 'e escluse'}">● Tutta −${st.escluse}</span>`;
  }
  if (st.parziale) {
    return `<span class="text-[10px] font-semibold rounded-full px-2 py-0.5"
              style="color:${col};border:1px solid ${col}">${st.incluse}/${st.totale}</span>`;
  }
  return '';
}

function _renderSubcatsLink() {
  const container = document.getElementById('subcatLinkList');
  if (!container) return;
  const categorie = _linkData.categorie || [];
  if (!categorie.length) {
    container.innerHTML = '<p class="text-xs text-gray-400 px-2">Nessuna categoria disponibile.</p>';
    _renderRiepilogoLink();
    return;
  }

  container.innerHTML = categorie.map(c => {
    const st     = _statoCategoria(c);
    const aperta = _catAperte.has(c.id);
    const subs   = c.sottocategorie || [];

    const righeSub = aperta ? (subs.length ? subs.map(s => {
      const checked = st.intera ? !_selSubEscl.has(s.id) : _selSubIncl.has(s.id);
      const escl    = st.intera && _selSubEscl.has(s.id);
      return `
        <label class="flex items-center gap-2 text-xs cursor-pointer hover:bg-gray-50 pl-8 pr-2 py-1 rounded">
          <input type="checkbox" ${checked ? 'checked' : ''}
            onchange="_toggleSubLink(${c.id}, ${s.id}, this.checked)">
          <span class="flex-1 ${escl ? 'text-gray-400 line-through' : 'text-gray-600'}">
            ${esc(s.nome)}${s.attiva ? '' : ' <span class="text-gray-400">(disattiva)</span>'}
          </span>
          <span class="flex gap-0.5">${_puntiniEventi(_altriEventiSub(s, c.id))}</span>
        </label>`;
    }).join('') : '<p class="text-[11px] text-gray-400 pl-8 py-1">Nessuna sottocategoria.</p>') : '';

    return `
      <div class="rounded ${st.intera || st.parziale ? 'bg-indigo-50/40' : ''}">
        <div class="flex items-center gap-2 text-xs px-2 py-1.5 hover:bg-gray-50 rounded">
          <input type="checkbox" data-cat-check="${c.id}" ${st.intera ? 'checked' : ''}
            title="Collega l'intera categoria"
            onchange="_toggleCatLink(${c.id}, this.checked)">
          <button type="button" onclick="_toggleCatAperta(${c.id})"
            class="flex-1 flex items-center gap-1.5 text-left min-w-0">
            <span class="text-gray-400 w-3 inline-block">${aperta ? '▾' : '▸'}</span>
            <span style="display:inline-block;width:8px;height:8px;border-radius:2px;background:${esc(c.colore || '#6366f1')}"></span>
            <span class="font-medium text-gray-700 truncate ${c.attiva ? '' : 'opacity-50'}">${esc(c.nome)}</span>
            <span class="text-gray-400">(${subs.length})</span>
          </button>
          <span class="flex gap-0.5">${_puntiniEventi(_altriEventiCat(c))}</span>
          ${_badgeCategoria(st)}
        </div>
        ${righeSub}
      </div>`;
  }).join('');

  // Stato "parziale" della checkbox categoria
  container.querySelectorAll('[data-cat-check]').forEach(cb => {
    const c = categorie.find(x => x.id == cb.dataset.catCheck);
    if (c) cb.indeterminate = _statoCategoria(c).parziale;
  });

  _renderRiepilogoLink();
}

function _toggleCatAperta(catId) {
  if (_catAperte.has(catId)) _catAperte.delete(catId);
  else _catAperte.add(catId);
  _renderSubcatsLink();
}

function _toggleCatLink(catId, checked) {
  const c = (_linkData.categorie || []).find(x => x.id === catId);
  const subs = c ? c.sottocategorie || [] : [];
  subs.forEach(s => { _selSubIncl.delete(s.id); _selSubEscl.delete(s.id); });
  if (checked) {
    _selCat.add(catId);
    _catAperte.add(catId);   // mostra le sottocategorie per eventuali esclusioni
  } else {
    _selCat.delete(catId);
  }
  _renderSubcatsLink();
}

function _toggleSubLink(catId, subId, checked) {
  if (_selCat.has(catId)) {
    if (checked) _selSubEscl.delete(subId);
    else         _selSubEscl.add(subId);
  } else {
    if (checked) _selSubIncl.add(subId);
    else         _selSubIncl.delete(subId);
  }
  _renderSubcatsLink();
}

function _rimuoviLinkCat(catId) { _toggleCatLink(catId, false); }
function _rimuoviLinkSub(subId) { _selSubIncl.delete(subId); _renderSubcatsLink(); }

// Chip di riepilogo dei collegamenti sotto la fisarmonica
function _renderRiepilogoLink() {
  let box = document.getElementById('subcatLinkRiepilogo');
  const container = document.getElementById('subcatLinkList');
  if (!box && container) {
    box = document.createElement('div');
    box.id = 'subcatLinkRiepilogo';
    box.className = 'flex flex-wrap gap-1.5 mt-2';
    container.insertAdjacentElement('afterend', box);
  }
  if (!box) return;

  const col  = esc(_coloreEventoCorrente());
  const chip = (testo, titolo, onRemove) => `
    <span class="inline-flex items-center gap-1 text-[11px] rounded-full pl-2 pr-1 py-0.5"
          style="background:${col}1a;color:${col};border:1px solid ${col}55" title="${esc(titolo)}">
      ${testo}
      <button type="button" onclick="${onRemove}" class="leading-none px-1 opacity-70 hover:opacity-100">✕</button>
    </span>`;

  const out = [];
  (_linkData.categorie || []).forEach(c => {
    const subs = c.sottocategorie || [];
    if (_selCat.has(c.id)) {
      const escluse = subs.filter(s => _selSubEscl.has(s.id));
      const testo = `${esc(c.nome)} › ${escluse.length ? `tutte tranne ${escluse.length}` : 'tutte'}`;
      const titolo = escluse.length ? 'Escluse: ' + escluse.map(s => s.nome).join(', ') : 'Intera categoria';
      out.push(chip(testo, titolo, `_rimuoviLinkCat(${c.id})`));
    } else {
      subs.filter(s => _selSubIncl.has(s.id)).forEach(s => {
        out.push(chip(`${esc(c.nome)} › ${esc(s.nome)}`, 'Sottocategoria', `_rimuoviLinkSub(${s.id})`));
      });
    }
  });

  box.innerHTML = out.length
    ? out.join('')
    : '<span class="text-[11px] text-gray-400">Nessun collegamento: la popup non verrà mostrata.</span>';
}

// Chip collegamenti per le card evento (app.js e admin.js)
function renderChipCollegamenti(collegamenti, colore) {
  if (!collegamenti || !collegamenti.length) return '';
  const col = esc(colore || '#4f46e5');
  return `<div class="flex flex-wrap gap-1 mt-1 mb-2">` + collegamenti.map(l => {
    const testo = l.tipo === 'categoria'
      ? `${esc(l.categoria_nome)} › ${l.escluse ? `tutte tranne ${l.escluse}` : 'tutte'}`
      : `${esc(l.categoria_nome)} › ${esc(l.sottocategoria_nome)}`;
    return `<span class="text-[10px] rounded-full px-2 py-0.5"
                  style="background:${col}14;color:${col};border:1px solid ${col}40">${testo}</span>`;
  }).join('') + `</div>`;
}

// Salva in blocco i collegamenti dell'evento
async function _salvaCollegamenti(eventoId) {
  if (!_linkDataOk) return;
  await api('/api/eventi.php?action=set_collegamenti', {
    method: 'POST',
    body: JSON.stringify({
      evento_id:              eventoId,
      categorie:              [..._selCat],
      sottocategorie_incluse: [..._selSubIncl],
      sottocategorie_escluse: [..._selSubEscl],
    }),
  });
}

// ── Salva evento ─────────────────────────────────────────────
async function salvaEvento() {
	if (!_isAdmin()) {
	  _mostraErrEvento('Permessi insufficienti.');
	  return;
	}
  _nascondiErrEvento();
  const nome = document.getElementById('eventoNome').value.trim();
  if (!nome) { _mostraErrEvento('Nome obbligatorio.'); return; }

  _mostraLoadingEvento('Salvataggio in corso…');

  const payload = {
    id:          _eventoCorrente,
    nome,
    descrizione: document.getElementById('eventoDescrizione').value.trim(),
    colore:      document.getElementById('eventoColore').value,
    attivo:      1,
  };

  try {
    const action = _eventoCorrente ? 'update' : 'create';
    const result = await api(`/api/eventi.php?action=${action}`, {
      method: 'POST',
      body: JSON.stringify(payload),
    });
    const eventoId = _eventoCorrente || result.id;
    _eventoCorrente = eventoId;

    // Salva mini-categorie
    for (const c of _miniCats) {
      const catAction = c.id ? 'cat_update' : 'cat_create';
      await api(`/api/eventi.php?action=${catAction}`, {
        method: 'POST',
        body: JSON.stringify({ ...c, evento_id: eventoId }),
      });
    }

    // Salva collegamenti categorie/sottocategorie
    await _salvaCollegamenti(eventoId);

    chiudiModalEvento();
    _nascondiLoadingEvento();
    _onEventoSalvato();

    // Se siamo in app.js e stavamo modificando un evento aperto, ricarica il dettaglio
    if (typeof apriEvento === 'function' && eventoId) {
      const dettaglio = document.getElementById('eventoDettaglio');
      if (dettaglio && !dettaglio.classList.contains('hidden')) {
        apriEvento(eventoId);
      }
    }
   } catch(e) {
    _nascondiLoadingEvento();
    _mostraErrEvento(e.message);
  }
}

// ── Elimina evento ───────────────────────────────────────────
async function eliminaEvento(id) {
	if (!_isAdmin()) {
	  alert('Permessi insufficienti.');
	  return;
	}
  if (!confirm('Eliminare questo evento? Le spese collegate non verranno eliminate.')) return;
  try {
    await api('/api/eventi.php?action=delete', {
      method: 'POST',
      body: JSON.stringify({ id }),
    });
    _onEventoSalvato();
  } catch(e) { alert('Errore: ' + e.message); }
}

// ── Helpers errore modal ─────────────────────────────────────
function _mostraErrEvento(msg) {
  const el = document.getElementById('eventoErrore');
  if (!el) return;
  el.textContent = msg;
  el.classList.remove('hidden');
}
function _nascondiErrEvento() {
  document.getElementById('eventoErrore')?.classList.add('hidden');
}