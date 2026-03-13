<?php
session_start();
require_once '../includes/db_connection.php';
require_once '../includes/functions.php';
checkAuth('student');

$student_id = $_SESSION['user_id'];
$success = $_SESSION['success'] ?? '';
$error   = $_SESSION['error']   ?? '';
unset($_SESSION['success'], $_SESSION['error']);

// Sidebar stats (needed by nav_body)
$unread = getStat($pdo,"SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0 AND deleted_at IS NULL",[$student_id]);

// Category suggestions
$cat_stmt = $pdo->prepare("SELECT DISTINCT category FROM student_goals WHERE student_id=? AND category IS NOT NULL AND category!='' AND deleted_at IS NULL ORDER BY category");
$cat_stmt->execute([$student_id]); $categories = $cat_stmt->fetchAll(PDO::FETCH_COLUMN);

$sys_stmt = $pdo->prepare("SELECT name FROM categories WHERE (is_global=1 OR created_by=?) AND deleted_at IS NULL ORDER BY name ASC");
$sys_stmt->execute([$student_id]); $system_categories = $sys_stmt->fetchAll(PDO::FETCH_COLUMN);
$all_categories = array_unique(array_merge($categories, $system_categories));

// Preserve form data on error
$form = [
    'title'           => $_POST['title']           ?? '',
    'description'     => $_POST['description']     ?? '',
    'category'        => $_POST['category']        ?? '',
    'target_value'    => $_POST['target_value']    ?? '',
    'unit'            => $_POST['unit']            ?? '',
    'start_date'      => $_POST['start_date']      ?? date('Y-m-d'),
    'due_date'        => $_POST['due_date']        ?? '',
    'priority'        => $_POST['priority']        ?? 'medium',
    'estimated_hours' => $_POST['estimated_hours'] ?? '',
];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title           = trim($_POST['title'] ?? '');
    $description     = trim($_POST['description'] ?? '');
    $category        = trim($_POST['category'] ?? '');
    $target_value    = floatval($_POST['target_value'] ?? 0);
    $unit            = trim($_POST['unit'] ?? '');
    $due_date        = $_POST['due_date'] ?: null;
    $start_date      = $_POST['start_date'] ?: date('Y-m-d');
    $estimated_hours = !empty($_POST['estimated_hours']) ? floatval($_POST['estimated_hours']) : null;
    $allowed_pri     = ['low','medium','high'];
    $priority        = in_array($_POST['priority']??'medium', $allowed_pri) ? $_POST['priority'] : 'medium';

    $errors = [];
    if (empty($title))         $errors[] = "Goal title is required.";
    elseif (strlen($title)<3)  $errors[] = "Title must be at least 3 characters.";
    if ($target_value <= 0)    $errors[] = "Target value must be greater than 0.";
    if (empty($unit))          $errors[] = "Unit is required (e.g., hours, pages, chapters).";
    if ($due_date && $due_date < date('Y-m-d')) $errors[] = "Due date cannot be in the past.";
    if ($start_date && $due_date && $start_date > $due_date) $errors[] = "Start date cannot be after due date.";

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            // ── Correct INSERT — uses only columns that exist in student_goals ──
            $stmt = $pdo->prepare("
                INSERT INTO student_goals
                    (student_id, title, description, category, unit,
                     target_value, current_value, progress_percentage,
                     priority, status, is_self_created,
                     start_date, due_date, created_at)
                VALUES
                    (?, ?, ?, ?, ?,
                     ?, 0, 0,
                     ?, 'pending', 1,
                     ?, ?, NOW())
            ");
            $stmt->execute([
                $student_id, $title, $description, $category, $unit,
                $target_value,
                $priority,
                $start_date, $due_date
            ]);
            $new_goal_id = $pdo->lastInsertId();

            // Goal created notification
            $pdo->prepare("
                INSERT INTO notifications (user_id, title, message, type, related_id, related_type, created_at)
                VALUES (?, 'Goal Created', ?, 'goal', ?, 'student_goal', NOW())
            ")->execute([$student_id, "New goal created: $title", $new_goal_id]);

            $pdo->commit();

            // Check if creating this goal unlocks any achievements (e.g. Dream Big, Planner, Visionary)
            awardAchievements($pdo, $student_id);

            $_SESSION['success'] = "Goal '$title' created successfully!";
            header("Location: goals.php"); exit;

        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Error creating goal: " . $e->getMessage();
        }
    } else {
        $error = implode(" ", $errors);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Create Goal — ProgressMate</title>
<?php require_once '../includes/student_nav.php'; nav_head(); ?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
/* ── Page header ── */
.page-header{
  width:100%;display:flex;align-items:flex-start;justify-content:space-between;
  gap:14px;flex-wrap:wrap;padding:18px 20px;border-radius:var(--r20);
  border:1px solid var(--border);
  background:radial-gradient(120% 220% at 15% 10%,rgba(255,255,255,.10),transparent 55%),
             linear-gradient(180deg,rgba(255,255,255,.05),rgba(255,255,255,.03));
  box-shadow:var(--shadow2);margin-bottom:22px;
}
.page-header h1{margin:0 0 4px;font-size:22px;font-weight:900;}
.page-header p{margin:0;color:var(--muted);font-size:13px;}
.hdr-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap;}

