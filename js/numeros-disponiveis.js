(() => {
  "use strict";
  const view = document.getElementById("my-numbers-view");
  const api = () => window.PulseAPI;
  const element = (tag, text, className) => {
    const item = document.createElement(tag);
    if (text !== undefined) item.textContent = text;
    if (className) item.className = className;
    return item;
  };
  const panel = element("section", undefined, "service-panel chip-panel"), top = element("div", undefined, "service-panel-top");
  const refresh = element("button", "Consultar disponíveis", "button secondary"); refresh.type = "button";
  top.append(element("h2", "Escolher um número"), refresh);
  const note = element("p", "Após o pagamento confirmado, escolha um número livre para usar como remetente nas campanhas.", "service-description");
  const feedback = element("p", undefined, "service-feedback"); feedback.setAttribute("role", "status");
  const list = element("div"); panel.append(top, note, feedback, list); view.append(panel);
  let epoch = 0, loading = false, reserving = false;
  const canChoose = () => ["owner", "manager"].includes(api()?.session.role);
  async function load() {
    if (api()?.mode !== "server" || loading || reserving) return;
    const current = epoch;
    loading = true; refresh.disabled = true; panel.setAttribute("aria-busy", "true");
    feedback.textContent = "Consultando disponibilidade…";
    list.replaceChildren();
    try {
      const result = await api().request("chipeira", { op: "available" });
      if (current !== epoch) return;
      feedback.textContent = "";
      if (!result.eligible) { note.textContent = result.reason; return; }
      note.textContent = result.test_access ? "Ambiente local de testes: escolha liberada para esta conta, sem cobrança. Somente números online e livres no estoque dos vendedores aparecem aqui." : canChoose() ? "Pagamento confirmado. Estes números estão online, livres no estoque dos vendedores e sem vínculo com outro cliente Pulse." : "Consulte os números disponíveis. Somente proprietários e gerentes podem escolhê-los.";
      if (!result.phones.length) list.append(element("p", "Nenhum número disponível agora. Consulte novamente mais tarde."));
      result.phones.forEach(phone => {
        const row = element("article", undefined, "chip-number-row"), info = element("div");
        info.append(element("strong", phone.phoneNumber), element("small", `${phone.operator || "Operadora não identificada"} · Disponível`));
        const choose = element("button", "Escolher número", "button primary"); choose.type = "button"; choose.disabled = !canChoose();
        choose.addEventListener("click", () => reserve(phone.phoneNumber, row, choose));
        row.append(info, choose); list.append(row);
      });
    } catch (error) { if (current === epoch) feedback.textContent = error.message; }
    finally { if (current === epoch) { loading = false; refresh.disabled = false; panel.removeAttribute("aria-busy"); } }
  }
  async function reserve(number, row, choose) {
    if (reserving || !canChoose()) return;
    const current = epoch;
    reserving = true; refresh.disabled = true;
    list.querySelectorAll("button").forEach(button => { button.disabled = true; });
    choose.textContent = "Reservando…";
    let rowFeedback = row.querySelector('[role="status"]');
    if (!rowFeedback) { rowFeedback = element("small"); rowFeedback.setAttribute("role", "status"); row.firstElementChild.append(rowFeedback); }
    rowFeedback.textContent = "Confirmando disponibilidade e vínculo…";
    feedback.textContent = "Reservando o número…";
    try {
      await api().request("chipeira", { op: "reserve", number }, "POST");
      if (current !== epoch) return;
      row.remove();
      feedback.textContent = `${number} vinculado à sua empresa. Ele já pode ser escolhido em Número remetente, na tela Enviar SMS.`;
      window.dispatchEvent(new CustomEvent("pulse-view-change", { detail: { view: "my-numbers" } }));
    } catch (error) { if (current === epoch) { feedback.textContent = error.message; rowFeedback.textContent = error.message; } }
    finally {
      if (current === epoch) { reserving = false; refresh.disabled = false; choose.textContent = "Escolher número"; list.querySelectorAll("button").forEach(button => { button.disabled = !canChoose(); }); }
    }
  }
  refresh.addEventListener("click", load);
  window.addEventListener("pulse-view-change", ({ detail }) => { if (detail.view === "my-numbers") load(); });
  window.addEventListener("pulse-api-mode", () => {
    epoch++; loading = reserving = false; list.replaceChildren(); feedback.textContent = ""; panel.removeAttribute("aria-busy");
    refresh.disabled = api()?.mode !== "server";
    if (!view.hidden) load();
  });
  api()?.ready?.then(() => { refresh.disabled = api()?.mode !== "server"; if (!view.hidden) load(); });
})();
