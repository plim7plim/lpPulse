(() => {
  "use strict";
  const byId = id => document.getElementById(id);
  const endpoint = new URL("../backend/api.php", location.href);
  let token = "", purpose = "", busy = false;
  const forms = ["register-form", "forgot-form", "new-password-form"];
  function route() {
    const match = location.hash.match(/^#(verify|reset)=([a-f0-9]{64})$/);
    if (match) { purpose = match[1]; token = match[2]; history.replaceState(null, "", location.pathname + location.search + "#definir-senha"); }
    const password = Boolean(token) && location.hash === "#definir-senha";
    const forgot = !password && location.hash !== "#cadastro";
    forms.forEach(id => { byId(id).hidden = id !== (password ? "new-password-form" : forgot ? "forgot-form" : "register-form"); });
    byId("access-title").textContent = password ? "Defina sua senha" : forgot ? "Recupere seu acesso" : "Crie sua conta";
    byId("access-description").textContent = password ? "Escolha uma senha para entrar no Pulse." : forgot ? "Enviaremos um link para o e-mail da sua conta." : "Confirme seu e-mail e defina sua senha pelo link que vamos enviar.";
    byId("access-feedback").textContent = ""; byId("access-success").hidden = true;
    if (location.hash === "#definir-senha" && !token) byId("access-feedback").textContent = "Reabra o link do e-mail para definir sua senha.";
    document.title = byId("access-title").textContent + " · Pulse";
  }
  async function request(action, data, form) {
    if (busy) return; busy = true;
    const button = form.querySelector("button[type=submit]"), label = button.textContent;
    button.disabled = true; button.textContent = "Aguarde…"; form.setAttribute("aria-busy", "true"); byId("access-feedback").textContent = "";
    try {
      const url = new URL(endpoint); url.searchParams.set("action", action);
      const response = await fetch(url, { method: "POST", credentials: "same-origin", headers: { "Content-Type": "application/json", Accept: "application/json" }, body: JSON.stringify(data) });
      const result = await response.json();
      if (!response.ok || !result.ok) throw new Error(result.error?.message || "Não foi possível concluir. Tente novamente.");
      byId("access-feedback").textContent = result.data.message;
      if (["verify_email", "reset_password"].includes(action)) {
        token = ""; purpose = ""; form.reset(); form.hidden = true; byId("access-success").hidden = false; history.replaceState(null, "", location.pathname + "#concluido");
      }
    } catch (error) { byId("access-feedback").textContent = error instanceof SyntaxError ? "O servidor não está disponível. Tente novamente." : error.message; }
    finally { busy = false; button.disabled = false; button.textContent = label; form.removeAttribute("aria-busy"); byId("access-feedback").focus(); }
  }
  byId("register-form").addEventListener("submit", event => { event.preventDefault(); request("register", { name: byId("register-name").value.trim(), company: byId("register-company").value.trim(), email: byId("register-email").value.trim() }, event.currentTarget); });
  byId("forgot-form").addEventListener("submit", event => { event.preventDefault(); request("forgot_password", { email: byId("forgot-email").value.trim() }, event.currentTarget); });
  byId("new-password-form").addEventListener("submit", event => {
    event.preventDefault(); const password = byId("new-password").value, confirmation = byId("confirm-password").value;
    if ([...password].length < 12 || new TextEncoder().encode(password).length > 72) { byId("access-feedback").textContent = "Use pelo menos 12 caracteres. Se a senha for muito longa, escolha uma frase mais curta."; return; }
    if (password !== confirmation) { byId("access-feedback").textContent = "As senhas precisam ser iguais."; return; }
    request(purpose === "verify" ? "verify_email" : "reset_password", { token, password, password_confirmation: confirmation }, event.currentTarget);
  });
  byId("access-show-password").addEventListener("change", event => { ["new-password", "confirm-password"].forEach(id => { byId(id).type = event.target.checked ? "text" : "password"; }); });
  window.addEventListener("hashchange", () => { if (location.hash !== "#definir-senha") { token = ""; purpose = ""; byId("new-password-form").reset(); } route(); });
  route();
})();
