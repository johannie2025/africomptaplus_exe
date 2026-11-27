<?php
function mod_pos(): void {
    $l = lid(); $pdo = db();
    $p = $pdo->prepare('SELECT id,name,barcode,sell_price AS price,stock FROM w_products WHERE license_id=? ORDER BY name LIMIT 3000'); $p->execute([$l]);
    $c = $pdo->prepare('SELECT id,name,phone FROM w_customers WHERE license_id=? ORDER BY name LIMIT 2000'); $c->execute([$l]);
    $cfg = ['products' => $p->fetchAll(), 'customers' => $c->fetchAll(), 'tax' => tax_rate(), 'cur' => setting('currency', 'XAF'), 'taxLabel' => setting('tax_label', 'TVA'),
            'create' => url('sales', ['a' => 'create']), 'view' => url('sales', ['a' => 'view']), 'csrf' => csrf_token(),
            'i' => ['empty' => t('Panier vide'), 'stock' => t('Stock insuffisant : '), 'cust' => t('Sélectionnez un client pour une vente à crédit'), 'err' => t('Erreur enregistrement vente')]];
    $doc = in_array($_GET['doc'] ?? '', ['quote', 'proforma'], true) ? $_GET['doc'] : '';
    if ($doc) { $cfg['doc'] = $doc; $cfg['create'] = url('documents', ['a' => 'create']); $cfg['view'] = url('documents', ['a' => 'view']); }
    $title = $doc === 'quote' ? t('Nouveau devis') : ($doc === 'proforma' ? t('Nouvelle facture proforma') : t('Point de vente'));
    render_head($title, $doc ? 'documents' : 'pos'); ?>
<h1><?= e($title) ?></h1>
<div class="pos">
<div class="card"><input id="q" class="big" placeholder="<?= e(t('Rechercher un produit')) ?> / <?= e(t('Scanner ou saisir')) ?>" autocomplete="off"> <button type="button" class="btn sm" data-scan="#q">📷</button>
<div id="plist" class="plist"></div></div>
<div class="card"><h2><?= e(t('Articles (')) ?><span id="cnt">0</span>)</h2><div id="cart"></div>
<div class="totals"><div><?= e(t('Sous-total')) ?><b id="sub">0</b></div><div id="taxrow"><?= e(setting('tax_label', 'TVA')) ?><b id="tax">0</b></div><div class="grand"><?= e(t('Total')) ?><b id="tot">0</b></div></div>
<label><?= e(t('Mode de paiement')) ?></label>
<div class="seg" id="pay"><label><input type="radio" name="pay" value="cash" checked><span><?= e(t('Espèces')) ?></span></label><label><input type="radio" name="pay" value="mobile_money"><span>Mobile Money</span></label><label><input type="radio" name="pay" value="credit"><span><?= e(t('Crédit')) ?></span></label></div>
<label><?= e(t('Client')) ?></label><select id="cust"><option value="0"><?= e(t('Client Passager')) ?></option></select>
<div id="newc" class="two"><input id="cname" placeholder="<?= e(t('Nom du client *')) ?>"><input id="cphone" placeholder="<?= e(t('Téléphone')) ?>"></div>
<div id="creditbox" hidden><div class="two"><div><label><?= e(t('Montant payé maintenant')) ?></label><input id="paid" type="number" min="0" value="0"></div><div><label><?= e(t('Échéance du crédit (AAAA-MM-JJ)')) ?></label><input id="due" type="date"></div></div></div>
<button id="pay-btn" class="btn cta big"><?= e($doc ? t('Enregistrer') : t('Encaisser')) ?></button></div></div>
<script>window.POS=<?= json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script><script src="assets/pos.js?v=3"></script>
<?php render_foot(); }
