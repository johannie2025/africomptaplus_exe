<?php
/** Actions POST admin sur une licence (CSRF déjà vérifié). */
function license_action(PDO $pdo, string $act, int $id): string {
    $st = $pdo->prepare('SELECT * FROM licenses WHERE id=?'); $st->execute([$id]); $l = $st->fetch();
    if (!$l) return 'Licence introuvable.';
    $t = now();
    switch ($act) {
        case 'revoke':   $pdo->prepare("UPDATE licenses SET status='revoked',updated_at=? WHERE id=?")->execute([$t, $id]); return 'Licence révoquée.';
        case 'restore':  $pdo->prepare("UPDATE licenses SET status='active',updated_at=? WHERE id=?")->execute([$t, $id]); return 'Licence réactivée.';
        case 'ext30': case 'ext365':
            if ($l['expires_at'] === null) return 'Licence perpétuelle : rien à prolonger.';
            $days = $act === 'ext30' ? 30 : 365;
            $base = max(strtotime($l['expires_at'] . ' UTC'), time());
            $pdo->prepare('UPDATE licenses SET expires_at=?,updated_at=? WHERE id=?')->execute([gmdate('Y-m-d H:i:s', $base + $days * 86400), $t, $id]);
            return "Licence prolongée de $days jours.";
        case 'reset':    $pdo->prepare('DELETE FROM activations WHERE license_id=?')->execute([$id]); return 'Appareils réinitialisés.';
        case 'delete':   $pdo->prepare('DELETE FROM activations WHERE license_id=?')->execute([$id]); $pdo->prepare('DELETE FROM licenses WHERE id=?')->execute([$id]); return 'Licence supprimée.';
    }
    return 'Action inconnue.';
}
