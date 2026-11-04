<?php
// Yalla family sync: shared by Yalla English and Yalla Math. Data: ~/.yalla/families/<CODE>.json (first names + learning stats only)
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
function out($a){ echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(['error'=>'method']);
$dir = dirname(__DIR__, 2) . '/.yalla/families'; @mkdir($dir, 0700, true);
// rate limit
$rl = dirname($dir) . '/rl'; @mkdir($rl, 0700, true);
$rf = $rl . '/f' . md5(($_SERVER['REMOTE_ADDR'] ?? 'x') . date('Y-m-d-H')); $n = (int)@file_get_contents($rf);
if ($n > 400) out(['error'=>'limit']); @file_put_contents($rf, $n + 1);
$in = json_decode(file_get_contents('php://input'), true); if (!is_array($in)) out(['error'=>'bad']);
$clean = fn($s, $l) => mb_substr(preg_replace('/[^\p{L}\p{N} \-_.·׳״\'"]/u', '', (string)$s), 0, $l);
$act = $in['action'] ?? '';
if ($act === 'create') {
  $abc = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
  do { $code = ''; for ($i = 0; $i < 8; $i++) $code .= $abc[random_int(0, strlen($abc) - 1)]; } while (file_exists("$dir/$code.json"));
  $fam = ['created'=>time(), 'name'=>$clean($in['family'] ?? '', 30), 'members'=>new stdClass()];
  file_put_contents("$dir/$code.json", json_encode($fam, JSON_UNESCAPED_UNICODE), LOCK_EX);
  out(['code'=>$code]);
}
$code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)($in['code'] ?? '')));
if (strlen($code) !== 8) out(['error'=>'code']);
$f = "$dir/$code.json"; if (!file_exists($f)) out(['error'=>'nofamily']);
$fp = fopen($f, 'c+'); flock($fp, LOCK_EX); $fam = json_decode(stream_get_contents($fp), true) ?: ['members'=>[]];
if (!is_array($fam['members'] ?? null)) $fam['members'] = [];
if ($act === 'sync') {
  $id = preg_replace('/[^a-z0-9]/', '', (string)($in['id'] ?? '')); $app = in_array($in['app'] ?? '', ['math','theory','psy','gemara'], true) ? $in['app'] : 'english';
  if (strlen($id) < 8) out(['error'=>'id']);
  $st = []; foreach ((array)($in['stats'] ?? []) as $k => $v) { $k = $clean($k, 20); if ($k === '') continue; $st[$k] = is_numeric($v) ? $v + 0 : $clean($v, 60); if (count($st) > 25) break; }
  $m = $fam['members'][$id] ?? [];
  $m['name'] = $clean($in['name'] ?? 'ללא שם', 20) ?: 'ללא שם'; $m['role'] = ($in['role'] ?? '') === 'parent' ? 'parent' : 'kid';
  $m['e'] = mb_substr(strip_tags((string)($in['e'] ?? '')), 0, 8); $m['app'] = $app; $m['stats'] = $st; $m['t'] = time();
  $fam['members'][$id] = $m;
  if (count($fam['members']) > 30) out(['error'=>'full']);
  ftruncate($fp, 0); rewind($fp); fwrite($fp, json_encode($fam, JSON_UNESCAPED_UNICODE)); fflush($fp);
}
if ($act === 'leave') { $id = preg_replace('/[^a-z0-9]/', '', (string)($in['id'] ?? '')); unset($fam['members'][$id]);
  ftruncate($fp, 0); rewind($fp); fwrite($fp, json_encode($fam, JSON_UNESCAPED_UNICODE)); fflush($fp); }
flock($fp, LOCK_UN); fclose($fp);
out(['ok'=>1, 'family'=>$fam['name'] ?? '', 'members'=>array_values(array_map(fn($k, $m) => $m + ['id'=>substr($k, 0, 6)], array_keys($fam['members']), $fam['members']))]);
