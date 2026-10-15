/*
 * Écran de contrôle des billets.
 * - Mode A : caméra du téléphone. Lecture par le détecteur natif du navigateur (BarcodeDetector, Chrome/Android) ou, à défaut
 *   (Safari/iPhone, Firefox), par la bibliothèque jsQR chargée à la demande. Le code lu est TOUJOURS envoyé au serveur, qui seul
 *   décide de la validité.
 * - Mode B : saisie manuelle, toujours disponible (caméra refusée, absente, ou QR code illisible).
 */
(function () {
  "use strict";
  const { api, esc, toast } = window.DDP;

  const $ = (id) => document.getElementById(id);
  const eventSelect = $("event");
  const video = $("video");
  const canvas = $("canvas");
  const ctx = canvas.getContext("2d", { willReadFrequently: true });
  const camera = $("camera");
  const resultBox = $("result");
  const STORAGE_KEY = "ddp-ctrl-event";

  let stream = null;
  let detector = null;
  let scanning = false;
  let busy = false;
  let lastCode = "";
  let lastCodeAt = 0;
  let deviceIds = [];
  let deviceIndex = 0;
  let torchOn = false;
  let okCount = 0;
  let autoClose = null;

  /* ---------- Événements ---------- */
  async function loadEvents() {
    const { status, data } = await api("/api/session.php");
    if (status !== 200 || !data.ok) {
      eventSelect.innerHTML = '<option value="">Événements indisponibles</option>';
      return;
    }
    const saved = safeGet(STORAGE_KEY);
    const opts = data.events.map((e) => `<option value="${e.id}">${esc(e.name)} · ${esc(e.date)}</option>`);
    eventSelect.innerHTML = opts.join("") + '<option value="">Tous les événements actifs</option>';
    if (!data.events.length) eventSelect.innerHTML = '<option value="">Aucun événement actif</option>';
    if (saved !== null && [...eventSelect.options].some((o) => o.value === saved)) eventSelect.value = saved;
  }
  eventSelect.addEventListener("change", () => safeSet(STORAGE_KEY, eventSelect.value));
  function safeGet(k) { try { return localStorage.getItem(k); } catch { return null; } }
  function safeSet(k, v) { try { localStorage.setItem(k, v); } catch { /* navigation privée */ } }

  /* ---------- Onglets ---------- */
  function setMode(mode) {
    const scan = mode === "scan";
    $("tab-scan").classList.toggle("active", scan);
    $("tab-manual").classList.toggle("active", !scan);
    $("tab-scan").setAttribute("aria-selected", String(scan));
    $("tab-manual").setAttribute("aria-selected", String(!scan));
    $("panel-scan").hidden = !scan;
    $("panel-manual").hidden = scan;
    if (!scan) {
      stopCamera();
      setTimeout(() => $("code").focus(), 50);
    }
  }
  $("tab-scan").addEventListener("click", () => setMode("scan"));
  $("tab-manual").addEventListener("click", () => setMode("manual"));

  /* ---------- Caméra ---------- */
  async function startCamera() {
    if (!window.isSecureContext || !navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      cameraUnavailable("La caméra n'est accessible qu'en HTTPS, sur un navigateur compatible. Utilisez la saisie manuelle.");
      return;
    }
    $("start-camera").disabled = true;
    $("start-camera").textContent = "Ouverture de la caméra…";
    try {
      const constraints = { audio: false, video: deviceIds.length ? { deviceId: { exact: deviceIds[deviceIndex] } } : { facingMode: { ideal: "environment" }, width: { ideal: 1280 }, height: { ideal: 1280 } } };
      stream = await navigator.mediaDevices.getUserMedia(constraints);
      video.srcObject = stream;
      await video.play();
      await setupDecoder();
      camera.classList.add("live");
      $("stop-camera").hidden = false;
      const track = stream.getVideoTracks()[0];
      const caps = track.getCapabilities ? track.getCapabilities() : {};
      $("torch").hidden = !caps.torch;
      if (!deviceIds.length) {
        const devices = await navigator.mediaDevices.enumerateDevices();
        deviceIds = devices.filter((d) => d.kind === "videoinput").map((d) => d.deviceId).filter(Boolean);
        const current = track.getSettings ? track.getSettings().deviceId : null;
        deviceIndex = Math.max(0, deviceIds.indexOf(current));
      }
      $("switch-camera").hidden = deviceIds.length < 2;
      scanning = true;
      requestAnimationFrame(tick);
    } catch (e) {
      const denied = e && (e.name === "NotAllowedError" || e.name === "SecurityError");
      cameraUnavailable(denied ? "Accès à la caméra refusé. Autorisez la caméra dans les réglages du navigateur, ou utilisez la saisie manuelle." : "Caméra indisponible sur cet appareil. Utilisez la saisie manuelle.");
    } finally {
      $("start-camera").disabled = false;
      $("start-camera").textContent = "Activer la caméra";
    }
  }

  function stopCamera() {
    scanning = false;
    if (stream) stream.getTracks().forEach((t) => t.stop());
    stream = null;
    torchOn = false;
    camera.classList.remove("live");
    $("stop-camera").hidden = true;
    $("torch").hidden = true;
  }

  function cameraUnavailable(message) {
    stopCamera();
    $("camera-hint").textContent = message;
    toast(message, true);
    setMode("manual");
  }

  async function setupDecoder() {
    if (detector) return;
    if ("BarcodeDetector" in window) {
      try {
        const formats = await window.BarcodeDetector.getSupportedFormats();
        if (formats.includes("qr_code")) {
          detector = new window.BarcodeDetector({ formats: ["qr_code"] });
          return;
        }
      } catch { /* on bascule sur jsQR */ }
    }
    if (!window.jsQR) await loadScript("/assets/vendor/jsQR.js");
    detector = {
      detect: async (source) => {
        const w = source.videoWidth;
        const h = source.videoHeight;
        if (!w || !h) return [];
        const scale = Math.min(1, 720 / Math.max(w, h));
        canvas.width = Math.round(w * scale);
        canvas.height = Math.round(h * scale);
        ctx.drawImage(source, 0, 0, canvas.width, canvas.height);
        const img = ctx.getImageData(0, 0, canvas.width, canvas.height);
        const code = window.jsQR(img.data, img.width, img.height, { inversionAttempts: "attemptBoth" });
        return code && code.data ? [{ rawValue: code.data }] : [];
      },
    };
  }

  function loadScript(src) {
    return new Promise((resolve, reject) => {
      const s = document.createElement("script");
      s.src = src;
      s.onload = resolve;
      s.onerror = () => reject(new Error("Lecteur QR indisponible"));
      document.head.appendChild(s);
    });
  }

  let lastTick = 0;
  async function tick(now) {
    if (!scanning) return;
    if (!busy && resultBox.hidden && now - lastTick > 120 && video.readyState >= 2) {
      lastTick = now;
      try {
        const codes = await detector.detect(video);
        if (codes.length) onCode(String(codes[0].rawValue || "").trim(), "scan");
      } catch { /* image suivante */ }
    }
    requestAnimationFrame(tick);
  }

  $("start-camera").addEventListener("click", startCamera);
  $("stop-camera").addEventListener("click", stopCamera);
  $("switch-camera").addEventListener("click", async () => {
    deviceIndex = (deviceIndex + 1) % deviceIds.length;
    stopCamera();
    await startCamera();
  });
  $("torch").addEventListener("click", async () => {
    if (!stream) return;
    torchOn = !torchOn;
    try { await stream.getVideoTracks()[0].applyConstraints({ advanced: [{ torch: torchOn }] }); } catch { torchOn = false; }
  });
  document.addEventListener("visibilitychange", () => {
    if (document.hidden && stream) stopCamera();
  });

  /* ---------- Contrôle ---------- */
  async function onCode(code, method) {
    if (!code || busy) return;
    // Le même QR code reste souvent quelques secondes devant la caméra : on ne le renvoie pas tout de suite.
    if (method === "scan" && code === lastCode && Date.now() - lastCodeAt < 4000) return;
    lastCode = code;
    lastCodeAt = Date.now();
    busy = true;
    const { status, data } = await api("/api/validate-ticket.php", {
      method: "POST",
      body: { code, event_id: eventSelect.value ? Number(eventSelect.value) : null, method },
      timeout: 8000,
    });
    busy = false;
    if (status === 0 || !data || !data.result) {
      // Panne réseau, serveur indisponible, réponse inattendue : refus par défaut.
      showResult({ result: "error", message: (data && data.error) || "Contrôle impossible : accès NON autorisé." });
      return;
    }
    showResult(data);
  }

  $("manual-form").addEventListener("submit", (ev) => {
    ev.preventDefault();
    const code = $("code").value.trim();
    if (!code) {
      toast("Saisissez le numéro du billet.", true);
      return;
    }
    onCode(code, "manual");
  });

  const TITLES = {
    valid: "Billet valide",
    already_used: "Déjà utilisé",
    invalid: "Billet invalide",
    cancelled: "Billet annulé",
    wrong_event: "Autre événement",
    error: "Erreur technique",
  };

  function showResult(r) {
    const kind = TITLES[r.result] ? r.result : "error";
    resultBox.className = "result result-" + kind;
    $("result-title").textContent = TITLES[kind];
    $("result-message").textContent = r.message || "";
    const rows = [];
    const t = r.ticket || {};
    if (kind === "already_used") {
      if (r.used_at) rows.push(["Premier contrôle", r.used_at_full || r.used_at]);
      if (r.used_by) rows.push(["Par", r.used_by]);
    }
    if (t.holder_name) rows.push(["Titulaire", t.holder_name]);
    if (t.reference) rows.push(["Référence", t.reference]);
    if (t.event_name) rows.push(["Événement", t.event_name]);
    if (t.id) rows.push(["Billet n°", "#" + t.id]);
    $("result-details").innerHTML = rows.map(([k, v]) => `<dt>${esc(k)}</dt><dd>${esc(v)}</dd>`).join("");
    resultBox.hidden = false;
    feedback(kind);
    if (kind === "valid") {
      okCount++;
      $("count-ok").textContent = String(okCount);
      $("code").value = "";
      // Entrée valide : l'écran se libère seul pour enchaîner ; un refus reste affiché jusqu'à ce qu'on le ferme.
      autoClose = setTimeout(closeResult, 2500);
    }
    $("result-next").focus();
  }

  function closeResult() {
    clearTimeout(autoClose);
    resultBox.hidden = true;
    if (!$("panel-manual").hidden) {
      $("code").select();
      $("code").focus();
    }
  }
  $("result-next").addEventListener("click", closeResult);
  document.addEventListener("keydown", (ev) => {
    if (!resultBox.hidden && (ev.key === "Escape" || ev.key === "Enter")) {
      ev.preventDefault();
      closeResult();
    }
  });

  /* ---------- Retour sonore et vibration ---------- */
  let audio;
  function feedback(kind) {
    try {
      if (navigator.vibrate) navigator.vibrate(kind === "valid" ? 120 : [220, 90, 220, 90, 220]);
      audio = audio || new (window.AudioContext || window.webkitAudioContext)();
      const notes = kind === "valid" ? [[880, 0, 0.12], [1320, 0.12, 0.16]] : [[220, 0, 0.22], [180, 0.28, 0.3]];
      notes.forEach(([freq, start, dur]) => {
        const o = audio.createOscillator();
        const g = audio.createGain();
        o.type = kind === "valid" ? "sine" : "square";
        o.frequency.value = freq;
        g.gain.setValueAtTime(0.0001, audio.currentTime + start);
        g.gain.exponentialRampToValueAtTime(0.25, audio.currentTime + start + 0.02);
        g.gain.exponentialRampToValueAtTime(0.0001, audio.currentTime + start + dur);
        o.connect(g).connect(audio.destination);
        o.start(audio.currentTime + start);
        o.stop(audio.currentTime + start + dur + 0.02);
      });
    } catch { /* son indisponible */ }
  }

  loadEvents();
})();
