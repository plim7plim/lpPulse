(() => {
  let theme = "dark";
  try {
    theme = localStorage.getItem("pulse-theme") || "dark";
  } catch {}
  function applyTheme() {
    document.documentElement.dataset.theme = theme;
    document.documentElement.style.colorScheme = theme;
    document.querySelectorAll("[data-theme-toggle]").forEach((button) => {
      const label =
        theme === "dark" ? "Ativar modo claro" : "Ativar modo noturno";
      button.textContent = theme === "dark" ? "☀" : "☾";
      button.setAttribute("aria-label", label);
      button.title = label;
    });
  }
  applyTheme();
  document.addEventListener("DOMContentLoaded", applyTheme);
  document.addEventListener("click", (event) => {
    if (!event.target.closest("[data-theme-toggle]")) return;
    theme = theme === "dark" ? "light" : "dark";
    applyTheme();
    try {
      localStorage.setItem("pulse-theme", theme);
    } catch {}
  });
})();
