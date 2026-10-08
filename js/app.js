// TODO: preencher com o WhatsApp comercial, incluindo país e DDD.
// Exemplo de formato: 5511999999999.
const contactNumber = "";
const motionPreference = window.matchMedia("(prefers-reduced-motion: reduce)");
const revealElements = document.querySelectorAll(
  ".intro > *, .summary, .landing-prices, .brand-strip, .contact > *, footer",
);

if (!motionPreference.matches && "IntersectionObserver" in window) {
  const observer = new IntersectionObserver(
    (entries) => {
      entries.forEach((entry) => {
        if (entry.isIntersecting) {
          entry.target.classList.add("is-visible");
          observer.unobserve(entry.target);
        }
      });
    },
    { threshold: 0.12 },
  );

  revealElements.forEach((element, index) => {
    element.style.setProperty("--reveal-delay", `${Math.min(index, 3) * 40}ms`);
    element.classList.add("reveal");
    observer.observe(element);
  });

  motionPreference.addEventListener("change", (event) => {
    if (event.matches) {
      observer.disconnect();
      revealElements.forEach((element) => element.classList.add("is-visible"));
    }
  });
}

const contactButton = document.querySelector(".whatsapp-contact");
const contactStatus = document.querySelector("#contact-status");

contactButton.addEventListener("click", () => {
  if (!contactNumber) {
    contactStatus.textContent = "WhatsApp disponível em breve.";
    contactStatus.hidden = false;
    return;
  }

  const message = "Olá! Gostaria de saber mais sobre os disparos da Pulse.";
  window.location.href = `https://wa.me/${contactNumber}?text=${encodeURIComponent(message)}`;
});
