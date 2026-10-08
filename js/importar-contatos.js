(() => {
  "use strict";
  function normalize(value) {
    if (typeof value === "number" && !Number.isSafeInteger(value)) return null;
    const digits = String(value ?? "").trim().replace(/[\s().+-]/g, "");
    if (!/^(?:55)?[1-9]\d{9,10}$/.test(digits)) return null;
    return "+" + (digits.length <= 11 ? "55" : "") + digits;
  }
  function collect(rows, column, header) {
    const valid = new Set(), invalid = [];
    let duplicates = 0;
    rows.slice(header ? 1 : 0).forEach((row, index) => {
      const value = row[column];
      if (value === undefined || value === null || String(value).trim() === "") return;
      const number = normalize(value);
      if (!number) invalid.push({ line: index + (header ? 2 : 1), value: String(value).slice(0, 60) });
      else if (valid.has(number)) duplicates++;
      else valid.add(number);
    });
    return { numbers: [...valid], invalid, duplicates };
  }
  window.PulseContacts = { normalize, collect };
  const recipients = document.getElementById("chip-recipients");
  if (!recipients) return;
  const create = (tag, text, className) => {
    const item = document.createElement(tag);
    if (text !== undefined) item.textContent = text;
    if (className) item.className = className;
    return item;
  };
  const oldLabel = recipients.closest("label"), field = create("div", undefined, oldLabel.className);
  field.append(...oldLabel.childNodes); oldLabel.replaceWith(field);
  field.querySelector("span").id = "recipients-label";
  recipients.setAttribute("aria-labelledby", "recipients-label");
  const tools = create("div", undefined, "import-tools");
  const open = create("button", "Importar planilha", "button secondary"); open.type = "button";
  const input = create("input"); input.type = "file"; input.accept = ".xlsx,.xls,.csv,.tsv"; input.hidden = true;
  const notice = create("p", "Excel ou CSV · até 500 números por campanha", "import-hint");
  tools.append(open, input); field.append(tools, notice);
  const guide = create("details", undefined, "import-guide");
  guide.append(create("summary", "Como preparar a planilha"));
  guide.append(create("p", "Crie uma coluna chamada Telefone e coloque um número com DDD por linha. Exemplo de formato: (11) 99999-9999 ou 5511999999999. A coluna Nome é opcional."));
  guide.append(create("p", "A primeira linha deve conter os nomes das colunas. Use números como texto, sem fórmulas. No Excel, escolha a aba e a coluna ao importar. Duplicados são removidos e números inválidos aparecem na revisão."));
  const template = create("a", "Baixar modelo CSV vazio");
  template.href = "../assets/modelo-contatos.csv"; template.download = "modelo-contatos.csv";
  guide.append(template, create("small", "Abra no Excel, preencha a coluna Telefone e salve como .xlsx ou .csv. Até 2 MB por arquivo e 500 números por campanha."));
  field.append(guide);
  const dialog = create("dialog", undefined, "service-detail-dialog import-dialog");
  dialog.setAttribute("aria-labelledby", "import-title");
  const title = create("h2", "Importar destinatários"); title.id = "import-title";
  const filename = create("p");
  const options = create("div", undefined, "import-options");
  function select(label) {
    const wrapper = create("label", label), item = create("select"); wrapper.append(item); options.append(wrapper); return item;
  }
  const sheet = select("Aba da planilha"), column = select("Coluna dos telefones");
  const headerLabel = create("label", undefined, "service-consent"), header = create("input"); header.type = "checkbox";
  headerLabel.append(header, create("span", "A primeira linha é um cabeçalho"));
  const preview = create("div", undefined, "import-preview"); preview.setAttribute("aria-live", "polite");
  const actions = create("div", undefined, "module-actions"), cancel = create("button", "Voltar", "button secondary"), confirm = create("button", "Adicionar à lista", "button primary");
  cancel.type = confirm.type = "button"; cancel.addEventListener("click", () => dialog.close()); actions.append(cancel, confirm);
  dialog.append(title, filename, options, headerLabel, preview, actions); document.body.append(dialog);
  let library, workbook, rows = [], result, generation = 0;
  function loadLibrary() {
    if (window.XLSX) return Promise.resolve(window.XLSX);
    if (!library) library = new Promise((resolve, reject) => {
      const script = create("script"); script.src = new URL("../js/vendor/xlsx.full.min.js", location.href).href;
      script.onload = () => resolve(window.XLSX);
      script.onerror = () => { script.remove(); library = null; reject(new Error("Não foi possível carregar o leitor de planilhas.")); };
      document.head.append(script);
    });
    return library;
  }
  function update() {
    result = collect(rows, Number(column.value), header.checked);
    const existing = recipients.value.split(/\r?\n/).map(normalize).filter(Boolean);
    const total = new Set([...existing, ...result.numbers]).size;
    preview.replaceChildren(create("p", `${result.numbers.length} ${result.numbers.length === 1 ? "número válido" : "números válidos"} · ${result.duplicates} ${result.duplicates === 1 ? "duplicado" : "duplicados"} · ${result.invalid.length} ${result.invalid.length === 1 ? "inválido" : "inválidos"}`));
    if (result.numbers.length) preview.append(create("p", result.numbers.slice(0, 4).join(" · "), "import-sample"));
    if (result.invalid.length) preview.append(create("p", "Não serão importados: " + result.invalid.slice(0, 3).map(item => `linha ${item.line}: ${item.value}`).join("; ")));
    if (total > 500) preview.append(create("p", `A lista ficaria com ${total} números. O limite atual é 500; selecione uma planilha menor.`));
    confirm.disabled = !result.numbers.length || total > 500;
  }
  function chooseSheet() {
    const selected = workbook.Sheets[sheet.value];
    rows = window.XLSX.utils.sheet_to_json(selected, { header: 1, raw: true, defval: "", blankrows: true });
    if (rows.length > 10000) throw new Error("A aba excede 10.000 linhas. Divida a planilha antes de importar.");
    const width = Math.max(0, ...rows.slice(0, 20).map(row => row.length));
    if (width > 100) throw new Error("Use uma planilha com até 100 colunas.");
    column.replaceChildren();
    let detected = -1;
    for (let i = 0; i < width; i++) {
      const label = String(rows[0]?.[i] || "").slice(0, 50);
      const name = label.normalize("NFD").replace(/[\u0300-\u036f]/g, "").toLowerCase().trim();
      if (/^(telefone|telefones|celular|celulares|numero|numeros|whatsapp|fone|phone)$/.test(name)) detected = i;
      column.append(new Option(`Coluna ${window.XLSX.utils.encode_col(i)}${label ? " · " + label : ""}`, String(i)));
    }
    column.value = String(detected >= 0 ? detected : 0);
    header.checked = detected >= 0;
    update();
  }
  open.addEventListener("click", () => input.click());
  input.addEventListener("change", async () => {
    const file = input.files[0]; if (!file) return;
    const current = generation;
    open.disabled = true; notice.textContent = "Lendo planilha…";
    try {
      if (!/\.(xlsx|xls|csv|tsv)$/i.test(file.name)) throw new Error("Escolha um arquivo Excel (.xlsx, .xls), CSV ou TSV.");
      if (file.size > 2 * 1024 * 1024) throw new Error("A planilha deve ter até 2 MB.");
      const xlsx = await loadLibrary(), bytes = await file.arrayBuffer();
      if (current !== generation) return;
      workbook = xlsx.read(bytes, { type: "array", raw: true, sheetRows: 10002, cellFormula: true });
      // Fórmulas não são executadas nem usadas como números importados.
      Object.values(workbook.Sheets).forEach(worksheet => Object.keys(worksheet).forEach(key => { if (worksheet[key]?.f) worksheet[key].v = ""; }));
      sheet.replaceChildren(...workbook.SheetNames.map(name => new Option(name, name)));
      filename.textContent = file.name; chooseSheet(); dialog.showModal();
      notice.textContent = "Revise a aba e a coluna antes de adicionar os números.";
    } catch (error) { if (current === generation) notice.textContent = error.message || "Não foi possível ler a planilha."; }
    finally { open.disabled = false; input.value = ""; }
  });
  sheet.addEventListener("change", () => { try { chooseSheet(); } catch (error) { preview.replaceChildren(create("p", error.message)); confirm.disabled = true; } });
  column.addEventListener("change", update); header.addEventListener("change", update);
  confirm.addEventListener("click", () => {
    update(); if (confirm.disabled) return;
    const oldLines = recipients.value.split(/\r?\n/).map(value => value.trim()).filter(Boolean);
    const known = new Set(oldLines.map(normalize).filter(Boolean));
    const added = result.numbers.filter(number => !known.has(number));
    if (oldLines.length + added.length > 500) { preview.append(create("p", "A lista ultrapassaria 500 linhas. Revise os contatos já preenchidos.")); return; }
    recipients.value = [...oldLines, ...added].join("\n"); recipients.dispatchEvent(new Event("input", { bubbles: true }));
    notice.textContent = `${added.length} ${added.length === 1 ? "número adicionado" : "números adicionados"}. Revise a mensagem e a autorização dos destinatários antes do envio.`;
    dialog.close(); recipients.focus();
  });
  window.addEventListener("pulse-api-mode", () => {
    generation++; workbook = null; rows = []; result = null; dialog.close(); input.value = "";
    notice.textContent = "Excel ou CSV · até 500 números por campanha";
  });
})();
