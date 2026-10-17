<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Work Plan · Ufffs Dashboard</title>
  <!-- Google Font & simple icons via Font Awesome (CDN) -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
  <style>
    /* ----- RESET & GLOBAL ----- */
    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
      transition: background-color 0.2s ease, color 0.2s ease, border-color 0.2s ease;
    }

    body {
      background: #f5f7fb;
      font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
      color: #1e293b;
      line-height: 1.5;
      padding: 2rem 1.5rem;
    }

    /* ----- WORK PLAN WRAPPER (like a kanban / task board) ----- */
    .workplan {
      max-width: 1300px;
      margin: 0 auto;
    }

    /* HEADER / TITLE SECTION */
    .plan-header {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 2rem;
    }

    .plan-header h1 {
      font-size: 2rem;
      font-weight: 600;
      letter-spacing: -0.02em;
      display: flex;
      align-items: center;
      gap: 12px;
    }

    .plan-header h1 i {
      color: #dd4814; /* CodeIgniter orange – but we use it as accent */
      font-size: 2rem;
    }

    .badge-ufffs {
      background: #1e293b;
      color: #f1f5f9;
      font-size: 0.75rem;
      font-weight: 500;
      padding: 0.25rem 0.9rem;
      border-radius: 999px;
      letter-spacing: 0.3px;
      text-transform: uppercase;
      display: inline-flex;
      align-items: center;
      gap: 6px;
    }

    .badge-ufffs i {
      color: #dd4814;
      font-size: 0.75rem;
    }

    /* STATS ROW (quick overview like work plan) */
    .stats-row {
      display: flex;
      flex-wrap: wrap;
      gap: 1rem;
      margin-bottom: 2.5rem;
    }

    .stat-card {
      background: #ffffff;
      padding: 1.25rem 1.5rem;
      border-radius: 20px;
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.02), 0 1px 3px rgba(0, 0, 0, 0.05);
      display: flex;
      align-items: center;
      gap: 1rem;
      flex: 1 1 160px;
    }

    .stat-icon {
      width: 44px;
      height: 44px;
      background: #f1f5f9;
      border-radius: 14px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.4rem;
      color: #dd4814;
    }

    .stat-info h3 {
      font-size: 0.75rem;
      font-weight: 500;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      color: #64748b;
    }

    .stat-info .value {
      font-size: 1.6rem;
      font-weight: 700;
      line-height: 1.2;
      color: #0f172a;
    }

    /* MAIN DASHBOARD – TASK COLUMNS (work plan style) */
    .board {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
      gap: 1.5rem;
      margin-bottom: 2.5rem;
    }

    .column {
      background: #ffffff;
      border-radius: 24px;
      padding: 1.5rem 1.25rem;
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.02);
      border: 1px solid #e9eef4;
      display: flex;
      flex-direction: column;
      transition: box-shadow 0.2s;
    }

    .column:hover {
      box-shadow: 0 8px 20px rgba(0, 0, 0, 0.04);
    }

    .column-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 1.25rem;
    }

    .column-header h2 {
      font-size: 1rem;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.4px;
      color: #475569;
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .column-header .count {
      background: #eef2f6;
      padding: 0.2rem 0.7rem;
      border-radius: 999px;
      font-size: 0.75rem;
      font-weight: 600;
      color: #334155;
    }

    /* TASK CARDS */
    .task-card {
      background: #f9fbfd;
      border-radius: 16px;
      padding: 1rem 1.1rem;
      margin-bottom: 0.9rem;
      border-left: 4px solid transparent;
      transition: background 0.2s, border-color 0.2s, transform 0.1s;
      box-shadow: 0 1px 2px rgba(0, 0, 0, 0.02);
    }

    .task-card:hover {
      background: #ffffff;
      border-left-color: #dd4814;
      box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
      transform: translateY(-2px);
    }

    .task-card .task-title {
      font-weight: 600;
      font-size: 0.95rem;
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 0.5rem;
    }

    .task-card .task-title i {
      color: #94a3b8;
      font-size: 0.9rem;
    }

    .task-card .task-desc {
      font-size: 0.85rem;
      color: #475569;
      margin-bottom: 0.75rem;
      line-height: 1.4;
    }

    .task-meta {
      display: flex;
      align-items: center;
      justify-content: space-between;
      font-size: 0.75rem;
      color: #64748b;
    }

    .task-meta .tag {
      background: #eef2f6;
      padding: 0.2rem 0.6rem;
      border-radius: 999px;
      font-weight: 500;
    }

    .task-meta .date {
      display: flex;
      align-items: center;
      gap: 4px;
    }

    /* special task progress line (optional) */
    .progress-indicator {
      height: 4px;
      background: #e2e8f0;
      border-radius: 4px;
      margin-top: 12px;
      overflow: hidden;
    }

    .progress-fill {
      height: 4px;
      background: #dd4814;
      border-radius: 4px;
      width: 0%;
    }

    /* a little extra for "ufffs" mood */
    .mood-tag {
      font-size: 0.7rem;
      background: #fff1e0;
      color: #b45309;
      border-radius: 30px;
      padding: 0.2rem 0.7rem;
      font-weight: 600;
      display: inline-flex;
      align-items: center;
      gap: 4px;
    }

    /* FOOTER / META PANEL (inspired by original CI footer but workplan) */
    .plan-footer {
      background: #ffffff;
      border-radius: 24px;
      padding: 1.5rem 2rem;
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      justify-content: space-between;
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.02);
      border: 1px solid #e9eef4;
      margin-top: 1rem;
    }

    .footer-left {
      display: flex;
      align-items: center;
      gap: 1.5rem;
      flex-wrap: wrap;
    }

    .env-badge {
      background: #f1f5f9;
      padding: 0.4rem 1rem;
      border-radius: 999px;
      font-size: 0.8rem;
      font-weight: 500;
      color: #1e293b;
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .env-badge i {
      color: #dd4814;
    }

    .time-badge {
      font-size: 0.8rem;
      color: #64748b;
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .footer-right {
      font-size: 0.8rem;
      color: #94a3b8;
    }

    /* RESPONSIVE */
    @media (max-width: 640px) {
      body {
        padding: 1.25rem;
      }

      .plan-header h1 {
        font-size: 1.5rem;
      }

      .stats-row {
        gap: 0.75rem;
      }

      .stat-card {
        padding: 1rem;
      }

      .plan-footer {
        flex-direction: column;
        align-items: flex-start;
        gap: 1rem;
      }
    }

    /* some playful ufffs energy */
    .glitch-emoji {
      filter: drop-shadow(0 2px 4px rgba(221, 72, 20, 0.2));
    }
  </style>
</head>
<body>
  <div class="workplan">
    <!-- HEADER: like a work plan title with ufffs mood -->
    <div class="plan-header">
      <h1>
        <i class="fas fa-clipboard-list glitch-emoji"></i>
        Work Plan · Ufffs
      </h1>
      <div class="badge-ufffs">
        <i class="fas fa-bolt"></i> ufffs mode
      </div>
    </div>

    <!-- QUICK STATS: feel like work plan overview -->
    <div class="stats-row">
      <div class="stat-card">
        <div class="stat-icon"><i class="fas fa-tasks"></i></div>
        <div class="stat-info">
          <h3>Active tasks</h3>
          <div class="value">9</div>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
        <div class="stat-info">
          <h3>Completed</h3>
          <div class="value">24</div>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-icon"><i class="fas fa-clock"></i></div>
        <div class="stat-info">
          <h3>In progress</h3>
          <div class="value">5</div>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-icon"><i class="fas fa-flag"></i></div>
        <div class="stat-info">
          <h3>Ufffs factor</h3>
          <div class="value">🔥 3</div>
        </div>
      </div>
    </div>

    <!-- BOARD: three columns like a work plan (backlog / doing / done) -->
    <div class="board">
      <!-- COLUMN 1: BACKLOG / TO DO -->
      <div class="column">
        <div class="column-header">
          <h2><i class="fas fa-inbox" style="color: #dd4814;"></i> Backlog</h2>
          <span class="count">4</span>
        </div>

        <div class="task-card">
          <div class="task-title">
            <span>Refactor welcome page</span>
            <i class="fas fa-ellipsis-h"></i>
          </div>
          <div class="task-desc">Replace CI default with work plan UI · ufffs style</div>
          <div class="task-meta">
            <span class="tag">frontend</span>
            <span class="date"><i class="far fa-calendar-alt"></i> Apr 12</span>
          </div>
          <div class="progress-indicator"><div class="progress-fill" style="width: 15%;"></div></div>
        </div>

        <div class="task-card">
          <div class="task-title">
            <span>API /tasks endpoint</span>
            <i class="fas fa-ellipsis-h"></i>
          </div>
          <div class="task-desc">Return JSON with work plan data</div>
          <div class="task-meta">
            <span class="tag">backend</span>
            <span class="date"><i class="far fa-calendar-alt"></i> Apr 14</span>
          </div>
        </div>

        <div class="task-card">
          <div class="task-title">
            <span>Ufffs notification</span>
            <i class="fas fa-ellipsis-h"></i>
          </div>
          <div class="task-desc">When a task is overdue, show a playful nudge</div>
          <div class="task-meta">
            <span class="tag">UX</span>
            <span class="date"><i class="far fa-calendar-alt"></i> Apr 16</span>
          </div>
        </div>

        <div class="task-card">
          <div class="task-title">
            <span>Dark mode toggle</span>
            <i class="fas fa-ellipsis-h"></i>
          </div>
          <div class="task-desc">Work plan after hours</div>
          <div class="task-meta">
            <span class="tag">UI</span>
            <span class="date"><i class="far fa-calendar-alt"></i> Apr 18</span>
          </div>
        </div>
      </div>

      <!-- COLUMN 2: IN PROGRESS -->
      <div class="column">
        <div class="column-header">
          <h2><i class="fas fa-spinner" style="color: #dd4814;"></i> In progress</h2>
          <span class="count">3</span>
        </div>

        <div class="task-card">
          <div class="task-title">
            <span>Work plan rewrite</span>
            <i class="fas fa-ellipsis-h"></i>
          </div>
          <div class="task-desc">Make it feel like a real planning dashboard</div>
          <div class="task-meta">
            <span class="tag">UI/UX</span>
            <span class="date"><i class="far fa-clock"></i> Today</span>
          </div>
          <div class="progress-indicator"><div class="progress-fill" style="width: 70%;"></div></div>
        </div>

        <div class="task-card">
          <div class="task-title">
            <span>Database migration</span>
            <i class="fas fa-ellipsis-h"></i>
          </div>
          <div class="task-desc">Add tasks, columns, and ufffs fields</div>
          <div class="task-meta">
            <span class="tag">DB</span>
            <span class="date"><i class="far fa-clock"></i> tomorrow</span>
          </div>
        </div>

        <div class="task-card">
          <div class="task-title">
            <span>Team sync meeting</span>
            <i class="fas fa-ellipsis-h"></i>
          </div>
          <div class="task-desc">Discuss work plan priorities</div>
          <div class="task-meta">
            <span class="tag">meeting</span>
            <span class="date"><i class="far fa-clock"></i> 10:30 AM</span>
          </div>
        </div>
      </div>

      <!-- COLUMN 3: DONE / COMPLETED -->
      <div class="column">
        <div class="column-header">
          <h2><i class="fas fa-check-circle" style="color: #dd4814;"></i> Done</h2>
          <span class="count">3</span>
        </div>

        <div class="task-card" style="opacity: 0.8;">
          <div class="task-title">
            <span><i class="fas fa-check" style="color: #22c55e; margin-right: 6px;"></i> Project setup</span>
            <i class="fas fa-ellipsis-h"></i>
          </div>
          <div class="task-desc">CodeIgniter 4 installed + work plan folder</div>
          <div class="task-meta">
            <span class="tag">setup</span>
            <span class="date"><i class="far fa-calendar-check"></i> Apr 10</span>
          </div>
        </div>

        <div class="task-card" style="opacity: 0.8;">
          <div class="task-title">
            <span><i class="fas fa-check" style="color: #22c55e; margin-right: 6px;"></i> Design moodboard</span>
            <i class="fas fa-ellipsis-h"></i>
          </div>
          <div class="task-desc">Collected references for "ufffs" aesthetic</div>
          <div class="task-meta">
            <span class="tag">design</span>
            <span class="date"><i class="far fa-calendar-check"></i> Apr 9</span>
          </div>
        </div>

        <div class="task-card" style="opacity: 0.8;">
          <div class="task-title">
            <span><i class="fas fa-check" style="color: #22c55e; margin-right: 6px;"></i> Wireframes</span>
            <i class="fas fa-ellipsis-h"></i>
          </div>
          <div class="task-desc">Rough layout for work plan board</div>
          <div class="task-meta">
            <span class="tag">UI</span>
            <span class="date"><i class="far fa-calendar-check"></i> Apr 8</span>
          </div>
        </div>
      </div>
    </div>

    <!-- FOOTER / META (inspired by CI but workplan style) -->
    <div class="plan-footer">
      <div class="footer-left">
        <div class="env-badge">
          <i class="fas fa-cube"></i> environment: <strong>development</strong>
        </div>
        <div class="time-badge">
          <i class="fas fa-stopwatch"></i> page rendered in 0.0231s · memory 2.4MB
        </div>
        <div class="mood-tag">
          <i class="fas fa-face-smile"></i> ufffs, but organized
        </div>
      </div>
      <div class="footer-right">
        <i class="far fa-copyright"></i> 2025 Work Plan · ufffs edition
      </div>
    </div>
  </div>

  <!-- tiny script for any future interactivity (none required) -->
  <script>
    // just a little console greeting for the ufffs work plan
    console.log('📋 work plan ui — ufffs mode active');
  </script>
</body>
</html>