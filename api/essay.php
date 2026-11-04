<?php
// Yalla Psychometry – writing-task (חיבור) feedback via Claude. Key: ~/.yalla/anthropic.key
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['error'=>'method']); exit; }
function out($a){ echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }

$home = dirname(__DIR__, 2);
$cfg  = $home . '/.yalla';
$key  = trim((string)@file_get_contents($cfg . '/anthropic.key'));
if ($key === '') out(['error'=>'nokey']);

$LIMIT = 12; // essays per IP per day
@mkdir($cfg . '/rl', 0700, true);
$ip = $_SERVER['REMOTE_ADDR'] ?? 'x';
$rl = $cfg . '/rl/psy_' . md5($ip . date('Y-m-d'));
$n  = (int)@file_get_contents($rl);
if ($n >= $LIMIT) out(['error'=>'limit']);

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) out(['error'=>'bad']);
$prompt = mb_substr(trim((string)($in['prompt'] ?? '')), 0, 1500);
$essay  = mb_substr(trim((string)($in['essay'] ?? '')), 0, 6000);
if ($prompt === '' || mb_strlen($essay) < 40) out(['error'=>'short']);
@file_put_contents($rl, $n + 1);

$system = "את/ה בודק/ת מנוסה של מטלת הכתיבה (החיבור) בבחינה הפסיכומטרית בישראל. הנבחן קיבל נושא וכתב חיבור טיעוני בעברית (בבחינה: 25–50 שורות, כ־30 דקות). "
 . "הערך/י את החיבור בשני ממדים, כל אחד בסולם 1–6: "
 . "(1) תוכן: הבנת המטלה, עמדה ברורה, טיעונים מבוססים ומגוונים, דוגמאות, התייחסות לעמדה הנגדית, ארגון ומבנה (פתיחה, גוף, סיכום), רצף לוגי וקישוריות. "
 . "(2) לשון: עושר לשוני ומשלב מתאים, דיוק בתחביר ובניסוח, אוצר מילים, פיסוק וכתיב. "
 . "היה/י הוגן/ת ומעודד/ת אבל מדויק/ת — אל תנפח/י ציונים. חיבור קצר מאוד או שאינו עונה לנושא מקבל ציון נמוך. "
 . "החזר/י אך ורק אובייקט JSON אחד, בלי markdown, במבנה: "
 . "{\"content\":1-6,\"language\":1-6,\"strengths\":[\"עד 3 נקודות חוזק\"],\"improve\":[\"עד 4 הצעות שיפור מעשיות וספציפיות\"],"
 . "\"fixes\":[{\"from\":\"ציטוט קצר מהחיבור עם שגיאה\",\"to\":\"הניסוח המתוקן\"}],\"summary\":\"2-3 משפטים של סיכום אישי\","
 . "\"outline\":\"מבנה מוצע לחיבור טוב על הנושא, בנקודות קצרות\"}. עד 6 תיקונים. הכול בעברית.";
$payload = ['model'=>'claude-sonnet-5-5', 'max_tokens'=>1500, 'system'=>$system,
  'messages'=>[['role'=>'user', 'content'=>"נושא המטלה:\n$prompt\n\nהחיבור של הנבחן:\n$essay"]]];
$ch = curl_init('https://api.anthropic.com/v1/messages');
curl_setopt_array($ch, [CURLOPT_POST=>true, CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>60,
  CURLOPT_HTTPHEADER=>['Content-Type: application/json', 'x-api-key: ' . $key, 'anthropic-version: 2023-06-01'],
  CURLOPT_POSTFIELDS=>json_encode($payload, JSON_UNESCAPED_UNICODE)]);
$res = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
if ($res === false || $code !== 200) out(['error'=>'api', 'code'=>$code]);
$j = json_decode($res, true); $text = '';
foreach (($j['content'] ?? []) as $b) if (($b['type'] ?? '') === 'text') $text .= $b['text'];
$d = null; if (preg_match('/\{.*\}/s', $text, $mm)) $d = json_decode($mm[0], true);
if (!is_array($d) || !isset($d['content'])) out(['error'=>'parse']);
$cl = fn($x) => max(1, min(6, (int)$x));
out(['content'=>$cl($d['content']), 'language'=>$cl($d['language'] ?? 1),
  'strengths'=>array_slice(array_map('strval', (array)($d['strengths'] ?? [])), 0, 3),
  'improve'=>array_slice(array_map('strval', (array)($d['improve'] ?? [])), 0, 4),
  'fixes'=>array_slice(array_values(array_filter((array)($d['fixes'] ?? []), 'is_array')), 0, 6),
  'summary'=>(string)($d['summary'] ?? ''), 'outline'=>(string)($d['outline'] ?? '')]);
