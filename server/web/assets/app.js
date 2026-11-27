// Scanner code-barres (caméra) : <button data-scan="#champ"> — utilise BarcodeDetector si disponible.
document.addEventListener('click', async e => {
  const b = e.target.closest('[data-scan]'); if (!b) return;
  const target = document.querySelector(b.dataset.scan);
  if (!('BarcodeDetector' in window) || !navigator.mediaDevices) { alert('Scanner non supporté par ce navigateur — saisissez le code. / Scanner not supported.'); return; }
  const det = new BarcodeDetector(), v = document.createElement('video'); v.setAttribute('playsinline', ''); v.style.cssText = 'position:fixed;inset:0;width:100%;height:100%;object-fit:cover;z-index:99;background:#000';
  const x = document.createElement('button'); x.textContent = '✕'; x.style.cssText = 'position:fixed;top:14px;right:14px;z-index:100;font-size:22px;padding:8px 14px;border-radius:10px;border:0';
  let stop = false; const s = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } }).catch(() => null); if (!s) return;
  v.srcObject = s; document.body.append(v, x); await v.play();
  const end = () => { stop = true; s.getTracks().forEach(t => t.stop()); v.remove(); x.remove(); }; x.onclick = end;
  (async function loop() { if (stop) return; try { const r = await det.detect(v); if (r.length) { target.value = r[0].rawValue; target.dispatchEvent(new Event('input', { bubbles: true })); target.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true })); end(); return; } } catch (_) {} requestAnimationFrame(loop); })();
});
// WhatsApp : QR + statut
(function () {
  const w = document.getElementById('wa'); if (!w) return;
  const $ = id => document.getElementById(id), post = a => fetch(w.dataset.url + '&a=' + a, { method: 'POST', headers: { 'X-CSRF': w.dataset.csrf } }).then(r => r.json());
  let timer = null, n = 0;
  const done = () => { clearInterval(timer); location.reload(); };
  const showQr = r => { if (r.qr) { $('qr').src = r.qr.startsWith('data:') ? r.qr : 'data:image/png;base64,' + r.qr; $('qr').hidden = false; } if (r.code) { $('pair').textContent = '#' + r.code; $('pair').hidden = false; } };
  $('bconnect')?.addEventListener('click', async () => {
    $('waerr').textContent = ''; const r = await post('connect').catch(() => ({ ok: false, error: 'Network' }));
    if (!r.ok) { $('waerr').textContent = r.error; return; } if (r.state === 'open') return done(); showQr(r); n = 0;
    timer = setInterval(async () => { n++; if (n > 50) return clearInterval(timer); const s = await post('status').catch(() => ({})); if (s.state === 'open') return done(); if (n % 8 === 0) { const c = await post('connect').catch(() => ({})); if (c.ok) showQr(c); } }, 3000);
  });
  $('blogout')?.addEventListener('click', async () => { if (!confirm('?')) return; await post('logout'); location.reload(); });
})();
