(function () {
  'use strict';
  const __ = (text) => wp.i18n.__(text, 'fourmix-intelligence');
  const config = window.FourmixIntelligencePersonalSettings;
  const form = document.getElementById('fmi-connect'), select = document.getElementById('fmi-staff-agent');
  const status = document.getElementById('fmi-personal-status');
  async function request(action, body) {
    const reply = await fetch(config.endpoint + action, {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce}, body: JSON.stringify(body || {})});
    const data = await reply.json(); if (!reply.ok) throw new Error(data.message || __('設定を確認できませんでした。')); return data;
  }
  function restore(data) {
    select.replaceChildren(new Option(__('StudioのAIを選択'), ''), ...data.agents.map((agent) => new Option(agent.label, agent.name)));
    select.value = data.selected_agent || ''; select.disabled = !data.agents.length;
  }
  function notify() { window.dispatchEvent(new Event('fourmix-intelligence-settings-updated')); }
  let busy = false;

  select.addEventListener('change', async () => {
    if (busy) return;
    if (!select.value) { status.textContent = __('利用するAIを選択してください。'); return; }
    busy = true; select.disabled = true;
    try { restore(await request('select', {agent: select.value})); status.textContent = __('AIの選択を保存しました。'); notify(); }
    catch (error) { restore({agents: []}); status.textContent = error.message; }
    finally { busy = false; }
  });
  request('catalog').then((data) => { if (busy) return; restore(data); if (!data.agents.length) status.textContent = __('Fourmix Intelligence にログインし、社内向けAIを選択してください。'); }).catch((error) => { status.textContent = error.message; });
})();
