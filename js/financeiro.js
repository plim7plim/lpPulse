(() => {
  "use strict";
  const view = document.getElementById("finance-view");
  const byId = id => document.getElementById(id);
  const api = () => window.PulseAPI;
  const money = value => Number(value || 0).toLocaleString("pt-BR", { style: "currency", currency: "BRL" });
  const date = value => value ? new Date(value.replace(" ", "T") + (value.length > 10 ? "Z" : "T12:00:00Z")).toLocaleDateString("pt-BR") : "—";
  const labels = { pending: "Pendente", approved: "Aprovada", rejected: "Recusada", cancelled: "Cancelada", paid: "Paga", overdue: "Vencida", open: "Em aberto", issued: "Emitida", draft: "Rascunho", void: "Cancelada", credit: "Crédito", debit: "Débito", reversal: "Estorno", adjustment: "Ajuste", pix: "Pix", boleto: "Boleto" };
  let epoch = 0, loading = false;
  const writable = () => ["owner", "manager"].includes(api()?.session.role);
  function element(tag, text, className) {
    const item = document.createElement(tag);
    if (text !== undefined) item.textContent = text;
    if (className) item.className = className;
    return item;
  }
  function status(value) { return element("span", labels[value] || value, "finance-status " + value); }
  function table(target, headers, rows, empty) {
    target.replaceChildren();
    if (!rows.length) { target.append(element("p", empty, "finance-empty")); return; }
    const wrap = element("div", undefined, "table-scroll");
    wrap.tabIndex = 0;
    wrap.setAttribute("role", "region");
    wrap.setAttribute("aria-label", target.closest("section")?.querySelector("h2")?.textContent || "Recargas");
    const grid = element("table", undefined, "price-table finance-table");
    const head = element("thead"), body = element("tbody"), headRow = element("tr");
    headers.forEach(text => { const cell = element("th", text); cell.scope = "col"; headRow.append(cell); });
    head.append(headRow);
    rows.forEach(values => {
      const row = element("tr");
      values.forEach(value => { const cell = element("td"); cell.append(value instanceof Node ? value : document.createTextNode(String(value))); row.append(cell); });
      body.append(row);
    });
    grid.append(head, body); wrap.append(grid); target.append(wrap);
  }
  function permissions() {
    ["finance-recharge-form", "finance-preferences-form"].forEach(id => {
      byId(id).querySelectorAll("input, select, button").forEach(field => { field.disabled = !writable(); });
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
      const [balance, requests, invoices, billing] = await Promise.all(["balance", "credit_requests", "invoices", "billing_preferences"].map(action => api().request(action)));
      if (current !== epoch) return;
      byId("finance-balance").textContent = money(balance.balance);
      byId("finance-environment").textContent = api().session.test_environment ? "Dados do banco local de testes" : "Créditos registrados no extrato";
      byId("finance-pending").textContent = requests.items.filter(item => item.status === "pending").length;
      const open = invoices.items.filter(item => ["issued", "open", "overdue"].includes(item.status));
      byId("finance-due").textContent = money(open.reduce((sum, item) => sum + Number(item.amount), 0));
      byId("finance-due-count").textContent = `${open.length} fatura(s) aguardando pagamento`;
      table(byId("finance-requests"), ["Solicitação", "Valor", "Método", "Status"], requests.items.map(item => [`#${item.id} · ${date(item.created_at)}`, money(item.amount), labels[item.method] || item.method, status(item.status)]), "Nenhuma recarga solicitada.");
      table(byId("finance-entries"), ["Data", "Descrição", "Tipo", "Valor"], balance.items.map(item => [date(item.occurred_at), item.description, labels[item.type] || item.type, money(item.amount)]), "Nenhuma movimentação registrada.");
      table(byId("finance-invoice-list"), ["Referência", "Descrição", "Vencimento", "Valor", "Status"], invoices.items.map(item => [item.reference, item.description, date(item.due_date), money(item.amount), status(item.status)]), "Nenhuma fatura emitida.");
      const prefs = billing.preferences || { responsible_name: api().session.user.name, billing_email: api().session.user.email, preferred_method: "pix" };
      Object.entries(prefs).forEach(([name, value]) => { const input = byId("finance-preferences-form").elements.namedItem(name); if (input && document.activeElement !== input) input.value = value || ""; });
      byId("finance-feedback").textContent = "";
      permissions();
    } catch (error) {
      if (current === epoch) byId("finance-feedback").textContent = error.message;
    } finally {
      if (current === epoch) { loading = false; byId("finance-refresh").disabled = false; view.removeAttribute("aria-busy"); }
    }
  }
  const tabs = [...view.querySelectorAll("[data-finance-tab]")];
  function selectTab(button) {
    tabs.forEach(tab => {
      const selected = tab === button;
      tab.setAttribute("aria-selected", String(selected)); tab.tabIndex = selected ? 0 : -1;
      byId("finance-" + tab.dataset.financeTab).hidden = !selected;
    });
  }
  tabs.forEach((button, index) => {
    button.addEventListener("click", () => selectTab(button));
    button.addEventListener("keydown", event => {
      if (!["ArrowLeft", "ArrowRight", "Home", "End"].includes(event.key)) return;
      event.preventDefault();
      const next = event.key === "Home" ? 0 : event.key === "End" ? tabs.length - 1 : (index + (event.key === "ArrowRight" ? 1 : -1) + tabs.length) % tabs.length;
      selectTab(tabs[next]); tabs[next].focus();
    });
  });
  function save(formId, action, feedbackId, message) {
    const form = byId(formId);
    form.addEventListener("submit", async event => {
      event.preventDefault();
      if (!writable() || form.dataset.saving || !form.reportValidity()) return;
      const current = epoch, submit = form.querySelector('[type="submit"]');
      form.dataset.saving = "true"; submit.disabled = true;
      byId(feedbackId).textContent = "Registrando…";
      try {
        const payload = Object.fromEntries(new FormData(form));
        if (action === "credit_requests") payload.amount = Number(payload.amount).toFixed(2);
        await api().request(action, payload, "POST");
        if (current !== epoch) return;
        byId(feedbackId).textContent = message;
        await load();
      } catch (error) { if (current === epoch) byId(feedbackId).textContent = error.message; }
      finally { delete form.dataset.saving; if (current === epoch) submit.disabled = !writable(); }
    });
  }
  save("finance-recharge-form", "credit_requests", "finance-recharge-feedback", "Solicitação registrada. Aguarde a equipe confirmar o pagamento; nenhum crédito foi liberado.");
  save("finance-preferences-form", "billing_preferences", "finance-preferences-feedback", "Dados de cobrança salvos.");
  byId("finance-refresh").addEventListener("click", load);
  window.addEventListener("pulse-view-change", ({ detail }) => { if (detail.view === "finance") { permissions(); load(); } });
  window.addEventListener("pulse-api-mode", () => {
    epoch++; loading = false; view.removeAttribute("aria-busy");
    ["finance-requests", "finance-entries", "finance-invoice-list"].forEach(id => byId(id).replaceChildren());
    ["finance-balance", "finance-pending", "finance-due"].forEach(id => { byId(id).textContent = "—"; });
    ["finance-feedback", "finance-recharge-feedback", "finance-preferences-feedback", "finance-environment", "finance-due-count"].forEach(id => { byId(id).textContent = ""; });
    byId("finance-preferences-form").reset(); permissions();
    if (!view.hidden) load();
  });
  api()?.ready?.then(() => { permissions(); if (!view.hidden) load(); });
})();
