// Persistência da prévia nesta aba, separada da conta autenticada.
(() => {
  const store = window.PulseStore;
  document.querySelectorAll(".session-form").forEach((form) =>
    form.addEventListener("submit", () => {
      if (store.mode !== "demo") return;
      const view = form
        .closest('section[id$="-view"]')
        .id.replace(/-view$/, "");
      const feedback = form.querySelector(".form-feedback");
      if (
        view === "travel-esim" &&
        document.querySelector("#esim-end").value <
          document.querySelector("#esim-start").value
      )
        return;
      const details = Object.fromEntries(
        Array.from(form.querySelectorAll("input,select,textarea")).map(
          (field) => [
            field.name || field.id,
            field.type === "checkbox" ? field.checked : field.value,
          ],
        ),
      );
      try {
        if (view === "payment") store.set("billingPreferences", details);
        else {
          const key =
            view === "credit"
              ? "creditRequests"
              : ["affiliates", "reseller"].includes(view)
                ? "partnershipRequests"
                : "serviceRequests";
          store.set(key, [
            ...store.get(key, []),
            {
              id: crypto.randomUUID(),
              type: view,
              details,
              status: "draft",
              created_at: new Date().toISOString(),
            },
          ]);
        }
        feedback.textContent =
          view === "credit"
            ? "Pedido salvo nesta aba. Nenhuma cobrança ou crédito gerado."
            : "Dados salvos nesta aba para demonstração.";
      } catch (error) {
        feedback.textContent = error.message;
      }
      feedback.hidden = false;
    }),
  );
  document
    .querySelector("#profile-form")
    .addEventListener("submit", (event) => {
      if (store.mode !== "demo") return;
      const values = Object.fromEntries(
        Array.from(event.target.querySelectorAll("input,select")).map(
          (field) => [field.id, field.value],
        ),
      );
      try {
        store.set("demoProfile", values);
      } catch (error) {
        document.querySelector("#toast").textContent = error.message;
      }
    });
  const restore = () => {
    if (store.mode !== "demo") return;
    const profile = store.get("demoProfile", {});
    Object.entries(profile).forEach(([id, value]) => {
      const field = document.getElementById(id);
      if (field) field.value = value;
    });
    if (profile["profile-name"]) {
      document.querySelector("#greeting-name").textContent = profile[
        "profile-name"
      ]
        .trim()
        .split(/\s+/)[0];
      document.querySelector(".sidebar-bottom b").textContent =
        profile["profile-company"];
      document.querySelector("#registration-alert").hidden = true;
    }
    const billing = store.get("billingPreferences", {});
    document
      .querySelectorAll("#payment-view input,#payment-view select")
      .forEach((field) => {
        if (billing[field.name || field.id] !== undefined)
          field.value = billing[field.name || field.id];
      });
  };
  window.addEventListener("pulse-mode-change", restore);
  restore();
  const amount = document.querySelector("#credit-amount");
  const presets = document.createElement("div");
  presets.className = "quick-presets";
  [50, 100, 200, 500, 1000].forEach((value) => {
    const button = document.createElement("button");
    button.type = "button";
    button.className = "button secondary";
    button.textContent = `R$ ${value}`;
    button.onclick = () => {
      amount.value = value;
      presets
        .querySelectorAll("button")
        .forEach((item) => item.classList.toggle("selected", item === button));
      amount.focus();
    };
    presets.append(button);
  });
  amount.before(presets);
  const datePresets = document.createElement("div");
  datePresets.className = "quick-presets";
  ["Este mês", "Mês passado", "Últimos 30 dias", "Últimos 90 dias"].forEach(
    (label, index) => {
      const button = document.createElement("button");
      button.type = "button";
      button.className = "button secondary";
      button.textContent = label;
      button.onclick = () => {
        const end = new Date(),
          start = new Date();
        if (index === 0) start.setDate(1);
        else if (index === 1) {
          start.setMonth(start.getMonth() - 1, 1);
          end.setDate(0);
        } else start.setDate(start.getDate() - (index === 2 ? 29 : 89));
        const localDate = (date) =>
          `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, "0")}-${String(date.getDate()).padStart(2, "0")}`;
        document.querySelector("#balance-start").value = localDate(start);
        document.querySelector("#balance-end").value = localDate(end);
        document.querySelector("#balance-filter").requestSubmit();
      };
      datePresets.append(button);
    },
  );
  document.querySelector("#balance-filter").prepend(datePresets);
})();
