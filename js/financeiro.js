(() => {
  "use strict";
  const view = document.getElementById("finance-view");
  const byId = (id) => document.getElementById(id);
  const api = () => window.PulseAPI;
  const money = (value) =>
    Number(value || 0).toLocaleString("pt-BR", { style: "currency", currency: "BRL" });
  const date = (value) =>
    value
      ? new Date(
          value.replace(" ", "T") + (value.length > 10 ? "Z" : "T12:00:00Z")
        ).toLocaleDateString("pt-BR")
      : "—";
  const labels = {
    pending: "Pendente",
    expired: "Expirada",
    refunded: "Estornada",
    partially_refunded: "Estorno parcial",
    approved: "Aprovada",
    rejected: "Recusada",
    cancelled: "Cancelada",
    paid: "Paga",
    overdue: "Vencida",
    open: "Em aberto",
    issued: "Emitida",
    draft: "Rascunho",
    void: "Cancelada",
    credit: "Crédito",
    debit: "Débito",
    reversal: "Estorno",
    adjustment: "Ajuste",
    pix: "Pix",
    boleto: "Boleto"
  };
  let epoch = 0,
    loading = false;
  const pixBox = element("section", undefined, "finance-pix");
  pixBox.hidden = true;
  pixBox.setAttribute("aria-label", "Pagamento Pix");
  byId("finance-recharge-form").after(pixBox);
  async function showPix(requestId) {
    const current = epoch;
    pixBox.hidden = false;
    pixBox.replaceChildren(element("p", "Consultando a cobrança Pix…"));
    try {
      const result = await api().request("pix_checkout", { request_id: String(requestId) }, "POST");
      if (current !== epoch) return;
      const pix = result.pix;
      pixBox.replaceChildren(element("h3", `Pix · ${money(result.amount)}`));
      if (pix.environment === "homologacao")
        pixBox.append(element("p", "Ambiente de testes. Não efetue um pagamento real."));
      if (/^data:image\/png;base64,[A-Za-z0-9+/=\s]+$/.test(pix.qr_image || "")) {
        const image = element("img");
        image.src = pix.qr_image;
        image.alt = "QR Code da cobrança Pix";
        image.width = image.height = 220;
        pixBox.append(image);
      }
      const label = element("label", "Pix copia e cola");
      const code = element("textarea");
      code.readOnly = true;
      code.value = pix.copy_paste;
      code.rows = 3;
      label.append(code);
      pixBox.append(label);
      if (pix.expires_at)
        pixBox.append(
          element("p", `Validade: ${new Date(pix.expires_at).toLocaleString("pt-BR")}`)
        );
      const copy = element("button", "Copiar código", "button button-secondary");
      copy.type = "button";
      copy.disabled = !pix.copy_paste;
      copy.addEventListener("click", async () => {
        try {
          await navigator.clipboard.writeText(code.value);
          copy.textContent = "Código copiado";
        } catch {
          code.focus();
          code.select();
          copy.textContent = "Selecione e copie o código";
        }
      });
      const check = element("button", "Verificar pagamento", "button button-primary");
      check.type = "button";
      const feedback = element("p", "O saldo será liberado após confirmação da Efí.");
      feedback.setAttribute("role", "status");
      check.addEventListener("click", async () => {
        check.disabled = true;
        try {
          const response = await api().request(
            "pix_status",
            { request_id: String(requestId) },
            "POST"
          );
          if (current !== epoch) return;
          feedback.textContent =
            response.status === "approved"
              ? "Pagamento confirmado. Créditos liberados."
              : `Pagamento: ${labels[response.status] || response.status}.`;
          await load();
        } catch (error) {
          if (current === epoch) feedback.textContent = error.message;
        } finally {
          check.disabled = false;
        }
      });
      pixBox.append(copy, check, feedback);
    } catch (error) {
      if (current === epoch) pixBox.replaceChildren(element("p", error.message));
    }
  }
  function paymentAction(item) {
    const wrap = element("span");
    wrap.append(status(item.status));
    if (item.method === "pix" && item.status === "pending" && writable()) {
      const button = element("button", "Gerar Pix", "button button-secondary");
      button.type = "button";
      button.addEventListener("click", () => {
        selectTab(byId("finance-tab-recharge"));
        showPix(item.id);
      });
      wrap.append(button);
    }
    return wrap;
  }
  const writable = () => ["owner", "manager"].includes(api()?.session.role);
  function element(tag, text, className) {
    const item = document.createElement(tag);
    if (text !== undefined) item.textContent = text;
    if (className) item.className = className;
    return item;
  }
  function status(value) {
    return element("span", labels[value] || value, "finance-status " + value);
  }
  function table(target, headers, rows, empty) {
    target.replaceChildren();
    if (!rows.length) {
      target.append(element("p", empty, "finance-empty"));
      return;
    }
    const wrap = element("div", undefined, "table-scroll");
    wrap.tabIndex = 0;
    wrap.setAttribute("role", "region");
    wrap.setAttribute(
      "aria-label",
      target.closest("section")?.querySelector("h2")?.textContent || "Recargas"
    );
    const grid = element("table", undefined, "price-table finance-table");
    const head = element("thead"),
      body = element("tbody"),
      headRow = element("tr");
    headers.forEach((text) => {
      const cell = element("th", text);
      cell.scope = "col";
      headRow.append(cell);
    });
    head.append(headRow);
    rows.forEach((values) => {
      const row = element("tr");
      values.forEach((value) => {
        const cell = element("td");
        cell.append(value instanceof Node ? value : document.createTextNode(String(value)));
        row.append(cell);
      });
      body.append(row);
    });
    grid.append(head, body);
    wrap.append(grid);
    target.append(wrap);
  }
  function permissions() {
    ["finance-recharge-form", "finance-preferences-form"].forEach((id) => {
      byId(id)
        .querySelectorAll("input, select, button")
        .forEach((field) => {
          field.disabled = !writable();
        });
    });
    view.querySelector(".finance-permission").hidden = writable();
  }
  async function load() {
    if (api()?.mode !== "server" || loading) return;
    const current = epoch;
    loading = true;
    byId("finance-refresh").disabled = true;
    view.setAttribute("aria-busy", "true");
    byId("finance-feedback").textContent = "Carregando financeiro…";
    try {
      if (api().session.role !== "viewer") await api().request("sms_reconcile", {}, "POST");
      const [balance, requests, invoices, billing] = await Promise.all(
        ["balance", "credit_requests", "invoices", "billing_preferences"].map((action) =>
          api().request(action)
        )
      );
      if (current !== epoch) return;
      byId("finance-balance").textContent = money(balance.balance);
      byId("finance-sms-balance").textContent =
        `Créditos SMS: ${Number(balance.sms_available || 0).toLocaleString("pt-BR")} · Longcode: ${Number(balance.longcode_available || 0).toLocaleString("pt-BR")}`;
      byId("finance-environment").textContent = api().session.test_environment
        ? "Dados do banco local de testes"
        : "Créditos registrados no extrato";
      byId("finance-pending").textContent = requests.items.filter(
        (item) => item.status === "pending"
      ).length;
      const open = invoices.items.filter((item) =>
        ["issued", "open", "overdue"].includes(item.status)
      );
      byId("finance-due").textContent = money(
        open.reduce((sum, item) => sum + Number(item.amount), 0)
      );
      byId("finance-due-count").textContent = `${open.length} fatura(s) aguardando pagamento`;
      table(
        byId("finance-requests"),
        ["Solicitação", "Valor", "Método", "Status"],
        requests.items.map((item) => [
          `#${item.id} · ${item.sms_quantity ? Number(item.sms_quantity).toLocaleString("pt-BR") + (item.sms_channel === "longcode" ? " Longcode · " : " SMS · ") : ""}${date(item.created_at)}`,
          money(item.amount),
          labels[item.method] || item.method,
          paymentAction(item)
        ]),
        "Nenhuma recarga solicitada."
      );
      table(
        byId("finance-entries"),
        ["Data", "Descrição", "Tipo", "Valor"],
        balance.items.map((item) => [
          date(item.occurred_at),
          item.description,
          labels[item.type] || item.type,
          money(item.amount)
        ]),
        "Nenhuma movimentação registrada."
      );
      table(
        byId("finance-invoice-list"),
        ["Referência", "Descrição", "Vencimento", "Valor", "Status"],
        invoices.items.map((item) => [
          item.reference,
          item.description,
          date(item.due_date),
          money(item.amount),
          status(item.status)
        ]),
        "Nenhuma fatura emitida."
      );
      const prefs = billing.preferences || {
        responsible_name: api().session.user.name,
        billing_email: api().session.user.email,
        preferred_method: "pix"
      };
      Object.entries(prefs).forEach(([name, value]) => {
        const input = byId("finance-preferences-form").elements.namedItem(name);
        if (input && document.activeElement !== input) input.value = value || "";
      });
      byId("finance-feedback").textContent = "";
      permissions();
    } catch (error) {
      if (current === epoch) byId("finance-feedback").textContent = error.message;
    } finally {
      if (current === epoch) {
        loading = false;
        byId("finance-refresh").disabled = false;
        view.removeAttribute("aria-busy");
      }
    }
  }
  const tabs = [...view.querySelectorAll("[data-finance-tab]")];
  function selectTab(button) {
    tabs.forEach((tab) => {
      const selected = tab === button;
      tab.setAttribute("aria-selected", String(selected));
      tab.tabIndex = selected ? 0 : -1;
      byId("finance-" + tab.dataset.financeTab).hidden = !selected;
    });
  }
  tabs.forEach((button, index) => {
    button.addEventListener("click", () => selectTab(button));
    button.addEventListener("keydown", (event) => {
      if (!["ArrowLeft", "ArrowRight", "Home", "End"].includes(event.key)) return;
      event.preventDefault();
      const next =
        event.key === "Home"
          ? 0
          : event.key === "End"
            ? tabs.length - 1
            : (index + (event.key === "ArrowRight" ? 1 : -1) + tabs.length) % tabs.length;
      selectTab(tabs[next]);
      tabs[next].focus();
    });
  });
  function save(formId, action, feedbackId, message) {
    const form = byId(formId);
    form.addEventListener("submit", async (event) => {
      event.preventDefault();
      if (!writable() || form.dataset.saving || !form.reportValidity()) return;
      const current = epoch,
        submit = form.querySelector('[type="submit"]');
      form.dataset.saving = "true";
      submit.disabled = true;
      byId(feedbackId).textContent = "Registrando…";
      try {
        const payload = Object.fromEntries(new FormData(form));
        if (action === "credit_requests") {
          payload.amount = Number(payload.amount).toFixed(2);
          if (["sms", "longcode"].includes(payload.purchase_type)) {
            payload.sms_quantity = Number(payload.sms_quantity);
            payload.sms_channel = payload.purchase_type;
          } else delete payload.sms_quantity;
          delete payload.purchase_type;
        }
        const saved = await api().request(action, payload, "POST");
        if (current !== epoch) return;
        byId(feedbackId).textContent = message;
        await load();
        if (action === "credit_requests" && saved.pix_available) await showPix(saved.id);
      } catch (error) {
        if (current === epoch) byId(feedbackId).textContent = error.message;
      } finally {
        delete form.dataset.saving;
        if (current === epoch) submit.disabled = !writable();
      }
    });
  }
  const purchaseForm = byId("finance-recharge-form");
  let priceCatalog = null;
  function updatePurchase() {
    const isSms = purchaseForm.elements.purchase_type.value !== "balance";
    const quantity = purchaseForm.elements.sms_quantity;
    quantity.disabled = !isSms;
    quantity.required = isSms;
    byId("finance-quantity-label").hidden = !isSms;
    purchaseForm.elements.amount.readOnly = isSms;
    byId("finance-sms-price").hidden = !isSms;
    if (!isSms || !priceCatalog) return;
    const count = Number(quantity.value);
    if (!Number.isInteger(count) || count < 1 || count > 1000000) {
      byId("finance-sms-price").textContent = "Informe uma quantidade inteira válida.";
      return;
    }
    const tier =
      purchaseForm.elements.purchase_type.value === "longcode"
        ? { rate_mills: priceCatalog.longcode_rate_mills }
        : priceCatalog.sms.find((band) => band.max === null || count <= band.max);
    const cents = Math.ceil((count * tier.rate_mills) / 10);
    purchaseForm.elements.amount.value = (cents / 100).toFixed(2);
    byId("finance-sms-price").textContent =
      `${count.toLocaleString("pt-BR")} SMS × ${money(tier.rate_mills / 1000)} = ${money(cents / 100)}. A tarifa vale para toda esta compra, sem somar compras anteriores.`;
  }
  purchaseForm.addEventListener("input", updatePurchase);
  window.PulsePricing?.ready.then((catalog) => {
    priceCatalog = catalog;
    updatePurchase();
  });
  updatePurchase();
  save(
    "finance-recharge-form",
    "credit_requests",
    "finance-recharge-feedback",
    "Solicitação registrada. Aguarde a equipe confirmar o pagamento; nenhum crédito foi liberado."
  );
  save(
    "finance-preferences-form",
    "billing_preferences",
    "finance-preferences-feedback",
    "Dados de cobrança salvos."
  );
  byId("finance-refresh").addEventListener("click", load);
  window.addEventListener("pulse-view-change", ({ detail }) => {
    if (detail.view === "finance") {
      permissions();
      load();
    }
  });
  window.addEventListener("pulse-api-mode", () => {
    epoch++;
    pixBox.hidden = true;
    pixBox.replaceChildren();
    loading = false;
    view.removeAttribute("aria-busy");
    ["finance-requests", "finance-entries", "finance-invoice-list"].forEach((id) =>
      byId(id).replaceChildren()
    );
    ["finance-balance", "finance-pending", "finance-due"].forEach((id) => {
      byId(id).textContent = "—";
    });
    [
      "finance-feedback",
      "finance-recharge-feedback",
      "finance-preferences-feedback",
      "finance-environment",
      "finance-due-count"
    ].forEach((id) => {
      byId(id).textContent = "";
    });
    byId("finance-preferences-form").reset();
    permissions();
    if (!view.hidden) load();
  });
  api()?.ready?.then(() => {
    permissions();
    if (!view.hidden) load();
  });
})();
