// Tickets autenticados via API e demonstração isolada em memória.
(() => {
  const demoTickets = [];
  const demoNotifications = [];
  let tickets = demoTickets;
  let notifications = demoNotifications;
  const server = () => window.PulseStore?.mode === "server";
  const editable = () =>
    !server() || window.PulseAPI?.session?.role !== "viewer";
  let epoch = 0;
  let refreshTask = null;
  const apiFeedback = document.createElement("p");
  apiFeedback.id = "ticket-api-feedback";
  apiFeedback.className = "notice";
  apiFeedback.hidden = true;
  apiFeedback.setAttribute("role", "status");
  $(".ticket-panel").append(apiFeedback);
  const detailError = document.createElement("p");
  detailError.className = "notice";
  detailError.setAttribute("role", "alert");
  detailError.hidden = true;
  $("#ticket-detail-dialog").append(detailError);
  function apiError(error) {
    const target = $("#ticket-detail-dialog").open ? detailError : apiFeedback;
    target.textContent =
      error.message || "Não foi possível consultar o atendimento.";
    target.hidden = false;
  }
  function parseDate(value) {
    return value && typeof value === "string" && !value.includes("T")
      ? value.replace(" ", "T") + "Z"
      : value;
  }
  function normalizeTicket(item, messages = []) {
    return {
      ...item,
      id: String(item.id),
      code: `#${item.id}`,
      description: item.subject || "Atendimento",
      email: item.contact_email || "",
      phone: item.contact_phone || "",
      updated: parseDate(item.updated_at || item.created_at),
      files: [],
      messages: messages.map((message) => ({
        text: message.body,
        date: parseDate(message.created_at),
        author: message.author_name || "Usuário",
      })),
    };
  }
  async function loadServer() {
    if (!server() || !window.PulseAPI?.session?.authenticated) return;
    if (refreshTask) return refreshTask;
    const current = epoch;
    refreshTask = (async () => {
      try {
        const results = await Promise.allSettled([
          window.PulseAPI.request("tickets"),
          window.PulseAPI.request("notifications"),
        ]);
        if (current !== epoch || !server()) return;
        results.forEach((result, index) => {
          if (result.status === "rejected") {
            apiError(result.reason);
            return;
          }
          if (index === 0)
            tickets = (result.value.items || []).map((item) =>
              normalizeTicket(item),
            );
          else
            notifications = (result.value.items || []).map((item) => ({
              id: String(item.id),
              text: item.title,
              ticketId: item.ticket_id ? String(item.ticket_id) : null,
              date: parseDate(item.created_at),
              read: !!item.read_at,
            }));
        });
        renderTickets();
        renderNotifications();
      } finally {
        if (current === epoch) refreshTask = null;
      }
    })();
    return refreshTask;
  }
  function syncMode() {
    $("#ticket-files").disabled = server();
    $("#ticket-dialog .file-hint").textContent = server()
      ? "Uploads ainda não foram configurados no servidor. Abra o ticket sem anexos."
      : "PDF, JPG, PNG, WebP ou TXT. Anexos ficam somente nesta sessão.";
    $(".ticket-demo-note").textContent = server()
      ? "Tickets e mensagens são registrados no servidor. Anexos estão desativados."
      : "Demonstração local · Tickets e anexos ficam nesta sessão e não são enviados à equipe.";
    $("#ticket-form .primary").textContent = server()
      ? "Abrir ticket"
      : "Criar ticket demonstrativo";
    $("#ticket-reply-form .primary").textContent = server()
      ? "Enviar mensagem"
      : "Adicionar à demonstração";
    $("#ticket-reply-form .module-note").textContent = server()
      ? "Mensagens são registradas no histórico do chamado."
      : "Nenhuma resposta é enviada à equipe.";
    $("#open-ticket").disabled = $("#first-ticket").disabled = !editable();
  }
  const categories = {
    technical: "Suporte Técnico",
    commercial: "Comercial",
    financial: "Financeiro",
    other: "Outros",
  };
  const states = {
    open: "Aberto",
    in_progress: "Em atendimento",
    waiting_customer: "Aguardando cliente",
    closed: "Encerrado",
  };
  let selectedTicket = null;
  let files = [];
  const urls = new Set();
  const formatDate = (date) =>
    new Date(date).toLocaleString("pt-BR", {
      day: "2-digit",
      month: "2-digit",
      hour: "2-digit",
      minute: "2-digit",
    });
  function element(tag, text, className = "") {
    const node = document.createElement(tag);
    node.textContent = text;
    node.className = className;
    return node;
  }
  function notify(text, ticketId) {
    notifications.unshift({ text, ticketId, date: Date.now(), read: false });
    renderNotifications();
  }
  function renderNotifications() {
    const list = $("#notification-list");
    list.replaceChildren();
    const count = notifications.filter((item) => !item.read).length;
    $("#notification-count").hidden = count === 0;
    $("#notification-count").textContent = count;
    $("#notifications-toggle").setAttribute(
      "aria-label",
      `Notificações (${count} não lidas)`,
    );
    if (!notifications.length)
      list.append(element("p", "Nenhuma notificação nesta sessão.", "empty"));
    notifications.forEach((item) => {
      const button = element(
        "button",
        item.text,
        "notification-item" + (item.read ? "" : " unread"),
      );
      button.append(element("small", formatDate(item.date)));
      button.onclick = async () => {
        if (server()) {
          const current = epoch;
          try {
            await window.PulseAPI.request(
              "notifications",
              { op: "read", id: item.id },
              "POST",
            );
            if (current !== epoch || !server()) return;
          } catch (error) {
            apiError(error);
            return;
          }
        }
        item.read = true;
        renderNotifications();
        closePopovers();
        setView("support");
        if (item.ticketId) openDetail(item.ticketId);
      };
      list.append(button);
    });
  }
  function renderTickets() {
    const rows = $("#ticket-rows");
    rows.replaceChildren();
    const query = $("#ticket-search").value.toLocaleLowerCase("pt-BR");
    const filter = $("#ticket-filter").value;
    const list = tickets.filter(
      (ticket) =>
        (filter === "all" || filter === ticket.status) &&
        `${ticket.code} ${ticket.description} ${categories[ticket.category]}`
          .toLocaleLowerCase("pt-BR")
          .includes(query),
    );
    list.forEach((ticket) => {
      const row = document.createElement("tr");
      const description = element(
        "td",
        ticket.description.slice(0, 65) +
          (ticket.description.length > 65 ? "…" : ""),
      );
      description.append(element("small", categories[ticket.category]));
      const state = document.createElement("td");
      state.append(
        element(
          "span",
          states[ticket.status],
          "badge " + (ticket.status === "closed" ? "done" : "preparation"),
        ),
      );
      const action = document.createElement("td");
      const button = element("button", "Ver chamado", "row-action");
      button.onclick = () => openDetail(ticket.id);
      action.append(button);
      row.append(
        element("td", ticket.code),
        description,
        state,
        element("td", formatDate(ticket.updated)),
        action,
      );
      rows.append(row);
    });
    $("#ticket-table").hidden = list.length === 0;
    $("#ticket-empty").hidden = list.length > 0;
    $("#ticket-empty h3").textContent = tickets.length
      ? "Nenhum ticket para este filtro"
      : "Nenhum ticket encontrado";
  }
  function openTicket() {
    if (!editable()) return;
    $("#ticket-form").reset();
    files = [];
    renderFiles();
    $("#ticket-error").hidden = true;
    $("#ticket-email").value = $("#profile-email").value || $("#email").value;
    $("#ticket-phone").value = $("#profile-phone").value;
    syncMode();
    $("#ticket-dialog").showModal();
  }
  function renderFiles() {
    const list = $("#ticket-file-list");
    list.replaceChildren();
    files.forEach((file, index) => {
      const row = element(
        "div",
        `${file.name} · ${(file.size / 1024 / 1024).toFixed(2)} MB`,
        "file-row",
      );
      const remove = element("button", "Remover", "text-button");
      remove.type = "button";
      remove.onclick = () => {
        files.splice(index, 1);
        renderFiles();
      };
      row.append(remove);
      list.append(row);
    });
  }
  $("#ticket-files").onchange = (event) => {
    if (server()) {
      event.target.value = "";
      return;
    }
    const incoming = [...event.target.files];
    const allowed = /\.(pdf|jpe?g|png|webp|txt)$/i;
    const valid =
      files.length + incoming.length <= 5 &&
      incoming.every(
        (file) =>
          file.size <= 10 * 1024 * 1024 &&
          file.size > 0 &&
          allowed.test(file.name),
      );
    $("#ticket-error").hidden = valid;
    if (valid) {
      files.push(...incoming);
      renderFiles();
    } else
      $("#ticket-error").textContent =
        "Escolha até 5 arquivos PDF, imagem ou TXT, não vazios e com até 10 MB cada.";
    event.target.value = "";
  };
  $("#ticket-form").onsubmit = async (event) => {
    event.preventDefault();
    const description = $("#ticket-description").value.trim();
    if (!description || !categories[$("#ticket-category").value]) return;
    if (server()) {
      const current = epoch;
      const submit = $("#ticket-form .primary");
      submit.disabled = true;
      try {
        const response = await window.PulseAPI.request(
          "tickets",
          {
            op: "create",
            category: $("#ticket-category").value,
            description,
            contact_phone: $("#ticket-phone").value.trim(),
            contact_email: $("#ticket-email").value.trim(),
          },
          "POST",
        );
        if (current !== epoch || !server()) return;
        $("#ticket-dialog").close();
        $("#ticket-search").value = "";
        $("#ticket-filter").value = "all";
        await loadServer();
        if (current === epoch && server())
          await openDetail(String(response.id));
      } catch (error) {
        $("#ticket-error").hidden = false;
        $("#ticket-error").textContent = error.message;
      } finally {
        submit.disabled = false;
      }
      return;
    }
    const now = Date.now();
    const ticket = {
      id: tickets.length + 1,
      code: `DEMO-${String(tickets.length + 1).padStart(4, "0")}`,
      category: $("#ticket-category").value,
      description,
      phone: $("#ticket-phone").value.trim(),
      email: $("#ticket-email").value.trim(),
      status: "open",
      updated: now,
      files: files.map((file) => {
        const url = URL.createObjectURL(file);
        urls.add(url);
        return { name: file.name, url };
      }),
      messages: [{ text: description, date: now }],
    };
    tickets.unshift(ticket);
    $("#ticket-search").value = "";
    $("#ticket-filter").value = "all";
    renderTickets();
    notify(`${ticket.code}: chamado demonstrativo criado.`, ticket.id);
    $("#ticket-dialog").close();
    openDetail(ticket.id);
  };
  async function openDetail(id) {
    if (server()) {
      const current = epoch;
      try {
        const response = await window.PulseAPI.request("tickets", {
          op: "detail",
          id: String(id),
        });
        if (current !== epoch || !server()) return;
        selectedTicket = normalizeTicket(
          response.ticket,
          response.messages || [],
        );
      } catch (error) {
        apiError(error);
        return;
      }
    } else {
      selectedTicket = tickets.find((ticket) => ticket.id === id);
    }
    if (!selectedTicket) return;
    $("#ticket-detail-code").textContent = selectedTicket.code;
    $("#ticket-detail-title").textContent = categories[selectedTicket.category];
    $("#ticket-detail-meta").textContent =
      `${states[selectedTicket.status]} · ${selectedTicket.email || "E-mail não informado"} · ${selectedTicket.phone || "Telefone não informado"}`;
    const thread = $("#ticket-thread");
    thread.replaceChildren();
    selectedTicket.messages.forEach((message) => {
      const card = element("article", "", "ticket-message");
      card.append(
        element(
          "b",
          server() ? message.author || "Usuário" : "Você · demonstração",
        ),
        element("small", formatDate(message.date)),
        element("p", message.text),
      );
      thread.append(card);
    });
    if (selectedTicket.files.length) {
      const attachments = element("div", "Anexos locais", "ticket-attachments");
      selectedTicket.files.forEach((file) => {
        const link = element("a", file.name);
        link.href = file.url;
        link.download = file.name;
        attachments.append(link);
      });
      thread.append(attachments);
    }
    detailError.hidden = true;
    $("#ticket-reply-form").reset();
    $("#ticket-reply").disabled =
      selectedTicket.status === "closed" || !editable();
    $("#ticket-reply-form button[type=submit]")?.removeAttribute("disabled");
    $("#ticket-reply-form .primary").disabled =
      selectedTicket.status === "closed" || !editable();
    $("#toggle-ticket-status").disabled = !editable();
    $("#toggle-ticket-status").textContent =
      selectedTicket.status === "closed" ? "Reabrir ticket" : "Encerrar ticket";
    if (!$("#ticket-detail-dialog").open)
      $("#ticket-detail-dialog").showModal();
  }
  $("#ticket-reply-form").onsubmit = async (event) => {
    event.preventDefault();
    const text = $("#ticket-reply").value.trim();
    if (!selectedTicket || selectedTicket.status === "closed" || !text) return;
    if (server()) {
      const current = epoch;
      const id = selectedTicket.id;
      const submit = $("#ticket-reply-form .primary");
      submit.disabled = true;
      try {
        await window.PulseAPI.request(
          "tickets",
          { op: "reply", id, body: text },
          "POST",
        );
        if (current !== epoch || !server()) return;
        await loadServer();
        if (current === epoch && server()) await openDetail(id);
      } catch (error) {
        apiError(error);
      } finally {
        submit.disabled = selectedTicket?.status === "closed" || !editable();
      }
      return;
    }
    selectedTicket.messages.push({ text, date: Date.now() });
    selectedTicket.updated = Date.now();
    notify(
      `${selectedTicket.code}: mensagem adicionada localmente.`,
      selectedTicket.id,
    );
    renderTickets();
    openDetail(selectedTicket.id);
  };
  $("#toggle-ticket-status").onclick = async () => {
    if (!selectedTicket) return;
    if (server()) {
      const current = epoch;
      const id = selectedTicket.id;
      const control = $("#toggle-ticket-status");
      control.disabled = true;
      try {
        await window.PulseAPI.request(
          "tickets",
          { op: selectedTicket.status === "closed" ? "reopen" : "close", id },
          "POST",
        );
        if (current !== epoch || !server()) return;
        await loadServer();
        if (current === epoch && server()) await openDetail(id);
      } catch (error) {
        apiError(error);
      } finally {
        control.disabled = !editable();
      }
      return;
    }
    selectedTicket.status =
      selectedTicket.status === "closed" ? "open" : "closed";
    selectedTicket.updated = Date.now();
    notify(
      `${selectedTicket.code}: ${states[selectedTicket.status].toLowerCase()} na demonstração.`,
      selectedTicket.id,
    );
    renderTickets();
    openDetail(selectedTicket.id);
  };
  $("#open-ticket").onclick = $("#first-ticket").onclick = openTicket;
  $("#close-ticket").onclick = $("#cancel-ticket").onclick = () =>
    $("#ticket-dialog").close();
  $("#close-ticket-detail").onclick = () => $("#ticket-detail-dialog").close();
  $("#ticket-search").oninput = $("#ticket-filter").onchange = renderTickets;
  function closePopovers() {
    $("#notifications-panel").hidden = $("#account-panel").hidden = true;
    $("#notifications-toggle").setAttribute("aria-expanded", "false");
    $("#account-toggle").setAttribute("aria-expanded", "false");
  }
  ["notifications", "account"].forEach((name) => {
    $("#" + name + "-toggle").onclick = () => {
      const open = $("#" + name + "-panel").hidden;
      closePopovers();
      $("#" + name + "-panel").hidden = !open;
      $("#" + name + "-toggle").setAttribute("aria-expanded", String(open));
    };
  });
  $("#read-notifications").onclick = async () => {
    if (server()) {
      const current = epoch;
      try {
        await window.PulseAPI.request("notifications", { op: "read" }, "POST");
        if (current !== epoch || !server()) return;
      } catch (error) {
        apiError(error);
        return;
      }
    }
    notifications.forEach((item) => (item.read = true));
    renderNotifications();
  };
  $("#account-profile").onclick = () => {
    closePopovers();
    setView("profile");
  };
  $("#account-exit").onclick = () => {
    closePopovers();
    $("#exit").click();
  };
  document.addEventListener("click", (event) => {
    if (
      !event.target.closest(
        ".header-popover, #notifications-toggle, #account-toggle",
      )
    )
      closePopovers();
  });
  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape") closePopovers();
  });
  $("#floating-whatsapp").onclick = () => {
    setView("support");
    $("#support-contact").click();
  };
  new MutationObserver(() => {
    $("#floating-whatsapp").hidden = $("#dashboard").hidden;
    if ($("#dashboard").hidden) closePopovers();
  }).observe($("#dashboard"), {
    attributes: true,
    attributeFilter: ["hidden"],
  });
  window.addEventListener("pagehide", () =>
    urls.forEach((url) => URL.revokeObjectURL(url)),
  );
  window.addEventListener("pulse-api-mode", () => {
    epoch++;
    refreshTask = null;
    selectedTicket = null;
    $("#ticket-detail-dialog").close();
    $("#ticket-dialog").close();
    closePopovers();
    apiFeedback.hidden = detailError.hidden = true;
    tickets = server() ? [] : demoTickets;
    notifications = server() ? [] : demoNotifications;
    files = [];
    renderFiles();
    syncMode();
    renderTickets();
    renderNotifications();
  });
  window.addEventListener("pulse-server-ready", () => {
    syncMode();
    loadServer().catch(apiError);
  });
  window.addEventListener("pulse-support-refresh", () => {
    if (server()) loadServer().catch(apiError);
  });
  syncMode();
  renderTickets();
  renderNotifications();
})();
