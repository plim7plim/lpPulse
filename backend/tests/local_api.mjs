import assert from "node:assert/strict";

const base = "http://127.0.0.1:4173";
let checks = 0;
function check(condition, message) {
  assert.ok(condition, message);
  checks++;
}
function client() {
  let cookie = "", csrf = "";
  return async function call(action, data = {}, method = "GET", overrides = {}) {
    const url = new URL(`/backend/api.php?action=${action}`, base);
    const options = { method, headers: { Accept: "application/json", Cookie: cookie, ...overrides } };
    if (method === "GET") Object.entries(data).forEach(([key, value]) => url.searchParams.set(key, value));
    else {
      options.headers["Content-Type"] = "application/json";
      options.headers.Origin ??= base;
      options.headers["X-CSRF-Token"] ??= csrf;
      options.body = JSON.stringify(data);
    }
    const response = await fetch(url, options);
    const result = await response.json();
    const setCookie = response.headers.get("set-cookie");
    if (setCookie) cookie = setCookie.split(";")[0];
    if (result.data?.csrf_token) csrf = result.data.csrf_token;
    return { status: response.status, ...result, setCookie };
  };
}
const owner = client();
const initial = await owner("session");
check(initial.data?.test_environment === true, "Abort: API is not the local test environment");
check((await owner("profile")).status === 401, "Anonymous profile rejected");
const login = await owner("login", { email: "admin@pulse.test", password: "PulseTeste123!" }, "POST");
check(login.status === 200 && login.data.role === "owner", "Owner login");
check(login.data.company.id === "1001", "Test company selected");
check(/HttpOnly/i.test(login.setCookie), "HttpOnly session");
const profile = await owner("profile");
check(profile.data.profile.id === "1001", "Profile from session company");
check((await owner("profile", profile.data.profile, "POST", { "X-CSRF-Token": "invalid" })).status === 403, "Invalid CSRF rejected");
check((await owner("profile", profile.data.profile, "POST", { Origin: "http://another-site.test" })).status === 403, "Wrong Origin rejected");
check((await owner("profile", profile.data.profile, "POST")).status === 200, "Profile persisted");
check((await owner("tickets", { op: "detail", id: "2001" })).status === 404, "Other-company ticket hidden");
const invoices = await owner("invoices");
check(invoices.data.items.length === 3, "Seed invoices available");
const balance = await owner("balance");
check(balance.data.balance === "875.00", "Seed balance exact decimal");
const credit = await owner("credit_requests", { amount: "100.00", method: "pix" }, "POST");
check(credit.data?.status === "pending" && credit.data.charge_created === false, "Recharge does not create a payment");
check((await owner("balance")).data.balance === "875.00", "Pending recharge does not credit balance");
const request = await owner("requests", { kind: "service", op: "create", type: "sms_number", details: { ddd: "11", notes: "Teste automatizado local" } }, "POST");
check(request.data?.status === "submitted", "Service request persisted");
check((await owner("requests", { kind: "service", op: "cancel", id: request.data.id }, "POST")).data?.status === "cancelled", "Request cancelled");
const ticket = await owner("tickets", { op: "create", subject: "Teste automatizado da API", category: "technical", description: "Teste local de gravação e transação." }, "POST");
check(ticket.data?.status === "open", "Ticket and initial message created");
check((await owner("tickets", { op: "reply", id: ticket.data.id, body: "Resposta de teste." }, "POST")).status === 200, "Reply persisted");
check((await owner("tickets", { op: "detail", id: ticket.data.id })).data.messages.length === 2, "Conversation persisted");
check((await owner("tickets", { op: "close", id: ticket.data.id }, "POST")).status === 200, "Ticket closed");
const viewer = client();
check((await viewer("login", { email: "leitura@pulse.test", password: "PulseTeste123!" }, "POST")).data?.role === "viewer", "Viewer login");
check((await viewer("tickets", { op: "create" }, "POST")).status === 403, "Viewer cannot write");
check((await viewer("users")).status === 403, "Viewer cannot list users");
const chip = await owner("chipeira", { op: "status" });
// Consulta somente o estado: este teste nunca cria campanhas na chipeira.
check(chip.status === 200 && typeof chip.data?.connected === "boolean" && chip.data.channels.includes("sms"), "SMS connection status available (read-only)");
check((await owner("logout", {}, "POST")).status === 200, "Logout persisted");
check((await owner("profile")).status === 401, "Session revoked");
await viewer("logout", {}, "POST");
console.log(`Local API + MariaDB: ${checks} checks passed.`);
