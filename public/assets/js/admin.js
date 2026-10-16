/* Administration : chaque page charge ses données depuis l'API PHP, sans rechargement complet. */
(function () {
  "use strict";
  const { api, esc, toast } = window.DDP;
  const page = document.body.dataset.page;
  const $ = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => [...root.querySelectorAll(sel)];

  const RESULT = { valid: "Valide", already_used: "Déjà utilisé", invalid: "Invalide", cancelled: "Annulé", wrong_event: "Autre événement", error: "Erreur technique" };
  const STATUS = { valid: "Valide", used: "Utilisé", cancelled: "Annulé" };
  const badge = (cls, label) => `<span class="badge badge-${esc(cls)}">${esc(label)}</span>`;
  const empty = (cols, text) => `<tr><td colspan="${cols}" class="empty">${esc(text)}</td></tr>`;
  const qs = (form) => new URLSearchParams([...new FormData(form)].filter(([, v]) => v !== "")).toString();

  async function fillEvents() {
    const sels = $$("[data-event-filter]");
    if (!sels.length) return [];
    const { data } = await api("/api/events.php");
    const events = (data && data.events) || [];
    sels.forEach((sel) => {
      const first = sel.querySelector("option");
      sel.innerHTML = (first ? first.outerHTML : "") + events.map((e) => `<option value="${e.id}">${esc(e.name)} · ${esc(e.date)}${e.is_active ? "" : " (inactif)"}</option>`).join("");
    });
    return events;
  }

  function pager(el, data, go) {
    el.innerHTML = data.pages > 1
      ? `<button class="btn btn-ghost btn-sm" ${data.page <= 1 ? "disabled" : ""} data-p="${data.page - 1}">Précédent</button><span class="muted">Page ${data.page} / ${data.pages}</span><button class="btn btn-ghost btn-sm" ${data.page >= data.pages ? "disabled" : ""} data-p="${data.page + 1}">Suivant</button>`
      : "";
    el.onclick = (ev) => {
      const b = ev.target.closest("[data-p]");
      if (b) go(Number(b.dataset.p));
    };
  }

  function checkRow(r) {
    return `<tr><td>${esc(r.at)}</td><td>${badge(r.result, r.result_label)}</td>${r.method !== undefined ? `<td>${esc(r.method)}</td>` : ""}<td>${r.ticket_id ? "#" + r.ticket_id : `<span class="muted mono">${esc(r.code || "")}</span>`}${r.reference ? `<br><small class="muted">${esc(r.reference)}</small>` : ""}</td>${r.holder !== undefined ? `<td>${esc(r.holder || "")}</td>` : ""}<td>${esc(r.event || "")}</td><td>${esc(r.controller || "")}</td></tr>`;
  }

  async function post(path, body) {
    const { status, data } = await api(path, { method: "POST", body });
    if (status !== 200 || !data.ok) {
      toast(data.error || "Action impossible.", true);
      return null;
    }
    if (data.message) toast(data.message);
    return data;
  }

  /* ---------- Tableau de bord ---------- */
  async function dashboard() {
    await fillEvents();
    const sel = $("[data-event-filter]");
    const load = async () => {
      const { data } = await api("/api/dashboard.php" + (sel.value ? "?event_id=" + sel.value : ""));
      if (!data || !data.ok) return toast(data.error || "Statistiques indisponibles.", true);
      const s = data.stats;
      $$("#kpis [data-k]").forEach((el) => { el.textContent = s.tickets[el.dataset.k].toLocaleString("fr-FR"); });
      const max = Math.max(1, ...Object.keys(RESULT).map((k) => s.checks[k]));
      $("#by-result").innerHTML = Object.keys(RESULT).map((k) => `<li class="r-${k}"><span>${RESULT[k]}</span><span class="bar"><i data-w="${(s.checks[k] / max) * 100}"></i></span><b>${s.checks[k]}</b></li>`).join("");
      const hmax = Math.max(1, ...s.entries_last_24h.map((h) => h.n));
      $("#hours").innerHTML = s.entries_last_24h.length ? s.entries_last_24h.map((h) => `<li><span>${esc(h.hour)}</span><span class="bar"><i data-w="${(h.n / hmax) * 100}"></i></span><b>${h.n}</b></li>`).join("") : '<li class="empty">Aucune entrée sur les dernières 24 heures.</li>';
      $$("[data-w]").forEach((i) => { i.style.width = i.dataset.w + "%"; });
      $("#recent tbody").innerHTML = data.recent.length ? data.recent.map((r) => checkRow({ ...r, method: undefined, holder: undefined })).join("") : empty(5, "Aucun contrôle pour l'instant.");
    };
    sel.addEventListener("change", load);
    await load();
    setInterval(load, 15000); // rafraîchissement en direct pendant l'événement
  }

  /* ---------- Historique ---------- */
  async function checks() {
    await fillEvents();
    const { data: u } = await api("/api/users.php");
    const us = $("[data-user-filter]");
    if (u && u.users) us.innerHTML += u.users.map((x) => `<option value="${x.id}">${esc(x.display_name)}</option>`).join("");
    const form = $("#check-filters");
    const load = async (p = 1) => {
      const q = qs(form);
      $("#export-checks").href = "/api/export-checks.php" + (q ? "?" + q : "");
      const { data } = await api("/api/checks.php?" + q + "&page=" + p);
      if (!data || !data.ok) return toast(data.error || "Historique indisponible.", true);
      $("#checks-count").textContent = `${data.total.toLocaleString("fr-FR")} contrôle(s)`;
      $("#checks tbody").innerHTML = data.rows.length ? data.rows.map(checkRow).join("") : empty(7, "Aucun contrôle ne correspond à ces filtres.");
      pager($("#checks-pager"), data, load);
    };
    form.addEventListener("submit", (ev) => { ev.preventDefault(); load(1); });
    form.addEventListener("reset", () => setTimeout(() => load(1), 0));
    await load(1);
  }

  /* ---------- Billets ---------- */
  async function tickets() {
    await fillEvents();
    const form = $("#ticket-filters");
    const load = async (p = 1) => {
      const q = qs(form);
      const ev = new FormData(form).get("event_id");
      $("#export-tickets").href = "/api/export-tickets.php" + (ev ? "?event_id=" + ev : "");
      $("#print-tickets").hidden = !ev;
      $("#print-tickets").href = "/admin/print.php?event_id=" + ev;
      const { data } = await api("/api/tickets.php?" + q + "&page=" + p);
      if (!data || !data.ok) return toast(data.error || "Billets indisponibles.", true);
      $("#tickets-count").textContent = `${data.total.toLocaleString("fr-FR")} billet(s)`;
      $("#tickets tbody").innerHTML = data.rows.length ? data.rows.map((t) => `<tr>
          <td>#${t.id}</td><td>${esc(t.holder || "—")}</td><td>${esc(t.reference || "")}</td><td>${esc(t.event)}</td>
          <td>${badge(t.status, STATUS[t.status])}</td><td>${t.used_at ? esc(t.used_at) + "<br><small class='muted'>" + esc(t.used_by || "") + "</small>" : ""}</td>
          <td class="actions"><button class="btn btn-ghost btn-sm" data-show="${t.id}">Détails</button></td></tr>`).join("") : empty(7, "Aucun billet ne correspond.");
      pager($("#tickets-pager"), data, load);
    };
    form.addEventListener("submit", (ev) => { ev.preventDefault(); load(1); });
    $("#tickets").addEventListener("click", (ev) => {
      const b = ev.target.closest("[data-show]");
      if (b) show(Number(b.dataset.show), () => load());
    });
    $("#create-tickets").addEventListener("submit", async (ev) => {
      ev.preventDefault();
      const f = new FormData(ev.target);
      const res = await post("/api/tickets.php", { action: "create", event_id: Number(f.get("event_id")), count: Number(f.get("count")), holder: f.get("holder"), reference: f.get("reference") });
      if (!res) return;
      toast(`${res.created} billet(s) créé(s).`);
      $("#created").innerHTML = `<p class="muted">Codes des billets créés (export CSV complet depuis « Exporter en CSV ») :</p><ul>${res.tickets.map((t) => `<li class="mono">#${t.id} · ${esc(t.token)}</li>`).join("")}</ul>`;
      load(1);
    });
    await load(1);
  }

  async function show(id, reload) {
    const { data } = await api("/api/ticket-status.php?id=" + id);
    if (!data || !data.ok) return toast(data.error || "Billet introuvable.", true);
    const t = data.ticket;
    const dlg = $("#ticket-dialog");
    let qrSvg = "";
    if (window.qrcode) {
      const q = window.qrcode(0, "M");
      q.addData(t.qr);
      q.make();
      qrSvg = q.createSvgTag({ cellSize: 6, margin: 2, scalable: true });
    }
    $("#ticket-detail").innerHTML = `
      <h2>Billet #${t.id} ${badge(t.status, STATUS[t.status])}</h2>
      ${qrSvg ? `<div class="qr">${qrSvg}</div>` : ""}
      <dl class="kv">
        <dt>Code</dt><dd class="mono">${esc(t.token)}</dd>
        <dt>Événement</dt><dd>${esc(t.event_name)} · ${esc(t.event_date)}</dd>
        <dt>Titulaire</dt><dd>${esc(t.holder_name || "—")}</dd>
        <dt>Référence</dt><dd>${esc(t.reference || "—")}</dd>
        <dt>Créé le</dt><dd>${esc(t.created_at)}</dd>
        ${t.used_at ? `<dt>Utilisé le</dt><dd>${esc(t.used_at)} par ${esc(t.used_by || "?")}</dd>` : ""}
        ${t.cancelled_at ? `<dt>Annulé le</dt><dd>${esc(t.cancelled_at)}</dd>` : ""}
      </dl>
      <div class="filters-actions">
        ${t.status === "valid" ? `<button class="btn btn-danger" data-act="cancel">Annuler le billet</button>` : ""}
        ${t.status === "cancelled" ? `<button class="btn btn-ghost" data-act="reactivate">Réactiver</button>` : ""}
        ${t.status === "used" ? `<button class="btn btn-ghost" data-act="reset">Remettre à « valide » (erreur de scan)</button>` : ""}
        <button class="btn btn-ghost" data-print>Imprimer</button>
      </div>
      <h2 class="mt">Contrôles de ce billet</h2>
      ${data.history.length ? `<ul>${data.history.map((h) => `<li>${esc(h.at)} · ${badge(h.result, h.result_label)} · ${esc(h.controller || "")}</li>`).join("")}</ul>` : '<p class="muted">Aucun contrôle.</p>'}`;
    dlg.showModal();
    $("#ticket-detail").onclick = async (ev) => {
      if (ev.target.closest("[data-print]")) return window.print();
      const b = ev.target.closest("[data-act]");
      if (!b) return;
      const msg = { cancel: "Annuler ce billet ? Il sera refusé à l'entrée.", reactivate: "Réactiver ce billet ?", reset: "Remettre ce billet à « valide » ? Il pourra de nouveau servir à entrer." }[b.dataset.act];
      if (!window.confirm(msg)) return;
      if (await post("/api/tickets.php", { action: b.dataset.act, id })) {
        dlg.close();
        reload();
      }
    };
  }

  /* ---------- Import ---------- */
  async function importPage() {
    await fillEvents();
    const loadImports = async () => {
      const { data } = await api("/api/imports.php");
      $("#imports tbody").innerHTML = data && data.imports && data.imports.length ? data.imports.map((i) => `<tr><td>${esc(i.at)}</td><td>${esc(i.filename)}</td><td>${esc(i.event)}</td><td>${i.total}</td><td>${i.inserted}</td><td>${i.duplicates}</td><td>${i.errors}</td><td>${esc(i.by || "")}</td></tr>`).join("") : empty(8, "Aucun import.");
    };
    $("#import-form").addEventListener("submit", async (ev) => {
      ev.preventDefault();
      const form = ev.target;
      const btn = $("button", form);
      btn.disabled = true;
      btn.textContent = "Import en cours…";
      const { status, data } = await api("/api/import-tickets.php", { method: "POST", form: new FormData(form), timeout: 120000 });
      btn.disabled = false;
      btn.textContent = "Importer";
      const out = $("#import-report");
      if (status !== 200 || !data.ok) {
        out.innerHTML = `<p class="notice notice-error">${esc(data.error || "Import impossible.")}</p>`;
        return;
      }
      out.innerHTML = `<div class="report"><p class="notice"><strong>${data.inserted}</strong> billet(s) importé(s) sur ${data.total} ligne(s)${data.generated ? ` (dont ${data.generated} code(s) généré(s))` : ""}. Doublons : <strong>${data.duplicates}</strong>. Lignes incorrectes : <strong>${data.errors}</strong>.</p>
        ${data.lines.length ? `<ul>${data.lines.map((l) => `<li>Ligne ${l.line} : ${esc(l.reason)}</li>`).join("")}</ul>` : ""}</div>`;
      form.reset();
      loadImports();
    });
    loadImports();
  }

  /* ---------- Événements ---------- */
  async function events() {
    const form = $("#event-form");
    const load = async () => {
      const { data } = await api("/api/events.php");
      const list = (data && data.events) || [];
      $("#events tbody").innerHTML = list.length ? list.map((e) => `<tr>
          <td><strong>${esc(e.name)}</strong></td><td>${esc(e.date)}</td><td>${esc(e.location || "")}</td>
          <td>${e.total}</td><td>${e.valid}</td><td>${e.used}</td><td>${e.cancelled}</td>
          <td>${e.is_active ? badge("ok", "Actif") : badge("off", "Inactif")}</td>
          <td class="actions"><button class="btn btn-ghost btn-sm" data-edit='${esc(JSON.stringify(e))}'>Modifier</button>${e.total ? "" : `<button class="btn btn-danger btn-sm" data-del="${e.id}">Supprimer</button>`}</td></tr>`).join("") : empty(9, "Aucun événement : créez-en un ci-dessus.");
    };
    form.addEventListener("submit", async (ev) => {
      ev.preventDefault();
      const f = new FormData(form);
      const body = { action: f.get("id") ? "update" : "create", id: Number(f.get("id")) || undefined, name: f.get("name"), starts_at: f.get("starts_at"), location: f.get("location"), is_active: form.is_active.checked };
      if (await post("/api/events.php", body)) {
        form.reset();
        form.id.value = "";
        $("#event-submit").textContent = "Créer l'événement";
        $("#event-cancel").hidden = true;
        load();
      }
    });
    form.addEventListener("reset", () => {
      form.id.value = "";
      $("#event-submit").textContent = "Créer l'événement";
      $("#event-cancel").hidden = true;
    });
    $("#events").addEventListener("click", async (ev) => {
      const ed = ev.target.closest("[data-edit]");
      if (ed) {
        const e = JSON.parse(ed.dataset.edit);
        form.id.value = e.id;
        form.name.value = e.name;
        form.starts_at.value = e.starts_at;
        form.location.value = e.location || "";
        form.is_active.checked = e.is_active;
        $("#event-submit").textContent = "Enregistrer";
        $("#event-cancel").hidden = false;
        form.scrollIntoView({ behavior: "smooth" });
        return;
      }
      const del = ev.target.closest("[data-del]");
      if (del && window.confirm("Supprimer cet événement ?") && (await post("/api/events.php", { action: "delete", id: Number(del.dataset.del) }))) load();
    });
    load();
  }

  /* ---------- Comptes ---------- */
  async function users() {
    const load = async () => {
      const { data } = await api("/api/users.php");
      if (!data || !data.ok) return toast(data.error || "Comptes indisponibles.", true);
      $("#users tbody").innerHTML = data.users.map((u) => {
        const me = u.id === data.me;
        return `<tr>
          <td><strong>${esc(u.display_name)}</strong>${me ? ' <small class="muted">(vous)</small>' : ""}</td><td class="mono">${esc(u.username)}</td>
          <td>${u.role === "admin" ? badge("admin", "Administrateur") : badge("", "Contrôleur")}</td>
          <td>${u.is_active ? badge("ok", "Actif") : badge("off", "Désactivé")}</td>
          <td>${esc(u.last_login_at || "Jamais")}</td><td>${u.checks}</td>
          <td class="actions">
            <button class="btn btn-ghost btn-sm" data-act="update" data-id="${u.id}" data-name="${esc(u.display_name)}" data-role="${u.role}">Modifier</button>
            <button class="btn btn-ghost btn-sm" data-act="password" data-id="${u.id}">Mot de passe</button>
            ${me ? "" : u.is_active ? `<button class="btn btn-ghost btn-sm" data-act="deactivate" data-id="${u.id}">Désactiver</button>` : `<button class="btn btn-ghost btn-sm" data-act="activate" data-id="${u.id}">Réactiver</button>`}
            ${me ? "" : `<button class="btn btn-danger btn-sm" data-act="delete" data-id="${u.id}">Supprimer</button>`}
          </td></tr>`;
      }).join("");
    };
    $("#user-form").addEventListener("submit", async (ev) => {
      ev.preventDefault();
      const f = new FormData(ev.target);
      if (await post("/api/users.php", { action: "create", username: f.get("username"), display_name: f.get("display_name"), role: f.get("role"), password: f.get("password") })) {
        ev.target.reset();
        load();
      }
    });
    $("#users").addEventListener("click", async (ev) => {
      const b = ev.target.closest("[data-act]");
      if (!b) return;
      const id = Number(b.dataset.id);
      const act = b.dataset.act;
      let body = { action: act, id };
      if (act === "update") {
        const name = window.prompt("Nom affiché :", b.dataset.name);
        if (name === null) return;
        const role = window.prompt("Rôle (admin ou controller) :", b.dataset.role);
        if (role === null) return;
        body = { ...body, display_name: name, role: role.trim() };
      } else if (act === "password") {
        const pw = window.prompt("Nouveau mot de passe (10 caractères minimum, lettres et chiffres) :");
        if (!pw) return;
        body.password = pw;
      } else if (!window.confirm({ deactivate: "Désactiver ce compte ? Il ne pourra plus se connecter.", activate: "Réactiver ce compte ?", delete: "Supprimer définitivement ce compte ? L'historique de ses contrôles est conservé." }[act])) return;
      if (await post("/api/users.php", body)) load();
    });
    load();
  }

  /* ---------- Journal ---------- */
  async function audit() {
    const { data } = await api("/api/audit.php");
    $("#audit tbody").innerHTML = data && data.rows && data.rows.length ? data.rows.map((r) => `<tr><td>${esc(r.at)}</td><td>${esc(r.action)}</td><td>${esc(r.target || "")}</td><td class="mono"><small>${esc(r.details || "")}</small></td><td>${esc(r.by || "")}</td></tr>`).join("") : empty(6, "Journal vide.");
  }

  const routes = { "admin-dashboard": dashboard, "admin-checks": checks, "admin-tickets": tickets, "admin-import": importPage, "admin-events": events, "admin-users": users, "admin-audit": audit };
  if (page === "admin-tickets") {
    const s = document.createElement("script");
    s.src = "/assets/vendor/qrcode-generator.js";
    document.head.appendChild(s);
  }
  if (routes[page]) routes[page]();
})();
