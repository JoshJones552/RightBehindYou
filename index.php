<?php
declare(strict_types=1);

const DATA_DIR = __DIR__ . '/../storage';
const ROOMS = [
    1 => ['title' => 'The Arrival', 'eyebrow' => 'ROOM 01 · THE FRONT HALL', 'description' => 'Rain ticks against the glass. A brass clock has stopped at midnight, and four empty grooves mark its face.', 'clue' => 'The note says: “A day has 24 hours. Midnight begins the count.”', 'answer' => '2412', 'object' => 'clock', 'prompt' => 'Set the clock'],
    2 => ['title' => 'The Conservatory', 'eyebrow' => 'ROOM 02 · THE GLASS GARDEN', 'description' => 'Moonlight falls over a locked greenhouse. Three planters are marked with the letters A, B, and C.', 'clue' => 'A pressed leaf reads: “Follow the warmest light; the ember remembers.”', 'answer' => 'ember', 'object' => 'greenhouse', 'prompt' => 'Name the hidden word'],
    3 => ['title' => 'The Archive', 'eyebrow' => 'ROOM 03 · THE LOWER ARCHIVE', 'description' => 'Dusty shelves surround a sealed elevator. A handwritten message is tucked beneath the brass call button.', 'clue' => 'The message reads: “When every page is turned, look where you began.”', 'answer' => 'home', 'object' => 'archive', 'prompt' => 'What does the elevator call?'],
];

function esc(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function read_store(string $name): array {
    $path = DATA_DIR . '/' . $name;
    if (!is_file($path)) return [];
    $data = json_decode((string)file_get_contents($path), true);
    return is_array($data) ? $data : [];
}
function write_store(string $name, array $data): bool {
    if (!is_dir(DATA_DIR) && !mkdir(DATA_DIR, 0750, true) && !is_dir(DATA_DIR)) return false;
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    return $json !== false && file_put_contents(DATA_DIR . '/' . $name, $json, LOCK_EX) !== false;
}
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return (string)$_SESSION['csrf'];
}
function csrf_valid(): bool {
    return isset($_POST['csrf'], $_SESSION['csrf']) && hash_equals((string)$_SESSION['csrf'], (string)$_POST['csrf']);
}
function logged_in(): bool { return isset($_SESSION['player_id'], $_SESSION['player_name']); }
function redirect(string $path): never { header('Location: ' . $path, true, 303); exit; }
function json_response(array $body, int $status = 200): never {
    http_response_code($status); header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store'); echo json_encode($body); exit;
}
function fresh_run(): void {
    $_SESSION['current_room'] = 1;
    $_SESSION['area_unlocked'] = [];
    $_SESSION['moves'] = 0;
    $_SESSION['run_started'] = time();
}
function update_leaderboard(): void {
    $scores = read_store('leaderboard.json');
    $scores[] = ['name' => (string)$_SESSION['player_name'], 'loops' => (int)$_SESSION['loops'], 'seconds' => max(1, time() - (int)$_SESSION['run_started']), 'date' => date('Y-m-d')];
    usort($scores, static fn($a, $b) => ($a['loops'] <=> $b['loops']) ?: ($a['seconds'] <=> $b['seconds']));
    write_store('leaderboard.json', array_slice($scores, 0, 50));
}

$secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
session_name('right_behind_you');
session_set_cookie_params(['httponly' => true, 'secure' => $secure, 'samesite' => 'Lax', 'path' => '/']);
session_start();
$action = (string)($_GET['action'] ?? '');
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$isAjax = str_contains(strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json');

if ($action === 'register' && $method === 'POST') {
    if (!csrf_valid()) { $notice = 'Your page expired. Please try again.'; }
    else {
        $name = trim((string)($_POST['name'] ?? ''));
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $password = (string)($_POST['password'] ?? '');
        $users = read_store('users.json');
        if (mb_strlen($name) < 2 || mb_strlen($name) > 32 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) $notice = 'Use a name (2–32 characters), a valid email, and a password of at least 8 characters.';
        elseif (isset($users[$email])) $notice = 'An account with that email already exists. Sign in instead.';
        else {
            $users[$email] = ['id' => bin2hex(random_bytes(12)), 'name' => $name, 'password' => password_hash($password, PASSWORD_DEFAULT)];
            if (!write_store('users.json', $users)) $notice = 'The account could not be saved. Check the storage folder permissions.';
            else { session_regenerate_id(true); $_SESSION['player_id'] = $users[$email]['id']; $_SESSION['player_name'] = $name; $_SESSION['loops'] = 0; fresh_run(); redirect('?page=game'); }
        }
    }
    $page = 'register';
} elseif ($action === 'login' && $method === 'POST') {
    if (!csrf_valid()) $notice = 'Your page expired. Please try again.';
    else {
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $user = read_store('users.json')[$email] ?? null;
        if (!$user || !password_verify((string)($_POST['password'] ?? ''), (string)$user['password'])) $notice = 'That email and password did not match.';
        else { session_regenerate_id(true); $_SESSION['player_id'] = $user['id']; $_SESSION['player_name'] = $user['name']; $_SESSION['loops'] = 0; fresh_run(); redirect('?page=game'); }
    }
    $page = 'login';
} elseif ($action === 'logout' && $method === 'POST' && csrf_valid()) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) { $p = session_get_cookie_params(); setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']); }
    session_destroy(); redirect('?page=home');
} elseif ($action === 'solve' && $method === 'POST') {
    if (!logged_in()) json_response(['ok' => false, 'message' => 'Your session ended. Sign in again.'], 401);
    if (!csrf_valid()) json_response(['ok' => false, 'message' => 'Refresh the page and try again.'], 403);
    $room = (int)($_SESSION['current_room'] ?? 0);
    if (!isset(ROOMS[$room]) || isset($_SESSION['area_unlocked'][$room])) json_response(['ok' => false, 'message' => 'This room is not ready for another attempt.'], 409);
    $answer = mb_strtolower(trim((string)($_POST['answer'] ?? '')));
    $_SESSION['moves'] = (int)($_SESSION['moves'] ?? 0) + 1;
    if (!hash_equals(ROOMS[$room]['answer'], $answer)) {
        $attempts = (int)($_SESSION['attempts'][$room] ?? 0) + 1; $_SESSION['attempts'][$room] = $attempts;
        $hints = ['The answer is four characters long.', 'Look closely at the clue. It points to a familiar word or number.', ROOMS[$room]['clue']];
        $hint = $hints[min(2, $attempts - 1)];
        json_response(['ok' => false, 'message' => 'Not quite. The room stays locked.', 'hint' => $hint, 'attempts' => $attempts]);
    }
    $_SESSION['area_unlocked'][$room] = true;
    if ($room < 3) {
        $_SESSION['current_room'] = $room + 1;
        json_response(['ok' => true, 'message' => 'The lock clicks open.', 'next' => '?page=game']);
    }
    $_SESSION['loops'] = (int)($_SESSION['loops'] ?? 0) + 1;
    fresh_run();
    json_response(['ok' => true, 'message' => 'The elevator opens onto the very first hall. Something is different now.', 'looped' => true, 'next' => '?page=game']);
} elseif ($action === 'escape' && $method === 'POST') {
    if (!logged_in()) redirect('?page=login');
    if (!csrf_valid() || (int)($_SESSION['loops'] ?? 0) < 1) redirect('?page=game');
    update_leaderboard(); $_SESSION['escaped'] = true; redirect('?page=ending');
}

$page = $page ?? (string)($_GET['page'] ?? 'home');
$allowed = ['home','login','register','game','leaderboard','ending'];
if (!in_array($page, $allowed, true)) $page = 'home';
if (in_array($page, ['game','ending'], true) && !logged_in()) redirect('?page=login');
if ($page === 'game' && !isset($_SESSION['current_room'])) fresh_run();
$roomNumber = (int)($_SESSION['current_room'] ?? 1);
$room = ROOMS[$roomNumber] ?? ROOMS[1];
$loops = (int)($_SESSION['loops'] ?? 0);
$scores = read_store('leaderboard.json');
$title = ['home'=>'Right Behind You','login'=>'Sign in','register'=>'Create your account','game'=>$room['title'],'leaderboard'=>'Hall of echoes','ending'=>'You made it out'] [$page];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#10100f"><title><?= esc($title) ?> · Right Behind You</title>
  <link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=DM+Mono:wght@400;500&family=DM+Sans:wght@400;500;600;700&family=Playfair+Display:wght@500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="app.css">
