<?php
/** Montants en lettres : français, anglais, espagnol. */
function words_fr(int $n): string {
    $U = ['zéro','un','deux','trois','quatre','cinq','six','sept','huit','neuf','dix','onze','douze','treize','quatorze','quinze','seize','dix-sept','dix-huit','dix-neuf'];
    $T = ['','','vingt','trente','quarante','cinquante','soixante'];
    $b100 = function (int $n, bool $last) use ($U, $T) {
        if ($n < 20) return $U[$n]; $t = intdiv($n, 10); $u = $n % 10;
        if ($t <= 6) return $u === 0 ? $T[$t] : $T[$t] . ($u === 1 ? ' et un' : '-' . $U[$u]);
        if ($t === 7) return 'soixante' . ($u === 1 ? ' et onze' : '-' . $U[10 + $u]);
        if ($n === 80) return $last ? 'quatre-vingts' : 'quatre-vingt';
        return 'quatre-vingt-' . $U[$n - 80];
    };
    $b1000 = function (int $n, bool $last) use ($U, $b100) {
        $h = intdiv($n, 100); $r = $n % 100;
        if ($h === 0) return $b100($r, $last);
        $head = $h === 1 ? 'cent' : $U[$h] . ' cent';
        if ($r === 0) return ($h > 1 && $last) ? $head . 's' : $head;
        return $head . ' ' . $b100($r, $last);
    };
    if ($n === 0) return 'zéro'; if ($n < 0) return 'moins ' . words_fr(-$n);
    $o = ''; $mm = intdiv($n, 1000000000); $n %= 1000000000; $mi = intdiv($n, 1000000); $n %= 1000000; $th = intdiv($n, 1000); $n %= 1000;
    if ($mm) $o .= $b1000($mm, false) . ($mm > 1 ? ' milliards ' : ' milliard ');
    if ($mi) $o .= $b1000($mi, false) . ($mi > 1 ? ' millions ' : ' million ');
    if ($th) $o .= ($th === 1 ? 'mille ' : $b1000($th, false) . ' mille ');
    if ($n) $o .= $b1000($n, true);
    return trim($o);
}
function words_en(int $n): string {
    $U = ['zero','one','two','three','four','five','six','seven','eight','nine','ten','eleven','twelve','thirteen','fourteen','fifteen','sixteen','seventeen','eighteen','nineteen'];
    $T = ['','','twenty','thirty','forty','fifty','sixty','seventy','eighty','ninety'];
    $g3 = function (int $n) use ($U, $T) {
        $o = ''; if ($n >= 100) { $o .= $U[intdiv($n, 100)] . ' hundred'; $n %= 100; if ($n) $o .= ' '; }
        if ($n >= 20) { $o .= $T[intdiv($n, 10)] . ($n % 10 ? '-' . $U[$n % 10] : ''); } elseif ($n) $o .= $U[$n];
        return $o;
    };
    if ($n === 0) return 'zero'; if ($n < 0) return 'minus ' . words_en(-$n);
    $names = ['', ' thousand', ' million', ' billion']; $parts = []; $i = 0;
    while ($n > 0) { $g = $n % 1000; if ($g) array_unshift($parts, $g3($g) . $names[$i]); $n = intdiv($n, 1000); $i++; }
    return implode(' ', $parts);
}
function words_es(int $n): string {
    $U = ['cero','uno','dos','tres','cuatro','cinco','seis','siete','ocho','nueve','diez','once','doce','trece','catorce','quince','dieciséis','diecisiete','dieciocho','diecinueve','veinte','veintiuno','veintidós','veintitrés','veinticuatro','veinticinco','veintiséis','veintisiete','veintiocho','veintinueve'];
    $T = ['','','','treinta','cuarenta','cincuenta','sesenta','setenta','ochenta','noventa'];
    $H = ['','ciento','doscientos','trescientos','cuatrocientos','quinientos','seiscientos','setecientos','ochocientos','novecientos'];
    $g3 = function (int $n) use ($U, $T, $H) {
        if ($n === 100) return 'cien'; $o = '';
        if ($n >= 100) { $o .= $H[intdiv($n, 100)]; $n %= 100; if ($n) $o .= ' '; }
        if ($n >= 30) { $o .= $T[intdiv($n, 10)] . ($n % 10 ? ' y ' . $U[$n % 10] : ''); } elseif ($n || $o === '') $o .= $U[$n];
        return $o;
    };
    $ap = function (string $s) { if (str_ends_with($s, 'veintiuno')) return substr($s, 0, -9) . 'veintiún'; if ($s === 'uno' || str_ends_with($s, ' uno')) return substr($s, 0, -3) . 'un'; return $s; };
    if ($n === 0) return 'cero'; if ($n < 0) return 'menos ' . words_es(-$n);
    $o = ''; $mm = intdiv($n, 1000000000); $n %= 1000000000; $mi = intdiv($n, 1000000); $n %= 1000000; $th = intdiv($n, 1000); $n %= 1000;
    if ($mm) $o .= $mm === 1 ? 'mil millones ' : $ap($g3($mm)) . ' mil millones ';
    if ($mi) $o .= $mi === 1 ? 'un millón ' : $ap($g3($mi)) . ' millones ';
    if ($th) $o .= $th === 1 ? 'mil ' : $ap($g3($th)) . ' mil ';
    if ($n) $o .= $g3($n);
    return trim($o);
}
function currency_words(string $c, string $lang): string {
    $m = ['XAF' => ['francs CFA', 'CFA francs', 'francos CFA'], 'XOF' => ['francs CFA', 'CFA francs', 'francos CFA'], 'GNF' => ['francs guinéens', 'Guinean francs', 'francos guineanos'],
          'CDF' => ['francs congolais', 'Congolese francs', 'francos congoleños'], 'KMF' => ['francs comoriens', 'Comorian francs', 'francos comorenses'],
          'EUR' => ['euros', 'euros', 'euros'], 'USD' => ['dollars US', 'US dollars', 'dólares estadounidenses']];
    $i = ['fr' => 0, 'en' => 1, 'es' => 2][$lang] ?? 0;
    return ($m[strtoupper($c)] ?? [$c, $c, $c])[$i];
}
/** Ex. « Mille deux cents francs CFA ». */
function amount_words(float $v, string $cur, string $lang = 'fr'): string {
    $n = (int)round($v);
    $w = $lang === 'en' ? words_en($n) : ($lang === 'es' ? words_es($n) : words_fr($n));
    if ($lang === 'es') { $w = preg_replace('/veintiuno$/', 'veintiún', preg_replace('/(?<![a-z])uno$/', 'un', $w)); if ($n > 0 && $n % 1000000 === 0) $w .= ' de'; }
    $w .= ' ' . currency_words($cur, $lang);
    return mb_strtoupper(mb_substr($w, 0, 1)) . mb_substr($w, 1);
}
