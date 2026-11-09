// ============================================================
// I18N.JS - Multilingua e formati (importi, valuta, date)
//
// Va caricato in <head> SENZA defer, prima degli altri script.
//
// Funzionamento:
//  - Le preferenze (lingua, valuta, formato_numero, formato_data) sono
//    salvate nel profilo utente e copiate in localStorage ("hf_prefs").
//    Prima del login si usano i valori dedotti dalla lingua del browser.
//  - Il dizionario /i18n/<lingua>.json ha come chiavi il testo italiano
//    originale; {0}, {1}... indicano parti variabili.
//  - Un MutationObserver traduce automaticamente testi e attributi
//    (placeholder, title, aria-label, alt) appena compaiono nella pagina.
//    I dati inseriti dagli utenti (passati da esc()) non vengono tradotti.
//  - Il cookie "hf_lang" comunica la lingua alle API PHP.
// ============================================================
(function () {
  'use strict';

  const LINGUE = {
    it: 'Italiano', en: 'English', es: 'Español', fr: 'Français',
    de: 'Deutsch', pt: 'Português', nl: 'Nederlands', pl: 'Polski',
  };
  const VALUTE = ['EUR', 'USD', 'GBP', 'CHF', 'PLN', 'SEK', 'NOK', 'DKK', 'CZK', 'HUF', 'RON',
                  'BRL', 'CAD', 'AUD', 'JPY', 'CNY', 'INR', 'MXN', 'ARS'];
  // Formato importi → locale Intl che produce quel formato
  const FORMATI_NUMERO = {
    '1.234,56': 'de-DE',
    '1,234.56': 'en-US',
    '1 234,56': 'fr-FR',
    "1'234.56": 'de-CH',
  };
  const FORMATI_DATA = ['dd/mm/yyyy', 'mm/dd/yyyy', 'yyyy-mm-dd', 'dd.mm.yyyy', 'dd-mm-yyyy'];
  const VALUTA_PER_REGIONE = {
    US: 'USD', GB: 'GBP', CH: 'CHF', LI: 'CHF', PL: 'PLN', SE: 'SEK', NO: 'NOK', DK: 'DKK',
    CZ: 'CZK', HU: 'HUF', RO: 'RON', BR: 'BRL', CA: 'CAD', AU: 'AUD', JP: 'JPY', CN: 'CNY',
    IN: 'INR', MX: 'MXN', AR: 'ARS',
  };
  const CHIAVE_PREFS = 'hf_prefs';
  const ATTRIBUTI    = ['placeholder', 'title', 'aria-label', 'alt'];
  const SALTA_TAG    = { SCRIPT: 1, STYLE: 1, TEXTAREA: 1, NOSCRIPT: 1, CODE: 1, PRE: 1 };

  // Percorso base dell'app ricavato da questo script (…/assets/i18n.js)
  const BASE = (document.currentScript && document.currentScript.src)
    ? document.currentScript.src.replace(/assets\/i18n\.js(\?.*)?$/, '')
    : '';

  // ── Preferenze ─────────────────────────────────────────────
  function normalizzaLingua(l) {
    l = String(l || '').slice(0, 2).toLowerCase();
    return LINGUE[l] ? l : null;
  }

  /** Preferenze predefinite per una lingua e (opzionale) un locale del browser, es. "en-US". */
  function predefinite(lingua, locale) {
    lingua = normalizzaLingua(lingua) || 'en';
    const regione = String(locale || '').split(/[-_]/)[1];
    const reg = regione ? regione.toUpperCase() : '';

    let valuta = VALUTA_PER_REGIONE[reg] || ({ en: 'GBP', pl: 'PLN' }[lingua]) || 'EUR';
    if (lingua === 'en' && !reg) valuta = 'GBP';

    let numero = '1.234,56';
    if (lingua === 'en') numero = '1,234.56';
    else if (lingua === 'fr' || lingua === 'pl' || (lingua === 'pt' && reg !== 'BR')) numero = '1 234,56';
    if (reg === 'CH' || reg === 'LI') numero = "1'234.56";

    let data = 'dd/mm/yyyy';
    if (lingua === 'en' && reg === 'US') data = 'mm/dd/yyyy';
    else if (lingua === 'de' || lingua === 'pl') data = 'dd.mm.yyyy';
    else if (lingua === 'nl') data = 'dd-mm-yyyy';

    return { lingua, valuta, formato_numero: numero, formato_data: data };
  }

  function localeBrowser() {
    return (navigator.languages && navigator.languages[0]) || navigator.language || 'en-US';
  }

  /** Prima lingua supportata tra quelle del browser (inglese se nessuna). */
  function linguaBrowser() {
    const lista = (navigator.languages && navigator.languages.length) ? navigator.languages : [navigator.language || 'en'];
    for (const l of lista) { const n = normalizzaLingua(l); if (n) return n; }
    return 'en';
  }

  function leggiPrefs() {
    let p = null;
    try { p = JSON.parse(localStorage.getItem(CHIAVE_PREFS) || 'null'); } catch (_) { p = null; }
    const lingua = normalizzaLingua(p && p.lingua) || linguaBrowser();
    const def = predefinite(lingua, localeBrowser());
    return {
      lingua,
      valuta:         (p && VALUTE.includes(p.valuta)) ? p.valuta : def.valuta,
      formato_numero: (p && FORMATI_NUMERO[p.formato_numero]) ? p.formato_numero : def.formato_numero,
      formato_data:   (p && FORMATI_DATA.includes(p.formato_data)) ? p.formato_data : def.formato_data,
    };
  }

  function scriviPrefs(p) {
    try { localStorage.setItem(CHIAVE_PREFS, JSON.stringify(p)); } catch (_) {}
    document.cookie = 'hf_lang=' + p.lingua + '; path=/; max-age=31536000; SameSite=Lax';
  }

  let prefs = leggiPrefs();
  scriviPrefs(prefs);

  // ── Dizionario ─────────────────────────────────────────────
  let dizionario = {};
  let modelli    = [];   // [{ re, trad, ordine }]

  function caricaDizionario(lingua) {
    dizionario = {}; modelli = [];
    if (lingua === 'it') return;
    try {
      // Sincrono di proposito: le traduzioni devono esistere prima che la pagina venga disegnata
      const xhr = new XMLHttpRequest();
      xhr.open('GET', BASE + 'i18n/' + lingua + '.json?v=' + (window.I18N_VERSIONE || '1'), false);
      xhr.send(null);
      if (xhr.status === 200) dizionario = JSON.parse(xhr.responseText) || {};
    } catch (e) {
      console.error('i18n: dizionario non caricato', e);
      dizionario = {};
    }
    for (const chiave of Object.keys(dizionario)) {
      if (chiave.charAt(0) === '@' || !/\{\d+\}/.test(chiave)) continue;
      const ordine = [];
      const re = new RegExp('^' + chiave
        .replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
        .replace(/\\\{(\d+)\\\}/g, (_, n) => { ordine.push(n); return '([\\s\\S]+?)'; }) + '$');
      modelli.push({ re, trad: dizionario[chiave], ordine });
    }
  }
  caricaDizionario(prefs.lingua);

  function cerca(testo) {
    const esatto = dizionario[testo];
    if (typeof esatto === 'string' && esatto !== '') return esatto;
    for (const m of modelli) {
      const r = testo.match(m.re);
      if (r) {
        const valori = {};
        m.ordine.forEach((n, i) => { valori[n] = r[i + 1]; });
        return m.trad.replace(/\{(\d+)\}/g, (x, n) => (valori[n] !== undefined ? valori[n] : x));
      }
    }
    return null;
  }

  // Dati inseriti dagli utenti: non vanno tradotti (registrati da esc())
  const datiUtente = new Set();
  function registraDatoUtente(s) {
    if (s == null) return;
    s = String(s).trim();
    if (!s || s.length > 200) return;
    if (datiUtente.size > 5000) datiUtente.clear();
    datiUtente.add(s);
  }

  let simboloCache = null;
  function simboloValuta() {
    if (simboloCache) return simboloCache;
    try {
      const parti = new Intl.NumberFormat(localeNumeri(), { style: 'currency', currency: prefs.valuta }).formatToParts(0);
      simboloCache = (parti.find(p => p.type === 'currency') || {}).value || prefs.valuta;
    } catch (_) { simboloCache = prefs.valuta; }
    return simboloCache;
  }

  function adattaValuta(s) {
    return (prefs.valuta !== 'EUR' && s.indexOf('€') >= 0) ? s.split('€').join(simboloValuta()) : s;
  }

  /**
   * Traduce un testo visualizzato. Ritorna null se non c'è nulla da cambiare.
   * Gestisce spazi, emoji/simboli iniziali e punteggiatura finale attorno alla chiave.
   */
  function traduciTesto(s) {
    if (!s || !/[A-Za-zÀ-ÿ]/.test(s)) return null;
    const m = s.match(/^(\s*)([\s\S]*?)(\s*)$/);
    const prima = m[1], centro = m[2], dopo = m[3];
    if (datiUtente.has(centro)) return null;

    let r = null;
    if (prefs.lingua !== 'it') {
      r = cerca(centro);
      if (r == null) {
        const d = centro.match(/^([^\p{L}\p{N}"'«(¿¡{]*)([\s\S]*?)([\s:.…!?*]*)$/u);
        if (d && (d[1] || d[3]) && d[2]) {
          let x = cerca(d[2] + d[3]);
          if (x != null) r = d[1] + x;
          else if ((x = cerca(d[2])) != null) r = d[1] + x + d[3];
          else if (d[1] && (x = cerca(d[1] + d[2])) != null) r = x + d[3];
        }
      }
    }
    const base = r == null ? centro : r;
    const finale = adattaValuta(base);
    if (r == null && finale === centro) return null;
    return prima + finale + dopo;
  }

  // ── Applicazione al DOM ─────────────────────────────────────
  const origTesti = new WeakMap();  // Text → { sorgente, tradotto }
  const origAttr  = new WeakMap();  // Element → { attr: { sorgente, tradotto } }

  function daSaltare(el) {
    for (let e = el; e && e.nodeType === 1; e = e.parentElement) {
      if (SALTA_TAG[e.tagName] || e.hasAttribute('data-noi18n') || e.isContentEditable) return true;
    }
    return false;
  }

  function processaTesto(nodo) {
    const padre = nodo.parentElement;
    if (!padre || daSaltare(padre)) return;
    const attuale = nodo.nodeValue;
    const info = origTesti.get(nodo);
    const sorgente = (info && info.tradotto === attuale) ? info.sorgente : attuale;
    const tradotto = traduciTesto(sorgente);
    if (tradotto != null) {
      origTesti.set(nodo, { sorgente, tradotto });
      if (tradotto !== attuale) nodo.nodeValue = tradotto;
    } else if (info && info.tradotto === attuale && sorgente !== attuale) {
      origTesti.delete(nodo);
      nodo.nodeValue = sorgente;   // ritorno all'italiano
    }
  }

  function processaAttributo(el, nome) {
    if (!el.hasAttribute(nome) || daSaltare(el)) return;
    const attuale = el.getAttribute(nome);
    const mappa = origAttr.get(el) || {};
    const info = mappa[nome];
    const sorgente = (info && info.tradotto === attuale) ? info.sorgente : attuale;
    const tradotto = traduciTesto(sorgente);
    if (tradotto != null) {
      mappa[nome] = { sorgente, tradotto };
      origAttr.set(el, mappa);
      if (tradotto !== attuale) el.setAttribute(nome, tradotto);
    } else if (info && info.tradotto === attuale && sorgente !== attuale) {
      delete mappa[nome];
      el.setAttribute(nome, sorgente);
    }
  }

  function scansiona(radice) {
    if (!radice) return;
    if (radice.nodeType === 3) { processaTesto(radice); return; }
    if (radice.nodeType !== 1 && radice.nodeType !== 9 && radice.nodeType !== 11) return;
    if (radice.nodeType === 1) {
      if (daSaltare(radice)) return;
      ATTRIBUTI.forEach(a => processaAttributo(radice, a));
    }
    const walker = document.createTreeWalker(radice, NodeFilter.SHOW_TEXT | NodeFilter.SHOW_ELEMENT, {
      acceptNode: n => (n.nodeType === 1 && SALTA_TAG[n.tagName]) ? NodeFilter.FILTER_REJECT : NodeFilter.FILTER_ACCEPT,
    });
    let n;
    while ((n = walker.nextNode())) {
      if (n.nodeType === 3) processaTesto(n);
      else ATTRIBUTI.forEach(a => processaAttributo(n, a));
    }
  }

  const osservatore = new MutationObserver(mutazioni => {
    for (const m of mutazioni) {
      if (m.type === 'childList') m.addedNodes.forEach(scansiona);
      else if (m.type === 'characterData') processaTesto(m.target);
      else if (m.type === 'attributes') processaAttributo(m.target, m.attributeName);
    }
  });
  osservatore.observe(document.documentElement, {
    childList: true, subtree: true, characterData: true,
    attributes: true, attributeFilter: ATTRIBUTI,
  });
  document.documentElement.setAttribute('lang', prefs.lingua);
  document.addEventListener('DOMContentLoaded', () => scansiona(document.documentElement));

  // alert / confirm / prompt nella lingua dell'utente
  const _alert = window.alert.bind(window);
  const _confirm = window.confirm.bind(window);
  const _prompt = window.prompt.bind(window);
  const tm = msg => { const s = String(msg == null ? '' : msg); const r = traduciTesto(s); return r == null ? s : r; };
  window.alert   = msg => _alert(tm(msg));
  window.confirm = msg => _confirm(tm(msg));
  window.prompt  = (msg, def) => _prompt(tm(msg), def);

  // ── Formati ────────────────────────────────────────────────
  function localeNumeri() { return FORMATI_NUMERO[prefs.formato_numero] || 'de-DE'; }

  /** Importo con valuta, es. fmtImporto(1234.5) → "1.234,50 €". */
  function fmtImporto(v, opzioni) {
    const n = Number(v) || 0;
    try {
      return new Intl.NumberFormat(localeNumeri(), Object.assign({ style: 'currency', currency: prefs.valuta }, opzioni || {})).format(n);
    } catch (_) {
      return n.toFixed(2) + ' ' + prefs.valuta;
    }
  }

  /** Anteprima di un importo con valuta e formato arbitrari (per i form delle preferenze). */
  function fmtImportoCon(v, valuta, formato) {
    try {
      return new Intl.NumberFormat(FORMATI_NUMERO[formato] || localeNumeri(), { style: 'currency', currency: valuta }).format(Number(v) || 0);
    } catch (_) { return String(v) + ' ' + valuta; }
  }

  /** Numero senza valuta con decimali fissi. */
  function fmtNumero(v, decimali) {
    const d = decimali == null ? 2 : decimali;
    return new Intl.NumberFormat(localeNumeri(), { minimumFractionDigits: d, maximumFractionDigits: d }).format(Number(v) || 0);
  }

  /** Data (YYYY-MM-DD o data/ora ISO) nel formato scelto dall'utente. */
  function fmtData(s, conOra) {
    if (!s) return '';
    let d;
    const m = String(s).match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/);
    if (m) d = { y: m[1], mo: m[2], g: m[3], h: m[4] || '00', mi: m[5] || '00' };
    else {
      const x = new Date(s);
      if (isNaN(x)) return String(s);
      const p = v => String(v).padStart(2, '0');
      d = { y: String(x.getFullYear()), mo: p(x.getMonth() + 1), g: p(x.getDate()), h: p(x.getHours()), mi: p(x.getMinutes()) };
    }
    const out = prefs.formato_data.replace('yyyy', d.y).replace('mm', d.mo).replace('dd', d.g);
    return conOra ? out + ' ' + d.h + ':' + d.mi : out;
  }

  /** Nomi dei mesi nella lingua dell'utente ("short" o "long"). */
  function nomiMesi(tipo) {
    const f = new Intl.DateTimeFormat(prefs.lingua, { month: tipo || 'short' });
    return Array.from({ length: 12 }, (_, i) => {
      const s = f.format(new Date(2024, i, 15));
      return s.charAt(0).toUpperCase() + s.slice(1);
    });
  }

  /** Traduzione esplicita: t('Pagina {0} di {1}', 2, 5). */
  function t(testo) {
    const args = Array.prototype.slice.call(arguments, 1);
    let r = (prefs.lingua !== 'it' && typeof dizionario[testo] === 'string' && dizionario[testo] !== '') ? dizionario[testo] : testo;
    r = r.replace(/\{(\d+)\}/g, (x, n) => (args[n] !== undefined ? args[n] : x));
    return adattaValuta(r);
  }

  /** Nome visualizzato di una categoria (traduce solo quella di sistema EVENTI). */
  function nomeCategoria(nome) {
    return nome === 'EVENTI' ? t('EVENTI') : nome;
  }

  // ── Cambio lingua / preferenze ─────────────────────────────
  /** Cambia lingua della pagina corrente senza ricaricarla (es. form di registrazione). */
  function cambiaLinguaPagina(lingua) {
    lingua = normalizzaLingua(lingua) || 'it';
    if (lingua === prefs.lingua) return;
    prefs = Object.assign({}, prefs, { lingua });
    simboloCache = null;
    scriviPrefs(prefs);
    caricaDizionario(lingua);
    document.documentElement.setAttribute('lang', lingua);
    scansiona(document.documentElement);
    document.dispatchEvent(new CustomEvent('linguacambiata', { detail: { lingua } }));
  }

  /** Salva le preferenze; ricarica la pagina se cambia qualcosa che è già stato disegnato. */
  function impostaPreferenze(nuove, ricarica) {
    const p = {
      lingua:         normalizzaLingua(nuove.lingua) || prefs.lingua,
      valuta:         VALUTE.includes(nuove.valuta) ? nuove.valuta : prefs.valuta,
      formato_numero: FORMATI_NUMERO[nuove.formato_numero] ? nuove.formato_numero : prefs.formato_numero,
      formato_data:   FORMATI_DATA.includes(nuove.formato_data) ? nuove.formato_data : prefs.formato_data,
    };
    const cambiate = JSON.stringify(p) !== JSON.stringify(prefs);
    prefs = p; simboloCache = null;
    scriviPrefs(p);
    if (cambiate && ricarica !== false) window.location.reload();
    return cambiate;
  }

  /** Allinea le preferenze locali a quelle del profilo utente restituito dal server. */
  function sincronizzaUtente(utente) {
    if (!utente || !utente.lingua) return;
    impostaPreferenze({
      lingua: utente.lingua, valuta: utente.valuta,
      formato_numero: utente.formato_numero, formato_data: utente.formato_data,
    }, true);
  }

  window.I18N = {
    LINGUE, VALUTE, FORMATI_DATA,
    FORMATI_NUMERO: Object.keys(FORMATI_NUMERO),
    get lingua() { return prefs.lingua; },
    get prefs() { return Object.assign({}, prefs); },
    predefinite, linguaBrowser, localeBrowser,
    t, traduci: s => { const r = traduciTesto(String(s)); return r == null ? String(s) : r; },
    fmtImporto, fmtImportoCon, fmtNumero, fmtData, nomiMesi, simboloValuta, nomeCategoria,
    datoUtente: registraDatoUtente,
    applica: scansiona,
    cambiaLinguaPagina, impostaPreferenze, sincronizzaUtente,
  };
  window.t = t;
})();