</head>
<body class="page-<?= esc($page) ?>">
<div class="grain" aria-hidden="true"></div>
<header class="topbar"><a class="brand" href="?page=home"><span class="brand-mark">R</span><span>RIGHT BEHIND YOU</span></a><nav><a href="?page=leaderboard">Hall of echoes</a><?php if (logged_in()): ?><span class="nav-player"><?= esc((string)$_SESSION['player_name']) ?></span><form method="post" action="?action=logout" class="logout-form"><input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>"><button type="submit">Leave game</button></form><?php else: ?><a href="?page=login">Sign in</a><?php endif; ?></nav></header>
<main>
<?php if ($page === 'home'): ?>
<section class="hero"><div class="hero-copy"><span class="kicker"><i></i> A THREE ROOM ESCAPE</span><h1>Somewhere,<br>there’s a way <em>out.</em></h1><p class="lede">The house keeps sending you back. Find the clues, trust what feels out of place, and look again when you return.</p><div class="hero-actions"><a class="button button-primary" href="?page=register">Enter the house <span>↗</span></a><a class="text-link" href="?page=login">I’ve been here before</a></div><div class="meta-row"><span>3 connected rooms</span><span>Session saved</span><span>One hidden exit</span></div></div><div class="hero-art" aria-label="An old house at night, lit by a single window"><div class="moon"></div><div class="cloud cloud-one"></div><div class="cloud cloud-two"></div><div class="house"><div class="roof"></div><div class="house-body"><div class="window window-one"></div><div class="window window-two"></div><div class="door"></div></div></div><div class="hill"></div><span class="art-caption">THE HOUSE · 12:00 AM</span><span class="art-number">01 / 03</span></div></section>
<section class="intro-strip"><span class="kicker">A NOTE ON THE DOOR</span><p>“If the hallway looks familiar, <em>pay attention to what changed.”</em></p><span class="intro-mark">✳</span></section>
<?php elseif ($page === 'login' || $page === 'register'): ?>
<section class="auth-layout"><div class="auth-art"><div class="auth-art-text"><span class="kicker">THE HOUSE IS WAITING</span><p>It remembers<br>every <em>wrong turn.</em></p></div><div class="auth-light"></div></div><div class="auth-card"><span class="kicker"><?= $page === 'login' ? 'WELCOME BACK' : 'BEGIN YOUR STORY' ?></span><h1><?= $page === 'login' ? 'Step inside.' : 'Make yourself known.' ?></h1><p class="muted"><?= $page === 'login' ? 'Your next clue is right where you left it.' : 'A name and a little curiosity are all you need.' ?></p><?php if (!empty($notice)): ?><div class="notice" role="alert"><?= esc($notice) ?></div><?php endif; ?><form method="post" action="?action=<?= $page ?>" class="form-stack"><input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>"><?php if ($page === 'register'): ?><label>Your name<input name="name" autocomplete="name" minlength="2" maxlength="32" required></label><?php endif; ?><label>Email address<input name="email" type="email" autocomplete="email" required></label><label>Password<input name="password" type="password" autocomplete="<?= $page === 'login' ? 'current-password' : 'new-password' ?>" minlength="8" required></label><button class="button button-primary" type="submit"><?= $page === 'login' ? 'Sign in' : 'Create account' ?> <span>↗</span></button></form><p class="switch-auth"><?= $page === 'login' ? 'New to the house?' : 'Already have an account?' ?> <a href="?page=<?= $page === 'login' ? 'register' : 'login' ?>"><?= $page === 'login' ? 'Create an account' : 'Sign in' ?></a></p></div></section>
<?php elseif ($page === 'game'): ?>
<section class="game-shell"><div class="game-heading"><div><span class="kicker"><?= esc($room['eyebrow']) ?></span><h1><?= esc($room['title']) ?></h1></div><div class="run-badge"><span>LOOP</span><strong><?= str_pad((string)($loops + 1), 2, '0', STR_PAD_LEFT) ?></strong></div></div><div class="progress" aria-label="Room <?= $roomNumber ?> of 3"><?php for($i=1;$i<=3;$i++): ?><span class="progress-step <?= $i < $roomNumber ? 'done' : ($i === $roomNumber ? 'active' : '') ?>"><i></i><b>0<?= $i ?></b></span><?php endfor; ?></div><div class="room-layout"><div class="room-scene scene-<?= esc($room['object']) ?>"><div class="scene-glow"></div><div class="scene-object"><span><?= $roomNumber === 1 ? '12:00' : ($roomNumber === 2 ? '✳' : 'HOME') ?></span></div><span class="scene-label"><?= esc($room['eyebrow']) ?></span></div><div class="puzzle-panel"><span class="kicker">LOOK CLOSELY</span><h2><?= esc($room['prompt']) ?></h2><p><?= esc($room['description']) ?></p><blockquote><span>FIELD NOTE</span><?= esc($room['clue']) ?></blockquote><form id="puzzle-form" class="form-stack" method="post" action="?action=solve"><input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>"><label for="answer">Your answer</label><div class="answer-row"><input id="answer" name="answer" placeholder="Type what you notice…" autocomplete="off" required><button type="submit" class="button button-primary" aria-label="Submit answer">↗</button></div><div id="feedback" class="feedback" role="status" aria-live="polite"></div></form><div class="room-footer"><span>ATTEMPTS: <b><?= (int)($_SESSION['attempts'][$roomNumber] ?? 0) ?></b></span><span><?= (int)($_SESSION['moves'] ?? 0) ?> MOVES THIS RUN</span></div><?php if ($loops > 0): ?><form method="post" action="?action=escape" class="escape-form"><input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>"><button class="text-link" type="submit">Try the other way ←</button><small>The house has shown you the way back before.</small></form><?php endif; ?></div></div><div class="game-bottom"><span>✳ &nbsp;Progress saves to your session</span><a href="?page=leaderboard">View the hall of echoes ↗</a></div></section>
<?php elseif ($page === 'leaderboard'): ?>
<section class="leaderboard-page"><span class="kicker">THE NAMES THAT MADE IT OUT</span><h1>Hall of <em>echoes.</em></h1><p class="lede">Every escape leaves a trace. The fewest loops find the door first.</p><?php if (!$scores): ?><div class="empty-state"><span>✳</span><h2>No one has escaped yet.</h2><p>Be the first to find the way out.</p><a class="button button-primary" href="?page=register">Enter the house ↗</a></div><?php else: ?><div class="score-table"><div class="score-row score-head"><span>RANK</span><span>PLAYER</span><span>LOOPS</span><span>TIME</span></div><?php foreach($scores as $i=>$score): ?><div class="score-row"><span class="rank">#<?= str_pad((string)($i+1),2,'0',STR_PAD_LEFT) ?></span><strong><?= esc((string)$score['name']) ?></strong><span><?= (int)$score['loops'] ?></span><span><?= gmdate('i:s',(int)$score['seconds']) ?></span></div><?php endforeach; ?></div><?php endif; ?></section>
<?php elseif ($page === 'ending'): ?>
<section class="ending"><span class="kicker"><i></i> THE DOOR WAS THERE ALL ALONG</span><div class="ending-orb">✳</div><h1>You made it <em>out.</em></h1><p>The house is quiet behind you. It will remember your name.</p><div class="ending-stats"><span>LOOPS<strong><?= (int)$_SESSION['loops'] ?></strong></span><span>MOVES<strong><?= (int)$_SESSION['moves'] ?></strong></span><span>TIME<strong><?= gmdate('i:s',max(1,time()-(int)$_SESSION['run_started'])) ?></strong></span></div><a class="button button-primary" href="?page=leaderboard">See your place in the hall ↗</a><form method="post" action="?action=logout" class="ending-logout"><input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>"><button class="text-link" type="submit">Leave the house</button></form></section>
<?php endif; ?>
</main><footer class="footer"><span>RIGHT BEHIND YOU <i>·</i> AN INTERACTIVE ESCAPE</span><span>LOOK AGAIN.</span></footer>
<script src="game.js" defer></script></body></html>
