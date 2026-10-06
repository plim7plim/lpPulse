(() => {
  const canvas = document.querySelector("#dispatch-background");
  const context = canvas.getContext("2d");
  if (!context) return;

  const reducedMotion = window.matchMedia("(prefers-reduced-motion: reduce)");
  let width = 0;
  let height = 0;
  let frame = 0;
  let lastTime = 0;
  let elapsed = 0;
  let burst = 0;
  let pointer = { x: 0, y: 0 };
  let smoothedPointer = { x: 0, y: 0 };
  let routes = [];

  function resize() {
    width = window.innerWidth;
    height = window.innerHeight;
    const mobile = width < 700;
    routes = Array.from({ length: mobile ? 7 : 12 }, (_, index) => ({
      phase: index * 0.173,
      speed: 0.09 + (index % 4) * 0.014,
      endX: mobile
        ? 0.08 + ((index * 37) % 85) / 100
        : 0.12 + ((index * 31) % 83) / 100,
      endY: 0.08 + ((index * 23) % 84) / 100,
      branch: index % 3,
    }));
    const ratio = Math.min(window.devicePixelRatio || 1, mobile ? 1.25 : 1.5);
    canvas.width = Math.round(width * ratio);
    canvas.height = Math.round(height * ratio);
    context.setTransform(ratio, 0, 0, ratio, 0, 0);
    if (reducedMotion.matches) draw();
  }

  function point(route, progress, origin) {
    const end = { x: route.endX * width, y: route.endY * height };
    const branchX = origin.x - width * (0.1 + route.branch * 0.065);
    const branchY = origin.y + (route.branch - 1) * height * 0.12;
    const inverse = 1 - progress;
    return {
      x:
        inverse ** 3 * origin.x +
        3 * inverse ** 2 * progress * branchX +
        3 * inverse * progress ** 2 * branchX +
        progress ** 3 * end.x,
      y:
        inverse ** 3 * origin.y +
        3 * inverse ** 2 * progress * branchY +
        3 * inverse * progress ** 2 * end.y +
        progress ** 3 * end.y,
    };
  }

  function draw() {
    context.clearRect(0, 0, width, height);
    const origin = {
      x: width * (width < 700 ? 0.92 : 0.88) + smoothedPointer.x * 26,
      y: height * 0.23 + smoothedPointer.y * 20,
    };
    const radius = Math.max(width, height) * 0.9;
    const glow = context.createRadialGradient(
      origin.x,
      origin.y,
      0,
      origin.x,
      origin.y,
      radius * 0.65,
    );
    glow.addColorStop(0, "rgba(18,201,117,0.22)");
    glow.addColorStop(0.35, "rgba(18,201,117,0.085)");
    glow.addColorStop(1, "rgba(18,201,117,0)");
    context.fillStyle = glow;
    context.fillRect(0, 0, width, height);

    // Trame légère autour du centre de diffusion.
    for (let x = 24; x < width; x += 38) {
      for (let y = 20; y < height; y += 38) {
        const distance = Math.hypot(x - origin.x, y - origin.y) / radius;
        context.fillStyle = `rgba(17,120,74,${Math.max(0, 0.14 - distance * 0.16)})`;
        context.fillRect(x, y, 1, 1);
      }
    }

    const breathing = reducedMotion.matches
      ? 0.5
      : (Math.sin(elapsed * 1.8) + 1) / 2;
    for (let ring = 0; ring < 3; ring++) {
      context.beginPath();
      context.arc(
        origin.x,
        origin.y,
        9 + ring * 13 + breathing * 3,
        0,
        Math.PI * 2,
      );
      context.strokeStyle = `rgba(10,165,91,${0.22 - ring * 0.055})`;
      context.lineWidth = 1;
      context.stroke();
    }
    context.beginPath();
    context.arc(origin.x, origin.y, 4, 0, Math.PI * 2);
    context.fillStyle = "rgba(10,165,91,0.55)";
    context.fill();

    // Ondas abertas: o centro emite pulsos que se dissipam no fundo.
    for (let index = 0; index < 4; index++) {
      const progress = (elapsed * 0.07 + index / 4) % 1;
      context.beginPath();
      context.arc(
        origin.x,
        origin.y,
        25 + progress * radius * 0.7,
        0,
        Math.PI * 2,
      );
      context.strokeStyle = `rgba(10,165,91,${(1 - progress) * 0.12})`;
      context.lineWidth = 1;
      context.stroke();
    }

    routes.forEach((route) => {
      context.beginPath();
      for (let step = 0; step <= 40; step++) {
        const position = point(route, step / 40, origin, radius);
        if (!step) context.moveTo(position.x, position.y);
        else context.lineTo(position.x, position.y);
      }
      context.strokeStyle = "rgba(15,150,87,0.055)";
      context.lineWidth = 1;
      context.stroke();

      const destination = point(route, 1, origin, radius);
      context.beginPath();
      context.arc(destination.x, destination.y, 3, 0, Math.PI * 2);
      context.fillStyle = "rgba(10,165,91,0.18)";
      context.fill();

      if (reducedMotion.matches) return;
      const progress = (elapsed * route.speed + route.phase) % 1;
      const head = point(route, progress, origin, radius);
      const tail = point(route, Math.max(0, progress - 0.085), origin, radius);
      const opacity =
        Math.min(1, progress * 5) * 0.65 * Math.min(1, (1 - progress) * 12);
      const trail = context.createLinearGradient(
        tail.x,
        tail.y,
        head.x,
        head.y,
      );
      trail.addColorStop(0, "rgba(18,201,117,0)");
      trail.addColorStop(1, `rgba(10,175,95,${opacity})`);
      context.beginPath();
      for (let step = 0; step <= 12; step++) {
        const position = point(
          route,
          Math.max(0, progress - 0.085) +
            (Math.min(progress, 0.085) * step) / 12,
          origin,
          radius,
        );
        if (!step) context.moveTo(position.x, position.y);
        else context.lineTo(position.x, position.y);
      }
      context.strokeStyle = trail;
      context.lineWidth = 2;
      context.stroke();
      context.beginPath();
      context.arc(head.x, head.y, 2.7, 0, Math.PI * 2);
      context.fillStyle = `rgba(10,175,95,${opacity})`;
      context.fill();

      // Halo do pacote em movimento.
      context.beginPath();
      context.arc(head.x, head.y, 7, 0, Math.PI * 2);
      context.strokeStyle = `rgba(10,175,95,${opacity * 0.22})`;
      context.lineWidth = 1;
      context.stroke();

      if (progress > 0.84) {
        const arrival = (progress - 0.84) / 0.16;
        context.beginPath();
        context.arc(
          destination.x,
          destination.y,
          5 + arrival * 18,
          0,
          Math.PI * 2,
        );
        context.strokeStyle = `rgba(10,175,95,${(1 - arrival) * 0.35})`;
        context.lineWidth = 1;
        context.stroke();
      }
    });

    if (burst > 0) {
      context.beginPath();
      context.arc(
        origin.x,
        origin.y,
        (1 - burst) * radius * 0.55 + 15,
        0,
        Math.PI * 2,
      );
      context.strokeStyle = `rgba(10,175,95,${burst * 0.3})`;
      context.lineWidth = 2;
      context.stroke();
    }
  }

  function animate(time) {
    frame = requestAnimationFrame(animate);
    if (time - lastTime < 1000 / (width < 700 ? 24 : 30)) return;
    const delta = lastTime ? Math.min((time - lastTime) / 1000, 0.06) : 0;
    lastTime = time;
    elapsed += delta;
    smoothedPointer.x += (pointer.x - smoothedPointer.x) * 0.045;
    smoothedPointer.y += (pointer.y - smoothedPointer.y) * 0.045;
    burst = Math.max(0, burst - delta * 0.65);
    draw();
  }

  function syncMotion() {
    cancelAnimationFrame(frame);
    lastTime = 0;
    if (reducedMotion.matches || document.hidden) draw();
    else frame = requestAnimationFrame(animate);
  }

  window.addEventListener("resize", resize, { passive: true });
  window.addEventListener(
    "pointerdown",
    (event) => {
      if (reducedMotion.matches) return;
      pointer = {
        x: event.clientX / width - 0.5,
        y: event.clientY / height - 0.5,
      };
      burst = 1;
    },
    { passive: true },
  );
  document.addEventListener("visibilitychange", syncMotion);
  reducedMotion.addEventListener("change", syncMotion);
  window.addEventListener(
    "pointermove",
    (event) => {
      if (event.pointerType !== "mouse" || reducedMotion.matches) return;
      pointer = {
        x: event.clientX / width - 0.5,
        y: event.clientY / height - 0.5,
      };
    },
    { passive: true },
  );
  document.querySelectorAll(".button").forEach((button) => {
    button.addEventListener("click", () => {
      if (!reducedMotion.matches) burst = 1;
    });
  });

  resize();
  syncMotion();
})();
