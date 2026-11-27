<?php
require_once __DIR__ . '/../inc/auth.php'; require_once __DIR__ . '/../inc/layout.php'; require_once __DIR__ . '/../inc/ai.php';
require_login(); $pdo = db();
$presets = ai_presets();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check(); $do = (string)($_POST['do'] ?? ''); $pid = (int)($_POST['pid'] ?? 0);
    try {
        if ($do === 'add_provider') {
            $preset = isset($presets[$_POST['preset'] ?? '']) ? $_POST['preset'] : 'custom';
            $pr = $presets[$preset];
            $name = trim((string)$_POST['name']) ?: $pr[0];
            $base = rtrim(trim((string)$_POST['base_url']) ?: $pr[2], '/');
            $key = trim((string)$_POST['api_key']);
            if (!preg_match('#^https://#i', $base)) throw new RuntimeException('URL de base HTTPS requise.');
            if ($key === '') throw new RuntimeException('Clé API requise.');
            $pdo->prepare('INSERT INTO ai_providers (name,preset,kind,base_url,api_key_enc,free_tier,enabled,created_at,updated_at) VALUES (?,?,?,?,?,?,1,?,?)')
                ->execute([substr($name, 0, 80), $preset, $pr[1], $base, ai_enc($key), !empty($_POST['free_tier']) ? 1 : 0, now(), now()]);
            flash('Fournisseur ajouté. Cliquez sur « Récupérer les modèles ».');
        } elseif ($do === 'update_key') {
            $key = trim((string)$_POST['api_key']);
            if ($key === '') throw new RuntimeException('Clé vide.');
            $pdo->prepare('UPDATE ai_providers SET api_key_enc=?, updated_at=? WHERE id=?')->execute([ai_enc($key), now(), $pid]);
            $pdo->prepare('UPDATE ai_models SET cooldown_until=NULL, fail_count=0, last_error=\'\' WHERE provider_id=?')->execute([$pid]);
            flash('Clé mise à jour, pauses réinitialisées.');
        } elseif ($do === 'toggle_provider') {
            $pdo->prepare('UPDATE ai_providers SET enabled=1-enabled WHERE id=?')->execute([$pid]); flash('État modifié.');
        } elseif ($do === 'free_tier') {
            $pdo->prepare('UPDATE ai_providers SET free_tier=1-free_tier WHERE id=?')->execute([$pid]); flash('Palier gratuit modifié (appliqué aux prochains imports).');
        } elseif ($do === 'delete_provider') {
            $pdo->prepare('DELETE FROM ai_providers WHERE id=?')->execute([$pid]); flash('Fournisseur supprimé.');
        } elseif ($do === 'fetch_models') {
            $st = $pdo->prepare('SELECT * FROM ai_providers WHERE id=?'); $st->execute([$pid]); $p = $st->fetch();
            if (!$p) throw new RuntimeException('Fournisseur introuvable.');
            $n = ai_sync_models($pid, ai_list_models($p));
            flash("$n modèle(s) récupéré(s). Cochez ceux à utiliser puis enregistrez.");
        } elseif ($do === 'save_models') {
            $en = $_POST['enabled'] ?? []; $fr = $_POST['free'] ?? []; $pr = $_POST['prio'] ?? [];
            $ids = $pdo->prepare('SELECT id FROM ai_models WHERE provider_id=?'); $ids->execute([$pid]);
            $up = $pdo->prepare('UPDATE ai_models SET enabled=?, is_free=?, priority=? WHERE id=? AND provider_id=?');
            foreach ($ids->fetchAll(PDO::FETCH_COLUMN) as $mid) {
                $up->execute([isset($en[$mid]) ? 1 : 0, isset($fr[$mid]) ? 1 : 0, max(1, min(999, (int)($pr[$mid] ?? 100))), $mid, $pid]);
            }
            flash('Modèles enregistrés.');
        } elseif ($do === 'reset_cooldown') {
            $pdo->prepare('UPDATE ai_models SET cooldown_until=NULL, fail_count=0, last_error=\'\' WHERE provider_id=? OR ?=0')->execute([$pid, $pid]);
            flash('Pauses réinitialisées.');
        } elseif ($do === 'save_settings') {
            $prefer = in_array($_POST['prefer'] ?? '', ['free_first', 'paid_first', 'priority'], true) ? $_POST['prefer'] : 'free_first';
            $vals = ['prefer' => $prefer,
                'cooldown_base' => (string)max(5, min(3600, (int)$_POST['cooldown_base'])),
                'cooldown_max' => (string)max(30, min(86400, (int)$_POST['cooldown_max'])),
                'max_attempts' => (string)max(1, min(10, (int)$_POST['max_attempts'])),
                'timeout' => (string)max(5, min(120, (int)$_POST['timeout']))];
            $st = $pdo->prepare('INSERT INTO ai_settings (skey,svalue) VALUES (?,?) ON DUPLICATE KEY UPDATE svalue=VALUES(svalue)');
            foreach ($vals as $k => $v) $st->execute([$k, $v]);
            flash('Réglages du routeur enregistrés.');
        } elseif ($do === 'test') {
            $r = ai_complete([['role' => 'user', 'content' => 'Réponds uniquement par le mot : OK']], ['max_tokens' => 20]);
            $_SESSION['ai_test'] = ['ok' => true, 'model' => $r['provider'] . ' / ' . $r['model'], 'text' => $r['content'], 'log' => $r['attempts']];
            flash('Test réussi.');
        }
    } catch (AiError $e) {
        if ($do === 'test') $_SESSION['ai_test'] = ['ok' => false, 'model' => '', 'text' => $e->getMessage(), 'log' => $e->attempts];
        flash($e->getMessage(), 'err');
    } catch (Throwable $e) { flash($e->getMessage(), 'err'); }
    redirect('ai.php' . ($pid ? '#p' . $pid : ''));
}

