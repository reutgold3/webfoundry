<?php
// Yalla Math – AI math tutor proxy (Claude API). Key outside web root: ~/.yalla/anthropic.key
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
function out($a){ echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(['error'=>'method']);
$home = dirname(__DIR__, 2); $cfg = $home . '/.yalla';
$key = trim((string)@file_get_contents($cfg . '/anthropic.key'));
if ($key === '') out(['error'=>'nokey']);
$LIMIT = 60;
@mkdir($cfg . '/rl', 0700, true);
$rl = $cfg . '/rl/m' . md5(($_SERVER['REMOTE_ADDR'] ?? 'x') . date('Y-m-d'));
$n = (int)@file_get_contents($rl); if ($n >= $LIMIT) out(['error'=>'limit']); @file_put_contents($rl, $n + 1);

$raw = file_get_contents('php://input'); if (strlen($raw) > 6000000) out(['error'=>'big']);
$in = json_decode($raw, true); if (!is_array($in)) out(['error'=>'bad']);
$grade = mb_substr(preg_replace('/[^\p{L}\p{N} ״׳\'"·]/u', '', (string)($in['grade'] ?? '')), 0, 40);
$name  = mb_substr(preg_replace('/[^\p{L} ]/u', '', (string)($in['name'] ?? '')), 0, 20);
$mode  = ($in['mode'] ?? '') === 'explain' ? 'explain' : 'photo';

$system = "את/ה מורה למתמטיקה סבלני/ת, חם/ה ומעודד/ת באפליקציה Yalla Math (ישראל). התלמיד/ה לומד/ת ב: $grade."
 . ($name ? " שם התלמיד/ה: $name." : "")
 . " עונים רק בעברית פשוטה וברורה, מותאמת לגיל, ורק על מתמטיקה. אם מבקשים משהו שאינו מתמטיקה, מסבירים בעדינות שאפשר לעזור רק במתמטיקה."
 . " מסבירים בשלבים ממוספרים (1., 2., 3. ...), כל שלב שורה אחת או שתיים, ומראים את החישוב. כותבים ביטויים מתמטיים בטקסט פשוט עם הסימנים × : − + = ושברים בצורה 3/4."
 . " בסוף: שורה שמתחילה ב'התשובה:' ומשפט עידוד קצר. לא מבקשים פרטים אישיים. אם התמונה לא ברורה או שאין בה תרגיל, אומרים זאת ומבקשים לצלם שוב מקרוב.";

$msgs = [];
foreach (array_slice((array)($in['history'] ?? []), -6) as $m) {
  $r = ($m['role'] ?? '') === 'assistant' ? 'assistant' : 'user'; $c = mb_substr(trim((string)($m['content'] ?? '')), 0, 3000);
  if ($c !== '') $msgs[] = ['role'=>$r, 'content'=>$c];
}
$text = mb_substr(trim((string)($in['text'] ?? '')), 0, 2000);
$content = [];
if ($mode === 'photo' && !empty($in['image'])) {
  $img = (string)$in['image'];
  if (!preg_match('#^data:image/(jpeg|png|webp);base64,([A-Za-z0-9+/=]+)$#', $img, $mm)) out(['error'=>'img']);
  $content[] = ['type'=>'image', 'source'=>['type'=>'base64', 'media_type'=>'image/' . $mm[1], 'data'=>$mm[2]]];
  $content[] = ['type'=>'text', 'text'=>($text !== '' ? $text : 'תסביר/י לי איך פותרים את התרגיל שבתמונה, שלב אחרי שלב.')];
} else {
  if ($text === '') out(['error'=>'empty']);
  $content[] = ['type'=>'text', 'text'=>$text];
}
// history must alternate and start with user
if ($msgs && $msgs[0]['role'] !== 'user') array_shift($msgs);
if ($msgs && end($msgs)['role'] === 'user') array_pop($msgs);
$msgs[] = ['role'=>'user', 'content'=>$content];

$payload = ['model'=>'claude-sonnet-5-5', 'max_tokens'=>1200, 'system'=>$system, 'messages'=>$msgs];
$ch = curl_init('https://api.anthropic.com/v1/messages');
curl_setopt_array($ch, [CURLOPT_POST=>true, CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>60,
  CURLOPT_HTTPHEADER=>['Content-Type: application/json', 'x-api-key: ' . $key, 'anthropic-version: 2023-06-01'],
  CURLOPT_POSTFIELDS=>json_encode($payload, JSON_UNESCAPED_UNICODE)]);
$res = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
if ($res === false || $code !== 200) out(['error'=>'api', 'code'=>$code]);
$j = json_decode($res, true); $t = '';
foreach (($j['content'] ?? []) as $b) if (($b['type'] ?? '') === 'text') $t .= $b['text'];
out(['text'=>trim($t)]);
