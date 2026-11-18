<?php
declare(strict_types=1);

// User accounts are stored in a private flat file; game progress and scores stay in PHP sessions.
const DATA_DIR = __DIR__ . '/storage';
const ROOMS = [
    1 => [
        'title' => 'The Storm Entry', 'eyebrow' => 'ROOM 01 · RANGER STATION',
        'description' => 'Rain rattles the windows. You ducked into an abandoned ranger station to wait out the storm, and your camera is still recording. A four-slot lock sits under the stopped clock.',
        'clue' => 'A trail guide is pinned beside it: “Hours in one day, then the hour this clock stopped.” The clock reads midnight.',
        'answer' => '2412', 'object' => 'clock', 'prompt' => 'What opens the old trail lock?',
    ],
    2 => [
        'title' => 'The Glasshouse', 'eyebrow' => 'ROOM 02 · OLD GREENHOUSE',
        'description' => 'A greenhouse leans against the station. Three planters are marked A, B, and C. One is warm, even though the storm should have put everything out.',
        'clue' => 'A note in the soil asks: “What can still glow after the flame is gone?”',
        'answer' => 'ember', 'object' => 'greenhouse', 'prompt' => 'What is left glowing?',
    ],
    3 => [
        'title' => 'The Ranger Archive', 'eyebrow' => 'ROOM 03 · RECORDS BELOW',
        'description' => 'Behind the greenhouse, a service lift drops into the station records room. Its call panel has one blank button. Your camera catches a muddy trail map on the floor.',
        'clue' => 'A ranger wrote: “When the storm lets up, where are you trying to get back to?”',
        'answer' => 'home', 'object' => 'archive', 'prompt' => 'What should the lift call?',
    ],
];

