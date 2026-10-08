(() => {
  $("#profile-form").addEventListener("submit", () => {
    const name = $("#profile-name").value.trim().split(/\s+/)[0];
    if (!name) return;
    $("#greeting-name").textContent = name;
    $(".account-name").textContent = name;
    $(".account-initial").textContent = name.charAt(0).toUpperCase();
  });
  const paths = {
    overview: "M3 10 12 3l9 7M5 9v12h5v-7h4v7h5V9",
    campaigns: "m3 11 18-8-6 18-4-8-8-2Zm8 2 10-10",
    credit:
      "M12 3a9 9 0 1 0 9 9M12 7v10m3-8h-4a2 2 0 0 0 0 4h2a2 2 0 0 1 0 4H9",
    balance: "M4 20h16M6 16V9m6 7V4m6 12v-5",
    finance: "M6 3h12v18l-3-2-3 2-3-2-3 2V3Zm3 5h6m-6 4h6",
    profile: "M3 5h18v14H3V5Zm4 4h2v3H7V9Zm6 0h5m-5 4h5m-12 3h5",
    payment: "M3 5h18v14H3V5Zm0 5h18",
    "system-users":
      "M16 21v-3a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v3M9 10a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm8-8a4 4 0 0 1 0 8m5 11v-3a4 4 0 0 0-3-4",
    "mobile-plans": "M7 2h10v20H7V2Zm4 17h2",
    "travel-esim": "m3 10 7 1 8-8 3 1-6 10 3 4-2 2-5-4-6 4-2-1 3-6-4-2 1-1Z",
    affiliates:
      "M9 10a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm-7 11v-3a4 4 0 0 1 4-4h6a4 4 0 0 1 4 4v3m1-18a4 4 0 0 1 0 7m5 11v-3a4 4 0 0 0-3-4",
    reseller: "M3 10h18l-2-7H5l-2 7Zm1 0v11h16V10M9 21v-7h6v7",
    virtual:
      "M8 3 5 3c-3 4 0 11 5 15s8 4 11 1v-3l-5-2-2 2c-3-1-6-4-7-7l2-1-1-5Z",
    pabx: "M4 14v-3a8 8 0 0 1 16 0v3M4 12H2v7h4v-7H4Zm16 0h2v7h-4v-7h2Zm0 7v2h-7",
    "sms-number": "M3 3h18v14H8l-5 4V3Z",
    streaming:
      "M12 10v4m-4-7a7 7 0 0 0 0 10m8-10a7 7 0 0 1 0 10M5 4a11 11 0 0 0 0 16M19 4a11 11 0 0 1 0 16",
  };
  document.querySelectorAll(".sidebar nav button").forEach((button) => {
    const key =
      button.dataset.view ||
      {
        "submenu-mobile": "balance",
        "submenu-fixed": "virtual",
        "submenu-apps": "sms-number",
        "submenu-communication": "pabx",
        "submenu-streaming": "streaming",
        "submenu-registration": "system-users",
        "submenu-financial": "payment",
        "submenu-partnerships": "affiliates",
      }[button.getAttribute("aria-controls")];
    const aliases = {
      "virtual-number": "virtual",
      "my-numbers": "mobile-plans",
      "whatsapp-number": "sms-number",
      "sms-marketing": "campaigns",
      "whatsapp-attendance": "pabx",
      "service-orders": "finance",
      "call-records": "balance",
    };
    const icon = paths[aliases[key] || key];
    const slot = button.querySelector('span[aria-hidden="true"]');
    if (!icon || !slot) return;
    const svg = document.createElementNS("http://www.w3.org/2000/svg", "svg");
    svg.setAttribute("viewBox", "0 0 24 24");
    svg.setAttribute("fill", "none");
    svg.setAttribute("stroke", "currentColor");
    svg.setAttribute("stroke-linecap", "round");
    svg.setAttribute("stroke-linejoin", "round");
    const path = document.createElementNS("http://www.w3.org/2000/svg", "path");
    path.setAttribute("d", icon);
    svg.append(path);
    slot.replaceChildren(svg);
  });
})();
