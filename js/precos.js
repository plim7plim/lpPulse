(() => {
  "use strict";
  const url = new URL("../assets/tarifas.json", document.currentScript.src);
  const money = (cents) =>
    (cents / 100).toLocaleString("pt-BR", { style: "currency", currency: "BRL" });
  const node = (tag, text, className) => {
    const el = document.createElement(tag);
    if (text !== undefined) el.textContent = text;
    if (className) el.className = className;
    return el;
  };
  function table(title, headers, rows) {
    const group = node("div", undefined, "pricing-group");
    group.append(node("h3", title));
    const scroll = node("div", undefined, "table-scroll");
    scroll.setAttribute("role", "region");
    scroll.setAttribute("aria-label", title);
    scroll.tabIndex = 0;
    const table = node("table", undefined, "price-table");
    const head = node("thead"),
      tr = node("tr"),
      body = node("tbody");
    headers.forEach((label) => {
      const cell = node("th", label);
      cell.scope = "col";
      tr.append(cell);
    });
    head.append(tr);
    rows.forEach((values) => {
      const row = node("tr");
      values.forEach((value, i) => {
        const cell = node(i === 0 ? "th" : "td", value);
        if (i === 0) cell.scope = "row";
        row.append(cell);
      });
      body.append(row);
    });
    table.append(head, body);
    scroll.append(table);
    group.append(scroll);
    return group;
  }
  const ready = fetch(url, { cache: "no-cache" })
    .then((response) => {
      if (!response.ok) throw new Error("Não foi possível carregar os preços.");
      return response.json();
    })
    .then((catalog) => {
      document.querySelectorAll(".pricing-panel").forEach((panel) => {
        const heading = node("div", undefined, "service-panel-top");
        const title = node("h2", "Preços e modalidades");
        title.id = "pricing-title";
        heading.append(title, node("span", "Valores em reais", "pricing-label"));
        const grid = node("div", undefined, "pricing-grid");
        const bands = (tiers, unit = "disparos") =>
          tiers.map((tier, i) => [
            i === 0
              ? `Até ${tier.max.toLocaleString("pt-BR")} ${unit}`
              : tier.max
                ? `${(tiers[i - 1].max + 1).toLocaleString("pt-BR")} a ${tier.max.toLocaleString("pt-BR")} ${unit}`
                : `A partir de ${(tiers[i - 1].max + 1).toLocaleString("pt-BR")} ${unit}`,
            money(tier.rate_mills / 10)
          ]);
        grid.append(
          table(
            "SMS · envios pela chipeira",
            ["Quantidade por compra", "Por SMS"],
            bands(catalog.sms, "SMS")
          )
        );
        grid.append(
          table("Disparos de WhatsApp", ["Volume", "Por disparo"], bands(catalog.whatsapp))
        );
        const numbers = table(
          "Números e assinaturas",
          ["Modalidade", "Preço", "Condições"],
          catalog.numbers.map((item) => [item.label, money(item.price_cents), item.details])
        );
        numbers.classList.add("pricing-wide");
        grid.append(numbers);
        grid.append(
          table(
            "SMS longcode · modalidade separada",
            ["Modalidade", "Por SMS"],
            [["Longcode", money(catalog.longcode_rate_mills / 10)]]
          )
        );
        const note = node(
          "p",
          "Tabela comercial de SMS para a chipeira. O preço do SMS é definido pela quantidade de cada compra e vale para todas as unidades dela, sem acumular campanhas ou meses. Planos online e assinatura estão em Planos e contratos; longcode está no Financeiro. eSIM e disparos de WhatsApp ainda dependem de fornecedor e integração. A tabela não ativa serviços nem cria cobranças.",
          "pricing-note"
        );
        panel.replaceChildren(heading, grid, note);
      });
      window.dispatchEvent(new CustomEvent("pulse-pricing-ready", { detail: catalog }));
      return catalog;
    })
    .catch((error) => {
      document.querySelectorAll(".pricing-panel").forEach((panel) => {
        panel.replaceChildren(node("p", error.message, "service-feedback"));
      });
      return null;
    });
  window.PulsePricing = { ready, money };
})();
