'use strict';
const $ = id => document.getElementById(id);
let token = '', snapshot = null, createKey = null, createPayload = null;
const notice = text => { $('notice').textContent = text; };
async function api(path, data, extra = {}) {
  const response = await fetch('/lab/v1' + path, {method: data === undefined ? 'GET' : 'POST', headers: {'Content-Type':'application/json', Authorization:'Bearer '+token, ...extra}, body: data === undefined ? undefined : JSON.stringify(data)});
  const result = response.status === 204 ? {} : await response.json();
  if (response.status === 401 && path !== '/session') { token=''; $('login').hidden=false; $('workspace').hidden=true; }
  if (!response.ok) throw new Error(result.error || 'Błąd serwera');
  return result;
}
const run = fn => async event => { if(event) event.preventDefault(); try { await fn(); } catch(error) { notice(error.message); } };
const button = (text, action) => { const b=document.createElement('button');b.textContent=text;b.onclick=run(action);return b; };
const localDate = date => new Date(date.getTime()-date.getTimezoneOffset()*60000).toISOString().slice(0,16);
$('notBefore').value=localDate(new Date());$('expires').value=localDate(new Date(Date.now()+3600000));
for(const status of ['QUEUED','CLAIMED','SENDING','SENT','DELIVERED','FAILED_RETRYABLE','FAILED_FINAL','UNKNOWN','CANCELLED']){const o=document.createElement('option');o.value=o.textContent=status;$('filter').append(o);}
async function refresh(){
 snapshot=await api('/snapshot?status='+encodeURIComponent($('filter').value));
 $('queueState').textContent=snapshot.config.enabled?'Kolejka aktywna':'Kolejka zatrzymana';
 $('alerts').textContent=snapshot.alerts.join(' ');
 $('allowlist').textContent=snapshot.allowlist.join(', ') || 'Lista pusta — wysyłka niemożliwa.';
 $('metrics').textContent=JSON.stringify(snapshot.metrics);
 $('devices').replaceChildren();
 for(const d of snapshot.devices){const p=document.createElement('p');p.textContent=`${d.name} · ${d.state} · SIM ${d.subscription ?? '—'} · heartbeat ${d.heartbeat ?? '—'}`;$('devices').append(p);for(const [title,action] of [['Start telefonu','resume'],['Pauza telefonu','pause'],['Unieważnij token','revoke']])$('devices').append(button(title,async()=>{await api(`/devices/${d.id}/${action}`,{});await refresh();}));}
 $('messages').replaceChildren();
 for(const m of snapshot.messages){const row=document.createElement('tr');for(const text of [m.id+' / '+m.recipient,m.status,m.attempt_count+' / '+(m.sim_subscription_id??'—'),m.sent_at??'—',m.delivered_at??'—']){const td=document.createElement('td');td.textContent=text;row.append(td);}const actions=document.createElement('td');actions.append(button('Historia',async()=>{$('history').textContent=JSON.stringify(await api(`/messages/${m.id}/events`),null,2);}));if(['QUEUED','FAILED_RETRYABLE'].includes(m.status))actions.append(button('Anuluj',async()=>{await api(`/messages/${m.id}/cancel`,{});await refresh();}));if(m.status==='UNKNOWN')actions.append(button('Rozstrzygnij',async()=>{const status=prompt('Potwierdzony wynik: SENT, DELIVERED lub FAILED_FINAL. Nie ponawia wysyłki.');if(!status)return;const reason=prompt('Powód / dowód (bez numeru i treści SMS):');if(!reason)return;await api(`/messages/${m.id}/resolve`,{status,reason});await refresh();}));row.append(actions);$('messages').append(row);}
}
$('loginForm').onsubmit=run(async()=>{const result=await api('/session',{password:$('password').value});token=result.token;$('password').value='';$('login').hidden=true;$('workspace').hidden=false;await refresh();$('interval').value=snapshot.config.interval_seconds;$('queueLimit').value=snapshot.config.queue_limit;$('dailyLimit').value=snapshot.config.daily_limit;$('retry').checked=Boolean(snapshot.config.retry_enabled);notice('Zalogowano. Sesja ważna godzinę.');});
const config = enabled => ({enabled,interval_seconds:Number($('interval').value),queue_limit:Number($('queueLimit').value),daily_limit:Number($('dailyLimit').value),retry_enabled:$('retry').checked});
$('configForm').onsubmit=run(async()=>{await api('/config',config(Boolean(snapshot.config.enabled)));await refresh();});
$('start').onclick=run(async()=>{await api('/config',config(true));await refresh();});
$('kill').onclick=run(async()=>{await api('/config',{...snapshot.config,enabled:false,retry_enabled:Boolean(snapshot.config.retry_enabled)});await refresh();notice('Pobieranie i nowe pozwolenia wysyłki zatrzymane. SMS już przekazanego do modemu nie można cofnąć.');});
$('pair').onclick=run(async()=>{const r=await api('/pairing-code',{});$('pairCode').textContent=r.code;setTimeout(()=>{$('pairCode').textContent='Kod wygasł.';},300000);});
$('allowForm').onsubmit=run(async()=>{await api('/allowlist',{number:$('allowNumber').value});$('allowNumber').value='';await refresh();});
$('removeNumber').onclick=run(async()=>{await api('/allowlist',{number:$('allowNumber').value,remove:true});$('allowNumber').value='';await refresh();});
$('body').oninput=run(async()=>{if(token){const s=await api('/segments',{body:$('body').value});$('segments').textContent=`${s.encoding} · ${s.units} jednostek · ${s.segments} segmentów · ${s.supported?'dozwolone':'poza zakresem MVP'}`;}});
$('messageForm').onsubmit=run(async()=>{const data={recipient:$('recipient').value,body:$('body').value,not_before:new Date($('notBefore').value).toISOString(),expires_at:new Date($('expires').value).toISOString()};const encoded=JSON.stringify(data);if(createPayload!==encoded){createPayload=encoded;createKey=crypto.randomUUID();}const r=await api('/messages',data,{'Idempotency-Key':createKey});notice('Dodano '+r.message_id+'. Powtórzenie tego samego formularza nie tworzy duplikatu.');await refresh();});
$('refresh').onclick=run(refresh);$('filter').onchange=run(refresh);
$('logout').onclick=run(async()=>{await api('/logout',{});token='';location.reload();});
$('export').onclick=run(async()=>{const data=await api('/export');const url=URL.createObjectURL(new Blob([JSON.stringify(data,null,2)],{type:'application/json'}));const a=document.createElement('a');a.href=url;a.download='udziosms-wyniki.json';a.click();setTimeout(()=>URL.revokeObjectURL(url),1000);});
setInterval(()=>{if(token)run(refresh)();},15000);
