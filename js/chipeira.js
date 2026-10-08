(() => {
  "use strict";
  const api = () => window.PulseAPI;
  const server = () => api()?.mode === "server";
  const canSend = () => server() && api().session.role !== "viewer";
  const node = (tag, text, className) => {
    const element = document.createElement(tag);
    if (text !== undefined) element.textContent = text;
    if (className) element.className = className;
    return element;
  };
  const button = (label, action, className = "button secondary") => {
    const item = node("button", label, className);
    item.type = "button";
    item.addEventListener("click", action);
    return item;
  };
  const call = (op, data = {}, method = "GET") =>
    api().request("chipeira", { op, ...data }, method);
  let epoch = 0,
    connected = false,
    phones = [],
    loading = false,
    dispatching = false;
  let requestId = null,
    selectedNumber = null;
  const numbersView = document.getElementById("my-numbers-view");
  const smsView = document.getElementById("sms-marketing-view");
  const campaignsView = document.getElementById("campaigns-view");
  if (!numbersView || !smsView || !campaignsView) return;
  const panel = node("section", undefined, "service-panel chip-panel");
  const top = node("div", undefined, "service-panel-top");
  const status = node(
    "p",
    "Entre na sua conta para consultar os números da chipeira.",
    "service-description",
  );
  status.setAttribute("role", "status");
  const refreshButton = button("Atualizar", refresh);
  top.append(node("h2", "Números da sua empresa"), refreshButton);
  const phoneList = node("div", undefined, "chip-numbers");
  const inbox = node("div", undefined, "chip-inbox");
  panel.append(top, status, phoneList, inbox);
  numbersView.querySelector(".service-inventory")?.replaceWith(panel);
  const campaignPanel = node("section", undefined, "service-panel chip-panel");
  const campaignTop = node("div", undefined, "service-panel-top");
  campaignTop.append(
    node("h2", "Campanhas de SMS"),
    button("Atualizar", refresh),
  );
  const campaignList = node("div");
  campaignPanel.append(campaignTop, campaignList);
  campaignsView.append(campaignPanel);
  const dispatchPanel = node("section", undefined, "service-panel chip-panel sms-composer");
  const dispatchHeader = node("div", undefined, "composer-heading");
  dispatchHeader.append(node("h2", "Nova campanha"), node("span", "Distribuição automática · até 64 slots", "composer-badge"));
  dispatchPanel.append(dispatchHeader);
  const dispatchStatus = node("p", undefined, "service-description");
  const form = node("form", undefined, "service-form composer-form");
  const fields = {};
  function field(key, label, tag = "input") {
    const wrapper = node(tag === "div" ? "div" : "label", undefined, `chip-field composer-${key}`);
    wrapper.append(node("span", label));
    const input = node(tag);
    input.id = `chip-${key}`;
    input.required = true;
    fields[key] = input;
    wrapper.append(input);
    form.append(wrapper);
    return input;
  }
  field("name", "Nome da campanha").maxLength = 120;
  fields.name.placeholder = "Ex.: Aviso aos clientes";
  field("message", "Mensagem", "textarea").maxLength = 1600;
  fields.message.rows = 4;
  fields.message.setAttribute("aria-label", "Mensagem");
  fields.message.placeholder = "Escreva o SMS que seus contatos vão receber…";
  const messageCount = node("small", "0 caracteres", "composer-counter");
  fields.message.parentElement.append(messageCount);
  fields.message.addEventListener("input", () => { messageCount.textContent = `${fields.message.value.length} caracteres`; });
  form.addEventListener("reset", () => { messageCount.textContent = "0 caracteres"; });
  field(
    "recipients",
    "Destinatários · um telefone com DDD por linha",
    "textarea",
  );
  fields.recipients.rows = 5;
  fields.recipients.maxLength = 10000;
  fields.recipients.placeholder = "11999999999\n21988888888";
  const consentLabel = node("label", undefined, "service-consent");
  const consent = node("input");
  consent.type = "checkbox";
  consent.required = true;
  consentLabel.append(
    consent,
    node("span", "Tenho autorização dos destinatários para enviar estes SMS."),
  );
  const submit = node("button", "Revisar envio", "button primary");
  submit.type = "submit";
  const feedback = node("p", undefined, "service-feedback");
  feedback.setAttribute("role", "status");
  form.append(consentLabel, submit);
  dispatchPanel.append(dispatchStatus, form, feedback);
  smsView.insertBefore(dispatchPanel, smsView.querySelector(".pricing-panel, .service-layout"));
  const review = node("dialog", undefined, "service-detail-dialog");
  review.setAttribute("aria-label", "Revisar campanha de SMS");
  const summary = node("div");
  const reviewActions = node("div", undefined, "module-actions");
  const send = button("Confirmar e enviar SMS", sendCampaign, "button primary");
  const back = button("Voltar", () => review.close());
  reviewActions.append(back, send);
  review.append(node("h2", "Revise antes de enviar"), summary, reviewActions);
  document.body.append(review);
  let reviewedPayload = null;
  form.addEventListener("input", () => {
    requestId = null;
  });
  form.addEventListener("submit", (event) => {
    event.preventDefault();
    if (!connected || !canSend() || !form.reportValidity() || dispatching)
      return;
    const raw = fields.recipients.value
      .split(/\r?\n/)
      .map((value) => value.trim())
      .filter(Boolean);
    const numbers = raw.map((value) => value.replace(/[\s().+-]/g, ""));
    const invalid = numbers.findIndex(
      (value) => !/^(?:55)?[1-9]\d{9,10}$/.test(value),
    );
    if (!raw.length || invalid >= 0 || raw.length > 500) {
      feedback.textContent =
        invalid >= 0
          ? `Verifique o telefone na linha ${invalid + 1}.`
          : "Informe entre 1 e 500 contatos.";
      return;
    }
    const recipients = [
      ...new Set(
        numbers.map((value) => `+${value.length <= 11 ? "55" : ""}${value}`),
      ),
    ];
    requestId ||= crypto.randomUUID();
    reviewedPayload = {
      request_id: requestId,
      name: fields.name.value.trim(),
      message: fields.message.value.trim(),
      recipients,
      consent: consent.checked,
    };
    summary.replaceChildren(
      node("p", `${reviewedPayload.name} · ${recipients.length} destinatários`),
      node("p", "Distribuição automática entre os slots online da chipeira (até 64)."),
      node("p", reviewedPayload.message, "chip-message"),
      node(
        "p",
        "Ao confirmar, a chipeira inicia os envios reais de SMS. O saldo do Pulse ainda não é debitado por esta integração.",
      ),
    );
    send.disabled = false;
    review.showModal();
  });
  async function sendCampaign() {
    if (dispatching || !reviewedPayload || !connected || !canSend()) return;
    const current = epoch;
    dispatching = true;
    send.disabled = back.disabled = submit.disabled = true;
    try {
      const result = await call("create", reviewedPayload, "POST");
      if (current !== epoch) return;
      feedback.textContent = `Campanha #${result.campaign.id} registrada na chipeira. Acompanhe os resultados em Campanhas.`;
      form.reset();
      requestId = null;
      reviewedPayload = null;
      review.close();
      await refresh();
    } catch (error) {
      if (current === epoch) {
        review.close();
        feedback.textContent = `${error.message} Repetir a mesma revisão usa o mesmo identificador e evita duplicar a campanha.`;
      }
    } finally {
      dispatching = false;
      send.disabled = back.disabled = false;
      submit.disabled = !connected || !canSend();
    }
  }
  function displayState() {
    refreshButton.disabled = !server() || loading;
    form.hidden = !connected || !canSend();
    dispatchStatus.textContent = connected
      ? canSend()
        ? "Os envios são distribuídos automaticamente entre os slots online da chipeira, até 64."
        : "Seu perfil permite consultar campanhas. O envio exige acesso de operador."
      : "Conectando à chipeira para preparar o envio.";
    const original = smsView.querySelector(".service-layout");
    if (original) original.hidden = connected;
    campaignPanel.hidden = !server();
    submit.disabled = !connected || !canSend() || dispatching;
  }
  async function showMessages(number) {
    const current = epoch;
    selectedNumber = number;
    inbox.replaceChildren(node("p", "Carregando mensagens…"));
    try {
      const result = await call("messages", { number });
      if (current !== epoch || selectedNumber !== number) return;
      inbox.replaceChildren(node("h3", `SMS recebidos · ${number}`));
      if (!result.messages.length)
        inbox.append(
          node("p", "Nenhum SMS recebido desde a atribuição deste número."),
        );
      result.messages.forEach((message) => {
        const item = node("article", undefined, "chip-message-row");
        item.append(
          node("strong", message.phone_number),
          node("p", message.body, "chip-message"),
          node("small", message.created_at),
        );
        inbox.append(item);
      });
    } catch (error) {
      if (current === epoch && selectedNumber === number)
        inbox.replaceChildren(node("p", error.message));
    }
  }
  async function cancelCampaign(id) {
    const current = epoch;
    try {
      await call("cancel", { id }, "POST");
      if (current === epoch) await refresh();
    } catch (error) {
      if (current === epoch) campaignList.prepend(node("p", error.message));
    }
  }
  async function showCampaign(id) {
    const current = epoch;
    try {
      const result = await call("campaign", { id });
      if (current !== epoch) return;
      const detail = node("dialog", undefined, "service-detail-dialog");
      detail.setAttribute("aria-label", `Campanha ${id}`);
      detail.append(
        node("h2", result.campaign.name),
        node("p", result.campaign.message_template, "chip-message"),
      );
      result.recipients.forEach((item) =>
        detail.append(
          node(
            "p",
            `${item.phone_number} · ${{ sent: "Enviado", failed: "Falhou", pending: "Pendente", cancelled: "Cancelado" }[item.status] || item.status}${item.error_message ? ` · ${item.error_message}` : ""}`,
          ),
        ),
      );
      detail.append(button("Fechar", () => detail.close()));
      detail.addEventListener("close", () => detail.remove(), { once: true });
      detail.classList.add("chip-detail");
      document.body.append(detail);
      detail.showModal();
    } catch (error) {
      if (current === epoch) campaignList.prepend(node("p", error.message));
    }
  }
  async function refresh() {
    if (!server() || loading) {
      displayState();
      return;
    }
    loading = true;
    refreshButton.disabled = true;
    const current = epoch;
    try {
      const result = await call("status");
      if (current !== epoch) return;
      connected = result.connected === true;
      if (!connected) {
        status.textContent =
          "Conexão pendente. Configure a chave da chipeira no servidor Pulse.";
        phoneList.replaceChildren();
        campaignList.replaceChildren();
        return;
      }
      const [inventory, history] = await Promise.all([
        call("phones"),
        call("campaigns"),
      ]);
      if (current !== epoch) return;
      phones = inventory.phones;
      status.textContent = phones.length
        ? `${phones.length} número(s) vinculado(s) · integração SMS conectada`
        : "Conectado. Após o pagamento confirmado, escolha um número disponível em Meus números.";
      phoneList.replaceChildren();
      phones.forEach((phone) => {
        const row = node("article", undefined, "chip-number-row");
        const info = node("div");
        info.append(
          node("strong", phone.phoneNumber),
          node(
            "small",
            `${phone.operator || "Operadora não identificada"} · ${phone.online ? "Online" : "Offline"}`,
          ),
        );
        row.append(
          info,
          button("Mensagens recebidas", () => showMessages(phone.phoneNumber)),
        );
        phoneList.append(row);
      });
      if (
        selectedNumber &&
        !phones.some((phone) => phone.phoneNumber === selectedNumber)
      ) {
        selectedNumber = null;
        inbox.replaceChildren();
      }
      campaignList.replaceChildren();
      if (!history.campaigns.length)
        campaignList.append(
          node("p", "Nenhuma campanha enviada pela chipeira."),
        );
      history.campaigns.forEach((campaign) => {
        const row = node("article", undefined, "chip-number-row");
        const info = node("div");
        info.append(
          node("strong", campaign.name),
          node(
            "small",
            `${{ running: "Em andamento", completed: "Finalizada", cancelled: "Cancelada" }[campaign.status]} · ${campaign.sent_count}/${campaign.total} enviados · ${campaign.failed_count} falhas`,
          ),
        );
        row.append(
          info,
          button("Detalhes", () => showCampaign(campaign.id)),
        );
        if (campaign.status === "running" && canSend())
          row.append(
            button("Cancelar pendentes", () => cancelCampaign(campaign.id)),
          );
        campaignList.append(row);
      });
    } catch (error) {
      if (current === epoch) {
        connected = false;
        status.textContent = error.message;
        phoneList.replaceChildren();
        inbox.replaceChildren();
        campaignList.replaceChildren();
      }
    } finally {
      if (current === epoch) {
        loading = false;
        displayState();
      }
    }
  }
  function reset() {
    epoch++;
    connected = false;
    loading = false;
    phones = [];
    requestId = null;
    reviewedPayload = null;
    selectedNumber = null;
    form.reset();
    phoneList.replaceChildren();
    inbox.replaceChildren();
    campaignList.replaceChildren();
    feedback.textContent = "";
    review.close();
    document.querySelectorAll(".chip-detail").forEach((dialog) => {
      dialog.close();
      dialog.remove();
    });
    status.textContent =
      "Entre na sua conta para consultar os números da chipeira.";
    displayState();
  }
  window.addEventListener("pulse-api-mode", () => {
    reset();
    if (server()) refresh();
  });
  window.addEventListener("pulse-server-ready", refresh);
  window.addEventListener("pulse-view-change", refresh);
  const timer = setInterval(() => {
    if (
      server() &&
      !document.hidden &&
      ["#my-numbers", "#sms-marketing", "#campaigns"].includes(location.hash)
    )
      refresh();
  }, 15000);
  window.addEventListener("pagehide", () => clearInterval(timer), {
    once: true,
  });
  api()?.ready?.then(refresh);
  displayState();
})();