/* ── Buttons ── */
.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;
  padding:10px 18px;border-radius:14px;border:1px solid var(--border);
  background:rgba(255,255,255,.05);color:var(--text);font-weight:700;
  font-size:13px;cursor:pointer;transition:.18s;font-family:inherit;text-decoration:none;}
.btn:hover{transform:translateY(-1px);background:rgba(255,255,255,.09);border-color:rgba(255,255,255,.18);}
.btn-primary{
  background:radial-gradient(120% 160% at 10% 20%,rgba(255,255,255,.14),transparent 55%),
             linear-gradient(135deg,rgba(79,70,229,.85),rgba(34,211,238,.25));
  border-color:rgba(255,255,255,.18);box-shadow:0 10px 30px rgba(79,70,229,.22);}
.btn-ghost{background:transparent;}

/* ── Alert ── */
.alert{display:flex;align-items:center;gap:12px;padding:13px 16px;border-radius:16px;
  border:1px solid var(--border);background:rgba(255,255,255,.04);margin-bottom:18px;font-size:14px;}
.alert i{width:32px;height:32px;display:grid;place-items:center;border-radius:10px;
  background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.12);flex-shrink:0;}
.alert-success{border-color:rgba(52,211,153,.30);} .alert-success i{color:var(--success);}
.alert-error{border-color:rgba(251,113,133,.30);}   .alert-error i{color:var(--danger);}

/* ── Form card ── */
.form-card{
  width:100%;max-width:780px;
  border-radius:var(--r20);border:1px solid var(--border);
  background:radial-gradient(140% 220% at 10% 0%,rgba(255,255,255,.08),transparent 60%),
             linear-gradient(180deg,rgba(255,255,255,.05),rgba(255,255,255,.02));
  box-shadow:var(--shadow);overflow:hidden;margin-bottom:22px;
}
.form-card-header{
  padding:16px 22px;border-bottom:1px solid var(--border2);background:rgba(255,255,255,.03);
  display:flex;align-items:center;gap:10px;
}
.form-card-header h3{margin:0;font-size:15px;font-weight:900;display:flex;align-items:center;gap:8px;}
.form-card-body{padding:22px;}

