/*
 * Tutoriel d'installation sur l'écran d'accueil — Les Dames du Parc, contrôle des billets.
 *
 * S'ouvre de lui-même à la première connexion sur un téléphone (iPhone/iPad ou Android) tant que l'application n'est pas installée,
 * et reste accessible à tout moment (bouton « Installer l'application », attribut data-install-guide). Les étapes s'adaptent à
 * l'appareil et au navigateur détectés ; on peut basculer à la main entre iPhone et Android. Sur Android, quand le navigateur le
 * permet, un bouton installe l'application en un geste. Aucune donnée n'est envoyée : l'état est gardé sur le téléphone.
 */
(function () {
  "use strict";
  const KEY = "ddp-install-guide";
  const get = (k) => { try { return localStorage.getItem(k); } catch { return null; } };
  const set = (k, v) => { try { localStorage.setItem(k, v); } catch { /* navigation privée */ } };
  const sget = (k) => { try { return sessionStorage.getItem(k); } catch { return null; } };
  const sset = (k, v) => { try { sessionStorage.setItem(k, v); } catch { /* navigation privée */ } };

  const ua = navigator.userAgent;
  const isIOS = /iPhone|iPad|iPod/.test(ua) || (/Macintosh/.test(ua) && navigator.maxTouchPoints > 1);
  const isIPad = /iPad/.test(ua) || (/Macintosh/.test(ua) && navigator.maxTouchPoints > 1);
  const isAndroid = /Android/i.test(ua);
  const iosBrowser = /CriOS/.test(ua) ? "chrome" : /FxiOS/.test(ua) ? "firefox" : /EdgiOS/.test(ua) ? "edge" : "safari";
  const androidBrowser = /SamsungBrowser/.test(ua) ? "samsung" : /Firefox/.test(ua) ? "firefox" : /EdgA/.test(ua) ? "edge" : "chrome";
  const standalone = () => window.matchMedia("(display-mode: standalone)").matches || window.navigator.standalone === true;

  // Service worker : rend l'application installable (Android) ; aucun contenu n'est mis en cache.
  if ("serviceWorker" in navigator) navigator.serviceWorker.register("/sw.js").catch(() => {});

  // Ouverte depuis l'écran d'accueil : l'installation est faite, le tutoriel ne s'affichera plus.
  if (standalone()) set(KEY, "installed");

  let deferred = null;
  window.addEventListener("beforeinstallprompt", (e) => {
    e.preventDefault();
    deferred = e;
    const b = document.getElementById("ig-oneclick");
    if (b) b.hidden = false;
  });
  window.addEventListener("appinstalled", () => {
    set(KEY, "installed");
    close();
    if (window.DDP && window.DDP.toast) window.DDP.toast("Application installée : retrouvez-la sur votre écran d'accueil.");
  });

  /* ---------- Pictogrammes (traits, comme dans l'application) ---------- */
  const svg = (d, cls = "") => `<svg class="${cls}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${d}</svg>`;
  const I = {
    share: svg('<path d="M12 3v12M8 7l4-4 4 4"/><path d="M5 11v8a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-8"/>'),
    add: svg('<rect x="3" y="3" width="18" height="18" rx="4"/><path d="M12 8v8M8 12h8"/>'),
    more: svg('<circle cx="12" cy="5" r="1.4" fill="currentColor"/><circle cx="12" cy="12" r="1.4" fill="currentColor"/><circle cx="12" cy="19" r="1.4" fill="currentColor"/>'),
    dots: svg('<circle cx="5" cy="12" r="1.4" fill="currentColor"/><circle cx="12" cy="12" r="1.4" fill="currentColor"/><circle cx="19" cy="12" r="1.4" fill="currentColor"/>'),
    menu: svg('<path d="M4 7h16M4 12h16M4 17h16"/>'),
    install: svg('<path d="M12 3v11M8 10l4 4 4-4"/><rect x="4" y="17" width="16" height="4" rx="1.5"/>'),
    check: svg('<path d="m5 12 5 5 9-10"/>'),
    camera: svg('<path d="M4 8h3l2-3h6l2 3h3v11H4z"/><circle cx="12" cy="13" r="3.5"/>'),
    phone: svg('<rect x="6" y="2" width="12" height="20" rx="3"/><path d="M11 18h2"/>'),
    close: svg('<path d="M18 6 6 18M6 6l12 12"/>'),
  };
  const key = (icon, label) => `<span class="ig-key">${icon}<span>${label}</span></span>`;
  const appIcon = '<span class="ig-appicon"><img src="/assets/img/apple-touch-icon.png" alt="" width="44" height="44"><small>Contrôle DDP</small></span>';

  /* ---------- Étapes selon l'appareil et le navigateur ---------- */
  function steps(os) {
    if (os === "ios") {
      const where = isIPad ? "en haut à droite de l'écran" : "en bas de l'écran (au centre)";
      const list = iosBrowser === "safari"
        ? [
            `Touchez le bouton ${key(I.share, "Partager")} ${where}. Vous ne le voyez pas ? Touchez d'abord ${key(I.dots, "")} à côté de l'adresse.`,
            `Faites défiler la liste vers le bas, puis touchez ${key(I.add, "Sur l'écran d'accueil")}.`,
            `Gardez le nom « Contrôle DDP » et l'option « Ouvrir en tant qu'app web » activée, puis touchez <strong>Ajouter</strong> en haut à droite.`,
            `L'icône des Dames du Parc apparaît sur votre écran d'accueil : ouvrez toujours l'application depuis cette icône. ${appIcon}`,
          ]
        : [
            `Touchez le bouton ${key(I.share, "Partager")} dans la barre d'adresse${iosBrowser === "chrome" ? " (en haut à droite)" : ""}.`,
            `Touchez ${key(I.add, "Sur l'écran d'accueil")} (faites défiler si besoin), puis <strong>Ajouter</strong>.`,
            `L'option n'apparaît pas ? Copiez l'adresse ci-dessous, ouvrez-la dans <strong>Safari</strong> et suivez les étapes de Safari.`,
            `L'icône des Dames du Parc apparaît sur votre écran d'accueil : ouvrez toujours l'application depuis cette icône. ${appIcon}`,
          ];
      return list;
    }
    if (androidBrowser === "samsung")
      return [
        `Touchez le menu ${key(I.menu, "")} en bas à droite de l'écran.`,
        `Touchez ${key(I.add, "Ajouter la page à")}, puis <strong>Écran d'accueil</strong>.`,
        `Confirmez avec <strong>Ajouter</strong>.`,
        `L'icône des Dames du Parc apparaît sur votre écran d'accueil : ouvrez toujours l'application depuis cette icône. ${appIcon}`,
      ];
    return [
      `Touchez le menu ${key(I.more, "")} en ${androidBrowser === "firefox" ? "bas" : "haut"} à droite de l'écran.`,
      `Touchez ${key(I.install, "Installer l'application")} ou ${key(I.add, "Ajouter à l'écran d'accueil")}.`,
      `Confirmez avec <strong>Installer</strong> (ou <strong>Ajouter</strong>, puis encore <strong>Ajouter</strong>).`,
      `L'icône des Dames du Parc apparaît sur votre écran d'accueil (et dans la liste des applications) : ouvrez toujours l'application depuis cette icône. ${appIcon}`,
    ];
  }

  /* ---------- Fenêtre ---------- */
  let root = null;
  let lastFocus = null;
  function render(os) {
    const browser = os === "ios" ? (iosBrowser === "safari" ? "Safari" : iosBrowser === "chrome" ? "Chrome" : iosBrowser === "firefox" ? "Firefox" : "Edge")
      : androidBrowser === "samsung" ? "Samsung Internet" : androidBrowser === "firefox" ? "Firefox" : androidBrowser === "edge" ? "Edge" : "Chrome";
    const detected = (os === "ios" && isIOS) || (os === "android" && isAndroid);
    root.querySelector(".ig-seg").innerHTML = ["ios", "android"]
      .map((o) => `<button type="button" role="tab" aria-selected="${o === os}" data-os="${o}">${o === "ios" ? "iPhone / iPad" : "Android"}</button>`)
      .join("");
    root.querySelector(".ig-detected").textContent = detected ? `Détecté : ${os === "ios" ? (isIPad ? "iPad" : "iPhone") : "Android"} · ${browser}` : "";
    root.querySelector(".ig-oneclick-wrap").hidden = os !== "android";
    root.querySelector("#ig-oneclick").hidden = !deferred;
    root.querySelector(".ig-steps").innerHTML = steps(os).map((s, i) => `<li><span class="ig-num">${i + 1}</span><p>${s}</p></li>`).join("");
    root.querySelector(".ig-copy").hidden = !(os === "ios" && isIOS && iosBrowser !== "safari");
  }

  function build() {
    root = document.createElement("div");
    root.className = "ig";
    root.hidden = true;
    root.innerHTML = `
      <div class="ig-backdrop" data-ig-close></div>
      <section class="ig-panel" tabindex="-1" role="dialog" aria-modal="true" aria-labelledby="ig-title" aria-describedby="ig-intro">
        <button type="button" class="ig-x" data-ig-close aria-label="Fermer">${I.close}</button>
        <div class="ig-head">
          <img src="/assets/img/apple-touch-icon.png" alt="" width="56" height="56">
          <div>
            <h2 id="ig-title">Installez l'application sur votre téléphone</h2>
            <p id="ig-intro">Une minute suffit : le contrôle des billets s'ouvrira alors comme une vraie application, en plein écran, depuis votre écran d'accueil.</p>
          </div>
        </div>
        <div class="ig-seg" role="tablist" aria-label="Type de téléphone"></div>
        <p class="ig-detected"></p>
        <div class="ig-oneclick-wrap">
          <button type="button" class="btn btn-primary btn-xl ig-oneclick" id="ig-oneclick" hidden>${I.install} Installer maintenant</button>
        </div>
        <ol class="ig-steps"></ol>
        <div class="ig-copy" hidden>
          <code>${location.origin}/scan.php</code>
          <button type="button" class="btn btn-ghost btn-sm" data-ig-copy>Copier l'adresse</button>
        </div>
        <p class="ig-tip">${I.camera}<span>Au premier scan, <strong>autorisez l'accès à l'appareil photo</strong> : il sert uniquement à lire les billets.</span></p>
        <div class="ig-actions">
          <button type="button" class="btn btn-primary btn-xl" data-ig-done>${I.check} C'est fait</button>
          <button type="button" class="btn btn-ghost" data-ig-later>Plus tard</button>
        </div>
      </section>`;
    document.body.appendChild(root);
    root.addEventListener("click", async (e) => {
      const t = e.target.closest("button, [data-ig-close]");
      if (!t) return;
      if (t.dataset.os) return render(t.dataset.os);
      if (t.hasAttribute("data-ig-close") || t.hasAttribute("data-ig-later")) { sset(KEY, "later"); return close(); }
      if (t.hasAttribute("data-ig-done")) { set(KEY, "done"); return close(); }
      if (t.hasAttribute("data-ig-copy")) {
        try { await navigator.clipboard.writeText(`${location.origin}/scan.php`); t.textContent = "Adresse copiée"; } catch { t.textContent = "Copiez l'adresse ci-dessus"; }
        return;
      }
      if (t.id === "ig-oneclick" && deferred) {
        deferred.prompt();
        const { outcome } = await deferred.userChoice.catch(() => ({ outcome: "dismissed" }));
        deferred = null;
        t.hidden = true;
        if (outcome === "accepted") { set(KEY, "installed"); close(); }
      }
    });
    root.addEventListener("keydown", (e) => {
      if (e.key === "Escape") { sset(KEY, "later"); close(); }
      if (e.key !== "Tab") return;
      const f = [...root.querySelectorAll("button:not([hidden]), a[href]")].filter((x) => x.offsetParent !== null);
      if (!f.length) return;
      const i = f.indexOf(document.activeElement);
      if (e.shiftKey && i <= 0) { e.preventDefault(); f[f.length - 1].focus(); }
      else if (!e.shiftKey && i === f.length - 1) { e.preventDefault(); f[0].focus(); }
    });
  }

  function open(os) {
    if (!root) build();
    lastFocus = document.activeElement;
    render(os || (isIOS ? "ios" : "android"));
    root.hidden = false;
    document.documentElement.classList.add("ig-lock");
    // Lecture depuis le haut : le focus va au panneau (sans le faire défiler), pas au bouton du bas.
    const panel = root.querySelector(".ig-panel");
    panel.scrollTop = 0;
    panel.focus({ preventScroll: true });
  }
  function close() {
    if (!root || root.hidden) return;
    root.hidden = true;
    document.documentElement.classList.remove("ig-lock");
    if (lastFocus && lastFocus.focus) lastFocus.focus();
  }

  // Bouton « Installer l'application » (profil, en-tête) : toujours disponible.
  document.addEventListener("click", (e) => {
    const t = e.target.closest("[data-install-guide]");
    if (t) { e.preventDefault(); open(); }
  });
  if (standalone()) document.querySelectorAll("[data-install-guide]").forEach((el) => { el.hidden = true; });

  // Première connexion sur un téléphone : ouverture automatique (une fois par session tant que ce n'est pas fait).
  if ((isIOS || isAndroid) && !standalone() && !get(KEY) && !sget(KEY)) setTimeout(() => open(), 900);

  window.DDPInstallGuide = { open, close };
})();
