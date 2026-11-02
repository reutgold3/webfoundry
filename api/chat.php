<?php
// Yalla English – free conversation proxy (Claude API). Key lives outside the web root: ~/.yalla/anthropic.key
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['error'=>'method']); exit; }
function out($a){ echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }

$home = dirname(__DIR__, 2);                 // /home/<user>
$cfg  = $home . '/.yalla';
$key  = trim((string)@file_get_contents($cfg . '/anthropic.key'));
if ($key === '') out(['error'=>'nokey']);

// simple per-IP daily limit
$LIMIT = 150;
@mkdir($cfg . '/rl', 0700, true);
$ip = $_SERVER['REMOTE_ADDR'] ?? 'x';
$rl = $cfg . '/rl/' . md5($ip . date('Y-m-d'));
$n  = (int)@file_get_contents($rl);
if ($n >= $LIMIT) out(['error'=>'limit']);
@file_put_contents($rl, $n + 1);
if (mt_rand(1, 50) === 1) { foreach (glob($cfg . '/rl/*') ?: [] as $f) if (filemtime($f) < time() - 172800) @unlink($f); }

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) out(['error'=>'bad']);
$msgs = [];
foreach (array_slice((array)($in['messages'] ?? []), -16) as $m) {
  $r = ($m['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
  $c = mb_substr(trim((string)($m['content'] ?? '')), 0, 600);
  if ($c === '') continue;
  if ($msgs && end($msgs)['role'] === $r) { $msgs[count($msgs)-1]['content'] .= "\n" . $c; continue; }
  $msgs[] = ['role'=>$r, 'content'=>$c];
}
if (!$msgs || $msgs[0]['role'] !== 'user') array_unshift($msgs, ['role'=>'user', 'content'=>'(The learner opened the chat. Start the conversation.)']);

$LEVELS = [
  'g12'=>'a young child (age 6-8), absolute beginner: very short simple sentences, basic words',
  'g2'=>'a young child (age 7-8), beginner: very short simple sentences', 'g3'=>'a child (age 8-9), beginner (A1)',
  'g4'=>'a child (age 9-10), A1', 'g56'=>'a child (age 10-11), A1-A2', 'g6'=>'a child (age 11-12), A2',
  'm7'=>'a teenager, A2', 'm8'=>'a teenager, A2-B1', 'm9'=>'a teenager, B1',
  'h10'=>'a high-school student, B1', 'h11'=>'a high-school student, B1-B2', 'h12'=>'a high-school student, B2',
  'uni'=>'a university student, B2', 'adult'=>'an adult beginner, A2', 'adv'=>'an adult, B2', 'talk'=>'an adult, B1-B2',
  'biz'=>'an adult professional, B2-C1, business context', 'pro'=>'an advanced adult, C1: use rich, idiomatic English'
];
$course = (string)($in['course'] ?? '');
$level  = $LEVELS[$course] ?? 'an English learner, B1';
$langs  = ['he'=>'Hebrew', 'ru'=>'Russian', 'ar'=>'Arabic'];
$lang   = $langs[$in['help'] ?? 'he'] ?? 'Hebrew';
$char   = mb_substr(preg_replace('/[^\p{L} ]/u', '', (string)($in['char'] ?? 'Maya')), 0, 20) ?: 'Maya';
$name   = mb_substr(preg_replace('/[^\p{L} ]/u', '', (string)($in['name'] ?? '')), 0, 20);
$topic  = mb_substr(preg_replace('/[^\p{L}\p{N} ,:\-]/u', '', (string)($in['topic'] ?? 'Free chat')), 0, 120);
$kid    = !empty($in['kid']) || preg_match('/^(g|m)/', $course);

$system = "You are $char, a warm, curious and funny English conversation partner in the Yalla English app (Israel). "
 . "You are talking with " . ($name ? "$name, " : "") . "who is $level. Conversation topic: $topic.\n"
 . "Talk like a real person, not a teacher: react genuinely to what they said, add a short thought or personal touch of your own, "
 . "then ask ONE open follow-up question that keeps the conversation going. Keep 'reply' to 1-3 sentences and match their level. "
 . "If they write in another language, gently answer in English and encourage them to try in English. "
 . ($kid ? "The learner is a child: keep everything child-friendly, positive and simple. " : "")
 . "Never ask for personal details such as full name, address, phone, school name or photos. Stay on safe, friendly topics.\n"
 . "ALSO check the learner's LAST message for grammar mistakes, wrong word choice and unnatural phrasing. Ignore capitalization and punctuation "
 . "(it may come from speech recognition). Do not correct style that is already natural.\n"
 . "Answer with ONLY one JSON object, no markdown, in this exact shape:\n"
 . "{\"reply\":\"your conversational answer in English\",\"reply_tr\":\"translation of reply into $lang\",\"ok\":true or false,"
 . "\"corrected\":\"the learner's last message fully corrected (empty if ok)\",\"tip\":\"1-2 short friendly sentences in $lang explaining what was wrong and why (empty if ok)\","
 . "\"natural\":\"optional: how a native speaker would naturally say it, if clearly better; otherwise empty\"}\n"
 . "If the conversation is just starting (no learner message yet), greet them and ask the first question, with ok true.";

$payload = ['model'=>'claude-haiku-5-5', 'max_tokens'=>600, 'system'=>$system, 'messages'=>$msgs];
$ch = curl_init('https://api.anthropic.com/v1/messages');
curl_setopt_array($ch, [CURLOPT_POST=>true, CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>40,
  CURLOPT_HTTPHEADER=>['Content-Type: application/json', 'x-api-key: ' . $key, 'anthropic-version: 2023-06-01'],
  CURLOPT_POSTFIELDS=>json_encode($payload, JSON_UNESCAPED_UNICODE)]);
$res = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
if ($res === false || $code !== 200) out(['error'=>'api', 'code'=>$code]);
$j = json_decode($res, true);
$text = '';
foreach (($j['content'] ?? []) as $b) if (($b['type'] ?? '') === 'text') $text .= $b['text'];
if (preg_match('/\{.*\}/s', $text, $mm)) $d = json_decode($mm[0], true);
if (empty($d['reply'])) out(['reply'=>trim($text) ?: 'Sorry, could you say that again?', 'ok'=>true]);
out(['reply'=>(string)$d['reply'], 'reply_tr'=>(string)($d['reply_tr'] ?? ''), 'ok'=>($d['ok'] ?? true) !== false,
     'corrected'=>(string)($d['corrected'] ?? ''), 'tip'=>(string)($d['tip'] ?? ''), 'natural'=>(string)($d['natural'] ?? '')]);
