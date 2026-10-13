(() => {
  "use strict";
  const api = () => window.PulseAPI;
  const form = document.getElementById("number-order-form");
  const body = document.getElementById("number-order-lines");
  const add = document.getElementById("number-add-line");
  const submit = document.getElementById("number-order-submit");
  const feedback = document.getElementById("number-order-feedback");
  const history = document.getElementById("number-order-history");
  const total = document.getElementById("number-order-total");
  const refresh = document.getElementById("number-orders-refresh");
  const purchased = document.getElementById("purchased-numbers");
  const purchaseDialog = document.getElementById("number-purchase-dialog");
  let selectedQuote = null,
    selectedInbox = null,
    paying = false,
    inboxLoading = false;
  const money = (cents) =>
    (Number(cents) / 100).toLocaleString("pt-BR", { style: "currency", currency: "BRL" });
  let sending = false,
    loading = false,
    epoch = 0,
    requestId = null;
  const allowed = () =>
    api()?.mode === "server" && ["owner", "manager", "operator"].includes(api().session.role);
  function node(tag, text, className) {
    const el = document.createElement(tag);
    if (text !== undefined) el.textContent = text;
    if (className) el.className = className;
    return el;
  }
  function update() {
    const amount = [...body.querySelectorAll('[data-field="quantity"]')].reduce(
      (sum, input) => sum + (Number(input.value) || 0),
      0
    );
    total.textContent = `${amount} ${amount === 1 ? "número solicitado" : "números solicitados"}`;
    add.disabled = sending || !allowed() || body.rows.length >= 20;
    submit.disabled = sending || !allowed();
    body.querySelectorAll("input,select,button").forEach((el) => {
      el.disabled =
        sending || !allowed() || (el.dataset.remove !== undefined && body.rows.length === 1);
    });
  }
  function addLine() {
    if (body.rows.length >= 20) return;
    const row = node("tr");
    function cell(field, label, el) {
      el.dataset.field = field;
      el.setAttribute("aria-label", label);
      const td = node("td");
      td.append(el);
      row.append(td);
      return el;
    }
    const type = cell("type", "Tipo de número", node("select"));
    type.append(new Option("SMS", "sms"), new Option("SMS + WhatsApp garantido", "whatsapp"));
    const quantity = cell("quantity", "Quantidade", node("input"));
    quantity.type = "number";
    quantity.min = "1";
    quantity.max = "1000";
    quantity.step = "1";
    quantity.required = true;
    quantity.value = "1";
    const ddd = cell("ddd", "DDD preferido (opcional)", node("input"));
    ddd.inputMode = "numeric";
    ddd.pattern = "[1-9][0-9]";
    ddd.maxLength = 2;
    ddd.placeholder = "Qualquer";
    const notes = cell("notes", "Observações (opcional)", node("input"));
    notes.maxLength = 500;
    notes.placeholder = "Opcional";
    const remove = node("button", "×", "number-remove");
    remove.type = "button";
    remove.dataset.remove = "";
    remove.setAttribute("aria-label", "Remover linha");
    remove.addEventListener("click", () => {
      row.remove();
      requestId = null;
      update();
    });
    const td = node("td");
    td.append(remove);
    row.append(td);
    body.append(row);
    update();
  }
  async function load() {
    if (loading || api()?.mode !== "server") return;
    const current = epoch;
    loading = true;
    refresh.disabled = true;
    try {
      const [result, owned] = await Promise.all([
        api().request("number_orders"),
        api().request("purchased_numbers")
      ]);
      if (current !== epoch) return;
      purchased.replaceChildren();
      if (!owned.items.length)
        purchased.append(
          node(
            "p",
            "Nenhum número entregue ainda. Acompanhe o orçamento e a entrega nos pedidos abaixo.",
            "import-hint"
          )
        );
      owned.items.forEach((item) => {
        const row = node("article", undefined, "chip-number-row"),
          info = node("div");
        info.append(
          node("strong", item.phone),
          node(
            "small",
            `${item.type === "whatsapp" ? "SMS + WhatsApp garantido" : "SMS · WhatsApp sem garantia"} · pedido #${item.order_id}`
          )
        );
        const button = node("button", "Consultar SMS", "button secondary");
        button.type = "button";
        button.addEventListener("click", () => {
          selectedInbox = item;
          document.getElementById("purchased-messages-title").textContent = `SMS · ${item.phone}`;
          document.getElementById("purchased-messages-dialog").showModal();
          loadInbox();
        });
        row.append(info, button);
        purchased.append(row);
      });
      history.replaceChildren();
      if (!result.items.length)
        history.append(node("p", "Nenhum pedido enviado ainda.", "import-hint"));
      const labels = {
        draft: "Rascunho",
        submitted: "Enviado",
        reviewing: "Em análise",
        quoted: "Orçamento disponível",
        accepted: "Aceito",
        cancelled: "Cancelado"
      };
      result.items.forEach((item) => {
        const row = node("article", undefined, "chip-number-row"),
          info = node("div"),
          details = item.details;
        info.append(
          node(
            "strong",
            `Pedido #${item.id} · ${item.service === "whatsapp_number" ? "SMS + WhatsApp garantido" : "SMS"}`
          ),
          node(
            "small",
            `${details.quantity || "—"} número(s) · ${details.ddd ? "DDD " + details.ddd : "Sem preferência de DDD"}`
          )
        );
        if (details.notes) info.append(node("small", details.notes));
        const quote = owned.quotes.find((quote) => String(quote.order_id) === String(item.id));
        if (quote) {
          info.append(
            node("small", `Orçamento: ${money(quote.amount_cents)} · ${quote.quote_note}`)
          );
          if (quote.status === "paid")
            info.append(node("small", "Pago. Aguardando a confirmação da entrega."));
          if (quote.status === "completed")
            info.append(
              node(
                "small",
                `Entrega concluída: ${quote.delivered} número(s) entregue(s), ${quote.failed} com valor devolvido.`
              )
            );
          if (
            quote.status === "quoted" &&
            ["owner", "manager"].includes(api().session.role) &&
            item.status === "quoted"
          ) {
            const buy = node(
              "button",
              `Comprar por ${money(quote.amount_cents)}`,
              "button primary"
            );
            buy.type = "button";
            buy.addEventListener("click", () => {
              selectedQuote = quote;
              document.getElementById("number-purchase-summary").textContent =
                `Pedido #${item.id}: ${details.quantity} número(s), total de ${money(quote.amount_cents)}. ${quote.quote_note}`;
              document.getElementById("number-purchase-feedback").textContent = "";
              purchaseDialog.showModal();
            });
            row.append(buy);
          }
        }
        row.prepend(info);
        row.append(node("span", labels[item.status] || item.status, "finance-status"));
        history.append(row);
      });
    } catch (error) {
      if (current === epoch) history.replaceChildren(node("p", error.message, "service-feedback"));
    } finally {
      if (current === epoch) {
        loading = false;
        refresh.disabled = false;
      }
    }
  }
  form.addEventListener("input", () => {
    requestId = null;
    update();
  });
  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    if (sending || !allowed() || !form.reportValidity()) return;
    const lines = [...body.rows].map((row) =>
      Object.fromEntries(
        [...row.querySelectorAll("[data-field]")].map((input) => [
          input.dataset.field,
          input.dataset.field === "quantity" ? Number(input.value) : input.value.trim()
        ])
      )
    );
    if (lines.reduce((sum, line) => sum + line.quantity, 0) > 1000) {
      feedback.textContent = "O pedido pode conter até 1.000 números.";
      return;
    }
    requestId ||= crypto.randomUUID();
    const current = epoch;
    sending = true;
    update();
    feedback.textContent = "Enviando pedido…";
    try {
      const result = await api().request("number_orders", { request_id: requestId, lines }, "POST");
      if (current !== epoch) return;
      feedback.textContent = `Pedido registrado: ${result.ids.map((id) => "#" + id).join(", ")}. Aguarde a análise e o orçamento. Nenhuma cobrança foi gerada.`;
      requestId = null;
      body.replaceChildren();
      addLine();
      await load();
    } catch (error) {
      if (current === epoch) feedback.textContent = error.message;
    } finally {
      if (current === epoch) {
        sending = false;
        update();
      }
    }
  });
  async function loadInbox() {
    if (!selectedInbox || inboxLoading || api()?.mode !== "server") return;
    const current = epoch,
      item = selectedInbox,
      target = document.getElementById("purchased-messages-list");
    inboxLoading = true;
    document.getElementById("purchased-messages-refresh").disabled = true;
    target.replaceChildren(node("p", "Consultando SMS…"));
    try {
      const result = await api().request("purchased_numbers", { op: "messages", id: item.id });
      if (current !== epoch || selectedInbox !== item) return;
      target.replaceChildren();
      if (!result.messages?.length)
        target.append(node("p", "Nenhum SMS recebido desde a entrega deste número."));
      (result.messages || []).forEach((message) => {
        const row = node("article", undefined, "chip-number-row"),
          content = node("div");
        content.append(
          node("small", `${message.phone_number || "Remetente"} · ${message.created_at} UTC`),
          node("p", message.body, "chip-message")
        );
        row.append(content);
        target.append(row);
      });
    } catch (error) {
      if (current === epoch) target.replaceChildren(node("p", error.message));
    } finally {
      inboxLoading = false;
      document.getElementById("purchased-messages-refresh").disabled = false;
    }
  }
  document.getElementById("number-purchase-cancel").addEventListener("click", () => {
    if (!paying) purchaseDialog.close();
  });
  document.getElementById("number-purchase-confirm").addEventListener("click", async () => {
    if (paying || !selectedQuote) return;
    paying = true;
    const current = epoch,
      button = document.getElementById("number-purchase-confirm");
    button.disabled = true;
    document.getElementById("number-purchase-cancel").disabled = true;
    try {
      await api().request(
        "purchased_numbers",
        {
          op: "pay",
          id: selectedQuote.order_id,
          version: Number(selectedQuote.version),
          amount_cents: Number(selectedQuote.amount_cents),
          confirm: true
        },
        "POST"
      );
      if (current !== epoch) return;
      purchaseDialog.close();
      feedback.textContent =
        "Compra confirmada com saldo. A entrega será conferida automaticamente.";
      await load();
    } catch (error) {
      if (current === epoch)
        document.getElementById("number-purchase-feedback").textContent = error.message;
    } finally {
      paying = false;
      button.disabled = false;
      document.getElementById("number-purchase-cancel").disabled = false;
    }
  });
  purchaseDialog.addEventListener("cancel", (event) => {
    if (paying) event.preventDefault();
  });
  document.getElementById("purchased-refresh").addEventListener("click", load);
  document.getElementById("purchased-messages-refresh").addEventListener("click", loadInbox);
  document
    .getElementById("purchased-messages-close")
    .addEventListener("click", () => document.getElementById("purchased-messages-dialog").close());
  add.addEventListener("click", () => {
    addLine();
    requestId = null;
  });
  refresh.addEventListener("click", load);
  window.addEventListener("pulse-view-change", ({ detail }) => {
    if (detail.view === "whatsapp-numbers") load();
  });
  window.addEventListener("pulse-api-mode", () => {
    epoch++;
    loading = sending = false;
    requestId = null;
    selectedQuote = selectedInbox = null;
    purchased.replaceChildren();
    document.getElementById("purchased-messages-list").replaceChildren();
    body.replaceChildren();
    history.replaceChildren();
    feedback.textContent = "";
    addLine();
    if (!document.getElementById("whatsapp-numbers-view").hidden) load();
  });
  const timer = setInterval(() => {
    if (
      !document.hidden &&
      api()?.mode === "server" &&
      !document.getElementById("whatsapp-numbers-view").hidden
    ) {
      load();
      if (document.getElementById("purchased-messages-dialog").open) loadInbox();
    }
  }, 30000);
  window.addEventListener("pagehide", () => clearInterval(timer), { once: true });
  addLine();
  api()?.ready?.then(() => {
    update();
    if (!document.getElementById("whatsapp-numbers-view").hidden) load();
  });
})();
