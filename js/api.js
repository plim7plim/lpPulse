(() => {
  "use strict";
  const endpoint = new URL("../backend/api.php", location.href);
  const byId = id => document.getElementById(id);
  const api = window.PulseAPI = { mode: "offline", session: { authenticated: false } };
  function sessionChanged(session) {
    api.session = session;
    api.mode = session.authenticated ? "server" : "offline";
    byId("login").hidden = Boolean(session.authenticated);
    byId("dashboard").hidden = !session.authenticated;
    const values = {
      "company-name": session.company?.name,
      "account-email": session.user?.email,
      "account-name": session.user?.name,
      "account-detail-email": session.user?.email,
      "account-company": session.company?.name,
      "account-role": { owner: "Proprietário", manager: "Gerente", operator: "Operador", viewer: "Consulta" }[session.role],
    };
    Object.entries(values).forEach(([id, value]) => { byId(id).textContent = value || ""; });
    byId("environment-note").hidden = !session.test_environment;
    byId("account-feedback").hidden = true;
    if (!session.authenticated) document.querySelectorAll("dialog[open]").forEach(dialog => dialog.close());
    window.dispatchEvent(new CustomEvent("pulse-api-mode", { detail: { mode: api.mode } }));
  }
  api.request = async function (action, data = {}, method = "GET") {
    const url = new URL(endpoint);
    url.searchParams.set("action", action);
    const options = { method, credentials: "same-origin", headers: { Accept: "application/json" } };
    if (method === "GET") Object.entries(data).forEach(([key, value]) => url.searchParams.set(key, String(value)));
    else {
      options.headers["Content-Type"] = "application/json";
      if (action !== "login" && api.session.csrf_token) options.headers["X-CSRF-Token"] = api.session.csrf_token;
      options.body = JSON.stringify(data);
    }
    const response = await fetch(url, options);
    let result;
    try { result = await response.json(); }
    catch { throw new Error("O servidor não está disponível. Tente novamente."); }
    if (!response.ok || !result.ok) {
      const error = new Error(result.error?.message || "Não foi possível concluir a operação.");
      error.code = result.error?.code;
      if (error.code === "unauthenticated") sessionChanged({ authenticated: false });
      throw error;
    }
    return result.data;
  };
  function notice(message) {
    byId("login-notice").textContent = message;
    byId("login-notice").hidden = !message;
  }
  const loginButton = byId("login-form").querySelector("button[type=submit]");
  byId("login-form").addEventListener("submit", async event => {
    event.preventDefault();
    if (loginButton.disabled) return;
    loginButton.disabled = true;
    notice("");
    try {
      const credentials = { email: byId("email").value.trim(), password: byId("password").value };
      if (!byId("company-id").hidden) credentials.company_id = byId("company-id").value.trim();
      sessionChanged(await api.request("login", credentials, "POST"));
      byId("password").value = "";
    } catch (error) {
      if (error.code === "company_required") {
        byId("company-id").hidden = byId("company-label").hidden = false;
        byId("company-id").required = true;
      }
      notice(error.message);
    } finally { loginButton.disabled = false; }
  });
  byId("exit").addEventListener("click", async () => {
    byId("exit").disabled = true;
    try {
      await api.request("logout", {}, "POST");
      sessionChanged({ authenticated: false });
    } catch (error) {
      byId("account-feedback").textContent = error.message;
      byId("account-feedback").hidden = false;
    } finally { byId("exit").disabled = false; }
  });
  api.ready = (async () => {
    try { sessionChanged(await api.request("session")); }
    catch (error) { sessionChanged({ authenticated: false }); notice(error.message); }
    finally { loginButton.disabled = false; loginButton.textContent = "Entrar"; }
  })();
})();