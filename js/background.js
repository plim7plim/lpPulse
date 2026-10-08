(() => {
  const canvas = document.querySelector("#dispatch-background");
  const context = canvas?.getContext("2d");
  if (!context) return;
  function draw() {
    const width = window.innerWidth,
      height = window.innerHeight;
    const ratio = Math.min(window.devicePixelRatio || 1, 2);
    canvas.width = width * ratio;
    canvas.height = height * ratio;
    context.setTransform(ratio, 0, 0, ratio, 0, 0);
    context.clearRect(0, 0, width, height);
    const origin = { x: width * 0.83, y: height * 0.27 };
    const count = width < 700 ? 5 : 8;
    for (let index = 0; index < count; index++) {
      const end = {
        x: width * (0.08 + index * 0.12),
        y: height * (0.18 + (index % 4) * 0.23),
      };
      context.beginPath();
      context.moveTo(origin.x, origin.y);
      context.bezierCurveTo(
        origin.x + width * 0.06,
        end.y,
        end.x + width * 0.12,
        end.y,
        end.x,
        end.y,
      );
      context.strokeStyle = "rgba(18,201,117,.09)";
      context.lineWidth = 1;
      context.stroke();
      context.beginPath();
      context.arc(end.x, end.y, 2.4, 0, Math.PI * 2);
      context.fillStyle = "rgba(18,201,117,.28)";
      context.fill();
    }
    context.beginPath();
    context.arc(origin.x, origin.y, 5, 0, Math.PI * 2);
    context.strokeStyle = "rgba(18,201,117,.3)";
    context.stroke();
  }
  window.addEventListener("resize", draw, { passive: true });
  draw();
})();
