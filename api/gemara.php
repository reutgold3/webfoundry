<?php
// Yalla Gemara: text proxy (Sefaria) + AI explanations/quiz/grading (Claude). Cache in ~/.yalla/gemara
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
function out($a){ echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }
$home = dirname(__DIR__, 2); $cfg = $home . '/.yalla'; $cache = $cfg . '/gemara'; @mkdir($cache, 0700, true);
$in = $_SERVER['REQUEST_METHOD'] === 'POST' ? (json_decode(file_get_contents('php://input'), true) ?: []) : $_GET;
$act = $in['action'] ?? '';
$MAS = ['Sukkah'=>1, 'Bava_Metzia'=>1, 'Berakhot'=>1, 'Bava_Kamma'=>1, 'Bava_Batra'=>1, 'Sanhedrin'=>1, 'Kiddushin'=>1, 'Gittin'=>1, 'Ketubot'=>1, 'Shabbat'=>1, 'Pesachim'=>1, 'Megillah'=>1, 'Taanit'=>1, 'Yevamot'=>1];
function get_url($u){ $ch = curl_init($u); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>25, CURLOPT_FOLLOWLOCATION=>true, CURLOPT_USERAGENT=>'YallaGemara/1.0']); $r = curl_exec($ch); $c = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch); return $c === 200 ? $r : false; }
function clean($s){ $s = preg_replace('#<[^>]+>#', '', (string)$s); return trim(html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8')); }
function flat($t){ $o = []; foreach ((array)$t as $x) { if (is_array($x)) { $o[] = implode(' ', array_map('clean', array_filter($x, 'is_string'))); } else $o[] = clean($x); } return $o; }
function rate($cfg, $p, $lim){ @mkdir($cfg . '/rl', 0700, true); $f = $cfg . '/rl/' . $p . md5(($_SERVER['REMOTE_ADDR'] ?? 'x') . date('Y-m-d')); $n = (int)@file_get_contents($f); if ($n >= $lim) out(['error'=>'limit']); @file_put_contents($f, $n + 1); }
if ($act === 'text') {
  $mas = preg_replace('/[^A-Za-z_]/', '', (string)($in['mas'] ?? '')); $daf = preg_replace('/[^0-9ab]/', '', (string)($in['daf'] ?? ''));
  if (!isset($MAS[$mas]) || !preg_match('/^\d{1,3}[ab]$/', $daf)) out(['error'=>'ref']);
  $cf = "$cache/t_{$mas}_{$daf}.json"; if (is_file($cf)) { echo file_get_contents($cf); exit; }
  rate($cfg, 'gt', 400);
  $ref = $mas . '.' . $daf;
  $g = get_url('https://www.sefaria.org/api/texts/' . rawurlencode($ref) . '?context=0&commentary=0&lang=he');
  if (!$g) out(['error'=>'fetch']);
  $gj = json_decode($g, true); $he = flat($gj['he'] ?? []);
  $r = get_url('https://www.sefaria.org/api/texts/' . rawurlencode('Rashi on ' . str_replace('_', ' ', $mas) . '.' . $daf) . '?context=0&commentary=0&lang=he');
  $rashi = []; if ($r) { $rj = json_decode($r, true); foreach ((array)($rj['he'] ?? []) as $i => $seg) $rashi[$i] = is_array($seg) ? array_values(array_filter(array_map('clean', $seg))) : ($seg ? [clean($seg)] : []); }
  $res = json_encode(['ref'=>$ref, 'he'=>$he, 'rashi'=>$rashi, 'heRef'=>$gj['heRef'] ?? '', 'version'=>$gj['heVersionTitle'] ?? '', 'license'=>$gj['heLicense'] ?? '', 'next'=>$gj['next'] ?? null, 'prev'=>$gj['prev'] ?? null], JSON_UNESCAPED_UNICODE);
  if ($he) file_put_contents($cf, $res);
  echo $res; exit;
}
// ---- AI actions ----
$key = trim((string)@file_get_contents($cfg . '/anthropic.key')); if ($key === '') out(['error'=>'nokey']);
function claude($key, $system, $user, $max = 900, $model = 'claude-sonnet-5-5'){
  $ch = curl_init('https://api.anthropic.com/v1/messages');
  curl_setopt_array($ch, [CURLOPT_POST=>true, CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>60,
    CURLOPT_HTTPHEADER=>['Content-Type: application/json', 'x-api-key: ' . $key, 'anthropic-version: 2023-06-01'],
    CURLOPT_POSTFIELDS=>json_encode(['model'=>$model, 'max_tokens'=>$max, 'thinking'=>['type'=>strpos($model, 'sonnet') !== false ? 'between_tools' : 'disabled'], 'system'=>$system, 'messages'=>[['role'=>'user', 'content'=>$user]]], JSON_UNESCAPED_UNICODE)]);
  $r = curl_exec($ch); $c = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
  if ($r === false || $c !== 200) { $GLOBALS['dbg'] = ['code'=>$c, 'body'=>mb_substr((string)$r, 0, 300)]; return null; }
  $j = json_decode($r, true); $t = '';
  foreach (($j['content'] ?? []) as $b) if (($b['type'] ?? '') === 'text') $t .= $b['text'];
  $t = trim($t); if ($t === '') { $GLOBALS['dbg'] = ['stop'=>$j['stop_reason'] ?? '', 'types'=>array_map(fn($b) => $b['type'] ?? '', (array)($j['content'] ?? []))]; return null; }
  return $t;
}
$SYS = "את/ה מלמד/ת גמרא בישיבה תיכונית בישראל, סבלני/ת וברור/ה. עונים בעברית פשוטה, לתלמיד/ה בכיתות י'–י\"ב. נאמנים לפשט הגמרא ולפירוש רש\"י. לא ממציאים מקורות; אם לא בטוחים, אומרים זאת. רק נושאי לימוד תורה וגמרא.";
$ref = mb_substr(preg_replace('/[^\p{L}\p{N} .:\'"׳״\-]/u', '', (string)($in['ref'] ?? '')), 0, 40);
$seg = mb_substr(trim((string)($in['seg'] ?? '')), 0, 2500);
$ctx = mb_substr(trim((string)($in['ctx'] ?? '')), 0, 2500);
if ($act === 'explain') {
  if ($seg === '') out(['error'=>'empty']); $cf = "$cache/e2_" . md5($ref . '|' . $seg) . '.json'; if (is_file($cf)) { echo file_get_contents($cf); exit; }
  rate($cfg, 'ge', 120);
  $t = claude($key, $SYS, "מקור: $ref\nהקטע מהגמרא:\n$seg\n" . ($ctx ? "\nמה שלפניו (להקשר):\n$ctx\n" : '') . "\nכתבו:\n1. תרגום מילולי קצר לעברית (משפט אחר משפט).\n2. הסבר פשוט: מה אומרים כאן ולמה, ומה תפקיד הקטע בסוגיה (קושיה/תירוץ/מקור/מסקנה וכו').\n3. 2–4 מילים או ביטויים בארמית מהקטע עם פירושם.\nבלי הקדמות. פורמט: שורות קצרות עם כותרות 'תרגום:', 'הסבר:', 'מילים:'.");
  if ($t === null) out(['error'=>'api', 'dbg'=>$GLOBALS['dbg'] ?? null]); $res = json_encode(['text'=>$t], JSON_UNESCAPED_UNICODE); file_put_contents($cf, $res); echo $res; exit;
}
if ($act === 'quiz') {
  if ($seg === '') out(['error'=>'empty']); $kind = ($in['kind'] ?? '') === 'unseen' ? 'unseen' : 'bagrut';
  $cf = "$cache/q_{$kind}_" . md5($ref . '|' . $seg) . '.json'; if (is_file($cf)) { echo file_get_contents($cf); exit; }
  rate($cfg, 'gq', 60);
  $t = claude($key, $SYS . " החזירו JSON בלבד.", "מקור: $ref\nטקסט הדף:\n$seg\n\nחברו 5 שאלות בסגנון בגרות בתלמוד" . ($kind === 'unseen' ? ' לקטע שלא נלמד (הבנת הטקסט, תרגום מילים, זיהוי קושיה ותירוץ, מבנה)' : ' (הבנה, הסבר שיטות, השוואה, תפקיד שלב בסוגיה, פירוש רש"י)') . ". החזירו בדיוק JSON בצורה: {\"q\":[{\"q\":\"שאלה\",\"model\":\"תשובה לדוגמה מלאה 2-4 משפטים\",\"points\":[\"נקודה שחייבת להופיע\",...]}]}", 1800);
  if ($t === null) out(['error'=>'api', 'dbg'=>$GLOBALS['dbg'] ?? null]); if (!preg_match('/\{.*\}/s', $t, $m)) out(['error'=>'parse']); $j = json_decode($m[0], true); if (empty($j['q'])) out(['error'=>'parse']);
  $res = json_encode(['q'=>array_slice($j['q'], 0, 6)], JSON_UNESCAPED_UNICODE); file_put_contents($cf, $res); echo $res; exit;
}
if ($act === 'grade') {
  rate($cfg, 'gg', 150);
  $q = mb_substr((string)($in['q'] ?? ''), 0, 600); $ans = mb_substr((string)($in['ans'] ?? ''), 0, 1500); $model = mb_substr((string)($in['model'] ?? ''), 0, 1200);
  if ($ans === '') out(['error'=>'empty']);
  $t = claude($key, $SYS . " החזירו JSON בלבד.", "מקור: $ref\nקטע:\n$seg\nשאלה: $q\nתשובה לדוגמה: $model\nתשובת התלמיד/ה (ייתכן שנאמרה בעל פה ותומללה, לכן התעלמו משגיאות כתיב): $ans\n\nהעריכו בעדינות ובעידוד. החזירו JSON: {\"score\":0-100,\"good\":\"מה היה טוב (משפט)\",\"missing\":\"מה חסר או שגוי (1-2 משפטים)\",\"better\":\"ניסוח תשובה מלאה וקצרה\"}", 700, 'claude-haiku-5-5');
  if ($t === null) out(['error'=>'api', 'dbg'=>$GLOBALS['dbg'] ?? null]); if (!preg_match('/\{.*\}/s', $t, $m)) out(['error'=>'parse']); $j = json_decode($m[0], true); if (!$j) out(['error'=>'parse']); out($j);
}
if ($act === 'ask') {
  rate($cfg, 'ga', 120); $qq = mb_substr(trim((string)($in['q'] ?? '')), 0, 800); if ($qq === '') out(['error'=>'empty']);
  $t = claude($key, $SYS, "מקור: $ref\nהקטע:\n$seg\n\nשאלת התלמיד/ה: $qq\nענו בקצרה ובבהירות (עד 8 שורות).", 700);
  if ($t === null) out(['error'=>'api', 'dbg'=>$GLOBALS['dbg'] ?? null]); out(['text'=>$t]);
}
out(['error'=>'action']);
