(function () {
  "use strict";
  const form = document.getElementById("login-form");
  const err = form.querySelector(".form-error");
  form.addEventListener("submit", async (ev) => {
    ev.preventDefault();
    err.hidden = true;
    const btn = form.querySelector("button");
    const username = form.username.value.trim();
    const password = form.password.value;
    if (!username || !password) {
      err.textContent = "Saisissez votre identifiant et votre mot de passe.";
      err.hidden = false;
      return;
    }
    btn.disabled = true;
    btn.textContent = "Connexion…";
    const { status, data } = await DDP.api("/api/login.php", { method: "POST", body: { username, password } });
    if (status === 200 && data.ok) {
      window.location.href = data.redirect || "/";
      return;
    }
    err.textContent = data.error || "Connexion impossible.";
    err.hidden = false;
    btn.disabled = false;
    btn.textContent = "Se connecter";
    form.password.value = "";
    form.password.focus();
  });
})();
