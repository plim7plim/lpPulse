document.querySelectorAll(".nav-group-toggle").forEach((button) => {
  button.addEventListener("click", () => {
    const open = button.getAttribute("aria-expanded") !== "true";
    const submenu = document.getElementById(
      button.getAttribute("aria-controls"),
    );
    button.setAttribute("aria-expanded", String(open));
    submenu.getAnimations().forEach((animation) => animation.cancel());
    if (window.matchMedia("(prefers-reduced-motion: reduce)").matches) {
      submenu.hidden = !open;
      return;
    }
    submenu.hidden = false;
    const height = submenu.scrollHeight;
    const animation = submenu.animate(
      open
        ? [
            { height: "0px", opacity: 0 },
            { height: `${height}px`, opacity: 1 },
          ]
        : [
            { height: `${height}px`, opacity: 1 },
            { height: "0px", opacity: 0 },
          ],
      { duration: 240, easing: "cubic-bezier(.22,1,.36,1)" },
    );
    animation.onfinish = () => {
      submenu.hidden = button.getAttribute("aria-expanded") !== "true";
    };
  });
});

document.querySelectorAll(".session-form").forEach((form) => {
  form.addEventListener("submit", (event) => {
    event.preventDefault();
    const feedback = form.querySelector(".form-feedback");
    if (
      form.querySelector("#esim-end") &&
      $("#esim-end").value < $("#esim-start").value
    ) {
      feedback.textContent =
        "A data final precisa ser igual ou posterior ao início da viagem.";
      feedback.hidden = false;
      return;
    }
    feedback.textContent = form.dataset.success;
    feedback.hidden = false;
  });
  form.addEventListener("input", () => {
    form.querySelector(".form-feedback").hidden = true;
  });
});

const invitedUsers = [];
const roles = {
  viewer: "Visualizador",
  operator: "Operador",
  manager: "Gerente",
};
function renderInvites() {
  const table = $("#user-table");
  table.replaceChildren();
  if (!invitedUsers.length) {
    const row = document.createElement("tr");
    const cell = document.createElement("td");
    cell.colSpan = 4;
    cell.className = "empty";
    cell.textContent = "Nenhum convite preparado nesta sessão.";
    row.append(cell);
    table.append(row);
  }
  invitedUsers.forEach((user) => {
    const row = document.createElement("tr");
    const name = document.createElement("td");
    const title = document.createElement("b");
    title.textContent = user.name;
    const email = document.createElement("small");
    email.textContent = user.email;
    name.append(title, email);
    const role = document.createElement("td");
    role.textContent = roles[user.role];
    const status = document.createElement("td");
    status.textContent = "Não enviado";
    const actions = document.createElement("td");
    const remove = document.createElement("button");
    remove.className = "row-action";
    remove.textContent = "Remover";
    remove.setAttribute("aria-label", `Remover convite de ${user.name}`);
    remove.onclick = () => {
      invitedUsers.splice(invitedUsers.indexOf(user), 1);
      renderInvites();
    };
    actions.append(remove);
    row.append(name, role, status, actions);
    table.append(row);
  });
}
$("#invite-form").addEventListener("submit", (event) => {
  event.preventDefault();
  const name = $("#invite-name").value.trim();
  const email = $("#invite-email").value.trim().toLowerCase();
  const feedback = $("#invite-feedback");
  if (!name) return;
  if (invitedUsers.some((user) => user.email === email)) {
    feedback.textContent =
      "Já existe um convite demonstrativo para esse e-mail.";
    feedback.hidden = false;
    return;
  }
  invitedUsers.push({ name, email, role: $("#invite-role").value });
  renderInvites();
  event.target.reset();
  feedback.textContent =
    "Convite preparado na demonstração. Nenhum e-mail enviado.";
  feedback.hidden = false;
});
renderInvites();

const exampleBalance = [
  {
    date: "2026-10-01",
    description: "Recarga de exemplo",
    credit: 1000,
    debit: 0,
  },
  {
    date: "2026-10-03",
    description: "Campanha de exemplo",
    credit: 0,
    debit: 350,
  },
  {
    date: "2026-10-05",
    description: "Campanha de relacionamento de exemplo",
    credit: 0,
    debit: 480,
  },
];
let visibleBalance = [...exampleBalance];
function renderBalance() {
  const table = $("#balance-table");
  table.replaceChildren();
  visibleBalance.forEach((entry) => {
    const row = document.createElement("tr");
    [
      entry.date.split("-").reverse().join("/"),
      entry.description,
      entry.credit
        ? entry.credit.toLocaleString("pt-BR", {
            style: "currency",
            currency: "BRL",
          })
        : "—",
      entry.debit
        ? entry.debit.toLocaleString("pt-BR", {
            style: "currency",
            currency: "BRL",
          })
        : "—",
    ].forEach((value) => {
      const cell = document.createElement("td");
      cell.textContent = value;
      row.append(cell);
    });
    table.append(row);
  });
  $("#balance-empty").hidden = visibleBalance.length > 0;
}
$("#balance-filter").addEventListener("submit", (event) => {
  event.preventDefault();
  const feedback = event.target.querySelector(".form-feedback");
  const start = $("#balance-start").value,
    end = $("#balance-end").value;
  if (start && end && end < start) {
    feedback.textContent = "A data final precisa ser posterior à data inicial.";
    feedback.hidden = false;
    return;
  }
  visibleBalance = exampleBalance.filter(
    (entry) => (!start || entry.date >= start) && (!end || entry.date <= end),
  );
  renderBalance();
  feedback.hidden = true;
});
$("#export-balance").addEventListener("click", () => {
  const rows = visibleBalance.map((entry) =>
    [
      entry.date,
      entry.description,
      entry.credit.toFixed(2).replace(".", ","),
      entry.debit.toFixed(2).replace(".", ","),
    ].join(";"),
  );
  const content =
    "\uFEFFData;Descrição (demonstração);Crédito;Débito\r\n" +
    rows.join("\r\n");
  const url = URL.createObjectURL(
    new Blob([content], { type: "text/csv;charset=utf-8" }),
  );
  const link = document.createElement("a");
  link.href = url;
  link.download = "saldo-pulse-demonstracao.csv";
  link.click();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
});
renderBalance();
