const exampleInvoices = [
  {
    id: "EX-001",
    description: "Campanha de outubro",
    due: "2026-10-15",
    amount: 350,
    status: "open",
  },
  {
    id: "EX-002",
    description: "Campanha de relacionamento",
    due: "2026-10-05",
    amount: 480,
    status: "paid",
  },
  {
    id: "EX-003",
    description: "Proposta cancelada",
    due: "2026-10-02",
    amount: 120,
    status: "cancelled",
  },
];
const money = (value) =>
  value.toLocaleString("pt-BR", { style: "currency", currency: "BRL" });
function renderInvoices() {
  const table = $("#invoice-table");
  table.replaceChildren();
  const labels = { open: "Em aberto", paid: "Paga", cancelled: "Cancelada" };
  exampleInvoices
    .filter(
      (invoice) =>
        $("#invoice-filter").value === "all" ||
        invoice.status === $("#invoice-filter").value,
    )
    .forEach((invoice) => {
      const row = document.createElement("tr");
      [
        invoice.id,
        invoice.description,
        invoice.due.split("-").reverse().join("/"),
        money(invoice.amount),
        labels[invoice.status],
      ].forEach((value) => {
        const cell = document.createElement("td");
        cell.textContent = value;
        row.append(cell);
      });
      const action = document.createElement("td");
      const button = document.createElement("button");
      button.className = "row-action";
      button.textContent = "Ver detalhes";
      button.onclick = () => {
        const detail = $("#invoice-detail");
        detail.replaceChildren();
        [
          invoice.id,
          invoice.description,
          `Valor: ${money(invoice.amount)}`,
          `Status: ${labels[invoice.status]}`,
        ].forEach((value) => {
          const line = document.createElement("p");
          line.textContent = value;
          detail.append(line);
        });
        $("#invoice-dialog").showModal();
      };
      action.append(button);
      row.append(action);
      table.append(row);
    });
}
$("#invoice-filter").onchange = renderInvoices;
$("#close-invoice").onclick = () => $("#invoice-dialog").close();
renderInvoices();

document.querySelectorAll("[data-plan]").forEach(
  (button) =>
    (button.onclick = () => {
      $("#mobile-data").value = button.dataset.plan;
      $("#mobile-lines").scrollIntoView({
        behavior: window.matchMedia("(prefers-reduced-motion: reduce)").matches
          ? "instant"
          : "smooth",
        block: "center",
      });
      $("#mobile-lines").focus({ preventScroll: true });
    }),
);
document.querySelectorAll("[data-destination]").forEach(
  (button) =>
    (button.onclick = () => {
      $("#esim-destination").value = button.dataset.destination;
      $("#esim-start").scrollIntoView({
        behavior: window.matchMedia("(prefers-reduced-motion: reduce)").matches
          ? "instant"
          : "smooth",
        block: "center",
      });
      $("#esim-start").focus({ preventScroll: true });
    }),
);
