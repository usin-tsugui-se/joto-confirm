<?php
// 現場Plus 接続テスト（2026-11-14 臨時・ログインはしない）。確認後に削除する。
header('Content-Type: text/plain; charset=utf-8');
header('X-Robots-Tag: noindex');

$targets = [
  'https://apg.kensetsu-cloud.jp/main/dyapp/a/AX4DE3B7/assoclogin',
  'https://dx1.kensetsu-cloud.jp/',
  'https://www.kensetsu-cloud.jp/',
  'https://api.ipify.org?format=json',
];

echo "実行時刻: " . date('Y-m-d H:i:s') . "\n";
echo "サーバー: " . php_uname('n') . "\n\n";

foreach ($targets as $u) {
  $ch = curl_init($u);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_CONNECTTIMEOUT => 15,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_USERAGENT => 'Mozilla/5.0 (connection test)',
  ]);
  $body = curl_exec($ch);
  $info = curl_getinfo($ch);
  $err = curl_error($ch);
  curl_close($ch);
  printf("%s\n  HTTP %s / 接続先IP %s / connect %.2fs / tls %.2fs / total %.2fs %s\n",
    $u, $info['http_code'], $info['primary_ip'], $info['connect_time'],
    $info['appconnect_time'], $info['total_time'], $err ? "エラー: $err" : '');
  if (strpos($u, 'ipify') !== false && $body) echo "  接続元IP: $body\n";
  echo "\n";
}
