(() => {
  "use strict";
  const api = () => window.PulseAPI;
  const view = document.getElementById("contracts-view");
  const list = document.getElementById("contract-list");
  const feedback = document.getElementById("contract-feedback");
  const dialog = document.getElementById("contract-confirm");
  const confirmButton = document.getElementById("contract-confirm-submit");
  let catalog = null,
    loading = false,
    pending = null,
    saving = false,
    epoch = 0;
  const writable = () =>
    api()?.mode === "server" && ["owner", "manager"].includes(api().session.role);
  const node = (tag, text, cls) => {
    const el = document.createElement(tag);
    if (text !== undefined) el.textContent = text;
    if (cls) el.className = cls;
    return el;
  };
  const money = (cents) => window.PulsePricing.money(cents);
  const prices = () =>
    Object.fromEntries((catalog?.numbers || []).map((item) => [item.id, item.price_cents]));
  const title = (product) =>
    product === "online" ? "Número online · 30 dias" : "100 números · 30 dias";
  function key(data) {
    const label = `pulse-contract:${api().session.company.id}:${data.op}:${data.product || data.id}:${data.quantity || ""}`;
    let value;
    try {
      value = sessionStorage.getItem(label);
    } catch {}
    value ||= crypto.randomUUID();
    try {
      sessionStorage.setItem(label, value);
    } catch {}
    return { label, value };
  }
  function review(data, text) {
    const id = key(data);
    pending = { data: { ...data, request_id: id.value, confirm: true }, label: id.label };
    document.getElementById("contract-confirm-text").textContent = text;
    document.getElementById("contract-confirm-feedback").textContent = "";
    confirmButton.disabled = false;
    dialog.showModal();
  }
  function button(text, data, description) {
    const el = node("button", text, "button secondary");
    el.type = "button";
    el.disabled = !writable();
    el.addEventListener("click", () => review(data, description));
    return el;
  }
  async function load() {
    if (loading || api()?.mode !== "server") return;
    loading = true;
    const current = epoch;
    try {
      const result = await api().request("number_contracts");
      if (current !== epoch) return;
      list.replaceChildren();
      if (!result.items.length)
        list.append(node("p", "Nenhum contrato contratado ainda.", "import-hint"));
      result.items.forEach((c) => {
        const card = node("article", undefined, "service-panel");
        const head = node("div", undefined, "service-panel-top");
        head.append(
          node("h2", `${title(c.product)} · #${c.id}`),
          node(
            "span",
            {
              paid: "Aguardando primeira entrega",
              active: "Ativo",
              expired: "Vencido",
              cancelled: "Cancelado"
            }[c.status],
            "finance-status"
          )
        );
        card.append(head);
        const deadline = c.expires_at
          ? new Date(c.expires_at.replace(" ", "T") + "Z").toLocaleString("pt-BR")
          : "O prazo começa na primeira entrega";
        card.append(node("p", `Validade: ${deadline}`, "service-description"));
        const used = Number(c.used);
        card.append(
          node(
            "p",
            c.product === "subscription"
              ? `${used} de 100 números solicitados neste período · ${c.delivered} entregues`
              : `${Math.max(0, used - (Number(c.cycle) === 1 || c.status === "paid" ? 1 : 0))} de 10 trocas utilizadas neste período`,
            "import-hint"
          )
        );
        const actions = node("div", undefined, "module-actions");
        if (["active", "paid"].includes(c.status)) {
          const qty = node("input");
          qty.type = "number";
          qty.min = "1";
          qty.max = c.product === "online" ? "1" : String(Math.max(1, 100 - used));
          qty.value = "1";
          qty.setAttribute("aria-label", "Quantidade de números do contrato");
          qty.style.maxWidth = "120px";
          if (c.product === "subscription") actions.append(qty);
          const redeem = node(
            "button",
            c.product === "online"
              ? used
                ? "Solicitar troca"
                : "Solicitar primeiro número"
              : "Solicitar números",
            "button secondary"
          );
          redeem.type = "button";
          redeem.disabled = !writable();
          redeem.addEventListener("click", () => {
            if (!qty.reportValidity()) return;
            review(
              { op: "redeem", id: c.id, quantity: Number(qty.value) },
              `Solicitar ${qty.value} número(s) incluído(s) neste contrato. Sujeito ao estoque. A entrega aparece em Comprar números; não haverá nova cobrança.`
            );
          });
          actions.append(redeem);
        }
        if (["active", "expired"].includes(c.status))
          actions.append(
            button(
              "Renovar 30 dias",
              { op: "renew", id: c.id, amount_cents: prices()[c.product] },
              `Renovar por ${money(prices()[c.product])}, descontados do saldo em reais. Disponível nas últimas 24 horas do período ou após o vencimento. Sem renovação automática.`
            )
          );
        card.append(actions);
        list.append(card);
      });
      feedback.textContent = "";
    } catch (error) {
      if (current === epoch) feedback.textContent = error.message;
    } finally {
      loading = false;
    }
  }
  window.PulsePricing?.ready.then((value) => {
    catalog = value;
    if (!catalog) {
      feedback.textContent = "Tabela de preços indisponível. Atualize a página antes de contratar.";
      permissions();
      return;
    }
    permissions();
    document.querySelectorAll("[data-contract-buy]").forEach((el) => {
      const product = el.dataset.contractBuy;
      el.textContent = `Contratar · ${money(prices()[product])}`;
      el.addEventListener("click", () =>
        review(
          { op: "buy", product, amount_cents: prices()[product] },
          `${title(product)} por ${money(prices()[product])}, descontados do saldo em reais. ${product === "online" ? "Depois de contratar, solicite seu primeiro número. A validade começa na primeira entrega." : "Cota de 100 números por 30 dias, entregues em lotes conforme o estoque."} Sem cobrança recorrente automática.`
        )
      );
    });
  });
  confirmButton.addEventListener("click", async () => {
    if (saving || !pending || !writable()) return;
    saving = true;
    confirmButton.disabled = true;
    const current = epoch;
    try {
      await api().request("number_contracts", pending.data, "POST");
      if (current !== epoch) return;
      try {
        sessionStorage.removeItem(pending.label);
      } catch {}
      dialog.close();
      pending = null;
      await load();
    } catch (error) {
      document.getElementById("contract-confirm-feedback").textContent = error.message;
      if (["insufficient_stock", "order_state"].includes(error.code)) {
        pending.data.request_id = crypto.randomUUID();
        try {
          sessionStorage.setItem(pending.label, pending.data.request_id);
        } catch {}
      }
    } finally {
      saving = false;
      confirmButton.disabled = false;
    }
  });
  document.getElementById("contract-confirm-close").addEventListener("click", () => {
    if (!saving) dialog.close();
  });
  dialog.addEventListener("cancel", (e) => {
    if (saving) e.preventDefault();
  });
  document.getElementById("contract-refresh").addEventListener("click", load);
  function permissions() {
    view
      .querySelectorAll("[data-contract-buy]")
      .forEach((el) => (el.disabled = !writable() || !catalog));
  }
  window.addEventListener("pulse-api-mode", () => {
    epoch++;
    pending = null;
    if (dialog.open) dialog.close();
    permissions();
    list.replaceChildren();
    if (!view.hidden) load();
  });
  window.addEventListener("pulse-view-change", ({ detail }) => {
    if (detail.view === "contracts") {
      permissions();
      load();
    }
  });
  api()?.ready?.then(() => {
    permissions();
    if (!view.hidden) load();
  });
  const timer = setInterval(() => {
    if (!document.hidden && !view.hidden) load();
  }, 30000);
  window.addEventListener("pagehide", () => clearInterval(timer), { once: true });
})();
