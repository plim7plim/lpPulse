// Dados da prévia duram nesta aba; dados autenticados ficam apenas em memória.
(() => {
  const cache = { demo: {}, server: {} };
  let mode = "demo";
  const prefix = "pulse-preview-v2:";
  const copy = (value) =>
    value === undefined ? undefined : JSON.parse(JSON.stringify(value));
  const emit = (key) =>
    window.dispatchEvent(
      new CustomEvent("pulse-store-change", { detail: { key, mode } }),
    );
  window.PulseStore = {
    get mode() {
      return mode;
    },
    get(key, fallback) {
      if (!(key in cache[mode]) && mode === "demo") {
        try {
          const raw = sessionStorage.getItem(prefix + key);
          if (raw) cache.demo[key] = JSON.parse(raw);
        } catch (_) {
          /* Navegação privada pode bloquear armazenamento. */
        }
      }
      return copy(key in cache[mode] ? cache[mode][key] : fallback);
    },
    set(key, value) {
      if (mode === "demo") {
        try {
          sessionStorage.setItem(prefix + key, JSON.stringify(value));
        } catch (_) {
          throw new Error(
            "Não foi possível salvar nesta aba. Verifique o armazenamento do navegador.",
          );
        }
      }
      cache[mode][key] = copy(value);
      emit(key);
      return copy(value);
    },
    hydrate(key, value) {
      cache[mode][key] = copy(value);
      emit(key);
    },
    setMode(next) {
      if (!["demo", "server"].includes(next)) throw new Error("Modo inválido.");
      if (mode === "server" && next !== mode) cache.server = {};
      mode = next;
      emit(null);
      window.dispatchEvent(
        new CustomEvent("pulse-mode-change", { detail: { mode } }),
      );
    },
  };
})();
