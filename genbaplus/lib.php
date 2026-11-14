<?php
/**
 * 現場Plus 取得プログラム（XServer 実行用） 2026-11-14
 *
 * 背景: 2026-11-13 午後から、現場Plus（apg.kensetsu-cloud.jp）が国外・クラウドからの接続に応答しなくなった。
 *       国内の XServer からは接続できるため、ここでログイン・取得し、結果を JSON に保存する。
 *       Claude の定期タスクは api.php から JSON を読むだけ（現場Plusには直接ログインしない）。
 *
 * 置き場所
 *   プログラム : u-sin.jp/public_html/form/genbaplus/   （.htaccess で api.php 以外は Web 非公開）
 *   設定・データ: u-sin.jp/genbaplus_private/           （public_html の外 = Web から見えない）
 *     config.php  … ログインID・パスワード・APIキー（社長が作成・入力）
 *     data/       … latest.json（4社まとめ）、{会社コード}.json、cookies、raw（調査用）、run.log
 *
 * 現場Plus 側には何も書き込まない（読み取りのみ）。
 */

date_default_timezone_set('Asia/Tokyo');

const GP_HOST = 'https://apg.kensetsu-cloud.jp';
const GP_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';

// 4社（会社コード => [テナント番号, 会社名]）
const GP_TENANTS = [
  'AX4DD823' => ['5101603', 'タマホーム'],
  'AX572DB4' => ['5713332', 'ネクストイノベーション'],
  'AX57401F' => ['5718047', 'グッドホーム（リフォ）'],
  'AX4DE3B7' => ['5104567', '諸戸の家'],
];

// 工程表タスクのうち拾う名前
const GP_TASK_RE = '/上棟|建て?方|建方|レッカー|クレーン|ラフター/u';

function gp_private_dir() {
  // .../u-sin.jp/public_html/form/genbaplus → .../u-sin.jp/genbaplus_private
  return dirname(__DIR__, 3) . '/genbaplus_private';
}

function gp_data_dir() {
  $d = gp_private_dir() . '/data';
  foreach ([$d, "$d/cookies", "$d/raw"] as $x) {
    if (!is_dir($x)) @mkdir($x, 0700, true);
  }
  return $d;
}

function gp_config() {
  $f = gp_private_dir() . '/config.php';
  if (!is_file($f)) throw new Exception('設定ファイルがありません: ' . $f);
  $c = include $f;
  if (!is_array($c) || empty($c['login_id']) || empty($c['password']) || empty($c['api_key'])) {
    throw new Exception('config.php に login_id / password / api_key がありません');
  }
  if (strpos($c['password'], 'ここに') !== false) throw new Exception('config.php のパスワードが未入力です');
  return $c;
}

function gp_log($msg) {
  $line = date('Y-m-d H:i:s') . ' ' . $msg . "\n";
  @file_put_contents(gp_data_dir() . '/run.log', $line, FILE_APPEND | LOCK_EX);
  if (PHP_SAPI === 'cli') echo $line;
}

/* ---------------- HTTP ---------------- */

class GpClient {
  public $code;      // 会社コード
  public $tenant;    // テナント番号
  public $name;      // 会社名
  public $jar;       // cookie ファイル
  public $csrf = '';
  public $memberRoot = '';
  public $requests = 0;

  function __construct($code) {
    $this->code = $code;
    [$this->tenant, $this->name] = GP_TENANTS[$code];
    $this->jar = gp_data_dir() . "/cookies/$code.txt";
  }

