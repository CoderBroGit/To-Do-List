/**
 * FocusTrack v2 — app.js
 * ──────────────────────────────
 * Handles: tasks · pomodoro · streak · metrics · charts
 * Talks to dashboard.php JSON API
 */

'use strict';

if (typeof window.FT === 'undefined') { initAuth(); } else { initDashboard(); }

/* ══════════════════════════════════════════════════════════
   AUTH PAGE
   ══════════════════════════════════════════════════════════ */
function initAuth() {
  document.querySelectorAll('.afield input').forEach(input => {
    input.addEventListener('focus',  () => input.closest('.afield')?.classList.add('focused'));
    input.addEventListener('blur',   () => input.closest('.afield')?.classList.remove('focused'));
  });
}

/* ══════════════════════════════════════════════════════════
   DASHBOARD
   ══════════════════════════════════════════════════════════ */
function initDashboard() {

  /* ── State ─────────────────────────────────────────────── */
  let tasks       = (window.FT.tasks || []).map(t => ({ ...t, is_done: !!t.is_done }));
  let streakData  = window.FT.streak || { current: 0, longest: 0, week: [] };
  let metricsData = null;
  let currentWindow = 10;

  /* Pomodoro state */
  let pomoState = {
    active:      false,
    sessionId:   null,
    taskId:      null,
    taskTitle:   '',
    totalSecs:   0,
    remainSecs:  0,
    interval:    null,
    duration:    25,          // user-chosen minutes (min 20)
    linkedTaskId: null,       // task 🍅 button clicked
  };

  /* ── DOM refs ───────────────────────────────────────────── */
  const $ = id => document.getElementById(id);
  const taskList      = $('taskList');
  const taskInput     = $('taskInput');
  const addBtn        = $('addBtn');
  const addHint       = $('addHint');
  const pctDisplay    = $('pctDisplay');
  const feedbackMsg   = $('feedbackMsg');
  const progressBar   = $('progressBar');
  const doneCount     = $('doneCount');
  const totalCount    = $('totalCount');
  const ringArc       = $('ringArc');
  const taskBadge     = $('taskBadge');
  const timeline      = $('timeline');
  const toast         = $('toast');

  // Streak (card + header badge)
  const streakNum       = $('streakNum');
  const longestStreak   = $('longestStreak');
  const streakWeek      = $('streakWeek');
  const headerStreakCount = $('headerStreakCount');

  // Today
  const todayDone    = $('todayDone');
  const todayAdded   = $('todayAdded');
  const todayPomo    = $('todayPomo');

  // Pomodoro
  const durDisplay   = $('durDisplay');
  const durDown      = $('durDown');
  const durUp        = $('durUp');
  const pomoStartBtn = $('pomoStartBtn');
  const pomoLauncher = $('pomoLauncher');
  const pomoActive   = $('pomoActive');
  const pomoRingArc  = $('pomoRingArc');
  const pomoTimeLabel= $('pomoTimeLabel');
  const pomoActiveTask=$('pomoActiveTask');
  const pomoStopBtn  = $('pomoStopBtn');
  const pomoDoneBtn  = $('pomoDoneBtn');

  // Metrics
  const mTotal    = $('mTotal');
  const mAvg      = $('mAvg');
  const mBest     = $('mBest');
  const mPomo     = $('mPomo');
  const barChart  = $('barChart');

  /* ── API ───────────────────────────────────────────────── */
  async function api(body) {
    const res = await fetch('dashboard.php', {
      method:  'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body:    JSON.stringify({ ...body, csrf_token: window.FT.csrf }),
    });
    return res.json();
  }

  /* ── Toast ─────────────────────────────────────────────── */
  let toastTimer;
  function showToast(msg, type = 'info') {
    toast.textContent = msg;
    toast.className   = `toast t-${type} visible`;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => toast.classList.remove('visible'), 3200);
  }

  /* ── Feedback ───────────────────────────────────────────── */
  function getFeedback(pct) {
    if (pct === 0)  return "A fresh start. Let's build something today.";
    if (pct <= 25)  return "Great beginning. Keep the momentum going.";
    if (pct <= 49)  return "Making real progress. You're doing well.";
    if (pct === 50) return "Halfway there. This is where it counts.";
    if (pct <= 74)  return "Past the halfway mark. Finish strong.";
    if (pct <= 99)  return "Almost there. One last push.";
    return "Everything done. Outstanding work today.";
  }

  /* ── Progress update ────────────────────────────────────── */
  function updateProgress() {
    const total = tasks.length;
    const done  = tasks.filter(t => t.is_done).length;
    const pct   = total > 0 ? Math.round(done / total * 100) : 0;

    pctDisplay.textContent  = pct + '%';
    doneCount.textContent   = done;
    totalCount.textContent  = total;
    taskBadge.textContent   = total;
    progressBar.style.width = pct + '%';

    // SVG ring  circumference = 2 * PI * 50 ≈ 314
    ringArc.style.strokeDashoffset = 314 - (pct / 100 * 314);

    const msg = getFeedback(pct);
    if (feedbackMsg.textContent !== msg) {
      feedbackMsg.style.opacity = '0';
      setTimeout(() => { feedbackMsg.textContent = msg; feedbackMsg.style.opacity = '1'; }, 250);
    }
  }

  /* ── Render task list ───────────────────────────────────── */
  function renderTaskList() {
    taskList.innerHTML = '';
    if (!tasks.length) {
      taskList.innerHTML = '<li class="task-empty" id="emptyState">Add your first task above ↑</li>';
      return;
    }
    tasks.forEach(t => taskList.appendChild(buildTaskEl(t)));
  }

  /* ── Render roadmap ─────────────────────────────────────── */
  function renderTimeline() {
    timeline.innerHTML = '';
    if (!tasks.length) {
      timeline.innerHTML = '<p class="tl-empty">Your roadmap will appear here.</p>';
      return;
    }
    tasks.forEach((t, i) => {
      const div = document.createElement('div');
      div.className  = `tl-node ${t.is_done ? 'tl-done' : ''}`;
      div.dataset.id = t.id;
      div.innerHTML  = `
        <div class="tl-dot">${t.is_done ? '✓' : i + 1}</div>
        <div class="tl-body">
          <p class="tl-title">${esc(t.title)}</p>
          <span class="tl-badge">${t.is_done ? 'Completed' : 'Pending'}</span>
        </div>`;
      timeline.appendChild(div);
    });
  }

  /* ── Build task <li> ────────────────────────────────────── */
  function buildTaskEl(task) {
    const li = document.createElement('li');
    li.className  = `task-item ${task.is_done ? 'is-done' : ''}`;
    li.dataset.id = task.id;
    li.innerHTML  = `
      <button class="task-check" aria-label="Toggle">
        <svg class="check-icon" viewBox="0 0 16 16" fill="none">
          <path d="M3 8l3.5 3.5L13 5" stroke="currentColor" stroke-width="2"
                stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
      </button>
      <span class="task-label">${esc(task.title)}</span>
      <button class="task-pomo-link" aria-label="Focus on this task" title="Start Pomodoro for this task">🍅</button>
      <button class="task-del" aria-label="Delete">
        <svg viewBox="0 0 16 16" fill="none">
          <path d="M4 4l8 8M12 4l-8 8" stroke="currentColor"
                stroke-width="1.5" stroke-linecap="round"/>
        </svg>
      </button>`;
    return li;
  }

  /* ── Sync single task in DOM ────────────────────────────── */
  function syncTaskEl(task) {
    const li = taskList.querySelector(`[data-id="${task.id}"]`);
    if (!li) return;
    li.classList.toggle('is-done', task.is_done);
    li.querySelector('.check-icon').style.opacity = task.is_done ? '1' : '0';
    const lbl = li.querySelector('.task-label');
    if (lbl) lbl.style.textDecoration = task.is_done ? 'line-through' : '';
  }

  /* ── ADD ────────────────────────────────────────────────── */
  async function handleAdd() {
    const title = taskInput.value.trim();
    if (!title) {
      addHint.textContent = 'Please enter a task first.';
      taskInput.focus();
      setTimeout(() => addHint.textContent = '', 2500);
      return;
    }
    addBtn.disabled    = true;
    addBtn.textContent = '…';

    try {
      const data = await api({ action: 'add', title });
      if (!data.ok) throw new Error(data.error);
      tasks.push({ ...data.task, is_done: false });
      renderTaskList();
      renderTimeline();
      updateProgress();
      taskInput.value = '';
      addHint.textContent = '';
      showToast('Task added.', 'success');
      // Refresh metrics quietly
      loadMetrics();
    } catch (e) {
      showToast(e.message || 'Could not add task.', 'error');
    }

    addBtn.disabled    = false;
    addBtn.textContent = '+';
  }

  /* ── TOGGLE ─────────────────────────────────────────────── */
  async function handleToggle(id) {
    const task = tasks.find(t => t.id === id);
    if (!task) return;

    // Optimistic
    task.is_done = !task.is_done;
    updateProgress();
    syncTaskEl(task);

    try {
      const data = await api({ action: 'toggle', id });
      if (!data.ok) throw new Error(data.error);
      task.is_done = !!data.is_done;
      syncTaskEl(task);
      renderTimeline();

      // Update streak if returned
      if (data.streak) {
        streakData = data.streak;
        renderStreakUI();
      }

      showToast(task.is_done ? 'Task complete! Well done.' : 'Marked as pending.', task.is_done ? 'success' : 'info');
      loadMetrics();
    } catch {
      // Rollback
      task.is_done = !task.is_done;
      updateProgress();
      syncTaskEl(task);
      showToast('Update failed.', 'error');
    }
  }

  /* ── DELETE ─────────────────────────────────────────────── */
  async function handleDelete(id, li) {
    li.classList.add('removing');
    await sleep(270);
    tasks = tasks.filter(t => t.id !== id);
    renderTaskList();
    renderTimeline();
    updateProgress();

    try {
      const data = await api({ action: 'delete', id });
      if (!data.ok) throw new Error(data.error);
      showToast('Task removed.', 'info');
    } catch {
      showToast('Delete failed — please refresh.', 'error');
    }
  }

  /* ── TASK LIST EVENTS ───────────────────────────────────── */
  taskList.addEventListener('click', e => {
    const li = e.target.closest('.task-item');
    if (!li) return;
    const id = parseInt(li.dataset.id, 10);

    if (e.target.closest('.task-del')) {
      handleDelete(id, li);
    } else if (e.target.closest('.task-pomo-link')) {
      // Link this task to the Pomodoro launcher
      const task = tasks.find(t => t.id === id);
      if (task) {
        pomoState.linkedTaskId = id;
        pomoState.taskTitle    = task.title;
        taskInput.value        = task.title;
        // Scroll up to launcher
        pomoLauncher.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        showToast(`"${task.title}" linked to Pomodoro.`, 'info');
      }
    } else if (e.target.closest('.task-check') || e.target.closest('.task-label')) {
      handleToggle(id);
    }
  });

  addBtn.addEventListener('click', handleAdd);
  taskInput.addEventListener('keydown', e => { if (e.key === 'Enter') handleAdd(); });
  taskInput.addEventListener('input', () => {
    const rem = 255 - taskInput.value.length;
    addHint.textContent = rem < 30 ? `${rem} chars left` : '';
  });

  /* ── STREAK UI ──────────────────────────────────────────── */
  function renderStreakUI() {
    if (streakNum)          streakNum.textContent        = streakData.current;
    if (longestStreak)      longestStreak.textContent    = streakData.longest;
    if (headerStreakCount)  headerStreakCount.textContent = streakData.current;
    if (!streakWeek || !streakData.week) return;
    streakWeek.innerHTML = '';
    streakData.week.forEach(d => {
      const div = document.createElement('div');
      div.className = `sw-day${d.active ? ' sw-active' : ''}`;
      div.innerHTML = `<div class="sw-dot"></div><span class="sw-label">${d.label[0]}</span>`;
      streakWeek.appendChild(div);
    });
  }

  /* ── POMODORO ───────────────────────────────────────────── */

  // Duration controls (min 20, max 120, step 5)
  durDown.addEventListener('click', () => {
    if (pomoState.duration > 20) {
      pomoState.duration = Math.max(20, pomoState.duration - 5);
      durDisplay.textContent = pomoState.duration;
    }
  });
  durUp.addEventListener('click', () => {
    if (pomoState.duration < 120) {
      pomoState.duration = Math.min(120, pomoState.duration + 5);
      durDisplay.textContent = pomoState.duration;
    }
  });

  // Start
  pomoStartBtn.addEventListener('click', startPomodoro);
  pomoStopBtn.addEventListener('click',  () => endPomodoro(false));
  pomoDoneBtn.addEventListener('click',  () => endPomodoro(true));

  async function startPomodoro() {
    if (pomoState.active) return;

    const duration   = pomoState.duration;
    const taskId     = pomoState.linkedTaskId;
    const taskTitle  = taskId
      ? (tasks.find(t => t.id === taskId)?.title || 'Focus Session')
      : (taskInput.value.trim() || 'Focus Session');

    try {
      const data = await api({
        action:           'pomo_start',
        task_id:          taskId,
        task_title:       taskTitle,
        duration_minutes: duration,
      });
      if (!data.ok) throw new Error(data.error);

      pomoState.active      = true;
      pomoState.sessionId   = data.session_id;
      pomoState.taskTitle   = taskTitle;
      pomoState.totalSecs   = duration * 60;
      pomoState.remainSecs  = duration * 60;

      pomoLauncher.style.display = 'none';
      pomoActive.style.display   = 'flex';
      pomoActiveTask.textContent = `"${taskTitle}"`;

      pomoState.interval = setInterval(tickPomodoro, 1000);
      showToast(`Pomodoro started — ${duration} min focus session.`, 'info');

    } catch (e) {
      showToast(e.message || 'Could not start session.', 'error');
    }
  }

  function tickPomodoro() {
    if (pomoState.remainSecs <= 0) {
      endPomodoro(true);
      return;
    }
    pomoState.remainSecs--;
    const mins = Math.floor(pomoState.remainSecs / 60);
    const secs = pomoState.remainSecs % 60;
    pomoTimeLabel.textContent = `${String(mins).padStart(2,'0')}:${String(secs).padStart(2,'0')}`;

    // Ring arc  circumference = 2 * PI * 34 ≈ 214
    const elapsed  = pomoState.totalSecs - pomoState.remainSecs;
    const progress = elapsed / pomoState.totalSecs;
    pomoRingArc.style.strokeDashoffset = 214 - (progress * 214);
  }

  async function endPomodoro(completed) {
    if (!pomoState.active) return;
    clearInterval(pomoState.interval);
    pomoState.active = false;

    try {
      await api({
        action:     'pomo_complete',
        session_id: pomoState.sessionId,
        completed:  completed ? 1 : 0,
      });
    } catch (_) { /* silent */ }

    // Reset UI
    pomoLauncher.style.display = '';
    pomoActive.style.display   = 'none';
    pomoTimeLabel.textContent  = '00:00';
    pomoRingArc.style.strokeDashoffset = '0';
    pomoState.linkedTaskId = null;
    pomoState.sessionId    = null;

    if (completed) {
      showToast(`Pomodoro complete! ${pomoState.duration} minutes focused.`, 'success');
    } else {
      showToast('Session stopped.', 'info');
    }

    // Refresh metrics and today stats
    loadMetrics();
  }

  /* ── METRICS ────────────────────────────────────────────── */
  async function loadMetrics() {
    try {
      const data = await api({ action: 'metrics' });
      if (!data.ok) return;
      metricsData = data.data;
      renderMetrics();
    } catch (_) { /* silent */ }
  }

  function renderMetrics() {
    if (!metricsData) return;

    // Today card
    if (todayDone)  todayDone.textContent  = metricsData.today.completed;
    if (todayAdded) todayAdded.textContent = metricsData.today.added;
    if (todayPomo)  todayPomo.textContent  = metricsData.today.pomo_min;

    // Summary stats
    const window30 = currentWindow === 30;
    const completed = window30 ? metricsData.total_completed_30 : metricsData.days10.reduce((s,d)=>s+d.completed,0);
    const pomoMin   = window30 ? metricsData.total_pomo_min_30  : metricsData.days10.reduce((s,d)=>s+d.pomo_min,0);
    const days      = window30 ? metricsData.days30 : metricsData.days10;
    const maxDay    = Math.max(...days.map(d=>d.completed), 0);
    const avg       = days.length ? (completed / days.length).toFixed(1) : '0';

    if (mTotal) mTotal.textContent = completed;
    if (mAvg)   mAvg.textContent   = avg;
    if (mBest)  mBest.textContent  = maxDay;
    if (mPomo)  mPomo.textContent  = pomoMin;

    renderBarChart(days);
  }

  function renderBarChart(days) {
    if (!barChart) return;
    barChart.innerHTML = '';
    const max = Math.max(...days.map(d => d.completed), 1);

    days.forEach(d => {
      const col = document.createElement('div');
      col.className = 'bar-col';

      const heightPct = max > 0 ? Math.max((d.completed / max) * 100, d.completed > 0 ? 4 : 0) : 0;
      const fill = document.createElement('div');
      fill.className     = d.completed > 0 ? 'bar-fill has-val' : 'bar-fill';
      fill.style.height  = heightPct + '%';
      fill.dataset.val   = d.completed + (d.completed === 1 ? ' task' : ' tasks');

      const lbl = document.createElement('div');
      lbl.className   = 'bar-label';
      // Show abbreviated label: "M", "T", day of month etc.
      const date = new Date(d.date + 'T00:00:00');
      lbl.textContent = date.toLocaleDateString('en-US', { weekday: 'narrow' });

      col.appendChild(fill);
      col.appendChild(lbl);
      barChart.appendChild(col);
    });
  }

  // Window tab switching
  document.querySelectorAll('.wtab').forEach(btn => {
    btn.addEventListener('click', () => {
      document.querySelectorAll('.wtab').forEach(b => b.classList.remove('wtab-on'));
      btn.classList.add('wtab-on');
      currentWindow = parseInt(btn.dataset.w, 10);
      renderMetrics();
    });
  });

  /* ── HELPERS ─────────────────────────────────────────────── */
  function sleep(ms) { return new Promise(r => setTimeout(r, ms)); }
  function esc(s) {
    return String(s)
      .replace(/&/g,'&amp;').replace(/</g,'&lt;')
      .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  /* ── HISTORY DRAWER ─────────────────────────────────────── */
  const historyBtn     = $('historyBtn');
  const historyDrawer  = $('historyDrawer');
  const historyOverlay = $('historyOverlay');
  const historyClose   = $('historyClose');
  const historyBody    = $('historyBody');

  function openHistory() {
    historyDrawer.classList.add('hd-open');
    historyOverlay.classList.add('hd-open');
    document.body.style.overflow = 'hidden';
    loadHistory();
  }

  function closeHistory() {
    historyDrawer.classList.remove('hd-open');
    historyOverlay.classList.remove('hd-open');
    document.body.style.overflow = '';
  }

  if (historyBtn)     historyBtn.addEventListener('click', openHistory);
  if (historyClose)   historyClose.addEventListener('click', closeHistory);
  if (historyOverlay) historyOverlay.addEventListener('click', closeHistory);
  document.addEventListener('keydown', e => { if (e.key === 'Escape') closeHistory(); });

  async function loadHistory() {
    if (!historyBody) return;
    historyBody.innerHTML = '<div class="hd-loading">Loading your history…</div>';
    try {
      const data = await api({ action: 'history' });
      if (!data.ok) throw new Error(data.error);
      renderHistory(data.days);
    } catch (e) {
      historyBody.innerHTML = '<div class="hd-loading">Could not load history.</div>';
    }
  }

  function renderHistory(days) {
    if (!historyBody) return;
    if (!days || days.length === 0) {
      historyBody.innerHTML = '<div class="hd-empty">No task history yet. Start completing tasks!</div>';
      return;
    }
    historyBody.innerHTML = '';
    days.forEach((day, idx) => {
      const section = document.createElement('div');
      section.className = 'hd-day';
      section.style.animationDelay = idx * 55 + 'ms';

      const pct        = day.total > 0 ? Math.round(day.completed / day.total * 100) : 0;
      const hasActivity = day.total > 0;

      const hdr = document.createElement('div');
      hdr.className = 'hd-day-hdr';
      hdr.innerHTML =
        '<div class="hd-day-info">' +
          '<span class="hd-day-label">' + esc(day.label) + '</span>' +
          (hasActivity
            ? '<span class="hd-day-pct' + (pct === 100 ? ' pct-full' : '') + '">' + pct + '% done</span>'
            : '<span class="hd-day-empty-badge">No tasks</span>') +
        '</div>' +
        (hasActivity
          ? '<div class="hd-day-bar-wrap"><div class="hd-day-bar" style="width:' + pct + '%"></div></div>'
          : '');
      section.appendChild(hdr);

      if (hasActivity) {
        const ul = document.createElement('ul');
        ul.className = 'hd-task-list';
        day.tasks.forEach(task => {
          const li = document.createElement('li');
          li.className = 'hd-task ' + (task.is_done ? 'hd-done' : 'hd-pending');
          li.innerHTML =
            '<span class="hd-task-dot">' + (task.is_done ? '✓' : '●') + '</span>' +
            '<span class="hd-task-title">' + esc(task.title) + '</span>' +
            '<span class="hd-task-status">' + (task.is_done ? 'Done' : 'Not done') + '</span>';
          ul.appendChild(li);
        });
        section.appendChild(ul);
      } else {
        const empty = document.createElement('p');
        empty.className = 'hd-day-no-tasks';
        empty.textContent = 'Rest day — no tasks added.';
        section.appendChild(empty);
      }
      historyBody.appendChild(section);
    });
  }

  /* ── INIT ───────────────────────────────────────────────── */
  updateProgress();
  renderStreakUI();
  loadMetrics();

  // Auto-refresh metrics every 60 seconds
  setInterval(loadMetrics, 60000);
}