function h(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function post_text(string $key): string {
    $value = filter_input(INPUT_POST, $key, FILTER_UNSAFE_RAW);
    return is_string($value) ? $value : '';
}
function read_users(): array {
    $path = DATA_DIR . '/users.json';
    if (!is_file($path)) return [];
    $data = json_decode((string)file_get_contents($path), true);
    return is_array($data) ? $data : [];
}
function save_users(array $users): bool {
    if (!is_dir(DATA_DIR) && !mkdir(DATA_DIR, 0750, true) && !is_dir(DATA_DIR)) return false;
    $json = json_encode($users, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    return $json !== false && file_put_contents(DATA_DIR . '/users.json', $json, LOCK_EX) !== false;
}
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return (string)$_SESSION['csrf'];
}
function csrf_valid(): bool {
    return isset($_POST['csrf'], $_SESSION['csrf']) && hash_equals((string)$_SESSION['csrf'], (string)$_POST['csrf']);
}
function redirect(string $to): never { header('Location: ' . $to, true, 303); exit; }
function set_flash(string $message, string $type = 'info'): void {
    $_SESSION['flash'] = ['message' => $message, 'type' => $type];
}
function start_player(array $user): never {
    session_regenerate_id(true);
    $_SESSION = [
        'csrf' => bin2hex(random_bytes(32)),
        'player_id' => $user['id'], 'player_name' => $user['name'],
        'current_room' => 1, 'area_unlocked' => [], 'attempts' => [],
        'scored_rooms' => [], 'score' => 0, 'moves' => 0, 'loops' => 0,
        'run_started' => time(), 'scores' => [],
    ];
    redirect('?page=game');
}
function hint_for(int $room, int $attempt): string {
    $tiers = [
        1 => [
            1 => 'The lock needs two numbers in order.',
            2 => 'First count the hours in a full day; then read the stopped clock.',
            3 => 'Put 24 before the clock’s midnight hour, 12.',
        ],
        2 => [
            1 => 'Think of a tiny glow left in a fire.',
            2 => 'It is a coal that stays warm after the flames die.',
            3 => 'The word starts with E and ends with R.',
        ],
        3 => [
            1 => 'The lift wants a destination, not a floor number.',
            2 => 'Think where you want to be when the storm ends.',
            3 => 'It is the place you were before this trip.',
        ],
    ];
    $tier = min(3, max(1, $attempt));
    return $tiers[$room][$tier] ?? 'Look again at the note in this room.';
}
function answer_type_note(int $room, string $answer): string {
    if ($room === 1 && !ctype_digit($answer)) return 'This lock takes digits.';
    if ($room !== 1 && ctype_digit($answer)) return 'This clue wants a word.';
    return 'That does not fit what the camera sees.';
}
function add_session_score(array $entry): void {
    $_SESSION['scores'] ??= [];
    $_SESSION['scores'][] = $entry;
    usort($_SESSION['scores'], static fn(array $a, array $b): int =>
        ($b['score'] <=> $a['score']) ?: ($a['seconds'] <=> $b['seconds']) ?: ($a['loops'] <=> $b['loops'])
    );
    $_SESSION['scores'] = array_slice($_SESSION['scores'], 0, 10);
}

$secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
session_name('right_behind_you');
session_set_cookie_params(['httponly' => true, 'secure' => $secure, 'samesite' => 'Lax', 'path' => '/']);
session_start();

$action = (string)($_GET['action'] ?? '');
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$page = (string)($_GET['page'] ?? 'home');
$notice = '';
$noticeType = 'info';
$oldName = '';
$oldEmail = '';
$oldAnswer = '';

if ($action === 'register' && $method === 'POST') {
    $oldName = trim(post_text('name'));
    $oldEmail = strtolower(trim(post_text('email')));
    $password = post_text('password');
    if (!csrf_valid()) $notice = 'This form expired. Reload it and try again.';
    elseif (mb_strlen($oldName) < 2 || mb_strlen($oldName) > 32) $notice = 'Use a name between 2 and 32 characters.';
    elseif (!filter_var($oldEmail, FILTER_VALIDATE_EMAIL)) $notice = 'Enter a valid email address.';
    elseif (strlen($password) < 8) $notice = 'Use a password with at least 8 characters.';
    else {
        $users = read_users();
        if (isset($users[$oldEmail])) $notice = 'That email already has a field log. Pick it up by signing in.';
        else {
            $user = ['id' => bin2hex(random_bytes(12)), 'name' => $oldName, 'password' => password_hash($password, PASSWORD_DEFAULT)];
            $users[$oldEmail] = $user;
            if (!save_users($users)) $notice = 'The account could not be saved. Check the storage folder permissions.';
            else start_player($user);
        }
    }
    $page = 'register';
    $noticeType = 'error';
} elseif ($action === 'login' && $method === 'POST') {
    $oldEmail = strtolower(trim(post_text('email')));
    $password = post_text('password');
    $user = read_users()[$oldEmail] ?? null;
    if (!csrf_valid()) $notice = 'This form expired. Reload it and try again.';
    elseif (!filter_var($oldEmail, FILTER_VALIDATE_EMAIL) || !$user || !password_verify($password, (string)($user['password'] ?? ''))) $notice = 'That email and password did not match.';
    else start_player($user);
    $page = 'login';
    $noticeType = 'error';
} elseif ($action === 'solve' && $method === 'POST') {
    if (empty($_SESSION['player_id'])) redirect('?page=login');
    if (!csrf_valid()) {
        set_flash('This answer form expired. Refresh the page and try again.', 'error');
        redirect('?page=game');
    }
    $roomNumber = (int)($_SESSION['current_room'] ?? 0);
    if (!isset(ROOMS[$roomNumber]) || isset($_SESSION['area_unlocked'][$roomNumber])) redirect('?page=game');
    $oldAnswer = trim(post_text('answer'));
    $answer = mb_strtolower($oldAnswer);
    $_SESSION['moves'] = (int)($_SESSION['moves'] ?? 0) + 1;
    if ($answer === '') {
        set_flash('No answer came through. Put a number or word in the camera notes box.', 'error');
        $_SESSION['old_answer'] = $oldAnswer;
        redirect('?page=game');
    }
    if (mb_strlen($answer) > 40) {
        set_flash('That answer is too long for this lock. Keep it under 40 characters.', 'error');
        $_SESSION['old_answer'] = '';
        redirect('?page=game');
    }
    if (!hash_equals(ROOMS[$roomNumber]['answer'], $answer)) {
        $_SESSION['attempts'][$roomNumber] = (int)($_SESSION['attempts'][$roomNumber] ?? 0) + 1;
        $attempt = (int)$_SESSION['attempts'][$roomNumber];
        $penalty = min(30, $attempt * 10);
        $_SESSION['score'] = max(0, (int)($_SESSION['score'] ?? 0) - $penalty);
        $note = answer_type_note($roomNumber, $answer);
        $hint = hint_for($roomNumber, $attempt);
        set_flash($note . ' ' . $hint . ' (' . $penalty . ' points off.)', 'error');
        $_SESSION['old_answer'] = $oldAnswer;
        redirect('?page=game');
    }

    $_SESSION['area_unlocked'][$roomNumber] = true;
    if (empty($_SESSION['scored_rooms'][$roomNumber])) {
        $_SESSION['score'] = (int)($_SESSION['score'] ?? 0) + 100;
        $_SESSION['scored_rooms'][$roomNumber] = true;
    }
    $_SESSION['old_answer'] = '';
    if ($roomNumber < 3) {
        $_SESSION['current_room'] = $roomNumber + 1;
        set_flash('The lock gave way. I’m going to see where this hall leads.', 'success');
        redirect('?page=game');
    }
    $_SESSION['loops'] = (int)($_SESSION['loops'] ?? 0) + 1;
    $_SESSION['current_room'] = 1;
    $_SESSION['area_unlocked'] = [];
    $_SESSION['attempts'] = [];
    set_flash('The lift opened onto the same entry hall. I didn’t get out—I came back through the station. Wait, the camera caught a door on the left.', 'loop');
    redirect('?page=game');
} elseif ($action === 'escape' && $method === 'POST') {
    if (empty($_SESSION['player_id'])) redirect('?page=login');
    if (!csrf_valid() || (int)($_SESSION['loops'] ?? 0) < 1) redirect('?page=game');
    $seconds = max(1, time() - (int)($_SESSION['run_started'] ?? time()));
    add_session_score([
        'name' => (string)$_SESSION['player_name'], 'score' => (int)($_SESSION['score'] ?? 0),
        'loops' => (int)$_SESSION['loops'], 'seconds' => $seconds,
    ]);
    $_SESSION['escaped'] = true;
    redirect('?page=ending');
} elseif ($action === 'logout' && $method === 'POST') {
    if (!csrf_valid()) redirect('?page=home');
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $cookie = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $cookie['path'], $cookie['domain'], $cookie['secure'], $cookie['httponly']);
    }
    session_destroy();
    redirect('?page=home');
}