  function req($method, $url, $body = null, $headers = [], $follow = true) {
    if (strpos($url, 'http') !== 0) $url = GP_HOST . $url;
    $ch = curl_init($url);
    $h = array_merge(['Accept-Language: ja,en;q=0.8'], $headers);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_FOLLOWLOCATION => $follow,
      CURLOPT_MAXREDIRS => 10,
      CURLOPT_COOKIEJAR => $this->jar,
      CURLOPT_COOKIEFILE => $this->jar,
      CURLOPT_USERAGENT => GP_UA,
      CURLOPT_CONNECTTIMEOUT => 15,
      CURLOPT_TIMEOUT => 60,
      CURLOPT_HTTPHEADER => $h,
      CURLOPT_HEADER => false,
    ]);
    if ($method === 'POST') {
      curl_setopt($ch, CURLOPT_POST, true);
      curl_setopt($ch, CURLOPT_POSTFIELDS, $body === null ? '' : $body);
    }
    $res = curl_exec($ch);
    $info = curl_getinfo($ch);
    $err = curl_error($ch);
    curl_close($ch);
    $this->requests++;
    usleep(120000); // 相手に負担をかけないよう間隔をあける
    if ($res === false) throw new Exception("$method $url → 通信エラー: $err");
    return ['status' => $info['http_code'], 'body' => $res, 'url' => $info['url']];
  }

  /** ログイン済みかを確認（/assoc を開いて memberRoot と csrf を取る） */
  function openAssoc() {
    $r = $this->req('GET', "/main/dyapp/a/{$this->code}/assoc");
    $html = $r['body'];
    if (preg_match('/<meta\s+name="_csrf"\s+content="([^"]+)"/', $html, $m)) $this->csrf = $m[1];
    if (preg_match('/id="__memberRoot"[^>]*value=[\'"]([^\'"]+)[\'"]/', $html, $m)) $this->memberRoot = $m[1];
    $ok = $this->csrf && $this->memberRoot && strpos($html, 'loginAction') === false;
    return $ok;
  }

  function login($loginId, $password) {
    if ($this->openAssoc()) { gp_log("{$this->name}: 前回のセッションを再利用"); return 'reuse'; }
    @unlink($this->jar);
    $r = $this->req('GET', "/main/dyapp/a/{$this->code}/assoclogin");
    $html = $r['body'];
    $csrf = '';
    if (preg_match('/<meta\s+name="_csrf"\s+content="([^"]+)"/', $html, $m)) $csrf = $m[1];
    elseif (preg_match('/name="_csrf"\s+value="([^"]+)"/', $html, $m)) $csrf = $m[1];
    if (!$csrf) throw new Exception("{$this->name}: ログイン画面の _csrf が取れません（HTTP {$r['status']}）");
    $post = http_build_query([
      'deviceid' => '', 'tenant' => $this->tenant, 'username' => $loginId, 'password' => $password, '_csrf' => $csrf,
    ]);
    $r2 = $this->req('POST', '/main/dyapp/loginAction', $post, [
      'X-CSRF-TOKEN: ' . $csrf,
      'Content-Type: application/x-www-form-urlencoded',
      'Referer: ' . GP_HOST . "/main/dyapp/a/{$this->code}/assoclogin",
      'Origin: ' . GP_HOST,
    ], false);
    if (!$this->openAssoc()) {
      throw new Exception("{$this->name}: ログイン失敗（loginAction HTTP {$r2['status']}）");
    }
    gp_log("{$this->name}: ログイン成功");
    return 'login';
  }

  /** memberRoot 配下に JSON で問い合わせる */
  function api($method, $path, $body = 'null') {
    $r = $this->req($method, $this->memberRoot . $path, $method === 'POST' ? $body : null, [
      'X-CSRF-TOKEN: ' . $this->csrf,
      'Content-Type: application/json',
      'Accept: application/json, text/javascript, */*; q=0.01',
      'X-Requested-With: XMLHttpRequest',
      'Referer: ' . GP_HOST . $this->memberRoot,
    ]);
    $j = json_decode($r['body'], true);
    return ['status' => $r['status'], 'json' => $j, 'raw' => $r['body']];
  }
}

/* ---------------- 取得 ---------------- */

function gp_find_key($data, $key, &$out) {
  if (!is_array($data)) return;
  foreach ($data as $k => $v) {
    if ($k === $key && (is_string($v) || is_numeric($v))) $out[] = (string)$v;
    if (is_array($v)) gp_find_key($v, $key, $out);
  }
}

function gp_ymd($v) {
  if (!$v) return '';
  if (is_numeric($v)) return date('Y-m-d', (int)($v > 9999999999 ? $v / 1000 : $v));
  if (preg_match('/(\d{4})[-\/年](\d{1,2})[-\/月](\d{1,2})/u', (string)$v, $m)) {
    return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
  }
  return (string)$v;
}

