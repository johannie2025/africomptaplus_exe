<?php
// Exécuté par le lanceur à chaque démarrage (CLI) : crée la base et importe les schémas.
// Idempotent : les schémas ne sont réimportés que s'ils ont changé.
$port = (int)getenv('ACP_DB_PORT'); $pass = (string)getenv('ACP_DB_PASS');
$app = (string)getenv('ACP_APP_DIR'); $data = (string)getenv('ACP_DATA_DIR');

$pdo = null; $err = null;
for ($i = 0; $i < 40 && !$pdo; $i++) {
    try { $pdo = new PDO("mysql:host=127.0.0.1;port=$port;charset=utf8mb4", 'root', $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); }
    catch (Throwable $e) { $err = $e; usleep(500000); }
}
if (!$pdo) { fwrite(STDERR, 'Connexion MariaDB impossible : ' . ($err ? $err->getMessage() : '?') . "\n"); exit(2); }

$pdo->exec('CREATE DATABASE IF NOT EXISTS `africompta` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$pdo->exec('USE `africompta`');

$files = ['schema.sql', 'schema_ai.sql', 'schema_bot.sql', 'schema_web.sql', 'schema_web2.sql', 'schema_web3.sql'];
$all = ''; foreach ($files as $f) { $p = "$app/sql/$f"; if (is_file($p)) $all .= file_get_contents($p); }
$hash = md5($all); $hf = "$data/.schema_hash";

if (!is_file($hf) || trim((string)file_get_contents($hf)) !== $hash) {
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($files as $f) {
        $p = "$app/sql/$f"; if (!is_file($p)) continue;
        foreach (sql_statements(file_get_contents($p)) as $st) {
            try { $pdo->exec($st); }
            catch (PDOException $e) {
                $code = (int)($e->errorInfo[1] ?? 0);
                if (in_array($code, [1050, 1060, 1061, 1062], true)) continue; // déjà présent
                fwrite(STDERR, "Erreur SQL dans $f : " . $e->getMessage() . "\n" . substr($st, 0, 200) . "\n"); exit(3);
            }
        }
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    file_put_contents($hf, $hash);
}

// 1er lancement : aucun utilisateur → le lanceur ouvre la page d'activation de la licence.
try { if ((int)$pdo->query('SELECT COUNT(*) FROM w_users')->fetchColumn() === 0) echo "FIRST_RUN=1\n"; } catch (Throwable $e) {}
echo "SETUP_OK\n";

function sql_statements(string $sql): array {
    $out = []; $buf = ''; $n = strlen($sql); $q = null;
    for ($i = 0; $i < $n; $i++) {
        $c = $sql[$i];
        if ($q !== null) {
            $buf .= $c;
            if ($c === '\\' && $q !== '`' && $i + 1 < $n) { $buf .= $sql[++$i]; continue; }
            if ($c === $q) { if ($i + 1 < $n && $sql[$i + 1] === $q) { $buf .= $sql[++$i]; continue; } $q = null; }
            continue;
        }
        if ($c === "'" || $c === '"' || $c === '`') { $q = $c; $buf .= $c; continue; }
        if (($c === '-' && $i + 1 < $n && $sql[$i + 1] === '-' && ($i + 2 >= $n || ctype_space($sql[$i + 2]))) || $c === '#') {
            while ($i < $n && $sql[$i] !== "\n") $i++; $buf .= "\n"; continue;
        }
        if ($c === '/' && $i + 1 < $n && $sql[$i + 1] === '*') {
            $e = strpos($sql, '*/', $i + 2); $i = $e === false ? $n : $e + 1; continue;
        }
        if ($c === ';') { if (trim($buf) !== '') $out[] = $buf; $buf = ''; continue; }
        $buf .= $c;
    }
    if (trim($buf) !== '') $out[] = $buf;
    return $out;
}