$allowedPages = ['home', 'register', 'login', 'game', 'leaderboard', 'ending', 'project'];
if (!in_array($page, $allowedPages, true)) $page = 'home';
if (in_array($page, ['game', 'ending'], true) && empty($_SESSION['player_id'])) redirect('?page=login');
if ($page === 'ending' && empty($_SESSION['escaped'])) redirect('?page=game');
if ($page === 'game' && empty($_SESSION['current_room'])) {
    $_SESSION['current_room'] = 1;
    $_SESSION['area_unlocked'] = [];
    $_SESSION['attempts'] = [];
    $_SESSION['scored_rooms'] = [];
    $_SESSION['score'] = 0;
    $_SESSION['moves'] = 0;
    $_SESSION['loops'] = 0;
    $_SESSION['run_started'] = time();
}
$roomNumber = (int)($_SESSION['current_room'] ?? 1);
$room = ROOMS[$roomNumber] ?? ROOMS[1];
$loops = (int)($_SESSION['loops'] ?? 0);
$scores = is_array($_SESSION['scores'] ?? null) ? $_SESSION['scores'] : [];
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
if (is_array($flash)) { $notice = (string)($flash['message'] ?? ''); $noticeType = (string)($flash['type'] ?? 'info'); }
if (isset($_SESSION['old_answer'])) { $oldAnswer = (string)$_SESSION['old_answer']; unset($_SESSION['old_answer']); }
$csrf = csrf_token();
$pageTitles = ['home' => 'Right Behind You', 'register' => 'New field log', 'login' => 'Resume recording', 'game' => $room['title'], 'leaderboard' => 'Trail notes', 'ending' => 'Made it outside', 'project' => 'About this project'];
$title = $pageTitles[$page];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="theme-color" content="#172b35"><title><?= h($title) ?> · Right Behind You</title>
  <link rel="stylesheet" href="app.css">