/* ── Form elements ── */
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;}
.form-full{grid-column:1/-1;}
.form-group{display:flex;flex-direction:column;gap:6px;}
.form-group label{font-size:11.5px;font-weight:800;color:var(--muted);text-transform:uppercase;letter-spacing:.4px;}
.form-group label .req{color:var(--danger);}
.form-group input,
.form-group select,
.form-group textarea{
  padding:11px 14px;border-radius:12px;border:1px solid rgba(255,255,255,.12);
  background:rgba(255,255,255,.06);color:var(--text);font-size:14px;
  font-family:inherit;outline:none;transition:.18s;
}
.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus{
  border-color:rgba(79,70,229,.55);box-shadow:0 0 0 3px rgba(79,70,229,.14);background:rgba(255,255,255,.09);
}
.form-group textarea{resize:vertical;min-height:100px;line-height:1.6;}
.form-group select option{background:#0B1030;color:#EAF0FF;}
.form-hint{font-size:11.5px;color:var(--muted2);}

/* Category tags */
.cat-tags{display:flex;gap:6px;flex-wrap:wrap;margin-top:4px;}
.cat-tag{
  font-size:11.5px;font-weight:700;padding:4px 10px;border-radius:999px;
  border:1px solid var(--border);background:rgba(255,255,255,.04);
  cursor:pointer;transition:.18s;
}
.cat-tag:hover{background:rgba(79,70,229,.20);border-color:rgba(79,70,229,.40);}

/* Priority picker */
.priority-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-top:4px;}
.priority-opt{
  padding:12px 10px;border-radius:14px;border:1px solid var(--border2);
  background:rgba(255,255,255,.03);text-align:center;cursor:pointer;transition:.18s;
}
.priority-opt:hover{background:rgba(255,255,255,.06);border-color:rgba(255,255,255,.14);}
.priority-opt.on{
  border-color:rgba(79,70,229,.50);
  background:radial-gradient(120% 160% at 20% 20%,rgba(255,255,255,.12),transparent 55%),
             linear-gradient(135deg,rgba(79,70,229,.30),rgba(34,211,238,.10));
}
.priority-opt i{font-size:18px;margin-bottom:6px;display:block;}
.priority-opt.low  i{color:rgba(148,163,184,.90);}
.priority-opt.medium i{color:rgba(251,191,36,.90);}
.priority-opt.high i{color:rgba(251,113,133,.90);}
.priority-opt span{font-size:12px;font-weight:800;color:var(--muted);}
.priority-opt.on span{color:var(--text);}

/* Form actions */
.form-actions{
  display:flex;justify-content:flex-end;gap:10px;
  padding-top:18px;border-top:1px solid var(--border2);margin-top:18px;
}

/* Tips card */
.tips-card{
  width:100%;max-width:780px;
  border-radius:var(--r20);border:1px solid var(--border);
  background:rgba(255,255,255,.03);overflow:hidden;
}
.tips-card-header{padding:14px 22px;border-bottom:1px solid var(--border2);background:rgba(255,255,255,.02);}
.tips-card-header h3{margin:0;font-size:14px;font-weight:900;display:flex;align-items:center;gap:8px;}
.tips-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px;padding:18px 22px;}
.tip{display:flex;align-items:flex-start;gap:10px;padding:12px;border-radius:14px;
  border:1px solid var(--border2);background:rgba(255,255,255,.02);}
.tip i{width:32px;height:32px;display:grid;place-items:center;border-radius:10px;flex-shrink:0;
  background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.10);}
.tip-title{font-size:13px;font-weight:800;margin-bottom:2px;}
.tip-text{font-size:11.5px;color:var(--muted);line-height:1.4;}

