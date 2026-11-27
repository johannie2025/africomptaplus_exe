<?php
/** Mini générateur PDF (texte + traits, Helvetica, WinAnsi) — sans dépendance. */
class MiniPdf {
    private array $pages = []; private string $cur = ''; public float $w, $h;
    public function __construct(float $w = 420, float $h = 595) { $this->w = $w; $this->h = $h; }
    private static function enc(string $s): string {
        $t = @iconv('UTF-8', 'CP1252//TRANSLIT//IGNORE', $s);
        $t = $t === false ? preg_replace('/[^\x20-\x7E]/', '?', $s) : $t;
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $t);
    }
    public function text(float $x, float $y, string $s, float $size = 10, bool $bold = false, string $align = 'L', array $rgb = [0, 0, 0]): void {
        $y = $this->h - $y;
        if ($align !== 'L') {
            $wd = $this->width($s, $size, $bold);
            $x = $align === 'R' ? $x - $wd : $x - $wd / 2;
        }
        $this->cur .= sprintf("BT %.2f %.2f %.2f rg /%s %.1f Tf %.2f %.2f Td (%s) Tj ET\n", $rgb[0], $rgb[1], $rgb[2], $bold ? 'F2' : 'F1', $size, $x, $y, self::enc($s));
    }
    public function width(string $s, float $size, bool $bold = false): float {   // approximation Helvetica
        return strlen(@iconv('UTF-8', 'CP1252//TRANSLIT//IGNORE', $s) ?: $s) * $size * ($bold ? 0.56 : 0.52);
    }
    public function fit(string $s, float $maxW, float $size, bool $bold = false): string {
        while ($s !== '' && $this->width($s, $size, $bold) > $maxW) $s = mb_substr($s, 0, mb_strlen($s) - 1);
        return $s;
    }
    public function line(float $x1, float $y1, float $x2, float $y2, float $wd = 0.5, array $rgb = [0.8, 0.8, 0.8]): void {
        $this->cur .= sprintf("%.2f %.2f %.2f RG %.2f w %.2f %.2f m %.2f %.2f l S\n", $rgb[0], $rgb[1], $rgb[2], $wd, $x1, $this->h - $y1, $x2, $this->h - $y2);
    }
    public function rect(float $x, float $y, float $w, float $h, array $rgb): void {
        $this->cur .= sprintf("%.2f %.2f %.2f rg %.2f %.2f %.2f %.2f re f\n", $rgb[0], $rgb[1], $rgb[2], $x, $this->h - $y - $h, $w, $h);
    }
    public function newPage(): void { $this->pages[] = $this->cur; $this->cur = ''; }
    public function output(): string {
        $this->pages[] = $this->cur; $o = []; $n = count($this->pages);
        $o[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $kids = ''; for ($i = 0; $i < $n; $i++) $kids .= (5 + $i * 2) . ' 0 R ';
        $o[2] = "<< /Type /Pages /Kids [$kids] /Count $n >>";
        $o[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $o[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        foreach ($this->pages as $i => $c) {
            $pn = 5 + $i * 2;
            $o[$pn] = sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.0f %.0f] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>', $this->w, $this->h, $pn + 1);
            $o[$pn + 1] = "<< /Length " . strlen($c) . " >>\nstream\n$c\nendstream";
        }
        $pdf = "%PDF-1.4\n"; $off = [];
        foreach ($o as $id => $body) { $off[$id] = strlen($pdf); $pdf .= "$id 0 obj\n$body\nendobj\n"; }
        $x = strlen($pdf); $cnt = count($o) + 1;
        $pdf .= "xref\n0 $cnt\n0000000000 65535 f \n";
        foreach ($o as $id => $_) $pdf .= sprintf("%010d 00000 n \n", $off[$id]);
        return $pdf . "trailer\n<< /Size $cnt /Root 1 0 R >>\nstartxref\n$x\n%%EOF";
    }
}

function money(float $v, string $cur): string { return number_format($v, 0, ',', ' ') . ' ' . $cur; }

/** Facture / devis / proforma PDF. $o['title'] (défaut FACTURE), $o['words_label'], $o['valid_until'] facultatifs. */
function bot_invoice_pdf(array $acc, array $o, array $items): string {
    require_once __DIR__ . '/words.php';
    $tr = function (string $s) { return function_exists('t') ? t($s) : $s; };
    $lg = function_exists('lang_pick') ? lang_pick() : 'fr';
    $p = new MiniPdf(); $cur = $acc['currency']; $brand = [0.05, 0.40, 0.30];
    $title = $tr($o['title'] ?? 'FACTURE');
    $head = function () use ($p, $acc, $o, $brand, $tr, $title) {
        $p->rect(0, 0, $p->w, 70, $brand);
        $p->text(24, 32, $acc['shop_name'] ?: 'Boutique', 17, true, 'L', [1, 1, 1]);
        $p->text(24, 52, $title . ' ' . $o['reference'], 10, false, 'L', [1, 1, 1]);
        $p->text($p->w - 24, 32, date('d/m/Y H:i', strtotime($o['created_at'] . ' UTC')), 9, false, 'R', [1, 1, 1]);
        $p->text(24, 92, $tr('Client') . ' : ' . ($o['customer_name'] ?: $tr('Client Passager')), 10, true);
        $p->text(24, 106, $tr('Téléphone') . ' : ' . ($o['phone'] ? '+' . ltrim((string)$o['phone'], '+') : '—'), 9);
        if (!empty($o['valid_until'])) $p->text($p->w - 24, 106, $tr('Validité : ') . $o['valid_until'], 9, false, 'R');
        $p->rect(24, 118, $p->w - 48, 18, [0.93, 0.96, 0.95]);
        $p->text(30, 130, $tr('Article'), 9, true); $p->text(235, 130, $tr('Qté'), 9, true, 'R'); $p->text(305, 130, 'P.U.', 9, true, 'R'); $p->text($p->w - 30, 130, 'Total', 9, true, 'R');
        return 148;
    };
    $y = $head();
    foreach ($items as $it) {
        if ($y > $p->h - 175) { $p->newPage(); $y = $head(); }
        $p->text(30, $y, $p->fit($it['name'], 180, 9), 9);
        $p->text(235, $y, (string)$it['qty'], 9, false, 'R');
        $p->text(305, $y, number_format($it['price'], 0, ',', ' '), 9, false, 'R');
        $p->text($p->w - 30, $y, number_format($it['price'] * $it['qty'], 0, ',', ' '), 9, false, 'R');
        $p->line(24, $y + 5, $p->w - 24, $y + 5); $y += 17;
    }
    $y += 6; $r = $p->w - 30;
    $p->text($r, $y, $tr('Sous-total') . ' : ' . money((float)$o['subtotal'], $cur), 9, false, 'R'); $y += 14;
    if ((float)$o['tax_amount'] > 0) { $p->text($r, $y, $acc['tax_label'] . ' : ' . money((float)$o['tax_amount'], $cur), 9, false, 'R'); $y += 14; }
    $p->text($r, $y + 4, 'TOTAL : ' . money((float)$o['total'], $cur), 13, true, 'R', $brand); $y += 26;
    $wl = $tr($o['words_label'] ?? 'Arrêtée la présente facture') . $tr(' à la somme de : ');
    $p->text(24, $y, $p->fit($wl, $p->w - 48, 8), 8); $y += 11;
    $p->text(24, $y, $p->fit(amount_words((float)$o['total'], $cur, $lg), $p->w - 48, 9, true), 9, true); $y += 18;
    if (!empty($o['payment'])) {
        $pm = ['cash' => $tr('Espèces'), 'mobile_money' => 'Mobile Money', 'credit' => $tr('Crédit')][$o['payment']] ?? $o['payment'];
        $p->text(24, $y, $tr('Paiement : ') . $pm, 9, true);
        if ($o['payment'] === 'mobile_money' && ($acc['momo_info'] ?? '') !== '') { $y += 13; $p->text(24, $y, $p->fit($acc['momo_info'], $p->w - 48, 8), 8); }
    }
    $p->text($p->w / 2, $p->h - 28, $tr('Merci de votre confiance') . ' — ' . ($acc['shop_name'] ?: ''), 8, false, 'C', [0.4, 0.4, 0.4]);
    return $p->output();
}

/** Reçu de règlement (dette client) PDF. */
function receipt_pdf(array $acc, array $r): string {
    require_once __DIR__ . '/words.php';
    $tr = function (string $s) { return function_exists('t') ? t($s) : $s; };
    $lg = function_exists('lang_pick') ? lang_pick() : 'fr';
    $p = new MiniPdf(420, 360); $brand = [0.05, 0.40, 0.30]; $cur = $acc['currency'];
    $p->rect(0, 0, $p->w, 64, $brand);
    $p->text(24, 30, $acc['shop_name'] ?: 'Boutique', 16, true, 'L', [1, 1, 1]);
    $p->text(24, 50, $tr('REÇU DE PAIEMENT') . ' ' . $r['reference'], 10, false, 'L', [1, 1, 1]);
    $p->text($p->w - 24, 30, date('d/m/Y H:i', strtotime($r['created_at'] . ' UTC')), 9, false, 'R', [1, 1, 1]);
    $y = 94;
    $p->text(24, $y, $tr('Client') . ' : ' . $r['customer_name'], 11, true); $y += 20;
    $p->text(24, $y, $tr('Réf. : ') . $r['sale_reference'], 10); $y += 22;
    $p->text(24, $y, $tr('MONTANT REÇU') . ' : ' . money((float)$r['amount'], $cur), 14, true, 'L', $brand); $y += 20;
    $p->text(24, $y, $p->fit(amount_words((float)$r['amount'], $cur, $lg), $p->w - 48, 9, true), 9, true); $y += 22;
    $p->text(24, $y, $tr('Paiement : ') . ($r['method'] === 'mobile_money' ? 'Mobile Money' : $tr('Espèces')), 10); $y += 16;
    $p->text(24, $y, $tr('Reste à payer') . ' : ' . money((float)$r['remaining'], $cur), 10, true, 'L', $r['remaining'] > 0 ? [0.78, 0.18, 0.18] : [0.05, 0.4, 0.3]);
    if ($r['remaining'] <= 0) $p->text($p->w - 24, $y, $tr('DETTE SOLDÉE'), 11, true, 'R', $brand);
    $p->text($p->w / 2, $p->h - 24, $tr('Signature du client') . ' ____________________', 8, false, 'C', [0.4, 0.4, 0.4]);
    return $p->output();
}
