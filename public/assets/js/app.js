/*
 * Application des contrôleurs — Les Dames du Parc.
 *
 * À l'ouverture : tous les billets de l'événement sont chargés sur le téléphone (empreintes SHA-256 des codes, jamais les codes),
 * ce qui permet de reconnaître un billet dès le scan et d'afficher les présentes / absentes. La validation, elle, est toujours
 * faite par le serveur (une seule entrée par billet, même avec plusieurs contrôleurs) ; sans réponse du serveur, l'accès n'est
 * pas autorisé.
 *
 * Lecture : codes-barres Code 128 des billets du site (12 chiffres), QR codes des billets et des cartes de membre.
 * Détecteur natif du navigateur (Chrome / Android) ou, à défaut (Safari / iPhone, Firefox), bibliothèque ZXing chargée à la demande.
 */
(function () {
  "use strict";
  const { api, esc, toast } = window.DDP;
  const $ = (id) => document.getElementById(id);
  const store = {
    get(k, d) { try { const v = localStorage.getItem("ddp-" + k); return v === null ? d : JSON.parse(v); } catch { return d; } },
    set(k, v) { try { localStorage.setItem("ddp-" + k, JSON.stringify(v)); } catch { /* navigation privée */ } },
  };

  const state = {
    events: [],
    eventId: store.get("event", null),
    tickets: [],
    byHash: new Map(),
    counts: null,
    site: null,
    online: navigator.onLine,
    mine: 0,
    filter: "all",
    busy: false,
  };
  const opts = { sound: store.get("sound", true), vibrate: store.get("vibrate", true), auto: store.get("auto", true) };

  /* ===================== Navigation ===================== */
  const views = { scan: $("view-scan"), history: $("view-history"), profile: $("view-profile") };
  function go(tab) {
    Object.entries(views).forEach(([k, v]) => { v.hidden = k !== tab; });
    document.querySelectorAll(".tabbar button").forEach((b) => b.toggleAttribute("aria-current", b.dataset.tab === tab));
    document.querySelectorAll(".tabbar button[aria-current]").forEach((b) => b.setAttribute("aria-current", "page"));
    if (tab === "history") loadMine();
    window.scrollTo(0, 0);
  }
  document.querySelector(".tabbar").addEventListener("click", (e) => {
    const b = e.target.closest("[data-tab]");
    if (b) go(b.dataset.tab);
  });

  /* ===================== Panneaux ===================== */
  let openSheet = null;
  function sheet(id, show = true) {
    if (openSheet && openSheet !== id) $(openSheet).hidden = true;
    $(id).hidden = !show;
    $("backdrop").hidden = !show;
    openSheet = show ? id : null;
  }
  $("backdrop").addEventListener("click", () => {
    if (openSheet === "sheet-result") return nextTicket();
    if (openSheet) sheet(openSheet, false);
  });
  document.addEventListener("keydown", (e) => {
    if (e.key !== "Escape") return;
    if (openSheet === "sheet-result") nextTicket();
    else if (openSheet) sheet(openSheet, false);
    else if (!$("scanner").hidden) closeScanner();
  });

  /* ===================== Connexion ===================== */
  function setNet(on, label) {
    state.online = on;
    const el = $("net");
    el.classList.toggle("on", on);
    el.classList.toggle("off", !on);
    el.querySelector("span").textContent = label || (on ? "En ligne" : "Hors ligne");
  }
  async function ping() {
    if (!navigator.onLine) return setNet(false, "Hors ligne");
    const { status } = await api("/api/session.php", { timeout: 6000 });
    setNet(status === 200, status === 200 ? "En ligne" : status === 0 ? "Serveur injoignable" : "Erreur serveur");
  }
  window.addEventListener("online", ping);
  window.addEventListener("offline", () => setNet(false, "Hors ligne"));

  /* ===================== Événements et billets ===================== */
  const evName = (e) => (e ? e.name : "Aucun événement");
  async function loadEvents() {
    const { status, data } = await api("/api/session.php");
    if (status !== 200) return;
    setNet(true);
    state.events = data.events || [];
    if (!state.events.some((e) => e.id === state.eventId)) state.eventId = state.events.length ? state.events[0].id : null;
    renderEvent();
  }
  function currentEvent() {
    return state.events.find((e) => e.id === state.eventId) || null;
  }
  function renderEvent() {
    const ev = currentEvent();
    $("event-name").textContent = evName(ev);
    $("event-date").textContent = ev ? [ev.date, ev.location].filter(Boolean).join(" · ") : "Aucun événement actif : prévenez un administrateur.";
    $("profile-event-name").textContent = evName(ev);
    $("event-list").innerHTML = state.events.map((e) => `<li><button type="button" data-ev="${e.id}" aria-pressed="${e.id === state.eventId}"><strong>${esc(e.name)}</strong><small>${esc([e.date, e.location].filter(Boolean).join(" · "))}</small></button></li>`).join("") || '<li class="empty">Aucun événement actif.</li>';
  }
  $("event-pick").addEventListener("click", () => sheet("sheet-event"));
  $("profile-event").addEventListener("click", () => sheet("sheet-event"));
  $("event-list").addEventListener("click", (e) => {
    const b = e.target.closest("[data-ev]");
    if (!b) return;
    state.eventId = Number(b.dataset.ev);
    store.set("event", state.eventId);
    renderEvent();
    sheet("sheet-event", false);
    loadTickets();
  });

  /** Chargement de TOUS les billets de l'événement (à l'ouverture, au changement d'événement, puis toutes les 30 s). */
  async function loadTickets(quiet = false) {
    if (!state.eventId) return;
    if (!quiet) $("sync-line").textContent = "Chargement des billets…";
    const { status, data } = await api("/api/tickets-cache.php?event_id=" + state.eventId, { timeout: 20000 });
    if (status !== 200 || !data.ok) {
      setNet(status !== 0, status === 0 ? "Serveur injoignable" : undefined);
      $("sync-line").textContent = state.tickets.length ? "Liste non actualisée : connexion au serveur impossible." : "Billets non chargés : connexion au serveur impossible.";
      $("sync-line").classList.add("warn");
      return;
    }
    setNet(true);
    state.tickets = data.tickets;
    state.byHash = new Map(data.tickets.map((t) => [t.h, t]));
    state.counts = data.counts;
    state.site = data.site;
    renderCounts();
    renderPeople();
    const site = data.site || {};
    const siteTxt = site.enabled ? (site.error ? " · site injoignable, dernière mise à jour " + (site.last_pull || "—") : " · site synchronisé") : "";
    $("sync-line").textContent = `${data.counts.total} billet${data.counts.total > 1 ? "s" : ""} chargé${data.counts.total > 1 ? "s" : ""} à ${data.loaded_at}${siteTxt}`;
    $("sync-line").classList.toggle("warn", Boolean(site.enabled && site.error));
    $("profile-sync").textContent = site.enabled ? (site.error ? "Erreur : " + site.error : "À jour (" + (site.last_pull || "—") + ")" + (site.pending ? ` · ${site.pending} présence(s) à transmettre` : "")) : "Lien avec le site non configuré";
  }

  function renderCounts() {
    const c = state.counts;
    if (!c) return;
    $("st-present").firstChild.nodeValue = String(c.present);
    $("st-total").textContent = " / " + (c.total - c.cancelled);
    const pct = c.total - c.cancelled ? Math.round((c.present / (c.total - c.cancelled)) * 100) : 0;
    $("st-bar").style.width = pct + "%";
    ["all", "absent", "present", "cancelled"].forEach((k) => {
      $("c-" + k).textContent = "(" + (k === "all" ? c.total : c[k]) + ")";
    });
  }

  /* ===================== Liste des participantes ===================== */
  const norm = (s) => String(s || "").normalize("NFD").replace(/[̀-ͯ]/g, "").toLowerCase();
  function renderPeople() {
    const q = norm($("people-q").value.trim());
    const list = state.tickets.filter((t) => !t.x).filter((t) => {
      if (state.filter === "present" && t.s !== "used") return false;
      if (state.filter === "absent" && t.s !== "valid") return false;
      if (state.filter === "cancelled" && t.s !== "cancelled") return false;
      return !q || norm(t.n).includes(q) || norm(t.r).includes(q);
    });
    const label = { valid: "Absente", used: "Présente", cancelled: "Annulé" };
    $("people-list").innerHTML = list.length
      ? list.slice(0, 400).map((t) => `<li><span class="dot ${t.s === "used" ? "present" : t.s === "valid" ? "absent" : "cancelled"}"></span><span class="who"><strong>${esc(t.n || "Billet #" + t.i)}</strong><small>${esc([t.r, t.p > 1 ? t.p + " places" : ""].filter(Boolean).join(" · "))}</small></span>
          <span class="side">${t.s === "used" ? esc(label.used) + (t.u ? "<br>" + esc(t.u) : "") : t.s === "valid" ? `<button type="button" class="btn btn-ghost btn-sm" data-person="${t.i}">Détails</button>` : esc(label[t.s] || "")}</span></li>`).join("")
      : '<li class="empty">Aucune participante ne correspond.</li>';
  }
  $("people-q").addEventListener("input", renderPeople);
  document.querySelector(".chips").addEventListener("click", (e) => {
    const b = e.target.closest("[data-f]");
    if (!b) return;
    state.filter = b.dataset.f;
    document.querySelectorAll(".chips [data-f]").forEach((x) => x.setAttribute("aria-pressed", String(x === b)));
    renderPeople();
  });
  document.querySelector(".seg").addEventListener("click", (e) => {
    const b = e.target.closest("[data-seg]");
    if (!b) return;
    document.querySelectorAll(".seg [data-seg]").forEach((x) => x.setAttribute("aria-selected", String(x === b)));
    $("seg-mine").hidden = b.dataset.seg !== "mine";
    $("seg-people").hidden = b.dataset.seg !== "people";
    if (b.dataset.seg === "people") loadTickets(true);
  });

  // Contrôle depuis la liste (billet oublié, téléphone déchargé…) : avec confirmation, journalisé « liste ».
  let personId = null;
  $("people-list").addEventListener("click", (e) => {
    const b = e.target.closest("[data-person]");
    if (!b) return;
    const t = state.tickets.find((x) => x.i === Number(b.dataset.person));
    if (!t) return;
    personId = t.i;
    $("p-title").textContent = t.n || "Billet #" + t.i;
    $("p-details").innerHTML = details([["Statut", "Absente (billet non scanné)"], ["N° de membre", t.r], ["Née le", t.b], ["Places", t.p > 1 ? String(t.p) : null]]);
    sheet("sheet-person");
  });
  $("p-close").addEventListener("click", () => sheet("sheet-person", false));
  $("p-checkin").addEventListener("click", async () => {
    if (!personId) return;
    if (!window.confirm("Valider l'entrée sans scanner le billet ? Vérifiez d'abord l'identité de la personne.")) return;
    sheet("sheet-person", false);
    await check({ ticket_id: personId }, null);
  });

  /* ===================== Historique « Mes contrôles » ===================== */
  async function loadMine() {
    const { status, data } = await api("/api/my-checks.php" + (state.eventId ? "?event_id=" + state.eventId : ""));
    if (status !== 200 || !data.ok) return;
    state.mine = data.valid;
    $("st-mine").textContent = String(data.valid);
    $("mine-list").innerHTML = data.rows.length
      ? data.rows.map((r) => `<li><span class="dot ${r.result}"></span><span class="who"><strong>${esc(r.holder || (r.code ? "Code " + r.code : "Billet inconnu"))}</strong><small>${esc(r.label)}${r.method === "manual" ? " · saisie" : r.method === "list" ? " · liste" : ""}</small></span><span class="side">${esc(r.at)}</span></li>`).join("")
      : '<li class="empty">Aucun contrôle pour l\'instant.</li>';
  }

  /* ===================== Empreinte d'un code (reconnaissance locale) ===================== */
  function normalize(raw) {
    raw = String(raw || "").trim();
    if (/^DDP1\./.test(raw)) return raw.split(".")[1] || "";
    if (/^https?:\/\//i.test(raw)) return raw;
    const compact = raw.replace(/\s+/g, "");
    return /^[\d-]{6,80}$/.test(compact) ? compact.replace(/-/g, "") : compact;
  }
  async function sha256(s) {
    if (!window.crypto || !crypto.subtle) return null;
    const buf = await crypto.subtle.digest("SHA-256", new TextEncoder().encode(s));
    return [...new Uint8Array(buf)].map((b) => b.toString(16).padStart(2, "0")).join("");
  }
  /** Un texte lu par la caméra ressemble-t-il à un billet ? (évite d'envoyer des lectures parasites) */
  const looksLikeTicket = (raw) => /^\d{12}$/.test(normalize(raw)) || /^DDP1\./.test(raw) || /\/verification\/[A-Za-z0-9_-]{8,}/.test(raw) || /^[A-Za-z0-9_-]{8,64}$/.test(raw.trim());

  /* ===================== Contrôle ===================== */
  async function check(payload, raw) {
    if (state.busy) return;
    state.busy = true;
    pauseScan();
    // Affichage immédiat du nom si le billet est connu du téléphone ; la décision vient du serveur.
    let local = null;
    if (raw) {
      const h = await sha256(normalize(raw));
      local = h ? state.byHash.get(h) : null;
    } else if (payload.ticket_id) {
      local = state.tickets.find((t) => t.i === payload.ticket_id) || null;
    }
    showPending(local);
    const { status, data } = await api("/api/validate-ticket.php", { method: "POST", body: { ...payload, event_id: state.eventId }, timeout: 9000 });
    state.busy = false;
    if (status === 0 || !data || !data.result) {
      setNet(false, "Serveur injoignable");
      return showResult({ result: "error", message: (data && data.error) || "Le serveur ne répond pas : accès NON autorisé. Réessayez." }, local);
    }
    setNet(true);
    showResult(data, local);
    if (data.result === "valid") {
      state.mine++;
      $("st-mine").textContent = String(state.mine);
      // Mise à jour locale immédiate (présentes / absentes), sans attendre le prochain chargement.
      const t = state.tickets.find((x) => x.i === data.ticket?.id);
      if (t && t.s === "valid") {
        t.s = "used";
        t.u = new Date().toLocaleTimeString("fr-FR", { hour: "2-digit", minute: "2-digit" });
        if (state.counts) { state.counts.present++; state.counts.absent--; }
        renderCounts();
        renderPeople();
      }
    }
  }

  const ICON = {
    valid: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12.5 4.5 4.5L19 7.5"/></svg>',
    already_used: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"><path d="M12 6v7M12 17.5v.5"/></svg>',
    refused: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"><path d="M7 7l10 10M17 7 7 17"/></svg>',
    error: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"><path d="M12 6v7M12 17.5v.5"/></svg>',
  };
  const TITLE = { valid: "Entrée validée", already_used: "Déjà utilisé", invalid: "Billet invalide", cancelled: "Billet annulé", wrong_event: "Autre événement", error: "Vérification impossible" };
  const details = (rows) => rows.filter(([, v]) => v).map(([k, v]) => `<dt>${esc(k)}</dt><dd>${esc(v)}</dd>`).join("");

  function showPending(local) {
    const s = $("sheet-result");
    s.className = "sheet r-pending";
    $("r-icon").innerHTML = '<span class="spinner"></span>';
    $("r-title").textContent = "Vérification…";
    $("r-msg").textContent = local ? "Billet reconnu, validation par le serveur." : "Validation par le serveur.";
    $("r-name").textContent = local && local.n ? local.n : "";
    $("r-details").innerHTML = "";
    $("r-countdown").hidden = true;
    $("r-next").hidden = true;
    sheet("sheet-result");
  }

  let autoTimer = null;
  function showResult(r, local) {
    const kind = TITLE[r.result] ? r.result : "error";
    const t = r.ticket || {};
    $("sheet-result").className = "sheet r-" + kind;
    $("r-icon").innerHTML = kind === "valid" ? ICON.valid : kind === "already_used" ? ICON.already_used : kind === "error" ? ICON.error : ICON.refused;
    $("r-title").textContent = TITLE[kind];
    $("r-msg").textContent = r.message || "";
    $("r-name").textContent = t.holder_name || (local && local.n) || "";
    const rows = [];
    if (kind === "already_used") rows.push(["Première entrée", r.used_at_full || r.used_at], ["Contrôlée par", r.used_by]);
    if (t.places > 1) rows.push(["Places", String(t.places)]);
    rows.push(["Née le", t.birth_date], ["N° de membre", t.reference]);
    if (kind === "wrong_event" || kind === "cancelled") rows.push(["Événement", t.event_name]);
    $("r-details").innerHTML = details(rows);
    $("r-next").hidden = false;
    $("r-next").textContent = !$("scanner").hidden ? "Billet suivant" : "Fermer";
    sheet("sheet-result");
    feedback(kind);
    clearTimeout(autoTimer);
    const cd = $("r-countdown");
    cd.hidden = !(kind === "valid" && opts.auto && !$("scanner").hidden);
    cd.classList.remove("run");
    if (!cd.hidden) {
      void cd.offsetWidth;
      cd.classList.add("run");
      autoTimer = setTimeout(nextTicket, 1800);
    }
    $("r-next").focus();
  }

  function nextTicket() {
    clearTimeout(autoTimer);
    sheet("sheet-result", false);
    $("code").value = "";
    if (!$("scanner").hidden) resumeScan();
  }
  $("r-next").addEventListener("click", nextTicket);

  /* ===================== Saisie manuelle ===================== */
  function openManual() {
    sheet("sheet-manual");
    setTimeout(() => $("code").focus(), 120);
  }
  $("open-manual").addEventListener("click", openManual);
  $("scanner-manual").addEventListener("click", () => { pauseScan(); openManual(); });
  $("scanner-error-manual").addEventListener("click", () => { closeScanner(); openManual(); });
  $("kbd-toggle").addEventListener("click", () => {
    const num = $("code").getAttribute("inputmode") === "numeric";
    $("code").setAttribute("inputmode", num ? "text" : "numeric");
    $("kbd-toggle").textContent = num ? "Clavier chiffres" : "Clavier lettres";
    $("code").blur();
    setTimeout(() => $("code").focus(), 50);
  });
  // Mise en forme par groupes de 4 chiffres pendant la saisie (comme imprimé sur le billet).
  $("code").addEventListener("input", (e) => {
    const v = e.target.value;
    if (/^[\d\s]*$/.test(v)) {
      const d = v.replace(/\s/g, "").slice(0, 12);
      e.target.value = d.replace(/(\d{4})(?=\d)/g, "$1 ");
    }
  });
  $("manual-form").addEventListener("submit", (e) => {
    e.preventDefault();
    const raw = $("code").value.trim();
    if (!raw) return toast("Saisissez le numéro du billet.", true);
    sheet("sheet-manual", false);
    check({ code: raw, method: "manual" }, raw);
  });

  /* ===================== Scanner (caméra) ===================== */
  const video = $("video");
  const canvas = $("canvas");
  const ctx = canvas.getContext("2d", { willReadFrequently: true });
  let stream = null;
  let decoder = null;
  let scanning = false;
  let lastText = "";
  let lastAt = 0;
  let frameNo = 0;

  async function openScanner() {
    if (!state.eventId) return toast("Choisissez d'abord l'événement contrôlé.", true);
    $("scanner").hidden = false;
    $("scanner-error").hidden = true;
    $("scanner-state").textContent = "Ouverture de la caméra…";
    document.body.style.overflow = "hidden";
    if (!window.isSecureContext || !navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      return scannerError("La caméra n'est disponible qu'en HTTPS, sur un navigateur récent. Utilisez la saisie du numéro.");
    }
    try {
      stream = await navigator.mediaDevices.getUserMedia({ audio: false, video: { facingMode: { ideal: "environment" }, width: { ideal: 1920 }, height: { ideal: 1080 } } });
      video.srcObject = stream;
      await video.play();
      const track = stream.getVideoTracks()[0];
      const caps = track.getCapabilities ? track.getCapabilities() : {};
      // Mise au point continue (codes-barres fins) quand le téléphone le permet.
      if (caps.focusMode && caps.focusMode.includes("continuous")) track.applyConstraints({ advanced: [{ focusMode: "continuous" }] }).catch(() => {});
      $("torch").hidden = !caps.torch;
      $("torch-spacer").hidden = Boolean(caps.torch);
      await setupDecoder();
      $("scanner-state").textContent = "Recherche d'un code…";
      resumeScan();
    } catch (e) {
      console.warn("Ouverture du scanner impossible :", e);
      const denied = e && (e.name === "NotAllowedError" || e.name === "SecurityError");
      scannerError(denied ? "Accès à la caméra refusé. Autorisez la caméra dans les réglages du navigateur, ou saisissez le numéro du billet." : e && e.message === "decoder" ? "Lecteur de codes indisponible. Saisissez le numéro du billet." : "Caméra indisponible sur cet appareil. Saisissez le numéro du billet.");
    }
  }

  function scannerError(text) {
    stopStream();
    $("scanner-error-text").textContent = text;
    $("scanner-error").hidden = false;
  }
  function stopStream() {
    scanning = false;
    if (stream) stream.getTracks().forEach((t) => t.stop());
    stream = null;
    $("torch").classList.remove("on");
  }
  function closeScanner() {
    stopStream();
    $("scanner").hidden = true;
    document.body.style.overflow = "";
  }
  function pauseScan() { scanning = false; }
  function resumeScan() {
    if (!stream) return;
    scanning = true;
    requestAnimationFrame(tick);
  }
  $("open-scanner").addEventListener("click", openScanner);
  $("scanner-close").addEventListener("click", closeScanner);
  $("scanner-error-close").addEventListener("click", closeScanner);
  $("torch").addEventListener("click", async () => {
    if (!stream) return;
    const on = !$("torch").classList.contains("on");
    try {
      await stream.getVideoTracks()[0].applyConstraints({ advanced: [{ torch: on }] });
      $("torch").classList.toggle("on", on);
      $("torch").setAttribute("aria-pressed", String(on));
    } catch { /* lampe indisponible */ }
  });
  document.addEventListener("visibilitychange", () => {
    if (document.hidden && stream) closeScanner();
  });

  async function setupDecoder() {
    if (decoder) return;
    if ("BarcodeDetector" in window) {
      try {
        const formats = await window.BarcodeDetector.getSupportedFormats();
        if (formats.includes("code_128")) {
          const det = new window.BarcodeDetector({ formats: ["code_128", "qr_code"].filter((f) => formats.includes(f)) });
          decoder = { decode: async () => { const r = await det.detect(video); return r.length ? r[0].rawValue : null; } };
          return;
        }
      } catch { /* ZXing ci-dessous */ }
    }
    if (!window.ZXing) {
      await new Promise((resolve, reject) => {
        const s = document.createElement("script");
        s.src = "/assets/vendor/zxing.min.js";
        s.onload = resolve;
        s.onerror = () => reject(new Error("decoder"));
        document.head.appendChild(s);
      });
    }
    const Z = window.ZXing;
    // ZXing ne sert ici qu'aux QR codes (billets locaux, cartes de membre) ; les codes-barres sont lus par DDPCode128.
    const hints = new Map();
    hints.set(Z.DecodeHintType.POSSIBLE_FORMATS, [Z.BarcodeFormat.QR_CODE]);
    hints.set(Z.DecodeHintType.TRY_HARDER, true);
    const reader = new Z.MultiFormatReader();
    reader.setHints(hints);
    decoder = {
      decode: async () => {
        // On analyse la zone du cadre de visée, largement élargie (le billet n'est jamais parfaitement centré).
        const vw = video.videoWidth;
        const vh = video.videoHeight;
        if (!vw || !vh) return null;
        const rect = $("frame").getBoundingClientRect();
        const box = video.getBoundingClientRect();
        const scale = Math.max(box.width / vw, box.height / vh);
        const offX = (vw * scale - box.width) / 2;
        const offY = (vh * scale - box.height) / 2;
        const pad = 0.3;
        let sx = (rect.left - box.left + offX) / scale;
        let sy = (rect.top - box.top + offY) / scale;
        let sw = rect.width / scale;
        let sh = rect.height / scale;
        sx = Math.max(0, sx - sw * pad);
        sy = Math.max(0, sy - sh * pad);
        sw = Math.min(vw - sx, sw * (1 + 2 * pad));
        sh = Math.min(vh - sy, sh * (1 + 2 * pad));
        const k = Math.min(1, 1100 / sw);
        canvas.width = Math.round(sw * k);
        canvas.height = Math.round(sh * k);
        ctx.drawImage(video, sx, sy, sw, sh, 0, 0, canvas.width, canvas.height);
        const bar = window.DDPCode128.decode(ctx.getImageData(0, 0, canvas.width, canvas.height));
        if (bar) return bar;
        if (frameNo % 2) return null; // QR code une image sur deux (plus coûteux)
        try {
          const res = reader.decodeWithState(new Z.BinaryBitmap(new Z.HybridBinarizer(new Z.HTMLCanvasElementLuminanceSource(canvas))));
          return res ? res.getText() : null;
        } catch {
          return null;
        } finally {
          reader.reset();
        }
      },
    };
  }

  let lastTick = 0;
  async function tick(now) {
    if (!scanning) return;
    if (now - lastTick > 90 && video.readyState >= 2) {
      lastTick = now;
      frameNo++;
      let text = null;
      try { text = await decoder.decode(); } catch { text = null; }
      if (text && scanning) {
        text = String(text).trim();
        // Le même billet reste souvent quelques secondes devant la caméra : pas de second envoi immédiat.
        if (looksLikeTicket(text) && !(text === lastText && Date.now() - lastAt < 4000)) {
          lastText = text;
          lastAt = Date.now();
          check({ code: text, method: "scan" }, text);
          return;
        }
      }
    }
    requestAnimationFrame(tick);
  }

  /* ===================== Son et vibration ===================== */
  let audio;
  function feedback(kind) {
    try {
      if (opts.vibrate && navigator.vibrate) navigator.vibrate(kind === "valid" ? 90 : [200, 80, 200]);
      if (!opts.sound) return;
      audio = audio || new (window.AudioContext || window.webkitAudioContext)();
      const notes = kind === "valid" ? [[880, 0, 0.1], [1320, 0.1, 0.14]] : [[240, 0, 0.2], [190, 0.24, 0.26]];
      notes.forEach(([f, start, dur]) => {
        const o = audio.createOscillator();
        const g = audio.createGain();
        o.type = kind === "valid" ? "sine" : "triangle";
        o.frequency.value = f;
        g.gain.setValueAtTime(0.0001, audio.currentTime + start);
        g.gain.exponentialRampToValueAtTime(0.3, audio.currentTime + start + 0.02);
        g.gain.exponentialRampToValueAtTime(0.0001, audio.currentTime + start + dur);
        o.connect(g).connect(audio.destination);
        o.start(audio.currentTime + start);
        o.stop(audio.currentTime + start + dur + 0.02);
      });
    } catch { /* son indisponible */ }
  }

  /* ===================== Profil ===================== */
  [["opt-sound", "sound"], ["opt-vibrate", "vibrate"], ["opt-auto", "auto"]].forEach(([id, k]) => {
    $(id).checked = opts[k];
    $(id).addEventListener("change", () => { opts[k] = $(id).checked; store.set(k, opts[k]); });
  });
  $("sync-now").addEventListener("click", async () => {
    $("profile-sync").textContent = "Synchronisation…";
    const { status, data } = await api("/api/sync.php", { method: "POST", body: {}, timeout: 20000 });
    toast(data.message || data.error || "Synchronisation impossible.", status !== 200);
    loadTickets(true);
  });

  /* ===================== Démarrage ===================== */
  (async function start() {
    await loadEvents();
    await loadTickets();
    loadMine();
    setInterval(() => { if (!document.hidden && $("scanner").hidden) loadTickets(true); }, 30000);
    setInterval(() => { if (!document.hidden) ping(); }, 20000);
  })();
})();
