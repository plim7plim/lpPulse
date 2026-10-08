(() => {
  "use strict";
  const preference = matchMedia("(prefers-reduced-motion: reduce)");
  const ease = "cubic-bezier(.22,1,.36,1)";
  function enter(section) {
    if (!section || section.hidden || preference.matches) return;
    section.querySelectorAll(".page-heading, .service-panel").forEach((element, index) => {
      element.getAnimations().forEach(animation => animation.cancel());
      element.animate(
        [{ opacity: 0, transform: "translateY(12px)" }, { opacity: 1, transform: "translateY(0)" }],
        { duration: 380, delay: index * 55, easing: ease, fill: "backwards" },
      );
    });
  }
  window.addEventListener("pulse-view-change", ({ detail }) => enter(document.getElementById(detail.view + "-view")));
  window.addEventListener("pulse-api-mode", () => enter(document.querySelector(".content > section:not([hidden])")));
  const feedback = new MutationObserver(records => {
    if (preference.matches) return;
    for (const { target } of records) {
      if (!target.textContent.trim() || target.hidden) continue;
      target.getAnimations().forEach(animation => animation.cancel());
      target.animate([{ opacity: 0, transform: "translateY(4px)" }, { opacity: 1, transform: "translateY(0)" }], { duration: 220, easing: ease });
    }
  });
  document.querySelectorAll(".service-feedback, #login-notice, #account-feedback").forEach(element => {
    feedback.observe(element, { childList: true });
  });
  preference.addEventListener("change", () => {
    if (preference.matches) document.getAnimations().forEach(animation => animation.cancel());
  });
  enter(document.querySelector(".content > section:not([hidden])"));
})();