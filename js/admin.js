(() => {
  "use strict";
  const api = () => window.PulseAPI;
  const byId = id => document.getElementById(id);
  const money = value => Number(value).toLocaleString("pt-BR", { style: "currency", currency: "BRL" });
  const statusLabel = value => ({ active: "Ativo", pending: "Pendente", suspended: "Suspenso", paid: "Pago", cancelled: "Cancelado", expired: "Expirado", submitted: "Enviado", reviewing: "Em análise", quoted: "Orçado", accepted: "Aceito", reserved: "Reservado", running: "Em andamento", settled: "Conferido", rejected: "Recusado" }[value] || value);
  let loading = false, epoch = 0;
  function node(tag, text) { const item = document.createElement(tag); if (text !== undefined) item.textContent = text; return item; }
  function action(text, callback) {
    const button = node("button", text); button.type = "button"; button.className = "button secondary";
    button.addEventListener("click", async () => {
      button.disabled = true;
      try { await callback(); } catch (error) { byId("admin-feedback").textContent = error.message; }
      finally { button.disabled = false; }
    }); return button;
  }
  function table(id, headings, rows) {
    const target = byId(id); target.replaceChildren();
    if (!rows.length) { target.append(node("p", "Nenhum registro.")); return; }
    const wrap = node("div"); wrap.className = "table-scroll"; wrap.tabIndex = 0;
    const grid = node("table"); grid.className = "price-table finance-table";
    const head = node("thead"), tr = node("tr"), body = node("tbody");
    headings.forEach(label => { const cell = node("th", label); cell.scope = "col"; tr.append(cell); }); head.append(tr);
    rows.forEach(values => { const row = node("tr"); values.forEach(value => { const cell = node("td"); cell.append(value instanceof Node ? value : document.createTextNode(String(value ?? "—"))); row.append(cell); }); body.append(row); });
    grid.append(head, body); wrap.append(grid); target.append(wrap);
  }
  function confirm(title, text) {
    const dialog = byId("admin-confirm"); byId("admin-confirm-title").textContent = title; byId("admin-confirm-text").textContent = text;
    byId("admin-confirm-note").value = ""; dialog.returnValue = "";
    return new Promise(resolve => { dialog.addEventListener("close", () => resolve(dialog.returnValue === "confirm" ? byId("admin-confirm-note").value.trim() : null), { once: true }); dialog.showModal(); });
  }
  async function save(payload, title, text) {
    const note = await confirm(title, text); if (!note) return;
    await api().request("admin", { ...payload, note }, "POST"); await load();
    byId("admin-feedback").textContent = "Alteração registrada no histórico administrativo.";
  }
  async function load() {
    if (!api()?.session.is_admin || loading) return;
    const current = epoch; loading = true; byId("admin-refresh").disabled = true;
    byId("admin-view").setAttribute("aria-busy", "true");
    try {
      const data = await api().request("admin"); if (current !== epoch) return;
      table("admin-companies", ["Empresa", "Saldo", "Tarifa por SMS", "Acesso", "Ações"], data.companies.map(company => {
        const controls = node("div"); controls.className = "module-actions";
        const rate = node("input"); rate.type = "number"; rate.min = "0.001"; rate.max = "10"; rate.step = "0.001"; rate.value = (company.rate_mills / 1000).toFixed(3); rate.setAttribute("aria-label", `Tarifa de ${company.name}`);
        const unlimitedLabel = node("label"), unlimited = node("input"); unlimited.type = "checkbox"; unlimited.checked = Boolean(Number(company.unlimited)); unlimitedLabel.append(unlimited, " Sem cobrança");
        controls.append(unlimitedLabel, action("Salvar tarifa", async () => {
          if (!rate.checkValidity()) { rate.reportValidity(); return; }
          await save({ op: "billing", company_id: company.id, rate_mills: Math.round(Number(rate.value) * 1000), unlimited: unlimited.checked }, "Alterar cobrança", "A nova tarifa vale para as próximas campanhas. Reservas existentes mantêm a tarifa original.");
        }), action(company.status === "suspended" ? "Reativar" : "Suspender", () => save({ op: "company_status", company_id: company.id, status: company.status === "suspended" ? "active" : "suspended" }, "Alterar acesso", `${company.name}: a suspensão encerra as sessões e impede novos acessos. Campanhas em andamento continuam.`)), action("Conferir SMS", async () => {
          const result = await api().request("admin", { op: "reconcile", company_id: company.id }, "POST"); await load(); byId("admin-feedback").textContent = `${result.settled} campanha(s) conferida(s); ${result.pending} pendente(s).`;
        }));
        return [`#${company.id} · ${company.name}`, money(company.balance), rate, statusLabel(company.status), controls];
      }));
      table("admin-credits", ["Recarga", "Cliente", "Valor", "Situação", "Ações"], data.credits.map(credit => {
        const controls = node("div"); controls.className = "module-actions";
        if (credit.status === "pending") controls.append(action("Confirmar recebimento", () => save({ op: "credit", company_id: credit.company_id, id: credit.id, status: "paid" }, "Confirmar pagamento recebido", `Liberar ${money(credit.amount)} para ${credit.name}. Confira o extrato bancário antes de confirmar.`)), action("Cancelar", () => save({ op: "credit", company_id: credit.company_id, id: credit.id, status: "cancelled" }, "Cancelar recarga", "Esta solicitação será encerrada sem adicionar saldo.")));
        return [credit.id, credit.name, money(credit.amount), statusLabel(credit.status), controls];
      }));
      const next = { submitted: ["reviewing", "cancelled"], reviewing: ["quoted", "cancelled"], quoted: ["accepted", "reviewing", "cancelled"] };
      const labels = { reviewing: "Analisar", quoted: "Marcar orçamento", accepted: "Marcar aceito", cancelled: "Cancelar" };
      table("admin-orders", ["Pedido", "Cliente", "Solicitação", "Situação", "Ações"], data.orders.map(order => {
        let details = {}; try { details = JSON.parse(order.details); } catch {}
        const info = node("div"); info.append(node("strong", `${details.quantity || "—"} · ${order.service === "whatsapp_number" ? "SMS + WhatsApp garantido" : "SMS"}`), node("p", `DDD ${details.ddd || "livre"} · ${details.notes || "Sem observações"}`));
        if (details.admin_note) info.append(node("small", details.admin_note));
        const controls = node("div"); controls.className = "module-actions";
        (next[order.status] || []).forEach(status => controls.append(action(labels[status], () => save({ op: "order", company_id: order.company_id, id: order.id, status }, "Atualizar pedido", "Informe o andamento. Esta ação não cobra nem entrega números automaticamente."))));
        return [order.id, order.name, info, statusLabel(order.status), controls];
      }));
      table("admin-charges", ["Campanha", "Cliente", "Reserva", "Cobrado", "Situação"], data.charges.map(row => [row.remote_id || "Confirmação pendente", row.company_id, money(row.reserved_cents / 100), row.charged_cents === null ? "Em conferência" : money(row.charged_cents / 100), statusLabel(row.status)]));
      table("admin-events", ["Data UTC", "Responsável", "Ação", "Detalhes"], data.events.map(event => [event.created_at, event.email, event.action, event.details]));
    } catch (error) { if (current === epoch) byId("admin-feedback").textContent = error.message; }
    finally { loading = false; byId("admin-refresh").disabled = false; byId("admin-view").removeAttribute("aria-busy"); }
  }
  byId("admin-refresh").addEventListener("click", load);
  window.addEventListener("pulse-view-change", event => { if (event.detail.view === "admin") load(); });
  window.addEventListener("pulse-api-mode", () => { epoch++; if (!api()?.session.is_admin) ["companies", "credits", "orders", "charges", "events"].forEach(name => byId(`admin-${name}`).replaceChildren()); if (location.hash === "#admin") load(); });
})();
