<?php
/**
 * Claude のタスク・社長が結果を読む入口（Web）。APIキー必須。
 *   ?key=…                    → latest.json（4社まとめ）
 *   ?key=…&refresh=AX4DE3B7   → その会社を今すぐ取り直してから返す（all で4社）
 *   ?key=…&log=1              → 実行ログの末尾
 *   ?key=…&raw=AX4DE3B7_getlist → 調査用の生データ
 */
require __DIR__ . '/lib.php';
header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex');
header('Cache-Control: no-store');

function out($a, $code = 200) {
  http_response_code($code);
  echo json_encode($a, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
  exit;
}

try {
  $cfg = gp_config();
} catch (Exception $e) {
  out(['ok' => false, 'error' => $e->getMessage()], 500);
}
$key = (string)($_GET['key'] ?? '');
if ($key === '' || !hash_equals((string)$cfg['api_key'], $key)) out(['ok' => false, 'error' => 'key'], 403);

$dir = gp_data_dir();

if (isset($_GET['log'])) {
  $f = "$dir/run.log";
  $lines = is_file($f) ? array_slice(file($f, FILE_IGNORE_NEW_LINES), -80) : [];
  out(['ok' => true, 'log' => $lines]);
}

if (isset($_GET['raw'])) {
  $n = preg_replace('/[^A-Za-z0-9_]/', '', (string)$_GET['raw']);
  $f = "$dir/raw/$n.json";
  if (!is_file($f)) out(['ok' => false, 'error' => 'not found'], 404);
  header('Content-Type: application/json; charset=utf-8');
  readfile($f);
  exit;
}

if (isset($_GET['refresh'])) {
  set_time_limit(290);
  ignore_user_abort(true);
  $r = (string)$_GET['refresh'];
  $codes = $r === 'all' ? null : array_values(array_intersect(explode(',', $r), array_keys(GP_TENANTS)));
  if ($r !== 'all' && !$codes) out(['ok' => false, 'error' => 'refresh には会社コードか all を指定'], 400);
  gp_log('--- API refresh ' . $r);
  $summary = gp_run($codes);
  $all = gp_build_latest();
  $all['ok'] = true;
  $all['refresh'] = $summary;
  out($all);
}

$f = "$dir/latest.json";
$all = is_file($f) ? json_decode(file_get_contents($f), true) : gp_build_latest();
$all['ok'] = true;
out($all);