/* Light theme field overrides */
[data-theme="light"] .form-group input,
[data-theme="light"] .form-group select,
[data-theme="light"] .form-group textarea{
  background:rgba(255,255,255,.90);border-color:rgba(79,70,229,.22);color:#1a1f3c;
}
[data-theme="light"] .form-group input:focus,
[data-theme="light"] .form-group select:focus,
[data-theme="light"] .form-group textarea:focus{border-color:rgba(79,70,229,.55);}
[data-theme="light"] .form-group select option{background:#fff;color:#1a1f3c;}
[data-theme="light"] .priority-opt{background:rgba(255,255,255,.60);border-color:rgba(79,70,229,.12);}
[data-theme="light"] .priority-opt.on{background:rgba(79,70,229,.10);border-color:rgba(79,70,229,.35);}
[data-theme="light"] .cat-tag{background:rgba(255,255,255,.70);border-color:rgba(79,70,229,.16);}
[data-theme="light"] .cat-tag:hover{background:rgba(79,70,229,.12);}

@media(max-width:640px){
  .form-grid{grid-template-columns:1fr;}
  .form-full{grid-column:1;}
  .priority-grid{grid-template-columns:repeat(3,1fr);}
}
</style>
</head>
<body>
<?php nav_body(); ?>

  <!-- HEADER -->
  <header class="page-header">
    <div>
      <h1>Create New Goal</h1>
      <p>Set a goal and start tracking your progress</p>
    </div>
    <div class="hdr-actions">
      <button class="theme-btn" id="themeBtn">
        <div class="tgl-track" id="themeTrack"><div class="tgl-thumb"></div></div>
        <span id="themeLabel">Dark</span>
      </button>
      <a href="goals.php" class="btn"><i class="fas fa-arrow-left"></i> Back to Goals</a>
    </div>
  </header>

  <?php if($success): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i><span><?php echo htmlspecialchars($success); ?></span></div><?php endif; ?>
  <?php if($error):   ?><div class="alert alert-error"><i class="fas fa-exclamation-circle"></i><span><?php echo htmlspecialchars($error); ?></span></div><?php endif; ?>

  <!-- FORM -->
  <form method="POST" id="goalForm">
    <!-- Basic Info -->
    <div class="form-card">
      <div class="form-card-header">
        <h3><i class="fas fa-info-circle" style="color:var(--info);"></i> Basic Information</h3>
      </div>
      <div class="form-card-body">
        <div class="form-grid">

          <div class="form-group form-full">
            <label>Goal Title <span class="req">*</span></label>
            <input type="text" name="title" maxlength="200" required
              placeholder="What do you want to achieve?"
              value="<?php echo htmlspecialchars($form['title']); ?>">
          </div>

          <div class="form-group form-full">
            <label>Description</label>
            <textarea name="description" placeholder="Describe your goal, why it matters, and the steps you'll take..."><?php echo htmlspecialchars($form['description']); ?></textarea>
          </div>

          <div class="form-group form-full">
            <label>Category</label>
            <input type="text" name="category" id="catInput"
              placeholder="e.g., Health, Education, Career, Fitness"
              value="<?php echo htmlspecialchars($form['category']); ?>"
              list="catList">
            <datalist id="catList">
              <?php foreach($all_categories as $c): ?><option value="<?php echo htmlspecialchars($c); ?>"><?php endforeach; ?>
            </datalist>
            <?php if(!empty($all_categories)): ?>
            <div class="cat-tags">
              <?php foreach(array_slice($all_categories,0,8) as $c): ?>
                <span class="cat-tag" onclick="document.getElementById('catInput').value='<?php echo addslashes($c); ?>'"><?php echo htmlspecialchars($c); ?></span>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>
          </div>

        </div>
      </div>
    </div>

    <!-- Goal Details -->
    <div class="form-card">
      <div class="form-card-header">
        <h3><i class="fas fa-sliders-h" style="color:var(--warning);"></i> Goal Details</h3>
      </div>
      <div class="form-card-body">
        <div class="form-grid">

          <div class="form-group">
            <label>Target Value <span class="req">*</span></label>
            <input type="number" name="target_value" min="0.01" step="0.01" required
              placeholder="e.g., 100, 5.5, 30"
              value="<?php echo htmlspecialchars($form['target_value']); ?>">
            <span class="form-hint">The number you want to reach</span>
          </div>

          <div class="form-group">
            <label>Unit <span class="req">*</span></label>
            <input type="text" name="unit" required
              placeholder="e.g., pages, km, hours, kg"
              value="<?php echo htmlspecialchars($form['unit']); ?>">
            <span class="form-hint">What you're measuring</span>
          </div>

          <div class="form-group">
            <label>Start Date</label>
            <input type="date" name="start_date"
              value="<?php echo htmlspecialchars($form['start_date']); ?>">
          </div>

          <div class="form-group">
            <label>Due Date</label>
            <input type="date" name="due_date"
              value="<?php echo htmlspecialchars($form['due_date']); ?>">
          </div>

          <div class="form-group form-full">
            <label>Priority</label>
            <input type="hidden" name="priority" id="priorityInput" value="<?php echo htmlspecialchars($form['priority']); ?>">
            <div class="priority-grid">
              <?php foreach([['low','fa-arrow-down','Low'],['medium','fa-equals','Medium'],['high','fa-arrow-up','High']] as [$val,$icon,$label]): ?>
                <div class="priority-opt <?php echo $val; ?> <?php echo $form['priority']===$val?'on':''; ?>" data-val="<?php echo $val; ?>">
                  <i class="fas <?php echo $icon; ?>"></i>
                  <span><?php echo $label; ?> Priority</span>
                </div>
              <?php endforeach; ?>
            </div>
          </div>

        </div>
      </div>
    </div>

    <!-- Actions -->
    <div class="form-actions" style="max-width:780px;">
      <button type="reset" class="btn btn-ghost" onclick="return confirm('Clear all fields?')">
        <i class="fas fa-redo"></i> Clear
      </button>
      <button type="submit" class="btn btn-primary">
        <i class="fas fa-plus"></i> Create Goal
      </button>
    </div>
  </form>

  <!-- Tips -->
  <div class="tips-card">
    <div class="tips-card-header"><h3><i class="fas fa-lightbulb" style="color:var(--warning);"></i> Tips for Effective Goals</h3></div>
    <div class="tips-grid">
      <div class="tip"><i class="fas fa-bullseye" style="color:var(--primary);"></i><div><div class="tip-title">Be Specific</div><div class="tip-text">Clear goals are easier to track</div></div></div>
      <div class="tip"><i class="fas fa-ruler" style="color:var(--info);"></i><div><div class="tip-title">Make it Measurable</div><div class="tip-text">Use numbers to track progress</div></div></div>
      <div class="tip"><i class="fas fa-calendar-check" style="color:var(--success);"></i><div><div class="tip-title">Set a Deadline</div><div class="tip-text">Deadlines create momentum</div></div></div>
      <div class="tip"><i class="fas fa-chart-line" style="color:var(--warning);"></i><div><div class="tip-title">Track Regularly</div><div class="tip-text">Update progress to stay motivated</div></div></div>
    </div>
  </div>

</main></div>

<script>
// Priority picker
document.querySelectorAll('.priority-opt').forEach(opt => {
  opt.addEventListener('click', () => {
    document.querySelectorAll('.priority-opt').forEach(o => o.classList.remove('on'));
    opt.classList.add('on');
    document.getElementById('priorityInput').value = opt.dataset.val;
  });
});

// Set min dates
const today = new Date().toISOString().split('T')[0];
document.querySelector('[name=start_date]').min = today;
document.querySelector('[name=due_date]').min   = today;

// Validate before submit
document.getElementById('goalForm').addEventListener('submit', function(e){
  const title  = this.title.value.trim();
  const target = parseFloat(this.target_value.value);
  const unit   = this.unit.value.trim();
  const due    = this.due_date.value;
  const start  = this.start_date.value;
  const errs   = [];
  if (!title || title.length < 3) errs.push('Title must be at least 3 characters.');
  if (!target || target <= 0)      errs.push('Target value must be greater than 0.');
  if (!unit)                        errs.push('Unit is required.');
  if (due && due < today)           errs.push('Due date cannot be in the past.');
  if (start && due && start > due)  errs.push('Start date cannot be after due date.');
  if (errs.length) {
    e.preventDefault();
    let alertEl = document.querySelector('.alert-error');
    if (!alertEl) {
      alertEl = document.createElement('div');
      alertEl.className = 'alert alert-error';
      alertEl.innerHTML = '<i class="fas fa-exclamation-circle"></i><span></span>';
      document.querySelector('.page-header').after(alertEl);
    }
    alertEl.querySelector('span').textContent = errs.join(' ');
    window.scrollTo({top:0,behavior:'smooth'});
  }
});
</script>
<?php nav_js(); ?>
</body>
</html>