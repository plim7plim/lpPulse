(() => {
  "use strict";
  const preference = matchMedia("(prefers-reduced-motion: reduce)");
  const ease = "cubic-bezier(.22,1,.36,1)";
  function animate(element, delay = 0) {
    if (preference.matches || element.closest("[hidden]")) return;
    element.getAnimations().forEach((animation) => animation.cancel());
    element.animate(
      [
        { opacity: 0, transform: "translateY(10px)" },
        { opacity: 1, transform: "translateY(0)" }
      ],
      { duration: 340, delay, easing: ease, fill: "backwards" }
    );
  }
  const scrolling =
    "IntersectionObserver" in window
      ? new IntersectionObserver(
          (entries) => {
            for (const entry of entries) {
              if (!entry.isIntersecting || entry.target.closest("[hidden]")) continue;
              animate(entry.target);
              scrolling.unobserve(entry.target);
            }
          },
          { threshold: 0.05 }
        )
      : null;
  function enter(section) {
    if (!section || section.hidden || preference.matches) return;
    section.querySelectorAll(".page-heading, .service-panel").forEach((element, index) => {
      if (element.getBoundingClientRect().top < innerHeight)
        animate(element, Math.min(index, 3) * 45);
      else scrolling?.observe(element);
    });
  }
  window.addEventListener("pulse-view-change", ({ detail }) =>
    enter(document.getElementById(detail.view + "-view"))
  );
  window.addEventListener("pulse-api-mode", () =>
    enter(document.querySelector(".content > section:not([hidden])"))
  );
  const feedback = new MutationObserver((records) => {
    if (preference.matches) return;
    for (const { target } of records) {
      if (!target.textContent.trim() || target.hidden) continue;
      target.getAnimations().forEach((animation) => animation.cancel());
      target.animate(
        [
          { opacity: 0, transform: "translateY(4px)" },
          { opacity: 1, transform: "translateY(0)" }
        ],
        { duration: 220, easing: ease }
      );
    }
  });
  document
    .querySelectorAll(".service-feedback, #login-notice, #account-feedback")
    .forEach((element) => {
      feedback.observe(element, { childList: true });
    });
  preference.addEventListener("change", () => {
    if (preference.matches) {
      scrolling?.disconnect();
      document.getAnimations().forEach((animation) => animation.cancel());
    }
  });
  enter(document.querySelector(".content > section:not([hidden])"));
})();