$S = ai_settings();
$providers = $pdo->query('SELECT p.*, (SELECT COUNT(*) FROM ai_models m WHERE m.provider_id=p.id) AS n_models,
    (SELECT COUNT(*) FROM ai_models m WHERE m.provider_id=p.id AND m.enabled=1) AS n_on FROM ai_providers p ORDER BY p.id')->fetchAll();
$test = $_SESSION['ai_test'] ?? null; unset($_SESSION['ai_test']);
$nowS = now();
page_head('Intelligence artificielle', 'ai'); ?>
<h1>IA — fournisseurs & modèles</h1>

<div class="card">
<h2>Routeur : rotation, pause (cooldown) et repli</h2>
<p style="color:var(--mu);margin:0 0 8px">À chaque requête, le routeur prend le modèle activé disponible le mieux classé ; à priorité égale, il fait tourner les modèles (le moins récemment utilisé d'abord). Un modèle qui échoue (quota, 429, panne) est mis en pause avec un délai qui double à chaque échec consécutif, puis le routeur bascule sur le suivant. Quota journalier épuisé → pause jusqu'à minuit UTC.</p>
<form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="do" value="save_settings">
<div><label>Ordre de préférence</label><select name="prefer">
<?php foreach (['free_first' => 'Gratuits d\'abord, payants en secours', 'paid_first' => 'Payants d\'abord, gratuits en secours', 'priority' => 'Priorité seule (ignorer gratuit/payant)'] as $k => $v) echo '<option value="' . $k . '"' . ($S['prefer'] === $k ? ' selected' : '') . '>' . e($v) . '</option>'; ?></select></div>
<div><label>Pause de base (s)</label><input type="number" name="cooldown_base" value="<?= e($S['cooldown_base']) ?>"></div>
<div><label>Pause maximale (s)</label><input type="number" name="cooldown_max" value="<?= e($S['cooldown_max']) ?>"></div>
<div><label>Essais max par requête</label><input type="number" name="max_attempts" value="<?= e($S['max_attempts']) ?>"></div>
<div><label>Délai d'attente (s)</label><input type="number" name="timeout" value="<?= e($S['timeout']) ?>"></div>
<div style="align-self:end"><button class="btn">Enregistrer</button></div></form>
<div class="acts" style="margin-top:12px">
<form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="test"><button class="btn cta">Tester le routeur</button></form>
<form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="reset_cooldown"><input type="hidden" name="pid" value="0"><button class="btn ghost">Réinitialiser toutes les pauses</button></form></div>
<?php if ($test): ?><div class="flash <?= $test['ok'] ? 'ok' : 'err' ?>" style="margin-top:12px">
<?= $test['ok'] ? 'Répondu par <b>' . e($test['model']) . '</b> : « ' . e($test['text']) . ' »' : e($test['text']) ?>
<?php foreach ($test['log'] as $l) echo '<div style="font-weight:400;font-size:13px">↳ ' . e($l[0]) . ' — ' . e($l[1]) . '</div>'; ?></div><?php endif; ?>
</div>

<div class="card">
<h2>Ajouter un fournisseur</h2>
<form method="post" class="row" id="addp"><?= csrf_field() ?><input type="hidden" name="do" value="add_provider">
<div><label>Fournisseur</label><select name="preset" id="preset">
<?php foreach ($presets as $k => $v) echo '<option value="' . $k . '" data-url="' . e($v[2]) . '" data-free="' . $v[3] . '">' . e($v[0]) . '</option>'; ?></select></div>
<div><label>Nom affiché (optionnel)</label><input name="name"></div>
<div><label>URL de base API</label><input name="base_url" id="base_url" placeholder="https://…"></div>
<div><label>Clé API</label><input name="api_key" type="password" autocomplete="off" required></div>
<div><label>&nbsp;</label><label style="display:flex;gap:8px;align-items:center;margin:0"><input type="checkbox" name="free_tier" id="free_tier" style="width:auto"> Palier gratuit (rotation + cooldown)</label></div>
<div style="align-self:end"><button class="btn">Ajouter</button></div></form>
</div>

<?php foreach ($providers as $p): ?>
<div class="card" id="p<?= (int)$p['id'] ?>">
<div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;justify-content:space-between">
<h2 style="margin:0"><?= e($p['name']) ?> <span class="badge <?= $p['enabled'] ? 'ok' : 'ko' ?>"><?= $p['enabled'] ? 'Actif' : 'Désactivé' ?></span>
<?= $p['free_tier'] ? '<span class="badge wa">Gratuit</span>' : '' ?></h2>
<div class="acts">
<form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="fetch_models"><input type="hidden" name="pid" value="<?= (int)$p['id'] ?>"><button class="btn sm cta">Récupérer les modèles</button></form>
<form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="toggle_provider"><input type="hidden" name="pid" value="<?= (int)$p['id'] ?>"><button class="btn sm ghost"><?= $p['enabled'] ? 'Désactiver' : 'Activer' ?></button></form>
<form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="free_tier"><input type="hidden" name="pid" value="<?= (int)$p['id'] ?>"><button class="btn sm ghost">Gratuit : <?= $p['free_tier'] ? 'oui' : 'non' ?></button></form>
<form method="post" class="inline" onsubmit="return confirm('Supprimer ce fournisseur et ses modèles ?')"><?= csrf_field() ?><input type="hidden" name="do" value="delete_provider"><input type="hidden" name="pid" value="<?= (int)$p['id'] ?>"><button class="btn sm ko">Supprimer</button></form>
</div></div>
<p style="color:var(--mu);margin:6px 0"><?= e($p['kind']) ?> · <?= e($p['base_url']) ?> · <?= (int)$p['n_on'] ?>/<?= (int)$p['n_models'] ?> modèle(s) activé(s)<?= $p['models_fetched_at'] ? ' · liste du ' . e($p['models_fetched_at']) . ' UTC' : '' ?></p>
<form method="post" class="row" style="margin-bottom:8px"><?= csrf_field() ?><input type="hidden" name="do" value="update_key"><input type="hidden" name="pid" value="<?= (int)$p['id'] ?>">
<input name="api_key" type="password" placeholder="Nouvelle clé API" autocomplete="off"><div><button class="btn sm ghost">Changer la clé</button></div></form>
<?php
$ms = $pdo->prepare('SELECT * FROM ai_models WHERE provider_id=? ORDER BY enabled DESC, priority, model_id'); $ms->execute([$p['id']]); $ms = $ms->fetchAll();
if ($ms): ?>
<form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="save_models"><input type="hidden" name="pid" value="<?= (int)$p['id'] ?>">
<div class="acts" style="margin-bottom:8px"><input class="mfilter" data-t="t<?= (int)$p['id'] ?>" placeholder="Filtrer les modèles…" style="max-width:280px">
<button type="button" class="btn sm ghost" onclick="pickFree(<?= (int)$p['id'] ?>)">Cocher les gratuits</button>
<button class="btn sm">Enregistrer les modèles</button></div>
<div class="tw" style="max-height:420px;overflow:auto"><table id="t<?= (int)$p['id'] ?>"><tr><th>Actif</th><th>Modèle</th><th>Gratuit</th><th>Priorité</th><th>État</th></tr>
<?php foreach ($ms as $m):
  $cd = $m['cooldown_until'] && $m['cooldown_until'] > $nowS;
  $state = $cd ? '<span class="badge wa">Pause → ' . e(substr($m['cooldown_until'], 11, 5)) . ' UTC</span>' : ($m['enabled'] ? '<span class="badge ok">Prêt</span>' : '');
  if ($m['last_error']) $state .= ' <small style="color:var(--mu)" title="' . e($m['last_error']) . '">' . e(mb_substr($m['last_error'], 0, 50)) . '</small>';
?><tr data-n="<?= e(strtolower($m['model_id'] . ' ' . $m['label'])) ?>">
<td><input type="checkbox" name="enabled[<?= (int)$m['id'] ?>]" value="1" style="width:auto" <?= $m['enabled'] ? 'checked' : '' ?>></td>
<td><b><?= e($m['model_id']) ?></b><?= $m['label'] && $m['label'] !== $m['model_id'] ? '<br><small style="color:var(--mu)">' . e($m['label']) . '</small>' : '' ?></td>
<td><input type="checkbox" class="fr" name="free[<?= (int)$m['id'] ?>]" value="1" style="width:auto" <?= $m['is_free'] ? 'checked' : '' ?>></td>
<td><input type="number" name="prio[<?= (int)$m['id'] ?>]" value="<?= (int)$m['priority'] ?>" style="width:80px"></td>
<td><?= $state ?><?= $m['ok_count'] ? ' <small style="color:var(--mu)">' . (int)$m['ok_count'] . ' ok</small>' : '' ?></td></tr>
<?php endforeach; ?></table></div></form>
<?php else: ?><p style="color:var(--mu)">Aucun modèle : cliquez sur « Récupérer les modèles ».</p><?php endif; ?>
</div>
<?php endforeach; ?>
<script>
const preset=document.getElementById('preset');
function applyPreset(){const o=preset.options[preset.selectedIndex];document.getElementById('base_url').placeholder=o.dataset.url||'https://…';document.getElementById('free_tier').checked=o.dataset.free==='1';}
preset.addEventListener('change',applyPreset);applyPreset();
document.querySelectorAll('.mfilter').forEach(i=>i.addEventListener('input',()=>{const q=i.value.toLowerCase();document.querySelectorAll('#'+i.dataset.t+' tr[data-n]').forEach(r=>r.style.display=r.dataset.n.includes(q)?'':'none');}));
function pickFree(id){document.querySelectorAll('#t'+id+' tr[data-n]').forEach(r=>{if(r.style.display!=='none'&&r.querySelector('.fr').checked)r.querySelector('input[name^=enabled]').checked=true;});}
</script>
<?php page_foot();
