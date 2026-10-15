/* Utilitaires communs : appels à l'API (jeton CSRF, session expirée), échappement HTML, messages. JavaScript natif, sans dépendance. */
(function () {
  "use strict";

  const csrfMeta = document.querySelector('meta[name="csrf-token"]');

  /** Appel à l'API PHP. Retourne { status, data }. Ne lève pas d'exception : une panne réseau donne status 0. */
  async function api(path, { method = "GET", body, form, timeout = 10000 } = {}) {
    const headers = { Accept: "application/json" };
    if (method !== "GET") headers["X-CSRF-Token"] = csrfMeta ? csrfMeta.content : "";
    let payload;
    if (form) payload = form;
    else if (body !== undefined) {
      headers["Content-Type"] = "application/json";
      payload = JSON.stringify(body);
    }
    const ctrl = new AbortController();
    const timer = setTimeout(() => ctrl.abort(), timeout);
    try {
      const res = await fetch(path, { method, headers, body: payload, credentials: "same-origin", cache: "no-store", signal: ctrl.signal });
      let data = {};
      try { data = await res.json(); } catch { data = { ok: false, error: "Réponse illisible du serveur." }; }
      if (res.status === 401 && data && data.login) {
        window.location.href = "/login.php";
      }
      if (data && typeof data.csrf === "string" && csrfMeta) csrfMeta.content = data.csrf;
      return { status: res.status, data };
    } catch (e) {
      return { status: 0, data: { ok: false, error: e.name === "AbortError" ? "Le serveur ne répond pas (délai dépassé)." : "Connexion au serveur impossible. Vérifiez le réseau." } };
    } finally {
      clearTimeout(timer);
    }
  }

  const esc = (s) => String(s ?? "").replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));

  let toastTimer;
  function toast(message, isError = false) {
    const el = document.querySelector(".toast");
    if (!el) return;
    el.textContent = message;
    el.classList.toggle("error", isError);
    el.hidden = false;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => { el.hidden = true; }, 4000);
  }

  document.addEventListener("click", async (ev) => {
    const btn = ev.target.closest("[data-logout]");
    if (!btn) return;
    btn.disabled = true;
    await api("/api/logout.php", { method: "POST", body: {} });
    window.location.href = "/login.php";
  });

  window.DDP = { api, esc, toast };
})();
