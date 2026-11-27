// Point de vente : panier côté navigateur ; les prix et le stock sont revalidés par le serveur.
(function () {
  const P = window.POS, $ = id => document.getElementById(id), cart = {};
  const fmt = v => Math.round(v).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ' ') + ' ' + P.cur;
  const prods = P.products.map(p => ({ ...p, price: +p.price, stock: +p.stock }));
  P.customers.forEach(c => { const o = document.createElement('option'); o.value = c.id; o.textContent = c.name + (c.phone ? ' · ' + c.phone : ''); $('cust').append(o); });
  function list() {
    const q = $('q').value.trim().toLowerCase(); const box = $('plist'); box.innerHTML = '';
    prods.filter(p => !q || p.name.toLowerCase().includes(q) || (p.barcode && p.barcode === q)).slice(0, 60).forEach(p => {
      const d = document.createElement('div'); d.className = 'prod' + (p.stock <= 0 ? ' out' : '');
      d.innerHTML = '<span><b></b><br><small></small></span><b></b>'; d.querySelector('b').textContent = p.name; d.querySelector('small').textContent = 'Stock : ' + p.stock; d.lastChild.textContent = fmt(p.price);
      d.onclick = () => add(p.id); box.append(d);
    });
  }
  function add(id) { const p = prods.find(x => x.id == id); if (!p) return; const q = (cart[id] || 0) + 1; if (q > p.stock && !P.doc) { alert(P.i.stock + p.name + ' (' + p.stock + ')'); return; } cart[id] = q; draw(); }
  function draw() {
    const box = $('cart'); box.innerHTML = ''; let sub = 0, cnt = 0;
    Object.keys(cart).forEach(id => { const p = prods.find(x => x.id == id), q = cart[id]; sub += p.price * q; cnt += q;
      const r = document.createElement('div'); r.className = 'ci'; r.innerHTML = '<div class="n"><b></b><br><small></small></div><button>−</button><b>' + q + '</b><button>+</button>';
      r.querySelector('b').textContent = p.name; r.querySelector('small').textContent = fmt(p.price) + ' × ' + q + ' = ' + fmt(p.price * q);
      const bs = r.querySelectorAll('button'); bs[0].onclick = () => { if (--cart[id] <= 0) delete cart[id]; draw(); }; bs[1].onclick = () => add(id); box.append(r); });
    if (!cnt) box.innerHTML = '<p class="mu">' + P.i.empty + '</p>';
    const tax = Math.round(sub * P.tax / 100); $('sub').textContent = fmt(sub); $('tax').textContent = fmt(tax); $('tot').textContent = fmt(sub + tax); $('taxrow').hidden = !(P.tax > 0); $('cnt').textContent = cnt;
  }
  const payv = () => document.querySelector('input[name=pay]:checked').value;
  const sync = () => { $('creditbox').hidden = P.doc || payv() !== 'credit'; $('newc').hidden = $('cust').value != 0; };
  document.querySelectorAll('input[name=pay]').forEach(r => r.onchange = sync); $('cust').onchange = sync; $('q').addEventListener('input', list);
  $('q').addEventListener('keydown', e => { if (e.key !== 'Enter') return; const v = $('q').value.trim(), p = prods.find(x => x.barcode && x.barcode === v); if (p) { add(p.id); $('q').value = ''; list(); } });
  $('pay-btn').onclick = async () => {
    if (!Object.keys(cart).length) { alert(P.i.empty); return; }
    const cid = +$('cust').value, name = $('cname').value.trim(); if (!P.doc && payv() === 'credit' && !cid && !name) { alert(P.i.cust); return; }
    $('pay-btn').disabled = true;
    const r = await fetch(P.create, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF': P.csrf }, body: JSON.stringify({ doc: P.doc || '', items: Object.keys(cart).map(id => ({ id: +id, qty: cart[id] })), payment: payv(), customer_id: cid, customer_name: name, customer_phone: $('cphone').value.trim(), paid: +$('paid').value || 0, due_date: $('due').value }) }).then(x => x.json()).catch(() => ({ ok: false, error: P.i.err }));
    if (r.ok) location = P.view + '&id=' + r.id; else { alert(r.error || P.i.err); $('pay-btn').disabled = false; }
  };
  if (P.doc) { $('pay').hidden = true; $('creditbox').hidden = true; const lb = $('pay').previousElementSibling; if (lb) lb.hidden = true; }
  sync(); list(); draw();
})();
