(() => {
  "use strict";
  const dashboard = document.getElementById("dashboard");
  const sidebar = dashboard.querySelector(".sidebar");
  const menu = document.getElementById("menu-toggle");
  const names = { "sms-marketing": "Enviar SMS", "my-numbers": "Meus números", campaigns: "Campanhas", finance: "Financeiro", account: "Minha conta" };
  function closeMenu() {
    dashboard.classList.remove("menu-open");
    menu.setAttribute("aria-expanded", "false");
    menu.setAttribute("aria-label", "Abrir menu");
  }
  function setView(view) {
    if (!Object.hasOwn(names, view)) view = "sms-marketing";
    history.replaceState(null, "", "#" + view);
    Object.keys(names).forEach(name => {
      document.getElementById(name + "-view").hidden = name !== view;
      const button = sidebar.querySelector(`[data-view="${name}"]`);
      button.classList.toggle("active", name === view);
      if (name === view) button.setAttribute("aria-current", "page");
      else button.removeAttribute("aria-current");
    });
    document.getElementById("breadcrumb").textContent = names[view];
    closeMenu();
    window.dispatchEvent(new CustomEvent("pulse-view-change", { detail: { view } }));
  }
  sidebar.querySelectorAll("[data-view]").forEach(button => {
    button.title = names[button.dataset.view];
    button.addEventListener("click", () => setView(button.dataset.view));
  });
  menu.addEventListener("click", () => {
    const open = dashboard.classList.toggle("menu-open");
    menu.setAttribute("aria-expanded", String(open));
    menu.setAttribute("aria-label", open ? "Fechar menu" : "Abrir menu");
  });
  document.addEventListener("keydown", event => { if (event.key === "Escape") closeMenu(); });
  document.addEventListener("click", event => {
    if (!sidebar.contains(event.target) && !menu.contains(event.target)) closeMenu();
  });
  window.addEventListener("hashchange", () => setView(location.hash.slice(1)));
  document.getElementById("show-password").addEventListener("click", event => {
    const input = document.getElementById("password");
    input.type = input.type === "password" ? "text" : "password";
    event.currentTarget.textContent = input.type === "password" ? "Mostrar" : "Ocultar";
    event.currentTarget.setAttribute("aria-label", event.currentTarget.textContent + " senha");
  });
  setView(location.hash.slice(1));
})();
