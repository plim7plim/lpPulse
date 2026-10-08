/* Solicitações locais ou autenticadas; provisionamento depende do provedor. */
(() => {
  "use strict";
  const host = document.querySelector(".dashboard-content");
  if (!host) return;
  const store = () => window.PulseStore;
  const read = () => store()?.get("serviceRequests", []) || [];
  const server = () => store()?.mode === "server";
  let modeEpoch = 0;
  const statuses = {
    draft: "Rascunho local",
    submitted: "Enviada para análise",
    reviewing: "Em análise",
    quoted: "Proposta disponível",
    approved: "Aprovada",
    rejected: "Recusada",
    cancelled: "Cancelada",
    pending: "Pendente",
  };
  const statusLabel = (item) => statuses[item.status] || item.status;
  const submitLabel = () =>
    server() ? "Enviar solicitação para análise" : "Salvar solicitação local";
  const wireName = (name) =>
    name.replace(/[A-Z]/g, (char) => `_${char.toLowerCase()}`);
  const el = (tag, text, cls) => {
    const node = document.createElement(tag);
    if (text !== undefined) node.textContent = text;
    if (cls) node.className = cls;
    return node;
  };
  const button = (label, action, cls = "button secondary") => {
    const node = el("button", label, cls);
    node.type = "button";
    node.addEventListener("click", action);
    return node;
  };
  const go = (view) => {
    if (typeof setView === "function") setView(view);
  };
  const date = (value) => new Date(value).toLocaleString("pt-BR");
  const labels = {
    "virtual-number": "Número virtual",
    "my-numbers": "Meus números",
    portability: "Portabilidade",
    "toll-free": "Número 0800",
    termination: "Terminação de chamadas",
    "sip-trunks": "Troncos SIP",
    "call-records": "Histórico de chamadas",
    pabx: "PABX virtual",
    "whatsapp-number": "Número para WhatsApp",
    "sms-number": "Número com SMS",
    "sms-marketing": "SMS Marketing",
    "whatsapp-attendance": "Atendimento WhatsApp",
    streaming: "Streaming",
    "service-orders": "Solicitações de serviços",
  };
  window.pulseViewNames = Object.assign(window.pulseViewNames || {}, labels);
  const views = {};
  Object.entries(labels).forEach(([id, title]) => {
    if (document.getElementById(`${id}-view`)) return;
    const section = el("section", undefined, "service-view");
    section.id = `${id}-view`;
    section.hidden = true;
    const heading = el("div", undefined, "service-heading");
    heading.append(
      el("h1", title),
      el(
        "p",
        {
          "virtual-number":
            "Escolha a cidade e o DDD para consultar um número.",
          "my-numbers": "Consulte os números vinculados à sua empresa.",
          portability: "Solicite a transferência do seu número para a Pulse.",
          "toll-free": "Informe como sua empresa pretende receber chamadas.",
          termination: "Descreva o volume e o destino das chamadas.",
          "sip-trunks": "Informe a capacidade necessária para sua central.",
          "call-records":
            "Consulte o histórico de chamadas das linhas conectadas.",
          pabx: "Configure sua central e revise a proposta por etapas.",
          "whatsapp-number":
            "Consulte um número dedicado ao WhatsApp da sua empresa.",
          "sms-number": "Solicite um número para receber SMS de aplicativos.",
          "sms-marketing": "Escreva a mensagem e revise sua lista de contatos.",
          "whatsapp-attendance":
            "Planeje o atendimento da sua equipe pelo WhatsApp.",
          streaming: "Consulte os serviços disponíveis para sua empresa.",
          "service-orders":
            "Acompanhe os pedidos enviados e consulte os detalhes.",
        }[id],
      ),
    );
    const note = el(
      "p",
      "Demonstração local · Os pedidos ficam nesta sessão do navegador. Nenhum serviço é ativado ou cobrado.",
      "service-demo-note service-mode-notice",
    );
    section.append(heading, note);
    host.append(section);
    views[id] = section;
  });
  function field(spec, prefix) {
    const [name, title, kind = "text", options = null, required = true] = spec;
    const wrapper = el("div", undefined, "service-field");
    const id = `${prefix}-${name}`;
    const label = el("label", `${title}${required ? " *" : ""}`);
    label.htmlFor = id;
    let input;
    if (Array.isArray(options)) {
      input = el("select");
      input.append(new Option("Selecione", ""));
      options.forEach((option) => input.append(new Option(option, option)));
    } else if (kind === "textarea") input = el("textarea");
    else {
      input = el("input");
      input.type = kind;
    }
    input.id = id;
    input.name = name;
    input.required = required;
    if (kind === "number") {
      input.min = "1";
      input.max = "1000";
      input.step = "1";
    }
    if (name === "ddd") {
      input.pattern = "[1-9][0-9]";
      input.maxLength = 2;
      input.inputMode = "numeric";
    }
    if (kind === "textarea") {
      input.rows = 3;
      input.maxLength = 5000;
      wrapper.classList.add("service-field-wide");
    }
    if (name === "contacts")
      input.placeholder = "Um telefone por linha, com DDD";
    wrapper.append(label, input);
    return { wrapper, input };
  }
  async function saveRequest(type, details) {
    if (!store())
      throw new Error("Armazenamento local indisponível. Recarregue a página.");
    if (server()) {
      const epoch = modeEpoch;
      if (!window.PulseAPI)
        throw new Error("API indisponível. Recarregue a página.");
      const wireDetails = Object.fromEntries(
        Object.entries(details).map(([key, value]) => [wireName(key), value]),
      );
      const result = await window.PulseAPI.request(
        "requests",
        {
          kind: "service",
          op: "create",
          type: type === "sip-trunks" ? "sip_trunk" : type.replace(/-/g, "_"),
          details: wireDetails,
        },
        "POST",
      );
      if (epoch !== modeEpoch || !server())
        throw new Error(
          "A sessão foi alterada. Consulte suas solicitações ao acessar novamente.",
        );
      const confirmed = result.item || result;
      if (!confirmed.id) throw new Error("Resposta da solicitação inválida.");
      const request = {
        ...confirmed,
        type,
        details,
        status: confirmed.status || "submitted",
        created_at: confirmed.created_at || new Date().toISOString(),
      };
      store().hydrate("serviceRequests", [...read(), request]);
      return request;
    }
    const request = {
      id:
        globalThis.crypto?.randomUUID?.() ||
        `pedido-${Date.now()}-${Math.random().toString(36).slice(2)}`,
      type,
      details,
      status: "draft",
      created_at: new Date().toISOString(),
    };
    const saved = store().set("serviceRequests", [...read(), request]);
    if (saved === false)
      throw new Error("Não foi possível salvar neste navegador.");
    return request;
  }
  const serviceFields = {
    "virtual-number": [
      ["city", "Cidade"],
      ["ddd", "DDD", "tel"],
      ["tariff", "Modalidade", "select", ["Tarifado", "Ilimitado"]],
      ["cycle", "Ciclo desejado", "select", ["Mensal", "Trimestral", "Anual"]],
      ["quantity", "Quantidade", "number"],
      ["notes", "Observações", "textarea", null, false],
    ],
    portability: [
      ["number", "Número a portar", "tel"],
      ["operator", "Operadora atual"],
      ["holder", "Titular da linha"],
      ["document", "CPF / CNPJ do titular"],
      ["contact", "E-mail de contato", "email"],
      ["notes", "Observações", "textarea", null, false],
    ],
    "toll-free": [
      ["company", "Empresa"],
      ["destination", "Telefone de destino", "tel"],
      ["coverage", "Abrangência desejada", "select", ["Nacional", "Regional"]],
      ["volume", "Chamadas mensais estimadas", "number"],
      ["contact", "E-mail de contato", "email"],
    ],
    termination: [
      [
        "destination",
        "Destino das chamadas",
        "select",
        ["Brasil fixo", "Brasil móvel", "Internacional", "Misto"],
      ],
      ["simultaneous", "Chamadas simultâneas", "number"],
      ["volume", "Minutos mensais estimados", "number"],
      ["contact", "E-mail de contato", "email"],
      ["notes", "Necessidades técnicas", "textarea", null, false],
    ],
    "sip-trunks": [
      ["pbx", "Sistema / PABX utilizado"],
      ["simultaneous", "Canais simultâneos", "number"],
      ["ip", "IP público do PABX", "text", null, false],
      ["contact", "E-mail técnico", "email"],
    ],
    "whatsapp-number": [
      ["ddd", "DDD desejado", "tel"],
      ["city", "Cidade"],
      ["company", "Empresa"],
      ["contact", "E-mail de contato", "email"],
    ],
    "sms-number": [
      ["ddd", "DDD desejado", "tel"],
      ["purpose", "Uso do número"],
      ["quantity", "Quantidade", "number"],
      ["contact", "E-mail de contato", "email"],
    ],
    "whatsapp-attendance": [
      ["company", "Empresa"],
      ["agents", "Quantidade de atendentes", "number"],
      ["departments", "Equipes / departamentos", "textarea"],
      ["existingNumber", "WhatsApp atual (opcional)", "tel", null, false],
      ["contact", "E-mail de contato", "email"],
    ],
    streaming: [
      [
        "plan",
        "Interesse",
        "select",
        ["Consultar planos disponíveis", "Uso residencial", "Uso empresarial"],
      ],
      ["city", "Cidade"],
      ["contact", "E-mail de contato", "email"],
      ["notes", "O que você procura?", "textarea", null, false],
    ],
    "sms-marketing": [
      ["name", "Nome da campanha"],
      ["message", "Mensagem", "textarea"],
      ["contacts", "Telefones dos contatos autorizados", "textarea"],
      ["contact", "E-mail de contato", "email"],
    ],
  };
  const intro = {
    "virtual-number":
      "Consulte a possibilidade de contratação. A disponibilidade e os preços dependem de confirmação da equipe.",
    portability:
      "Prepare os dados do titular e da linha. A análise e a autorização de portabilidade dependem da operadora.",
    "toll-free":
      "Solicite uma proposta de 0800 de acordo com o destino e o volume de chamadas.",
    termination:
      "Informe sua demanda para preparar uma proposta de roteamento de chamadas.",
    "sip-trunks":
      "Nenhum tronco SIP contratado. Prepare uma consulta técnica sem inserir senhas ou credenciais.",
    "whatsapp-number":
      "Nenhum número contratado. Solicite uma consulta de DDD; não há número ou QR de ativação disponível.",
    "sms-number":
      "Consulte números com recebimento de SMS. Serviços compatíveis e disponibilidade precisam ser confirmados.",
    "whatsapp-attendance":
      "Prepare uma proposta de atendimento com equipes e agentes. A conexão de WhatsApp depende do provedor.",
    streaming:
      "Envie os dados para uma proposta. Catálogo, licenciamento e planos ainda não foram configurados.",
    "sms-marketing":
      "Prepare um rascunho. Cadastro e provedor precisam estar configurados para liberar o envio.",
  };
  const lists = {};
  function requestsPanel(type, section) {
    const panel = el(
      "section",
      undefined,
      "service-panel service-request-history",
    );
    const header = el("div", undefined, "service-panel-top");
    header.append(
      el("h2", "Minhas solicitações"),
      button("Ver todas →", () => go("service-orders"), "service-link"),
    );
    const content = el("div");
    panel.append(header, content);
    section.append(panel);
    lists[type] = () => {
      content.replaceChildren();
      const items = read()
        .filter((item) => item.type === type)
        .reverse();
      if (!items.length) {
        content.append(
          el("p", "Nenhuma solicitação preparada.", "service-empty-small"),
        );
        return;
      }
      items.forEach((item) => {
        const row = el("div", undefined, "service-history-row");
        const text = el("div");
        text.append(
          el("strong", `Solicitação ${String(item.id).slice(0, 8)}`),
          el("small", date(item.created_at)),
        );
        row.append(
          text,
          el(
            "span",
            statusLabel(item),
            `service-state ${item.status === "cancelled" ? "is-cancelled" : ""}`,
          ),
          button("Detalhes", () => showDetails(item), "service-link"),
        );
        content.append(row);
      });
    };
    lists[type]();
  }
  function createForm(type, section, specs) {
    const grid = el("div", undefined, "service-layout");
    const panel = el("section", undefined, "service-panel");
    panel.append(
      el(
        "h2",
        type === "virtual-number" ? "Consultar número" : "Preparar solicitação",
      ),
      el("p", intro[type], "service-description"),
    );
    const form = el("form", undefined, "service-form");
    const fields = el("div", undefined, "service-fields");
    const inputs = {};
    specs.forEach((spec) => {
      const entry = field(spec, type);
      fields.append(entry.wrapper);
      inputs[spec[0]] = entry.input;
    });
    const feedback = el("p", "", "service-feedback");
    feedback.setAttribute("role", "status");
    const submit = el("button", submitLabel(), "button primary service-submit");
    submit.type = "submit";
    form.append(fields);
    if (type === "sms-marketing") {
      const counter = el("p", undefined, "service-message-counter");
      const update = () => {
        const sms = smsCount(inputs.message.value);
        counter.textContent = `${sms.length} caracteres · ${sms.encoding} · ${sms.parts} SMS por contato`;
      };
      inputs.message.addEventListener("input", update);
      update();
      form.append(counter);
      const consent = el("label", undefined, "service-consent");
      const checkbox = el("input");
      checkbox.type = "checkbox";
      checkbox.required = true;
      consent.append(
        checkbox,
        el("span", "Tenho autorização dos contatos para esta comunicação."),
      );
      form.append(consent);
    }
    form.append(submit, feedback);
    form.addEventListener("submit", async (event) => {
      event.preventDefault();
      if (!form.reportValidity()) return;
      const details = Object.fromEntries(
        Object.entries(inputs).map(([key, input]) => [key, input.value.trim()]),
      );
      if (type === "sms-marketing") {
        const raw = details.contacts
          .split(/\r?\n/)
          .map((value) => value.trim())
          .filter(Boolean);
        const normalized = raw.map((value) => value.replace(/\D/g, ""));
        const invalid = normalized.findIndex(
          (value) => !/^(?:55)?[1-9][0-9][0-9]{8,9}$/.test(value),
        );
        if (!raw.length || invalid >= 0) {
          feedback.textContent =
            invalid >= 0
              ? `Telefone inválido na linha ${invalid + 1}. Use DDD e telefone.`
              : "Adicione ao menos um contato.";
          return;
        }
        details.contacts = [...new Set(normalized)].join("\n");
        details.contactsCount = new Set(normalized).size;
        details.smsEstimate =
          smsCount(details.message).parts * details.contactsCount;
        details.permissionConfirmed = true;
      }
      try {
        submit.disabled = true;
        await saveRequest(type, details);
        feedback.textContent = server()
          ? "Solicitação registrada no servidor para análise. Nenhum serviço foi ativado ou cobrado."
          : "Solicitação salva nesta sessão do navegador. Ela ainda não foi enviada à equipe.";
        refresh();
      } catch (error) {
        feedback.textContent = error.message;
      } finally {
        submit.disabled = false;
      }
    });
    panel.append(form);
    grid.append(panel);
    section.append(grid);
    requestsPanel(type, section);
  }
  function smsCount(text) {
    const basic =
      "@£$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";
    const extension = "^{}\\[~]|€\f";
    let gsmLength = 0;
    for (const char of text) {
      if (basic.includes(char)) gsmLength++;
      else if (extension.includes(char)) gsmLength += 2;
      else
        return {
          length: text.length,
          encoding: "Unicode",
          parts:
            text.length === 0
              ? 0
              : text.length <= 70
                ? 1
                : Math.ceil(text.length / 67),
        };
    }
    return {
      length: text.length,
      encoding: "GSM-7",
      parts:
        gsmLength === 0 ? 0 : gsmLength <= 160 ? 1 : Math.ceil(gsmLength / 153),
    };
  }
  Object.entries(serviceFields).forEach(([type, specs]) => {
    if (views[type]) createForm(type, views[type], specs);
  });
  function emptyInventory(id, title, text, actionLabel, target) {
    const section = views[id];
    if (!section) return;
    const panel = el("section", undefined, "service-panel service-inventory");
    const mark = el("div", "◌", "service-empty-icon");
    mark.setAttribute("aria-hidden", "true");
    panel.append(mark, el("h2", title), el("p", text));
    if (target)
      panel.append(button(actionLabel, () => go(target), "button primary"));
    section.append(panel);
  }
  emptyInventory(
    "my-numbers",
    "Você ainda não possui números",
    "Os números contratados aparecerão aqui quando houver integração com o provedor.",
    "Consultar novo número",
    "virtual-number",
  );
  emptyInventory(
    "call-records",
    "Nenhuma chamada registrada",
    "Após a conexão do provedor, esta tela poderá apresentar origem, destino, duração e consumo das chamadas.",
    "Consultar telefonia",
    "termination",
  );
  if (views["whatsapp-number"]) {
    const controls = el("div", undefined, "service-inventory-controls");
    const search = el("input");
    search.type = "search";
    search.placeholder = "Buscar número";
    search.setAttribute("aria-label", "Buscar número WhatsApp");
    const status = el("select");
    status.setAttribute("aria-label", "Status dos números");
    [
      "Todos",
      "Ativo",
      "Aguardando ativação",
      "Provisionando",
      "Falha",
      "Pagamento pendente",
      "Aguardando reativação",
      "Bloqueado",
      "Cancelado",
    ].forEach((value) => status.append(new Option(value, value)));
    const note = el("p", "Nenhum número contratado.", "service-empty-small");
    const update = () => {
      note.textContent =
        search.value.trim() || status.value !== "Todos"
          ? "Nenhum número encontrado com estes filtros."
          : "Nenhum número contratado.";
    };
    search.addEventListener("input", update);
    status.addEventListener("change", update);
    controls.append(search, status);
    const panel = el("section", undefined, "service-panel");
    panel.append(el("h2", "Meus números WhatsApp"), controls, note);
    views["whatsapp-number"].insertBefore(
      panel,
      views["whatsapp-number"].children[2],
    );
  }
  const dialog = el("dialog", undefined, "service-detail-dialog");
  const detailBody = el("div", undefined, "service-detail-body");
  const close = button("Fechar", () => dialog.close());
  dialog.append(detailBody, close);
  document.body.append(dialog);
  function showDetails(item) {
    detailBody.replaceChildren(
      el(
        "span",
        `Solicitação ${String(item.id).slice(0, 8)}`,
        "service-eyebrow",
      ),
      el("h2", labels[item.type] || item.type),
      el(
        "p",
        `${date(item.created_at)} · ${statusLabel(item)}`,
        "service-description",
      ),
    );
    const dl = el("dl", undefined, "service-detail-list");
    const specs =
      serviceFields[item.type] ||
      wizardSteps.flatMap((step) => step.fields || []);
    Object.entries(item.details || {}).forEach(([key, value]) => {
      const label =
        specs.find(
          (spec) => spec[0] === key || wireName(spec[0]) === key,
        )?.[1] ||
        {
          contactsCount: "Contatos válidos",
          contacts_count: "Contatos válidos",
          smsEstimate: "SMS estimados",
          sms_estimate: "SMS estimados",
          permissionConfirmed: "Autorização confirmada",
          permission_confirmed: "Autorização confirmada",
        }[key] ||
        key;
      dl.append(
        el("dt", label),
        el(
          "dd",
          typeof value === "boolean" ? (value ? "Sim" : "Não") : String(value),
        ),
      );
    });
    detailBody.append(
      dl,
      el(
        "p",
        server()
          ? "Solicitação registrada para análise. Provisionamento e cobrança dependem de confirmação."
          : "Este registro local não representa contratação ou comunicação enviada.",
        "service-demo-note",
      ),
    );
    if (!dialog.open) dialog.showModal();
  }
  const wizardSteps = [
    {
      title: "Operação",
      fields: [
        ["business", "Nome da empresa"],
        [
          "operation",
          "Tipo de operação",
          "select",
          ["Atendimento", "Vendas", "Atendimento e vendas"],
        ],
      ],
    },
    {
      title: "Plano",
      fields: [
        ["extensions", "Quantidade de ramais", "number"],
        ["simultaneous", "Chamadas simultâneas", "number"],
        [
          "cycle",
          "Ciclo desejado",
          "select",
          ["Mensal", "Trimestral", "Anual"],
        ],
      ],
    },
    {
      title: "Número",
      fields: [
        [
          "numberMode",
          "Número principal",
          "select",
          [
            "Consultar novo número",
            "Portar número existente",
            "Já possuo número",
          ],
        ],
        ["ddd", "DDD", "tel"],
        [
          "existingNumber",
          "Número existente (se aplicável)",
          "tel",
          null,
          false,
        ],
      ],
    },
    {
      title: "Dados",
      fields: [
        ["holder", "Responsável"],
        ["contact", "E-mail", "email"],
        ["phone", "Telefone", "tel"],
      ],
    },
    {
      title: "Departamentos",
      fields: [["departments", "Departamentos e ramais", "textarea"]],
    },
    {
      title: "Menu",
      fields: [
        ["greeting", "Mensagem de saudação / URA", "textarea"],
        ["routing", "Opções de atendimento", "textarea"],
      ],
    },
    {
      title: "Horários",
      fields: [
        ["hours", "Dias e horários de atendimento", "textarea"],
        ["afterHours", "Mensagem fora do horário", "textarea", null, false],
      ],
    },
    { title: "Resumo" },
    { title: "Pagamento" },
  ];
  if (views.pabx) {
    const panel = el("section", undefined, "service-panel service-wizard");
    panel.append(el("h2", "Monte sua proposta de PABX"));
    const progress = el("ol", undefined, "service-wizard-progress");
    wizardSteps.forEach((step, index) =>
      progress.append(el("li", `${index + 1}. ${step.title}`)),
    );
    const form = el("form");
    const body = el("div", undefined, "service-fields");
    const actions = el("div", undefined, "service-wizard-actions");
    const feedback = el("p", "", "service-feedback");
    feedback.setAttribute("role", "status");
    let index = 0;
    const values = {};
    let currentInputs = {};
    const back = button("← Voltar", () => {
      capture();
      index--;
      renderStep();
    });
    const next = el("button", "Continuar →", "button primary");
    next.type = "submit";
    actions.append(back, next);
    form.append(body, actions, feedback);
    panel.append(progress, form);
    views.pabx.append(panel);
    function capture() {
      Object.entries(currentInputs).forEach(([key, input]) => {
        values[key] = input.value.trim();
      });
    }
    function renderStep() {
      body.replaceChildren();
      currentInputs = {};
      feedback.textContent = "";
      Array.from(progress.children).forEach((node, i) => {
        node.classList.toggle("is-current", i === index);
        node.classList.toggle("is-complete", i < index);
        node.setAttribute("aria-current", i === index ? "step" : "false");
      });
      back.hidden = index === 0;
      next.textContent =
        index === 8
          ? server()
            ? "Enviar proposta para análise"
            : "Salvar proposta local"
          : "Continuar →";
      if (wizardSteps[index].fields)
        wizardSteps[index].fields.forEach((spec) => {
          const entry = field(spec, "pabx");
          entry.input.value = values[spec[0]] || "";
          currentInputs[spec[0]] = entry.input;
          body.append(entry.wrapper);
        });
      else if (index === 7) {
        const summary = el(
          "dl",
          undefined,
          "service-detail-list service-field-wide",
        );
        wizardSteps
          .flatMap((step) => step.fields || [])
          .forEach(([key, title]) => {
            summary.append(
              el("dt", title),
              el("dd", values[key] || "Não informado"),
            );
          });
        body.append(summary);
      } else {
        const block = el("div", undefined, "service-field-wide");
        block.append(
          el("h3", "Condições a confirmar"),
          el(
            "p",
            "O preço e a forma de pagamento serão definidos após análise da equipe. Salvar esta proposta não cria assinatura, fatura ou cobrança.",
            "service-description",
          ),
        );
        const check = el("label", undefined, "service-consent");
        const input = el("input");
        input.type = "checkbox";
        input.required = true;
        check.append(
          input,
          el(
            "span",
            server()
              ? "Revisei os dados e quero enviar esta proposta para análise."
              : "Revisei os dados e quero salvar uma proposta local.",
          ),
        );
        block.append(check);
        body.append(block);
      }
    }
    form.addEventListener("submit", async (event) => {
      event.preventDefault();
      if (!form.reportValidity()) return;
      capture();
      if (index < 8) {
        index++;
        renderStep();
      } else {
        try {
          next.disabled = true;
          await saveRequest("pabx", { ...values });
          refresh();
          Object.keys(values).forEach((key) => delete values[key]);
          index = 0;
          renderStep();
          feedback.textContent = server()
            ? "Proposta enviada para análise. Acompanhe em Minhas solicitações."
            : "Proposta salva nesta sessão. Acompanhe em Minhas solicitações.";
        } catch (error) {
          feedback.textContent = error.message;
        } finally {
          next.disabled = false;
        }
      }
    });
    renderStep();
    requestsPanel("pabx", views.pabx);
  }
  let renderOrders = () => {};
  if (views["service-orders"]) {
    const panel = el("section", undefined, "service-panel");
    const controls = el("div", undefined, "service-orders-controls");
    const search = el("input");
    search.type = "search";
    search.placeholder = "Buscar serviço, protocolo ou dados";
    search.setAttribute("aria-label", "Buscar solicitações");
    const status = el("select");
    status.setAttribute("aria-label", "Filtrar situação");
    status.append(new Option("Todas as situações", ""));
    Object.entries(statuses).forEach(([value, label]) =>
      status.append(new Option(label, value)),
    );
    const type = el("select");
    type.setAttribute("aria-label", "Filtrar serviço");
    type.append(new Option("Todos os serviços", ""));
    Object.keys(serviceFields)
      .concat("pabx")
      .forEach((key) => type.append(new Option(labels[key], key)));
    const output = el("div", undefined, "service-orders-output");
    const filtered = () =>
      read()
        .filter(
          (item) =>
            (!status.value || item.status === status.value) &&
            (!type.value || item.type === type.value) &&
            `${item.id} ${labels[item.type]} ${Object.values(item.details || {}).join(" ")}`
              .toLocaleLowerCase("pt-BR")
              .includes(search.value.trim().toLocaleLowerCase("pt-BR")),
        )
        .reverse();
    controls.append(
      search,
      type,
      status,
      button("Exportar CSV", () => {
        const safe = (value) => {
          let text = String(value ?? "");
          if (/^[=+\-@\t\r]/.test(text)) text = `'${text}`;
          return `"${text.replace(/"/g, '""')}"`;
        };
        const rows = [
          ["Protocolo", "Serviço", "Situação", "Criado em", "Dados"],
          ...filtered().map((item) => [
            item.id,
            labels[item.type],
            statusLabel(item),
            date(item.created_at),
            JSON.stringify(item.details),
          ]),
        ];
        const url = URL.createObjectURL(
          new Blob(
            [
              "\uFEFF" +
                rows.map((row) => row.map(safe).join(";")).join("\r\n"),
            ],
            { type: "text/csv;charset=utf-8" },
          ),
        );
        const anchor = el("a");
        anchor.href = url;
        anchor.download = "pulse-solicitacoes-locais.csv";
        document.body.append(anchor);
        anchor.click();
        anchor.remove();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
      }),
    );
    renderOrders = () => {
      output.replaceChildren();
      const items = filtered();
      if (!items.length) {
        output.append(
          el("div", "Nenhuma solicitação encontrada.", "service-inventory"),
        );
        return;
      }
      const table = el("table");
      const head = el("thead");
      const heading = el("tr");
      ["Serviço", "Protocolo", "Situação", "Criado em", "Ações"].forEach(
        (text) => heading.append(el("th", text)),
      );
      head.append(heading);
      const body = el("tbody");
      items.forEach((item) => {
        const row = el("tr");
        [
          labels[item.type] || item.type,
          String(item.id).slice(0, 8),
          statusLabel(item),
          date(item.created_at),
        ].forEach((value) => row.append(el("td", value)));
        const actions = el("td", undefined, "service-row-actions");
        actions.append(
          button("Detalhes", () => showDetails(item), "service-link"),
        );
        if (["draft", "submitted", "reviewing", "quoted"].includes(item.status))
          actions.append(
            button(
              "Cancelar",
              () => {
                const warning = el("div", undefined, "service-cancel-confirm");
                warning.append(
                  el("span", "Cancelar esta solicitação?"),
                  button(
                    "Confirmar",
                    async (event) => {
                      try {
                        event.currentTarget.disabled = true;
                        if (server()) {
                          const epoch = modeEpoch;
                          await window.PulseAPI.request(
                            "requests",
                            { kind: "service", op: "cancel", id: item.id },
                            "POST",
                          );
                          if (epoch !== modeEpoch || !server())
                            throw new Error(
                              "A sessão foi alterada. Consulte suas solicitações ao acessar novamente.",
                            );
                        }
                        const updated = read().map((entry) =>
                          entry.id === item.id
                            ? {
                                ...entry,
                                status: "cancelled",
                                updated_at: new Date().toISOString(),
                              }
                            : entry,
                        );
                        if (server())
                          store().hydrate("serviceRequests", updated);
                        else store().set("serviceRequests", updated);
                        refresh();
                      } catch (error) {
                        warning.replaceChildren(el("span", error.message));
                      }
                    },
                    "service-link",
                  ),
                  button("Voltar", () => warning.remove(), "service-link"),
                );
                actions.append(warning);
              },
              "service-link",
            ),
          );
        row.append(actions);
        body.append(row);
      });
      table.append(head, body);
      output.append(table);
    };
    search.addEventListener("input", renderOrders);
    status.addEventListener("change", renderOrders);
    type.addEventListener("change", renderOrders);
    panel.append(controls, output);
    views["service-orders"].append(panel);
    renderOrders();
  }
  function refresh() {
    Object.values(lists).forEach((render) => render());
    renderOrders();
    host.querySelectorAll(".service-mode-notice").forEach((note) => {
      note.textContent = server()
        ? "Solicitações enviadas ao servidor para análise. Ativação e cobrança dependem de confirmação e do provedor."
        : "Demonstração local · Os pedidos ficam nesta sessão do navegador. Nenhum serviço é ativado ou cobrado.";
    });
    host.querySelectorAll(".service-submit").forEach((node) => {
      node.textContent = submitLabel();
    });
  }
  window.addEventListener("pulse-store-change", refresh);
  window.addEventListener("pulse-mode-change", () => {
    modeEpoch++;
    refresh();
  });
  window.addEventListener("pulse-api-mode", () => {
    modeEpoch++;
    refresh();
  });
  window.addEventListener("pulse-server-ready", refresh);
})();