</head>
<body class="page-<?= h($page) ?>">
<div class="grain" aria-hidden="true"></div>
<header class="topbar"><a class="brand" href="?page=home"><span class="brand-mark">R</span><span>RIGHT BEHIND YOU</span></a><nav><a href="?page=leaderboard">Trail notes</a><a href="?page=project">Project</a><?php if (!empty($_SESSION['player_id'])): ?><span class="nav-player"><?= h((string)$_SESSION['player_name']) ?></span><form method="post" action="?action=logout" class="logout-form"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><button type="submit">End recording</button></form><?php else: ?><a href="?page=login">Resume</a><?php endif; ?></nav></header>
<main>
<?php if ($page === 'home'): ?>
<section class="hero">
  <div class="hero-copy"><span class="kicker"><i></i> FIELD LOG · 12:14 AM</span><h1>Storm knocked<br>me off the <em>trail.</em></h1><p class="lede">I ducked into an abandoned ranger station in the woods. The rooms don’t line up, the rain is getting louder, and my camera is still rolling.</p><div class="hero-actions"><a class="button button-primary" href="?page=register">Start the field log <span>↗</span></a><a class="text-link" href="?page=login">Pick up the recording</a></div><div class="meta-row"><span>3 rooms to check</span><span>Storm outside</span><span>Camera still on</span></div></div>
  <div class="hero-art" aria-label="A hand-drawn ranger station in the woods during a storm"><div class="scene-note">WOODLAND ROAD · NO SIGNAL <b><i></i> REC</b></div><svg class="storm-doodle" viewBox="0 0 640 390" role="img" aria-label="A crooked little station between rain-soaked pine trees"><path class="doodle-cloud" d="M68 92c3-18 27-22 38-9 7-22 39-19 42 5 20-10 35 7 31 22H73c-8-4-10-11-5-18Z"/><path class="doodle-rain" d="m111 128-8 22m39-18-7 24m-72-17-8 20m438-49-8 22m40-19-7 22m42-4-7 20M186 188l-7 20m377-7-8 22m-310-4-7 21m279-2-8 23m-185 7-7 20m249-4-8 20"/><path class="pine" d="M39 283 82 175l42 108h-24l30 52H35l29-52Zm476 2 38-95 39 95h-22l28 49h-88l27-49Z"/><path class="station-wall" d="m205 230 111-83 119 82-3 126H208Z"/><path class="station-roof" d="m193 237 122-99 132 98"/><path class="station-trim" d="M208 355h225M229 239v116m178-116v116M265 355v-77q0-25 25-25t25 25v77"/><path class="station-window" d="M236 264h40v43h-40zm93 0h43v43h-43z"/><path class="trail" d="M317 355c-8 18 10 20 4 35m26-35c12 13-3 21 8 35"/><path class="camera-body" d="M68 314h65q9 0 9 9v43q0 8-9 8H68q-9 0-9-8v-43q0-9 9-9Zm74 12 26-15v65l-26-15Z"/><circle class="camera-lens" cx="96" cy="345" r="15"/><circle class="rec-light" cx="125" cy="325" r="4"/><path class="scribble-arrow" d="M202 330H151m10-10-10 10 10 10"/></svg><span class="art-caption">TAKE 01 · WAITING OUT THE RAIN</span><span class="art-number">CAM 01</span></div>
