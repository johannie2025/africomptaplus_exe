<?php
function mod_lang(): void {
    $l = (string)($_GET['l'] ?? ''); lang_set($l);
    if (me()) db()->prepare('UPDATE w_users SET lang=? WHERE id=?')->execute([$l, me()['id']]);
    $back = $_SERVER['HTTP_REFERER'] ?? url();
    redirect(preg_match('#^https?://#', $back) && parse_url($back, PHP_URL_HOST) === ($_SERVER['HTTP_HOST'] ?? '') ? $back : url());
}
