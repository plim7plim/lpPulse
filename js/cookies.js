(() => {
  'use strict';
  const root = new URL('../', document.currentScript.src);
  const key = 'pulse-cookie-consent-v1';
  const maxAge = 180 * 24 * 60 * 60 * 1000;
  const defaults = { necessary: true, analytics: false, functional: false, ads: false };
  let consent = null;
  try { const saved = JSON.parse(localStorage.getItem(key)); if (saved?.version === 1 && saved.timestamp <= Date.now() && Date.now() - saved.timestamp < maxAge && ['analytics','functional','ads'].every(k => typeof saved[k] === 'boolean')) consent = { ...saved, necessary: true }; } catch (_) {}
  const host = document.createElement('div'); host.className = 'pulse-cookies';
  host.innerHTML = `<section class="pc-banner" aria-label="Consentimento de cookies" ${consent ? 'hidden' : ''}><div><strong>A sua privacidade é importante para nós</strong><p>Utilizamos cookies e tecnologias semelhantes para o funcionamento do Pulse. Você escolhe se permite recursos opcionais de análise, personalização e publicidade. Saiba mais na <a href="${new URL('politica-de-cookies.html', root)}">Política de Cookies</a>.</p></div><div class="pc-actions"><button data-pc="reject">Rejeitar</button><button data-pc="configure">Configurar</button><button class="pc-primary" data-pc="accept">Aceitar todos</button></div></section><button class="pc-manage" type="button" data-pc="configure"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M20.8 13A9 9 0 1 1 11 3a5 5 0 0 0 9.8 10Z"/><circle cx="8" cy="10" r="1"/><circle cx="10" cy="16" r="1"/><circle cx="15" cy="14" r="1"/></svg><span>Gerenciar cookies</span></button><dialog class="pc-dialog" aria-labelledby="pc-title"><div class="pc-heading"><h2 id="pc-title">Preferências de cookies</h2><button data-pc="close" aria-label="Fechar preferências">×</button></div><p>Escolha quais categorias deseja permitir. Os recursos necessários permanecem ativos.</p><label class="pc-category"><span><strong>Necessários</strong><small>Segurança, sessão de acesso e registro da sua decisão. Sempre ativos.</small></span><input type="checkbox" checked disabled aria-label="Cookies necessários, sempre ativos"></label><label class="pc-category"><span><strong>Desempenho e análise</strong><small>Permitem medir o uso das páginas quando ferramentas de análise forem adotadas.</small></span><input type="checkbox" data-category="analytics"></label><label class="pc-category"><span><strong>Funcionais</strong><small>Permitem recursos opcionais de personalização.</small></span><input type="checkbox" data-category="functional"></label><label class="pc-category"><span><strong>Publicidade</strong><small>Permitem mensurar campanhas quando ferramentas de publicidade forem adotadas.</small></span><input type="checkbox" data-category="ads"></label><p><a href="${new URL('politica-de-cookies.html', root)}">Leia a Política de Cookies</a></p><div class="pc-actions"><button data-pc="reject">Rejeitar não essenciais</button><button class="pc-primary" data-pc="save">Salvar preferências</button></div><p class="pc-storage" role="status" hidden>Seu navegador não permitiu salvar a decisão. Ela vale nesta página; em outra visita o aviso poderá reaparecer.</p></dialog>`;
  (document.querySelector(".workspace") || document.body).append(host);
  const banner = host.querySelector('.pc-banner'), dialog = host.querySelector('dialog');
  const manage = host.querySelector('.pc-manage');
  manage.hidden = !banner.hidden;
  let opener;
  function notify() { window.dispatchEvent(new CustomEvent('pulse:cookie-consent', { detail: { ...(consent || defaults) } })); }
  let closing = false;
  function leave(element) {
    if (matchMedia('(prefers-reduced-motion: reduce)').matches || !element.animate) return Promise.resolve();
    return element.animate([{opacity:1,transform:'translateY(0)'},{opacity:0,transform:'translateY(12px)'}],{duration:180,easing:'ease-in'}).finished.catch(()=>{});
  }
  async function close() {
    if (!dialog.open || closing) return;
    closing = true;
    await leave(dialog);
    dialog.close(); closing = false; opener?.focus();
  }
  dialog.addEventListener('cancel', event => { event.preventDefault(); close(); });
  function save(values) {
    consent = { ...defaults, ...values, necessary: true, version: 1, timestamp: Date.now() };
    let stored = true; try { localStorage.setItem(key, JSON.stringify(consent)); } catch (_) { stored = false; }
    if (!banner.hidden) leave(banner).then(()=>{banner.hidden=true;manage.hidden=false;});
    notify();
    if (stored) close(); else { host.querySelector('.pc-storage').hidden = false; if (!dialog.open) dialog.showModal(); }
  }
  document.addEventListener('click', e => {
    const button = e.target.closest('[data-pc]'); if (!button) return;
    switch (button.dataset.pc) {
      case 'configure': opener = button; host.querySelectorAll('[data-category]').forEach(input => { input.checked = Boolean(consent?.[input.dataset.category]); }); if (!dialog.open) dialog.showModal(); break;
      case 'close': close(); break;
      case 'reject': save(defaults); break;
      case 'accept': save({ analytics: true, functional: true, ads: true }); break;
      case 'save': save(Object.fromEntries([...host.querySelectorAll('[data-category]')].map(input => [input.dataset.category, input.checked]))); break;
    }
  });
  window.PulseCookieConsent = { get: () => ({ ...(consent || defaults) }), allows: category => Boolean((consent || defaults)[category]) };
  notify();
  if (location.hash === '#cookies') host.querySelector('[data-pc=configure]').click();
})();
