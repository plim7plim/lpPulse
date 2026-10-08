(() => {
  "use strict";
  const api = () => window.PulseAPI;
  const form = document.getElementById("number-order-form");
  const body = document.getElementById("number-order-lines");
  const add = document.getElementById("number-add-line");
  const submit = document.getElementById("number-order-submit");
  const feedback = document.getElementById("number-order-feedback");
  const history = document.getElementById("number-order-history");
  const total = document.getElementById("number-order-total");
  const refresh = document.getElementById("number-orders-refresh");
  let sending = false, loading = false, epoch = 0, requestId = null;
  const allowed = () => api()?.mode === "server" && ["owner","manager","operator"].includes(api().session.role);
  function node(tag, text, className) {
    const el = document.createElement(tag); if (text !== undefined) el.textContent = text; if (className) el.className = className; return el;
  }
  function update() {
    const amount = [...body.querySelectorAll('[data-field="quantity"]')].reduce((sum,input)=>sum+(Number(input.value)||0),0);
    total.textContent = `${amount} ${amount===1 ? "número solicitado" : "números solicitados"}`;
    add.disabled = sending || !allowed() || body.rows.length >= 20;
    submit.disabled = sending || !allowed();
    body.querySelectorAll("input,select,button").forEach(el=>{el.disabled = sending || !allowed() || (el.dataset.remove !== undefined && body.rows.length===1);});
  }
  function addLine() {
    if(body.rows.length>=20)return;
    const row = node("tr");
    function cell(field, label, el) { el.dataset.field=field; el.setAttribute("aria-label",label); const td=node("td");td.append(el);row.append(td);return el; }
    const type=cell("type","Tipo de número",node("select"));
    type.append(new Option("SMS","sms"),new Option("SMS + WhatsApp garantido","whatsapp"));
    const quantity=cell("quantity","Quantidade",node("input"));quantity.type="number";quantity.min="1";quantity.max="1000";quantity.step="1";quantity.required=true;quantity.value="1";
    const ddd=cell("ddd","DDD preferido (opcional)",node("input"));ddd.inputMode="numeric";ddd.pattern="[1-9][0-9]";ddd.maxLength=2;ddd.placeholder="Qualquer";
    const notes=cell("notes","Observações (opcional)",node("input"));notes.maxLength=500;notes.placeholder="Opcional";
    const remove=node("button","×","number-remove");remove.type="button";remove.dataset.remove="";remove.setAttribute("aria-label","Remover linha");remove.addEventListener("click",()=>{row.remove();requestId=null;update();});
    const td=node("td");td.append(remove);row.append(td);body.append(row);update();
  }
  async function load() {
    if(loading || api()?.mode!=="server")return;
    const current=epoch;loading=true;refresh.disabled=true;
    try {
      const result=await api().request("number_orders");if(current!==epoch)return;
      history.replaceChildren();
      if(!result.items.length)history.append(node("p","Nenhum pedido enviado ainda.","import-hint"));
      const labels={draft:"Rascunho",submitted:"Enviado",reviewing:"Em análise",quoted:"Orçamento disponível",accepted:"Aceito",cancelled:"Cancelado"};
      result.items.forEach(item=>{
        const row=node("article",undefined,"chip-number-row"),info=node("div"),details=item.details;
        info.append(node("strong",`Pedido #${item.id} · ${item.service==="whatsapp_number"?"SMS + WhatsApp garantido":"SMS"}`),node("small",`${details.quantity||"—"} número(s) · ${details.ddd?"DDD "+details.ddd:"Sem preferência de DDD"}`));
        if(details.notes)info.append(node("small",details.notes));row.append(info,node("span",labels[item.status]||item.status,"finance-status"));history.append(row);
      });
    } catch(error){if(current===epoch)history.replaceChildren(node("p",error.message,"service-feedback"));}
    finally{if(current===epoch){loading=false;refresh.disabled=false;}}
  }
  form.addEventListener("input",()=>{requestId=null;update();});
  form.addEventListener("submit",async event=>{
    event.preventDefault();if(sending||!allowed()||!form.reportValidity())return;
    const lines=[...body.rows].map(row=>Object.fromEntries([...row.querySelectorAll('[data-field]')].map(input=>[input.dataset.field,input.dataset.field==="quantity"?Number(input.value):input.value.trim()])));
    if(lines.reduce((sum,line)=>sum+line.quantity,0)>1000){feedback.textContent="O pedido pode conter até 1.000 números.";return;}
    requestId ||= crypto.randomUUID();const current=epoch;sending=true;update();feedback.textContent="Enviando pedido…";
    try {
      const result=await api().request("number_orders",{request_id:requestId,lines},"POST");if(current!==epoch)return;
      feedback.textContent=`Pedido registrado: ${result.ids.map(id=>"#"+id).join(", ")}. Aguarde a análise e o orçamento. Nenhuma cobrança foi gerada.`;
      requestId=null;body.replaceChildren();addLine();await load();
    }catch(error){if(current===epoch)feedback.textContent=error.message;}
    finally{if(current===epoch){sending=false;update();}}
  });
  add.addEventListener("click",()=>{addLine();requestId=null;});refresh.addEventListener("click",load);
  window.addEventListener("pulse-view-change",({detail})=>{if(detail.view==="whatsapp-numbers")load();});
  window.addEventListener("pulse-api-mode",()=>{epoch++;loading=sending=false;requestId=null;body.replaceChildren();history.replaceChildren();feedback.textContent="";addLine();if(!document.getElementById("whatsapp-numbers-view").hidden)load();});
  addLine();api()?.ready?.then(()=>{update();if(!document.getElementById("whatsapp-numbers-view").hidden)load();});
})();