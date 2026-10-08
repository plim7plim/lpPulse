(() => {
  "use strict";
  const canvas = document.getElementById("dispatch-background");
  const ctx = canvas?.getContext("2d");
  if (!ctx) return;
  const motion = matchMedia("(prefers-reduced-motion: reduce)");
  let width, height, paths = [], frame = 0, lastPaint = 0;
  function point(p, t) {
    const u = 1 - t;
    return { x: u**3*p.a.x+3*u*u*t*p.b.x+3*u*t*t*p.c.x+t**3*p.d.x, y: u**3*p.a.y+3*u*u*t*p.b.y+3*u*t*t*p.c.y+t**3*p.d.y };
  }
  function circle(p, radius, color, fill = false) {
    ctx.beginPath(); ctx.arc(p.x,p.y,radius,0,Math.PI*2);
    ctx.strokeStyle = ctx.fillStyle = color;
    fill ? ctx.fill() : ctx.stroke();
  }
  function resize() {
    width = innerWidth; height = innerHeight;
    const ratio = Math.min(devicePixelRatio || 1, 2);
    canvas.width = width*ratio; canvas.height = height*ratio;
    ctx.setTransform(ratio,0,0,ratio,0,0);
    const a = {x:width*.86,y:height*.24};
    const targets = width<700 ? [[.12,.14],[.24,.48],[.78,.76],[.15,.93]] : [[.12,.10],[.36,.22],[.08,.52],[.32,.78],[.67,.92],[.94,.68],[.59,.47],[.96,.07]];
    paths = targets.map(([x,y],i)=>({a,d:{x:width*x,y:height*y},b:{x:width*(1.05-i*.024),y:height*y*.7},c:{x:width*(x+.17),y:height*(y+.06)},offset:i*.37}));
    paint(performance.now());
  }
  function paint(time) {
    ctx.clearRect(0,0,width,height);
    const origin = paths[0].a;
    const wash = ctx.createRadialGradient(origin.x,origin.y,0,origin.x,origin.y,width*.48);
    wash.addColorStop(0,"rgba(14,200,120,.07)"); wash.addColorStop(1,"rgba(14,200,120,0)");
    ctx.fillStyle=wash; ctx.fillRect(0,0,width,height); ctx.lineWidth=1;
    for (const p of paths) {
      ctx.beginPath();ctx.moveTo(p.a.x,p.a.y);ctx.bezierCurveTo(p.b.x,p.b.y,p.c.x,p.c.y,p.d.x,p.d.y);
      ctx.strokeStyle="rgba(14,168,104,.19)";ctx.stroke();circle(p.d,2.7,"rgba(14,168,104,.48)",true);
      if (motion.matches) continue;
      const phase=(time/4200+p.offset)%1;
      if (phase<.8) {
        const progress = phase/.8;
        for(let i=12;i>=1;i--) {
          const t=progress-i*.006;
          if(t<0)continue;
          const a=point(p,t), b=point(p,t+.006);
          ctx.beginPath();ctx.moveTo(a.x,a.y);ctx.lineTo(b.x,b.y);
          ctx.lineWidth=2;ctx.strokeStyle=`rgba(9,181,106,${.8*(1-i/13)})`;ctx.stroke();
        }
        ctx.lineWidth=1;
        circle(point(p,progress),3.3,"rgba(9,181,106,.9)",true);
      } else {
        const ripple=(phase-.8)/.2;
        circle(p.d,3+ripple*24,`rgba(14,168,104,${.48*(1-ripple)})`);
      }
    }
    circle(origin,4,"rgba(14,168,104,.6)",true);circle(origin,10,"rgba(14,168,104,.18)");
  }
  function animate(time) {
    if(time-lastPaint>=33){paint(time);lastPaint=time;}
    frame=requestAnimationFrame(animate);
  }
  function resume() {
    cancelAnimationFrame(frame);
    if(!document.hidden&&!motion.matches)frame=requestAnimationFrame(animate);
    else paint(performance.now());
  }
  addEventListener("resize",resize,{passive:true});
  document.addEventListener("visibilitychange",resume);
  motion.addEventListener("change",resume);
  addEventListener("pagehide",()=>cancelAnimationFrame(frame),{once:true});
  resize();resume();
})();