function gp_save_raw($code, $name, $data) {
  @file_put_contents(gp_data_dir() . "/raw/{$code}_{$name}.json",
    is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

/** 1社分を取得して配列で返す */
function gp_fetch_tenant($code, $cfg) {
  $started = microtime(true);
  $c = new GpClient($code);
  $mode = $c->login($cfg['login_id'], $cfg['password']);

  $list = $c->api('POST', '/dashboard/getlist');
  gp_save_raw($code, 'getlist', $list['raw']);
  $items = $list['json']['items'] ?? (is_array($list['json']) && isset($list['json'][0]) ? $list['json'] : []);
  if (!$items && $list['status'] != 200) throw new Exception("{$c->name}: getlist HTTP {$list['status']}");

  $sites = [];
  $rawSaved = false;
  foreach ($items as $it) {
    $jc = $it['jnm'] ?? '';
    if ($jc === '') continue;
    $site = [
      'company' => $c->name,
      'company_code' => $code,
      'site_code' => $jc,
      'site_id' => 'GP-' . $jc,
      'name' => $it['jn'] ?? '',
      'address_list' => $it['a'] ?? '',
      'start' => gp_ymd($it['ds'] ?? ''),
      'end' => gp_ymd($it['de'] ?? ''),
      'framework_date' => '',
      'dates' => [],
      'address' => '',
      'lat' => null, 'lng' => null,
      'branch' => '',
      'tasks' => [],
      'charge' => [],
      'errors' => [],
    ];
    $enc = rawurlencode($jc);

    // 現場情報（上棟日・住所・緯度経度）
    try {
      $si = $c->api('POST', "/registersite/jobitemdetail/j/$enc/siteinfo/getsiteinfo");
      $j = $si['json'] ?: [];
      $di = $j['dateInfo'] ?? [];
      foreach ($di as $k => $v) if ($v) $site['dates'][$k] = gp_ymd($v);
      $site['framework_date'] = $site['dates']['frameworkDate'] ?? '';
      $li = $j['landInfo'] ?? [];
      $ad = $li['jobItemAddress'] ?? [];
      $site['address'] = trim(($ad['prefectures'] ?? '') . ($ad['address1'] ?? '') . ($ad['address2'] ?? '') . ($ad['address3'] ?? ''));
      $site['lat'] = $li['lat'] ?? null;
      $site['lng'] = $li['lng'] ?? null;
      if (!$rawSaved) gp_save_raw($code, 'siteinfo_sample', $si['raw']);
    } catch (Exception $e) { $site['errors'][] = 'siteinfo: ' . $e->getMessage(); }

    // 工程表（上棟・建て方・レッカー等のタスク）
    try {
      $sl = $c->api('GET', "/schedule/ajax/schedulelist/j/$enc");
      $nos = [];
      gp_find_key($sl['json'], 'scheduleNo', $nos);
      $nos = array_values(array_unique($nos));
      if (!$rawSaved) gp_save_raw($code, 'schedulelist_sample', $sl['raw']);
      foreach ($nos as $no) {
        $cd = $c->api('POST', "/schedule/ajax/clientdata/j/$enc/d/" . rawurlencode($no));
        if (!$rawSaved) gp_save_raw($code, 'clientdata_sample', $cd['raw']);
        $tasks = $cd['json']['taskManager']['tasks'] ?? [];
        foreach ($tasks as $t) {
          $tn = (string)($t['taskName'] ?? '');
          $subs = [];
          foreach (($t['personInCharges'] ?? []) as $p) {
            $s = trim((string)($p['subconName'] ?? ''));
            if ($s !== '') $subs[] = $s;
          }
          $ours = (bool)preg_grep('/ユーシン/u', $subs);
          if (!preg_match(GP_TASK_RE, $tn) && !$ours) continue;
          $site['tasks'][] = [
            'schedule_no' => $no,
            'name' => $tn,
            'start' => substr((string)($t['startDate'] ?? ''), 0, 10),
            'end_exclusive' => substr((string)($t['endDate'] ?? ''), 0, 10),
            'subcons' => $subs,
            'yushin' => $ours,
          ];
        }
      }
    } catch (Exception $e) { $site['errors'][] = 'schedule: ' . $e->getMessage(); }

    // タマホームの支店タグ
    if ($code === 'AX4DD823') {
      try {
        $tg = $c->api('GET', "/tag/ajax/attachedlist/get/JOB_ITEM/$enc");
        $names = [];
        foreach ((array)$tg['json'] as $x) if (!empty($x['tagName'])) $names[] = $x['tagName'];
        $site['branch'] = implode('・', $names);
      } catch (Exception $e) { $site['errors'][] = 'tag: ' . $e->getMessage(); }
    }

    // 担当者
    try {
      $ch = $c->api('POST', "/registersite/jobitemdetail/j/$enc/chargeinfo/getchargeinfoinit");
      if (!$rawSaved) gp_save_raw($code, 'chargeinfo_sample', $ch['raw']);
      foreach (($ch['json']['chargePersons'] ?? []) as $p) {
        $nm = '';
        foreach (['name', 'personName', 'userName', 'chargePersonName', 'fullName'] as $k) {
          if (!empty($p[$k]) && is_string($p[$k])) { $nm = $p[$k]; break; }
        }
        $site['charge'][] = ['type' => $p['chargePersonType'] ?? '', 'name' => $nm, 'tel' => $p['tel'] ?? ''];
      }
    } catch (Exception $e) { $site['errors'][] = 'charge: ' . $e->getMessage(); }

    $rawSaved = true;
    $sites[] = $site;
  }

  return [
    'company' => $c->name,
    'company_code' => $code,
    'fetched_at' => date('Y-m-d H:i:s'),
    'login' => $mode,
    'site_count' => count($sites),
    'requests' => $c->requests,
    'seconds' => round(microtime(true) - $started, 1),
    'sites' => $sites,
  ];
}

/** 指定した会社（省略時は4社）を取得して保存。会社ごとの成否を返す */
function gp_run($codes = null) {
  $cfg = gp_config();
  $codes = $codes ?: array_keys(GP_TENANTS);
  $dir = gp_data_dir();
  $summary = [];
  foreach ($codes as $code) {
    if (!isset(GP_TENANTS[$code])) continue;
    try {
      $res = gp_fetch_tenant($code, $cfg);
      file_put_contents("$dir/$code.json", json_encode($res, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
      $summary[$code] = 'OK ' . $res['site_count'] . '件 ' . $res['seconds'] . '秒';
      gp_log(GP_TENANTS[$code][1] . ': ' . $summary[$code] . '（' . $res['login'] . '）');
    } catch (Exception $e) {
      $summary[$code] = 'NG ' . $e->getMessage();
      gp_log(GP_TENANTS[$code][1] . ': 失敗 ' . $e->getMessage());
      $prev = is_file("$dir/$code.json") ? json_decode(file_get_contents("$dir/$code.json"), true) : null;
      if ($prev) { $prev['last_error'] = date('Y-m-d H:i:s') . ' ' . $e->getMessage(); file_put_contents("$dir/$code.json", json_encode($prev, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX); }
    }
  }
  $all = gp_build_latest();
  gp_push_sheet($all, $cfg);
  return $summary;
}

/**
 * 取得結果を予定表（APP＿AI管理_予定一覧）の Apps Script へ送り、「現場Plus」タブを更新させる（2026-11-14 追加）。
 * Google から XServer を読みに行くと 501 で断られるため、XServer から Google へ送る向きにした。
 * 受け側は doPost（コード.gs）→ gp_receive_（現場Plus.gs）。認証は config.php の api_key
 * （Apps Script のスクリプトプロパティ GENBAPLUS_KEY と同じ値）。
 * 送り先は config.php の sheet_push_url があればそれ、無ければ下の既定値。失敗しても取得結果には影響しない。
 */
const GP_SHEET_PUSH_URL = 'https://script.google.com/macros/s/AKfycbxwTQ-BrNuZS9KSYYOrVY889a9NuLgoXr2yyuhrfKI6bOPARRldPWKNcujs2-cL0En3Iw/exec';

function gp_push_sheet($all, $cfg) {
  $url = !empty($cfg['sheet_push_url']) ? $cfg['sheet_push_url'] : GP_SHEET_PUSH_URL;
  if (!$url || (isset($cfg['sheet_push']) && !$cfg['sheet_push'])) return null;
  $body = json_encode(['genbaplus_key' => (string)$cfg['api_key'], 'genbaplus' => $all], JSON_UNESCAPED_UNICODE);
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $body,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,   // Apps Script は 302 → googleusercontent で結果を返す
    CURLOPT_TIMEOUT => 120,
    CURLOPT_CONNECTTIMEOUT => 20,
  ]);
  $res = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err = curl_error($ch);
  curl_close($ch);
  $msg = $err ? ('失敗 ' . $err) : ('HTTP ' . $code . ' ' . mb_substr(preg_replace('/\s+/', ' ', (string)$res), 0, 200));
  gp_log('予定表へ送信: ' . $msg);
  return $msg;
}

/** 4社分をまとめた latest.json を作る */
function gp_build_latest() {
  $dir = gp_data_dir();
  $all = ['built_at' => date('Y-m-d H:i:s'), 'note' => '現場Plus 4社の取得結果（XServerで取得）。tasks は工程表の上棟・建て方・レッカー等と、ユーシン担当のタスク。', 'companies' => []];
  foreach (GP_TENANTS as $code => $t) {
    $f = "$dir/$code.json";
    $all['companies'][$code] = is_file($f) ? json_decode(file_get_contents($f), true) : ['company' => $t[1], 'error' => '未取得'];
  }
  file_put_contents("$dir/latest.json", json_encode($all, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
  return $all;
}