</section>
<section class="intro-strip"><span class="kicker">CAMERA AUDIO</span><p>“If I end up back in that hall, check the <em>left wall.</em>”</p><span class="intro-mark">REC</span></section>
<?php elseif ($page === 'register' || $page === 'login'): ?>
<section class="auth-layout"><div class="auth-art"><div class="auth-art-text"><span class="kicker">CAMERA NOTE · BEFORE GOING IN</span><p><?= $page === 'login' ? 'The storm got worse.<br>I went back in.' : 'Just a quick stop<br>until the <em>rain quits.</em>' ?></p><small>RANGER STATION · WOODLAND TRAIL</small></div><div class="auth-light"></div></div><div class="auth-card"><span class="kicker"><?= $page === 'login' ? 'RESUME RECORDING' : 'NEW FIELD LOG' ?></span><h1><?= $page === 'login' ? 'Where did I leave off?' : 'Start the recording.' ?></h1><p class="muted"><?= $page === 'login' ? 'The camera kept running while you were away.' : 'Name the video log before heading inside.' ?></p><?php if ($notice !== ''): ?><div class="notice <?= h($noticeType) ?>" role="alert"><?= h($notice) ?></div><?php endif; ?><form method="post" action="?action=<?= h($page) ?>" class="form-stack"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><?php if ($page === 'register'): ?><label for="player-name">Your name<input id="player-name" name="name" autocomplete="name" minlength="2" maxlength="32" value="<?= h($oldName) ?>" required></label><?php endif; ?><label for="player-email">Email address<input id="player-email" name="email" type="email" autocomplete="email" value="<?= h($oldEmail) ?>" required></label><label for="player-password">Password<input id="player-password" name="password" type="password" autocomplete="<?= $page === 'login' ? 'current-password' : 'new-password' ?>" minlength="8" required></label><button class="button button-primary" type="submit"><?= $page === 'login' ? 'Resume the log' : 'Start the log' ?> <span>↗</span></button></form><p class="switch-auth"><?= $page === 'login' ? 'First time here?' : 'Already made a log?' ?> <a href="?page=<?= $page === 'login' ? 'register' : 'login' ?>"><?= $page === 'login' ? 'Make one' : 'Pick it up' ?></a></p></div></section>
<?php elseif ($page === 'game'): ?>
<section class="game-shell"><div class="game-heading"><div><span class="kicker"><?= h($room['eyebrow']) ?></span><h1><?= h($room['title']) ?></h1></div><div class="run-badge"><span>LOOP</span><strong><?= str_pad((string)max(1, $loops), 2, '0', STR_PAD_LEFT) ?></strong></div></div><div class="progress" aria-label="Room <?= $roomNumber ?> of 3"><?php for ($i = 1; $i <= 3; $i++): ?><span class="progress-step <?= $i < $roomNumber ? 'done' : ($i === $roomNumber ? 'active' : '') ?>"><i></i><b>0<?= $i ?></b></span><?php endfor; ?></div><div class="room-score">CAMERA SCORE <strong><?= (int)($_SESSION['score'] ?? 0) ?></strong><span>+100 for each new room · wrong guesses cost points</span></div><div class="room-layout"><div class="room-scene scene-<?= h($room['object']) ?>"><div class="scene-glow"></div><div class="scene-object"><span><?= $roomNumber === 1 ? '12:00' : ($roomNumber === 2 ? '✦' : 'HOME') ?></span></div><span class="scene-label"><?= h($room['eyebrow']) ?></span><span class="scene-rec"><i></i> REC</span></div><div class="puzzle-panel"><span class="kicker">CAMERA NOTE · LOOK CLOSER</span><h2><?= h($room['prompt']) ?></h2><p><?= h($room['description']) ?></p><blockquote><span>VOICE MEMO</span><?= h($room['clue']) ?></blockquote><?php if ($notice !== ''): ?><div class="notice <?= h($noticeType) ?>" role="status"><?= h($notice) ?></div><?php endif; ?><form class="form-stack" method="post" action="?action=solve"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><label for="answer">Your answer</label><div class="answer-row"><input id="answer" name="answer" value="<?= h($oldAnswer) ?>" maxlength="40" placeholder="Say what you see…" autocomplete="off" required><button type="submit" class="button button-primary">Submit clip ↗</button></div></form><div class="room-footer"><span>RETAKES: <b><?= (int)($_SESSION['attempts'][$roomNumber] ?? 0) ?></b></span><span><?= (int)($_SESSION['moves'] ?? 0) ?> CLIPS THIS RUN</span></div><?php if ($loops > 0): ?><form method="post" action="?action=escape" class="escape-form"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><button class="text-link" type="submit">Wait—I saw a door on the left ←</button><small>The lift put me back here. I have to re-enter to find the way out.</small></form><?php endif; ?></div></div><div class="game-bottom"><span>● &nbsp;Recording and progress save to this session</span><a href="?page=leaderboard">Check the trail log ↗</a></div></section>
<?php elseif ($page === 'leaderboard'): ?>
<section class="leaderboard-page"><span class="kicker">FIELD LOGS · PLAYERS WHO GOT OUT</span><h1>Trail <em>notes.</em></h1><p class="lede">These runs made it outside. Scores last for this browser session; fewer loops and a faster time break ties.</p><?php if (!$scores): ?><div class="empty-state"><span>REC</span><h2>No footage yet.</h2><p>Be the first to make it through the storm.</p><a class="button button-primary" href="?page=register">Start a field log ↗</a></div><?php else: ?><div class="score-table"><div class="score-row score-head"><span>RANK</span><span>PLAYER</span><span>SCORE</span><span>TIME</span></div><?php foreach ($scores as $i => $score): ?><div class="score-row"><span class="rank">#<?= str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT) ?></span><strong><?= h((string)$score['name']) ?></strong><span><?= (int)$score['score'] ?> · <?= (int)$score['loops'] ?> loops</span><span><?= gmdate('i:s', (int)$score['seconds']) ?></span></div><?php endforeach; ?></div><?php endif; ?></section>
<?php elseif ($page === 'ending'): ?>
<section class="ending"><span class="kicker"><i></i> FIELD LOG · END OF CLIP</span><div class="ending-orb">▶</div><h1>Made it <em>outside.</em></h1><p>Okay, camera, I’m out. The storm’s still going, but the trail is where it’s supposed to be. I’m not going back for the tripod.</p><div class="ending-stats"><span>SCORE<strong><?= (int)($_SESSION['score'] ?? 0) ?></strong></span><span>LOOPS<strong><?= (int)($_SESSION['loops'] ?? 0) ?></strong></span><span>TIME<strong><?= gmdate('i:s', max(1, time() - (int)($_SESSION['run_started'] ?? time()))) ?></strong></span></div><a class="button button-primary" href="?page=leaderboard">Check the trail log ↗</a><form method="post" action="?action=logout" class="ending-logout"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><button class="text-link" type="submit">End recording</button></form></section>
<?php elseif ($page === 'project'): ?>
<section class="leaderboard-page project-page"><span class="kicker">PROJECT FIELD CARD</span><h1>Right Behind <em>You.</em></h1><p class="lede">A PHP escape-room game about a vlogger sheltering from a storm, getting caught in an abandoned ranger station’s loop, and noticing the exit on the way back in.</p><div class="empty-state"><h2>Joshua Jones · Project lead</h2><p>Design and story · PHP game logic · HTML/CSS interface · testing and documentation</p><p>Topic 04: The PHP Vault</p></div></section>
<?php endif; ?>
</main><footer class="footer"><span>RIGHT BEHIND YOU <i>·</i> WOODLAND FIELD LOG</span><a href="?page=project">PROJECT CARD</a><span>STAY ON THE TRAIL.</span></footer>
</body>
</html>
