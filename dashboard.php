<?php
/**
 * FocusTrack v2 — dashboard.php
 * Handles: page render + JSON API for tasks, pomodoro, streak, metrics
 */
require_once __DIR__ . '/config.php';

if (!isset($_SESSION['user_id'])) redirect('auth.php');

$userId   = (int)$_SESSION['user_id'];
$userName = $_SESSION['user_name'] ?? 'Friend';

/* ── JSON API ────────────────────────────────────────────── */
$isApi = str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')
      || str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json');

if ($isApi) {
    header('Content-Type: application/json');
    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $body['action'] ?? '';

    if (!csrf_verify($body['csrf_token'] ?? '')) {
        echo json_encode(['ok'=>false,'error'=>'Invalid CSRF token.']); exit;
    }

    try {
        $db = get_db();

        /* ── TASK: ADD ─────────────────────────────────── */
        if ($action === 'add') {
            $title = trim($body['title'] ?? '');
            if (!$title) { echo json_encode(['ok'=>false,'error'=>'Title required.']); exit; }
            $db->prepare('INSERT INTO tasks (user_id,title) VALUES (?,?)')->execute([$userId,$title]);
            $id = (int)$db->lastInsertId();
            // Track daily add
            $db->prepare('INSERT INTO daily_activity (user_id,activity_date,tasks_added)
                          VALUES (?,?,1)
                          ON DUPLICATE KEY UPDATE tasks_added = tasks_added + 1')
               ->execute([$userId, today()]);
            echo json_encode(['ok'=>true,'task'=>['id'=>$id,'title'=>$title,'is_done'=>0,'completed_at'=>null]]);
            exit;
        }

        /* ── TASK: TOGGLE ──────────────────────────────── */
        if ($action === 'toggle') {
            $id = (int)($body['id'] ?? 0);
            $s  = $db->prepare('SELECT is_done FROM tasks WHERE id=? AND user_id=? AND DATE(created_at) = CURDATE()');
            $s->execute([$id,$userId]);
            $task = $s->fetch();
            if (!$task) { echo json_encode(['ok'=>false,'error'=>'Not found.']); exit; }

            $newDone = $task['is_done'] ? 0 : 1;
            $completedAt = $newDone ? date('Y-m-d H:i:s') : null;
            $db->prepare('UPDATE tasks SET is_done=?, completed_at=? WHERE id=? AND user_id=?')
               ->execute([$newDone, $completedAt, $id, $userId]);

            if ($newDone) {
                // Task marked complete: increment daily counter and update streak
                $db->prepare('INSERT INTO daily_activity (user_id,activity_date,tasks_completed)
                              VALUES (?,?,1)
                              ON DUPLICATE KEY UPDATE tasks_completed = tasks_completed + 1')
                   ->execute([$userId, today()]);
                updateStreak($db, $userId);
            } else {
                // Task un-done: decrement counter, floor at 0 to prevent negatives
                $db->prepare('INSERT INTO daily_activity (user_id,activity_date,tasks_completed)
                              VALUES (?,?,0)
                              ON DUPLICATE KEY UPDATE
                                tasks_completed = GREATEST(tasks_completed - 1, 0)')
                   ->execute([$userId, today()]);
                // If no tasks remain completed today, roll back today's streak credit
                rollbackStreakIfNeeded($db, $userId);
            }

            $streak = getStreakData($db, $userId);
            echo json_encode(['ok'=>true,'is_done'=>$newDone,'completed_at'=>$completedAt,'streak'=>$streak]);
            exit;
        }

        /* ── TASK: DELETE ──────────────────────────────── */
        if ($action === 'delete') {
            $id = (int)($body['id'] ?? 0);
            $db->prepare('DELETE FROM tasks WHERE id=? AND user_id=? AND DATE(created_at) = CURDATE()')->execute([$id,$userId]);
            echo json_encode(['ok'=>true]); exit;
        }

        /* ── POMODORO: START ───────────────────────────── */
        if ($action === 'pomo_start') {
            $taskId    = $body['task_id'] ? (int)$body['task_id'] : null;
            $taskTitle = trim($body['task_title'] ?? 'Focus Session');
            $duration  = max(20, (int)($body['duration_minutes'] ?? 25));
            $db->prepare('INSERT INTO pomodoro_sessions (user_id,task_id,task_title,duration_minutes,started_at)
                          VALUES (?,?,?,?,NOW())')
               ->execute([$userId, $taskId, $taskTitle, $duration]);
            echo json_encode(['ok'=>true,'session_id'=>(int)$db->lastInsertId(),'duration_minutes'=>$duration]);
            exit;
        }

        /* ── POMODORO: COMPLETE ────────────────────────── */
        if ($action === 'pomo_complete') {
            $sessionId = (int)($body['session_id'] ?? 0);
            $completed = (int)($body['completed'] ?? 0);
            $s = $db->prepare('SELECT duration_minutes FROM pomodoro_sessions WHERE id=? AND user_id=?');
            $s->execute([$sessionId,$userId]);
            $session = $s->fetch();
            if ($session) {
                $db->prepare('UPDATE pomodoro_sessions SET ended_at=NOW(), completed=? WHERE id=? AND user_id=?')
                   ->execute([$completed, $sessionId, $userId]);
                if ($completed) {
                    $mins = (int)$session['duration_minutes'];
                    $db->prepare('INSERT INTO daily_activity (user_id,activity_date,pomodoro_minutes)
                                  VALUES (?,?,?)
                                  ON DUPLICATE KEY UPDATE pomodoro_minutes = pomodoro_minutes + ?')
                       ->execute([$userId, today(), $mins, $mins]);
                }
            }
            echo json_encode(['ok'=>true]); exit;
        }

        /* ── METRICS ───────────────────────────────────── */
        if ($action === 'metrics') {
            echo json_encode(['ok'=>true, 'data'=>getMetrics($db, $userId)]); exit;
        }

        /* HISTORY */
        if ($action === 'history') {
            $stmt = $db->prepare(
                "SELECT id, title, is_done, DATE(created_at) as task_date
                 FROM tasks
                 WHERE user_id = ?
                   AND DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                   AND DATE(created_at) < CURDATE()
                 ORDER BY created_at ASC"
            );
            $stmt->execute([$userId]);
            $rows = $stmt->fetchAll();

            $grouped = [];
            foreach ($rows as $row) {
                $date = $row['task_date'];
                if (!isset($grouped[$date])) {
                    $grouped[$date] = [
                        'date'      => $date,
                        'label'     => date('l, M j', strtotime($date)),
                        'tasks'     => [],
                        'total'     => 0,
                        'completed' => 0,
                    ];
                }
                $grouped[$date]['tasks'][] = [
                    'id'      => (int)$row['id'],
                    'title'   => $row['title'],
                    'is_done' => (int)$row['is_done'],
                ];
                $grouped[$date]['total']++;
                if ($row['is_done']) $grouped[$date]['completed']++;
            }

            for ($i = 7; $i >= 1; $i--) {
                $d = date('Y-m-d', strtotime("-$i days"));
                if (!isset($grouped[$d])) {
                    $grouped[$d] = [
                        'date'      => $d,
                        'label'     => date('l, M j', strtotime($d)),
                        'tasks'     => [],
                        'total'     => 0,
                        'completed' => 0,
                    ];
                }
            }

            krsort($grouped);
            echo json_encode(['ok' => true, 'days' => array_values($grouped)]);
            exit;
        }

    } catch (PDOException $e) {
        echo json_encode(['ok'=>false,'error'=>'Database error: '.$e->getMessage()]); exit;
    }

    echo json_encode(['ok'=>false,'error'=>'Unknown action.']); exit;
}

/* ── LOGOUT ──────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='logout') {
    if (csrf_verify($_POST['csrf_token']??'')) { $_SESSION=[]; session_destroy(); redirect('auth.php'); }
}
/* ── To Check and Reset Streak ─────────────────────────────────────────────── */
function checkAndResetStreak(PDO $db, int $userId): void {
    $yesterday = date('Y-m-d', strtotime('-1 day'));

    $s = $db->prepare(
        'SELECT current_streak, last_active FROM streaks WHERE user_id = ?'
    );
    $s->execute([$userId]);
    $streak = $s->fetch();

    // No streak row, or last active was yesterday or today — nothing to reset
    if (!$streak || $streak['current_streak'] == 0) return;
    if ($streak['last_active'] >= $yesterday) return;

    // Last active was 2+ days ago — streak is broken, reset to 0
    $db->prepare(
        'UPDATE streaks SET current_streak = 0, last_active = NULL WHERE user_id = ?'
    )->execute([$userId]);
}
/* ── HELPERS ─────────────────────────────────────────────── */
function rollbackStreakIfNeeded(PDO $db, int $userId): void {
    $today = today();

    // Check how many tasks are still completed today after the decrement
    $s = $db->prepare(
        'SELECT tasks_completed FROM daily_activity
          WHERE user_id = ? AND activity_date = ?'
    );
    $s->execute([$userId, $today]);
    $row = $s->fetch();

    // If still at least 1 task completed, streak credit is still valid — do nothing
    if ($row && (int)$row['tasks_completed'] > 0) return;

    // Zero tasks completed today: remove today's streak credit
    $s2 = $db->prepare('SELECT current_streak, longest_streak, last_active FROM streaks WHERE user_id=?');
    $s2->execute([$userId]);
    $streak = $s2->fetch();

    if (!$streak || $streak['last_active'] !== $today) return; // today was never credited

    // Decrement streak by 1 (floor at 0), revert last_active to yesterday if streak > 0
    $newCur     = max((int)$streak['current_streak'] - 1, 0);
    // If streak drops to 0 there is no meaningful last_active day
    $newLastActive = $newCur > 0 ? date('Y-m-d', strtotime('-1 day')) : null;

    $db->prepare('UPDATE streaks SET current_streak=?, last_active=? WHERE user_id=?')
       ->execute([$newCur, $newLastActive, $userId]);
    // Note: longest_streak is never reduced — it records the historical best
}

function updateStreak(PDO $db, int $userId): void {
    $today     = today();
    $yesterday = date('Y-m-d', strtotime('-1 day'));

    $s = $db->prepare('SELECT current_streak, longest_streak, last_active FROM streaks WHERE user_id=?');
    $s->execute([$userId]);
    $streak = $s->fetch();

    if (!$streak) {
        $db->prepare('INSERT INTO streaks (user_id,current_streak,longest_streak,last_active) VALUES (?,1,1,?)')
           ->execute([$userId, $today]);
        return;
    }

    $lastActive = $streak['last_active'];
    $cur        = (int)$streak['current_streak'];
    $longest    = (int)$streak['longest_streak'];

    if ($lastActive === $today) return; // Already counted today

    $newCur = ($lastActive === $yesterday) ? $cur + 1 : 1;
    $newLng = max($longest, $newCur);

    $db->prepare('UPDATE streaks SET current_streak=?, longest_streak=?, last_active=? WHERE user_id=?')
       ->execute([$newCur, $newLng, $today, $userId]);
}

function getStreakData(PDO $db, int $userId): array {
    $s = $db->prepare('SELECT current_streak, longest_streak, last_active FROM streaks WHERE user_id=?');
    $s->execute([$userId]);
    $row = $s->fetch() ?: ['current_streak'=>0,'longest_streak'=>0,'last_active'=>null];

    // Last 7 days activity
    $week = $db->prepare('SELECT activity_date FROM daily_activity
                           WHERE user_id=? AND activity_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
                             AND tasks_completed > 0');
    $week->execute([$userId]);
    $activeDates = array_column($week->fetchAll(), 'activity_date');

    $days = [];
    for ($i = 6; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i days"));
        $days[] = ['date'=>$d, 'label'=>date('D', strtotime($d)), 'active'=>in_array($d,$activeDates)];
    }

    return [
        'current'  => (int)$row['current_streak'],
        'longest'  => (int)$row['longest_streak'],
        'last_active' => $row['last_active'],
        'week'     => $days,
    ];
}

function getMetrics(PDO $db, int $userId): array {
    // 30-day window
    $s30 = $db->prepare('SELECT activity_date, tasks_completed, tasks_added, pomodoro_minutes
                          FROM daily_activity
                          WHERE user_id=? AND activity_date >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
                          ORDER BY activity_date ASC');
    $s30->execute([$userId]);
    $raw30 = $s30->fetchAll();

    // Build full 30-day array
    $map30 = [];
    foreach ($raw30 as $r) $map30[$r['activity_date']] = $r;
    $days30 = [];
    for ($i = 29; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i days"));
        $days30[] = [
            'date'   => $d,
            'label'  => date('M j', strtotime($d)),
            'completed' => (int)($map30[$d]['tasks_completed'] ?? 0),
            'added'     => (int)($map30[$d]['tasks_added']     ?? 0),
            'pomo_min'  => (int)($map30[$d]['pomodoro_minutes']?? 0),
        ];
    }
    $days10 = array_slice($days30, -10);

    // Totals
    $totCompleted = array_sum(array_column($days30, 'completed'));
    $totPomo      = array_sum(array_column($days30, 'pomo_min'));
    $maxDay       = max(array_column($days30, 'completed') ?: [0]);
    $avgPerDay    = $totCompleted > 0 ? round($totCompleted / 30, 1) : 0;

    // Today summary
    $todayRow = $map30[today()] ?? ['tasks_completed'=>0,'tasks_added'=>0,'pomodoro_minutes'=>0];

    // Total tasks ever
    $tot = $db->prepare('SELECT COUNT(*) as c FROM tasks WHERE user_id=?'); $tot->execute([$userId]);
    $allTasks = (int)$tot->fetch()['c'];

    return [
        'days30'       => $days30,
        'days10'       => $days10,
        'total_completed_30' => $totCompleted,
        'total_pomo_min_30'  => $totPomo,
        'avg_per_day'        => $avgPerDay,
        'best_day'           => $maxDay,
        'all_tasks'          => $allTasks,
        'today'              => [
            'completed' => (int)$todayRow['tasks_completed'],
            'added'     => (int)$todayRow['tasks_added'],
            'pomo_min'  => (int)$todayRow['pomodoro_minutes'],
        ],
    ];
}

/* ── PAGE RENDER ─────────────────────────────────────────── */
try {
    $db    = get_db();
    checkAndResetStreak($db, $userId);
    $stmt  = $db->prepare(
        'SELECT id,title,is_done,completed_at FROM tasks
         WHERE user_id=? AND DATE(created_at) = CURDATE()
         ORDER BY created_at ASC'
    );
    $stmt->execute([$userId]);
    $tasks = $stmt->fetchAll();
} catch (PDOException $e) { $tasks = []; }

$total   = count($tasks);
$done    = array_sum(array_column($tasks, 'is_done'));
$pct     = $total > 0 ? (int)round($done/$total*100) : 0;
$csrf    = csrf_token();
$hour    = (int)date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');

try { $streak = getStreakData($db, $userId); }
catch (Exception $e) { $streak = ['current'=>0,'longest'=>0,'week'=>[]]; }

function feedback(int $p): string {
    if ($p===0)   return "A fresh start. Let's build something today.";
    if ($p<=25)   return "Great beginning. Keep the momentum going.";
    if ($p<=49)   return "Making real progress. You're doing well.";
    if ($p===50)  return "Halfway there. This is where it counts.";
    if ($p<=74)   return "Past the halfway mark. Finish strong.";
    if ($p<=99)   return "Almost there. One last push.";
    return "Everything done. Outstanding work today.";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Dashboard — FocusTrack</title>
  <link rel="preconnect" href="https://fonts.googleapis.com"/>
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin/>
  <link href="https://fonts.googleapis.com/css2?family=Cabinet+Grotesk:wght@400;500;700;800;900&family=Satoshi:wght@300;400;500;700&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="app.css"/>
</head>
<body class="dash-body">

  <!-- ── TOPBAR ───────────────────────────────────────────── -->
  <header class="dash-topbar">
    <a href="index.html" class="dash-brand">
      <span class="dash-logomark">◎</span>
      <span class="dash-logoname">FocusTrack</span>
    </a>

    <p class="dash-greeting">
      <?= $greeting ?>, <strong><?= htmlspecialchars($userName) ?></strong> 👋
    </p>

    <div class="dash-topbar-right">
      <!-- Streak badge in header -->
      <div class="header-streak-badge" id="headerStreakBadge">
        <span class="hsb-fire">🔥</span>
        <span class="hsb-count" id="headerStreakCount"><?= $streak['current'] ?></span>
        <span class="hsb-label">day streak</span>
      </div>

      <button class="btn-history" id="historyBtn" title="View last 7 days">
        <span>&#128197;</span> History
      </button>

      <form method="POST" action="dashboard.php">
        <input type="hidden" name="action"     value="logout"/>
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>"/>
        <button type="submit" class="btn-dash-signout">Sign out</button>
      </form>
    </div>
  </header>

  <!-- ── BENTO GRID ───────────────────────────────────────── -->
  <main class="bento-grid">

    <!-- ── ROW 1: Progress · Today · Add Task ─────────────── -->

    <!-- A: Progress Ring -->
    <div class="bento-card card-progress">
      <p class="card-label">Journey Progress</p>
      <div class="prog-layout">
        <div class="ring-host">
          <svg class="prog-ring" viewBox="0 0 120 120">
            <circle class="ring-track" cx="60" cy="60" r="50"/>
            <circle class="ring-arc" cx="60" cy="60" r="50"
                    id="ringArc"
                    stroke-dasharray="314"
                    stroke-dashoffset="<?= 314 - (int)round($pct/100*314) ?>"/>
          </svg>
          <div class="ring-inner">
            <span class="ring-pct" id="pctDisplay"><?= $pct ?>%</span>
            <span class="ring-sub">complete</span>
          </div>
        </div>
        <div class="prog-info">
          <p class="prog-feedback" id="feedbackMsg"><?= feedback($pct) ?></p>
          <div class="prog-bar-track">
            <div class="prog-bar-fill" id="progressBar" style="width:<?= $pct ?>%"></div>
          </div>
          <p class="prog-count">
            <span id="doneCount"><?= $done ?></span> of
            <span id="totalCount"><?= $total ?></span> tasks done
          </p>
        </div>
      </div>
    </div>

    <!-- B: Today Summary -->
    <div class="bento-card card-today">
      <p class="card-label">Today</p>
      <div class="today-stats" id="todayStats">
        <div class="ts-item">
          <span class="ts-val" id="todayDone">—</span>
          <span class="ts-key">done</span>
        </div>
        <div class="ts-divider"></div>
        <div class="ts-item">
          <span class="ts-val" id="todayAdded">—</span>
          <span class="ts-key">added</span>
        </div>
        <div class="ts-divider"></div>
        <div class="ts-item">
          <span class="ts-val" id="todayPomo">—</span>
          <span class="ts-key">focus min</span>
        </div>
      </div>
    </div>

    <!-- C: Add Task + Pomodoro -->
    <div class="bento-card card-add">
      <p class="card-label">New Task</p>
      <div class="add-row">
        <input type="text" id="taskInput" class="task-input"
               placeholder="What are you working on?" maxlength="255" autocomplete="off"/>
        <button class="btn-add" id="addBtn" title="Add task">+</button>
      </div>
      <p class="add-hint" id="addHint"></p>

      <!-- Pomodoro launcher -->
      <div class="pomo-launcher" id="pomoLauncher">
        <div class="pomo-select-row">
          <span class="pomo-icon">🍅</span>
          <span class="pomo-label-text">Pomodoro</span>
          <div class="pomo-duration-wrap">
            <button class="dur-btn" id="durDown">−</button>
            <span id="durDisplay">25</span>
            <span class="pomo-min-label">min</span>
            <button class="dur-btn" id="durUp">+</button>
          </div>
          <button class="btn-pomo-start" id="pomoStartBtn">Start</button>
        </div>
        <p class="pomo-note">Min 20 min · linked to selected task</p>
      </div>

      <!-- Active Pomodoro -->
      <div class="pomo-active" id="pomoActive" style="display:none">
        <div class="pomo-timer-ring">
          <svg viewBox="0 0 80 80">
            <circle class="pt-track" cx="40" cy="40" r="34"/>
            <circle class="pt-fill" cx="40" cy="40" r="34" id="pomoRingArc"
                    stroke-dasharray="214" stroke-dashoffset="0"/>
          </svg>
          <div class="pomo-time-label" id="pomoTimeLabel">00:00</div>
        </div>
        <div class="pomo-active-info">
          <p class="pomo-active-task" id="pomoActiveTask">—</p>
          <div class="pomo-btns">
            <button class="btn-pomo-stop" id="pomoStopBtn">✕ Stop</button>
            <button class="btn-pomo-done" id="pomoDoneBtn">✓ Done</button>
          </div>
        </div>
      </div>
    </div>

    <!-- ── ROW 2: Task List · Roadmap ─────────────────────── -->

    <!-- D: Task List -->
    <div class="bento-card card-tasks">
      <div class="tasks-hdr">
        <p class="card-label">Tasks</p>
        <span class="badge" id="taskBadge"><?= $total ?></span>
      </div>
      <ul class="task-list" id="taskList">
        <?php foreach ($tasks as $t): ?>
        <li class="task-item <?= $t['is_done'] ? 'is-done' : '' ?>" data-id="<?= $t['id'] ?>">
          <button class="task-check" aria-label="Toggle">
            <svg class="check-icon" viewBox="0 0 16 16" fill="none">
              <path d="M3 8l3.5 3.5L13 5" stroke="currentColor" stroke-width="2.2"
                    stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
          </button>
          <span class="task-label"><?= htmlspecialchars($t['title']) ?></span>
          <button class="task-pomo-link" title="Focus on this task">🍅</button>
          <button class="task-del" aria-label="Delete">
            <svg viewBox="0 0 16 16" fill="none">
              <path d="M4 4l8 8M12 4l-8 8" stroke="currentColor"
                    stroke-width="1.8" stroke-linecap="round"/>
            </svg>
          </button>
        </li>
        <?php endforeach; ?>
        <?php if (empty($tasks)): ?>
        <li class="task-empty" id="emptyState">Add your first task above ↑</li>
        <?php endif; ?>
      </ul>
    </div>

    <!-- E: Journey Roadmap -->
    <div class="bento-card card-roadmap">
      <p class="card-label">Journey Roadmap</p>
      <div class="timeline" id="timeline">
        <?php foreach ($tasks as $i => $t): ?>
        <div class="tl-node <?= $t['is_done'] ? 'tl-done' : '' ?>" data-id="<?= $t['id'] ?>">
          <div class="tl-dot"><?= $t['is_done'] ? '✓' : ($i + 1) ?></div>
          <div class="tl-body">
            <p class="tl-title"><?= htmlspecialchars($t['title']) ?></p>
            <span class="tl-badge"><?= $t['is_done'] ? 'Completed' : 'Pending' ?></span>
          </div>
        </div>
        <?php endforeach; ?>
        <?php if (empty($tasks)): ?>
        <p class="tl-empty">Your roadmap will appear here.</p>
        <?php endif; ?>
      </div>
    </div>

    <!-- ── ROW 3: Streak Detail · Metrics ─────────────────── -->

    <!-- F: Streak Detail -->
    <div class="bento-card card-streak">
      <p class="card-label">Streak</p>
      <div class="streak-main">
        <span class="streak-fire">🔥</span>
        <span class="streak-num" id="streakNum"><?= $streak['current'] ?></span>
        <span class="streak-unit">day<?= $streak['current'] !== 1 ? 's' : '' ?></span>
      </div>
      <p class="streak-best">Best: <strong id="longestStreak"><?= $streak['longest'] ?></strong> days</p>
      <div class="streak-week" id="streakWeek">
        <?php foreach ($streak['week'] as $day): ?>
        <div class="sw-day <?= $day['active'] ? 'sw-active' : '' ?>">
          <div class="sw-dot"></div>
          <span class="sw-label"><?= $day['label'][0] ?></span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- G: Productivity Metrics -->
    <div class="bento-card card-metrics">
      <div class="metrics-hdr">
        <p class="card-label">Productivity</p>
        <div class="window-tabs">
          <button class="wtab wtab-on" data-w="10">10 days</button>
          <button class="wtab" data-w="30">30 days</button>
        </div>
      </div>
      <div class="metrics-summary" id="metricsSummary">
        <div class="ms-item">
          <span class="ms-val" id="mTotal">—</span>
          <span class="ms-key">completed</span>
        </div>
        <div class="ms-item">
          <span class="ms-val" id="mAvg">—</span>
          <span class="ms-key">avg / day</span>
        </div>
        <div class="ms-item">
          <span class="ms-val" id="mBest">—</span>
          <span class="ms-key">best day</span>
        </div>
        <div class="ms-item">
          <span class="ms-val" id="mPomo">—</span>
          <span class="ms-key">focus min</span>
        </div>
      </div>
      <div class="bar-chart" id="barChart">
        <div class="chart-loading">Loading metrics…</div>
      </div>
    </div>

  </main>

  <!-- Toast -->
  <div class="toast" id="toast"></div>

  <script>
    window.FT = {
      csrf:   <?= json_encode($csrf) ?>,
      userId: <?= $userId ?>,
      tasks:  <?= json_encode(array_map(fn($t) => [
        'id'      => (int)$t['id'],
        'title'   => $t['title'],
        'is_done' => (int)$t['is_done'],
      ], $tasks)) ?>,
      streak: <?= json_encode($streak) ?>
    };
  </script>
  <!-- History Drawer -->
  <div class="history-overlay" id="historyOverlay"></div>
  <aside class="history-drawer" id="historyDrawer">
    <div class="hd-header">
      <div class="hd-title">
        <span class="hd-icon">&#128197;</span>
        <span>Last 7 Days</span>
      </div>
      <button class="hd-close" id="historyClose" aria-label="Close">&#10005;</button>
    </div>
    <div class="hd-body" id="historyBody">
      <div class="hd-loading">Loading your history&hellip;</div>
    </div>
  </aside>

  <script src="app.js"></script>
</body>
</html>