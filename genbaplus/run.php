<?php
/**
 * cron から実行する入口（CLI専用）。
 * 例: /usr/bin/php8.3 /home/xs990294/u-sin.jp/public_html/form/genbaplus/run.php
 * 会社を絞る場合: ... run.php AX4DE3B7
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require __DIR__ . '/lib.php';
set_time_limit(0);
$codes = array_slice($argv, 1);
try {
  gp_log('--- cron 開始 ' . ($codes ? implode(',', $codes) : '4社'));
  $s = gp_run($codes ?: null);
  gp_log('--- cron 終了 ' . json_encode($s, JSON_UNESCAPED_UNICODE));
} catch (Exception $e) {
  gp_log('--- cron 失敗 ' . $e->getMessage());
  exit(1);
}
