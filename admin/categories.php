<?php
session_start();
require_once '../includes/db_connection.php';
require_once '../includes/functions.php';
checkAuth('admin');

// CSRF Token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// POST handling
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf_token, $_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid request.';
    } else {
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'create' || $action === 'update') {
                $name = trim($_POST['name'] ?? '');
                $description = trim($_POST['description'] ?? '');
                $color = preg_match('/^#[a-f0-9]{6}$/i', $_POST['color'] ?? '') ? $_POST['color'] : '#6366f1';
                $icon = trim($_POST['icon'] ?? 'fas fa-book');

                if (empty($name)) {
                    throw new Exception('Category name is required.');
                }

                if ($action === 'create') {
                    $stmt = $pdo->prepare("
                        INSERT INTO categories 
                        (name, description, color, icon, is_global, created_by, created_at) 
                        VALUES (?, ?, ?, ?, 1, ?, NOW())
                    ");
                    $stmt->execute([$name, $description, $color, $icon, $_SESSION['user_id']]);
                    $_SESSION['success'] = 'Category created successfully.';
                } else {
                    $id = (int)($_POST['id'] ?? 0);
                    if ($id <= 0) {
                        throw new Exception('Invalid category ID.');
                    }
                    $stmt = $pdo->prepare("
                        UPDATE categories 
                        SET name = ?, description = ?, color = ?, icon = ? 
                        WHERE id = ? AND deleted_at IS NULL
                    ");
                    $stmt->execute([$name, $description, $color, $icon, $id]);
                    $_SESSION['success'] = 'Category updated successfully.';
                }
            } elseif ($action === 'delete') {
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) {
                    throw new Exception('Invalid category ID.');
                }
                $pdo->prepare("UPDATE categories SET deleted_at = NOW() WHERE id = ?")->execute([$id]);
                $_SESSION['success'] = 'Category deleted successfully.';
            }
        } catch (Exception $e) {
            $_SESSION['error'] = $e->getMessage();
        }
    }
    header('Location: categories.php');
    exit();
}

// Flash messages
$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);

// Fetch categories
$categories = $pdo->query("
    SELECT * FROM categories 
    WHERE deleted_at IS NULL 
    ORDER BY name ASC
")->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Categories - ProgressMate</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #4f46e5;
            --primary-dark: #4338ca;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #3b82f6;
            --purple: #8b5cf6;
            --gold: #fbbf24;
            --silver: #9ca3af;
            --bronze: #f97316;
            --gray-100: #f9fafb;
            --gray-200: #f3f4f6;
            --gray-300: #e5e7eb;
            --gray-400: #9ca3af;
            --gray-500: #6b7280;
            --gray-700: #374151;
            --gray-900: #111827;
            --shadow-sm: 0 1px 3px rgba(0,0,0,0.1);
            --shadow: 0 4px 12px rgba(0,0,0,0.08);
            --radius: 12px;
            --transition: all 0.3s ease;

            /* Light background shades for system status cards */
            --success-light: #ecfdf5;
            --info-light: #eff6ff;
            --purple-light: #f3e8ff;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: var(--gray-100); color: var(--gray-900); line-height: 1.6; }
        a { text-decoration: none; }

        .dashboard-wrapper { display: flex; min-height: 100vh; position: relative; }
        
        .sidebar {
            width: 280px;
            background: white;
            border-right: 1px solid var(--gray-300);
            position: fixed;
            height: 100vh;
            z-index: 1000;
            display: flex;
            flex-direction: column;
            transition: var(--transition);
            box-shadow: var(--shadow);
        }

        .sidebar-header { padding: 24px 20px; border-bottom: 1px solid var(--gray-300); display: flex; align-items: center; justify-content: space-between; }
        .logo { font-size: 20px; font-weight: 700; color: var(--primary); display: flex; align-items: center; gap: 10px; }
        .sidebar-close { display: none; background: none; border: none; font-size: 20px; color: var(--gray-500); cursor: pointer; }

        .user-profile { padding: 24px 20px; border-bottom: 1px solid var(--gray-300); display: flex; align-items: center; gap: 15px; }
        .profile-pic { width: 56px; height: 56px; border-radius: 50%; object-fit: cover; border: 3px solid var(--gray-300); }
        .profile-pic.default { background: linear-gradient(135deg, var(--primary), var(--purple)); color: white; font-size: 24px; font-weight: bold; display: flex; align-items: center; justify-content: center; }

        .nav-menu { flex: 1; padding: 16px 0; overflow-y: auto; }
        .nav-link { display: flex; align-items: center; gap: 12px; padding: 14px 20px; color: var(--gray-700); transition: var(--transition); }
        .nav-link:hover { background: var(--gray-200); color: var(--primary); }
        .nav-link.active { background: #eef2ff; color: var(--primary); border-left: 4px solid var(--primary); font-weight: 600; }
        .badge { margin-left: auto; background: var(--primary); color: white; padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; }

        .sidebar-quick-stats { padding: 20px; border-top: 1px solid var(--gray-300); }
        .sidebar-stat { display: flex; align-items: center; gap: 15px; margin-bottom: 16px; }
        .sidebar-stat-icon { width: 44px; height: 44px; border-radius: 10px; background: #eef2ff; color: var(--primary); display: flex; align-items: center; justify-content: center; font-size: 20px; }
        .sidebar-stat-label { font-size: 13px; color: var(--gray-500); }
        .sidebar-stat-number { font-size: 18px; font-weight: 700; }

        .sidebar-footer { padding: 20px; }
        .logout-btn { display: flex; align-items: center; gap: 12px; padding: 14px 20px; background: #fee2e2; color: #dc2626; border-radius: 10px; width: 100%; font-weight: 500; transition: var(--transition); }

        .main-content { flex: 1; margin-left: 280px; padding: 32px; transition: var(--transition); }

        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 32px; flex-wrap: wrap; gap: 16px; }
        .header-content h1 { font-size: 30px; font-weight: 700; }
        .header-content p { color: var(--gray-500); margin-top: 8px; }

        .btn { padding: 12px 24px; border-radius: 10px; font-weight: 500; cursor: pointer; border: none; transition: var(--transition); display: inline-flex; align-items: center; gap: 8px; font-size: 15px; }
        .btn-primary { background: var(--primary); color: white; }
        .btn-primary:hover { background: var(--primary-dark); }
        .btn-outline { background: white; color: var(--primary); border: 1px solid var(--primary); }
        .btn-outline:hover { background: var(--primary); color: white; }
        .btn-danger { background: var(--danger); color: white; }
        .btn-danger:hover { background: #dc2626; }

        .alert-success { background: var(--success-light); color: var(--success); padding: 16px; border-radius: var(--radius); margin-bottom: 24px; border-left: 5px solid var(--success); }
        .alert-error { background: var(--danger-light); color: var(--danger); padding: 16px; border-radius: var(--radius); margin-bottom: 24px; border-left: 5px solid var(--danger); }

        .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 24px; }
        .card { background: white; border-radius: var(--radius); padding: 24px; box-shadow: var(--shadow); }

        .category-tag { 
            display: inline-flex; 
            align-items: center; 
            gap: 8px; 
            padding: 8px 16px; 
            border-radius: 20px; 
            color: white; 
            font-weight: 600; 
            margin-bottom: 12px; 
        }

        .modal { 
            display: none; 
            position: fixed; 
            inset: 0; 
            background: rgba(0,0,0,0.5); 
            align-items: center; 
            justify-content: center; 
            z-index: 1000; 
        }
        .modal.active { display: flex; }
        .modal-content { 
            background: white; 
            border-radius: var(--radius); 
            width: 100%; 
            max-width: 500px; 
            padding: 32px; 
            box-shadow: var(--shadow); 
        }

        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: 500; }
        .form-group input, .form-group textarea { 
            width: 100%; 
            padding: 12px; 
            border: 1px solid var(--gray-300); 
            border-radius: 8px; 
        }
        .form-row { display: flex; gap: 16px; }
        .form-row .form-group { flex: 1; }

        .mobile-toggle { 
            display: none; 
            position: fixed; 
            top: 20px; 
            left: 20px; 
            z-index: 1100; 
            background: var(--primary); 
            color: white; 
            border: none; 
            width: 48px; 
            height: 48px; 
            border-radius: 12px; 
            font-size: 20px; 
            cursor: pointer; 
            box-shadow: var(--shadow); 
        }
        .sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 999; }

        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); width: 300px; }
            .sidebar.active { transform: translateX(0); }
            .sidebar-overlay.active { display: block; }
            .sidebar-close { display: block; }
            .mobile-toggle { display: flex; align-items: center; justify-content: center; }
            .main-content { margin-left: 0; padding: 24px 16px; padding-top: 80px; }
            .grid { grid-template-columns: 1fr; }
            .form-row { flex-direction: column; }
        }
    </style>
</head>
<body>
    <button class="mobile-toggle" id="sidebarToggle"><i class="fas fa-bars"></i></button>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <div class="dashboard-wrapper">
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <div class="logo"><i class="fas fa-star"></i> ProgressMate</div>
                <button class="sidebar-close" id="sidebarClose"><i class="fas fa-times"></i></button>
            </div>

            <div class="user-profile">
                <?php if (!empty($_SESSION['profile_picture'])): ?>
                    <img src="../<?php echo htmlspecialchars($_SESSION['profile_picture']); ?>" alt="Profile" class="profile-pic">
                <?php else: ?>
                    <div class="profile-pic default"><?php echo strtoupper(substr($_SESSION['name'] ?? 'A', 0, 1)); ?></div>
                <?php endif; ?>
                <div class="user-info">
                    <h4><?php echo htmlspecialchars($_SESSION['name'] ?? 'Admin'); ?></h4>
                    <p><?php echo htmlspecialchars($_SESSION['email'] ?? ''); ?></p>
                    <span style="font-size: 12px; background: var(--primary-light); color: var(--primary); padding: 4px 10px; border-radius: 20px; font-weight: 600;">ADMIN</span>
                </div>
            </div>

            <nav class="nav-menu">
                <a href="admin.php" class="nav-link"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                <a href="students.php" class="nav-link"><i class="fas fa-users"></i> Students</a>
                <a href="goals.php" class="nav-link"><i class="fas fa-bullseye"></i> System Goals</a>
                <a href="assign_goals.php" class="nav-link"><i class="fas fa-tasks"></i> Assign Goals</a>
                <a href="achievements.php" class="nav-link"><i class="fas fa-trophy"></i> Achievements</a>
                <a href="reports.php" class="nav-link"><i class="fas fa-chart-bar"></i> Reports</a>
                <a href="notifications.php" class="nav-link"><i class="fas fa-bell"></i> Notifications</a>
                <a href="categories.php" class="nav-link active"><i class="fas fa-tags"></i> Categories</a>
                <a href="settings.php" class="nav-link"><i class="fas fa-cog"></i> Settings</a>
            </nav>

            <div class="sidebar-quick-stats">
                <!-- Quick stats can be added here if needed -->
            </div>

            <div class="sidebar-footer">
                <a href="../logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
        </aside>

        <main class="main-content">
            <header class="page-header">
                <div>
                    <h1>Manage Categories</h1>
                    <p>Create and organize goal categories</p>
                </div>
                <button class="btn btn-primary" onclick="openModal()">Add New Category</button>
            </header>

            <?php if ($success): ?>
                <div class="alert-success"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert-error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <div class="grid">
                <?php if (empty($categories)): ?>
                    <div class="card" style="text-align:center; grid-column:1/-1; padding:60px;">
                        <i class="fas fa-tags" style="font-size:48px; color:var(--gray-500); margin-bottom:16px;"></i>
                        <p>No categories yet. Create your first one!</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($categories as $cat): ?>
                        <div class="card">
                            <div class="category-tag" style="background:<?php echo htmlspecialchars($cat['color']); ?>;">
                                <i class="<?php echo htmlspecialchars($cat['icon']); ?>"></i>
                                <?php echo htmlspecialchars($cat['name']); ?>
                            </div>
                            <p><?php echo htmlspecialchars($cat['description'] ?: 'No description'); ?></p>
                            <div style="display:flex; gap:12px; margin-top:20px;">
                                <button class="btn btn-outline" onclick='editCategory(<?php echo json_encode($cat, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>Edit</button>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo $cat['id']; ?>">
                                    <button type="submit" class="btn btn-danger" onclick="return confirm('Delete this category? Goals will retain the category name.')">Delete</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- Modal -->
    <div class="modal" id="categoryModal">
        <div class="modal-content">
            <h2 id="modalTitle">Add Category</h2>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="action" id="modalAction" value="create">
                <input type="hidden" name="id" id="modalId">

                <div class="form-group">
                    <label>Name <span style="color:var(--danger);">*</span></label>
                    <input type="text" name="name" id="modalName" required placeholder="e.g., Mathematics">
                </div>

                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" id="modalDesc" placeholder="Brief description of this category"></textarea>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Color</label>
                        <input type="color" name="color" id="modalColor" value="#6366f1">
                    </div>
                    <div class="form-group">
                        <label>Icon (Font Awesome class)</label>
                        <input type="text" name="icon" id="modalIcon" value="fas fa-book" placeholder="e.g., fas fa-calculator">
                    </div>
                </div>

                <div style="display:flex; gap:12px; margin-top:24px;">
                    <button type="submit" class="btn btn-primary">Save Category</button>
                    <button type="button" class="btn btn-outline" onclick="closeModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const modal = document.getElementById('categoryModal');
        const sidebar = document.getElementById('sidebar');
        const sidebarToggle = document.getElementById('sidebarToggle');
        const sidebarClose = document.getElementById('sidebarClose');
        const overlay = document.getElementById('sidebarOverlay');

        function openModal() {
            document.getElementById('modalTitle').textContent = 'Add Category';
            document.getElementById('modalAction').value = 'create';
            document.getElementById('modalId').value = '';
            document.getElementById('modalName').value = '';
            document.getElementById('modalDesc').value = '';
            document.getElementById('modalColor').value = '#6366f1';
            document.getElementById('modalIcon').value = 'fas fa-book';
            modal.classList.add('active');
        }

        function editCategory(cat) {
            document.getElementById('modalTitle').textContent = 'Edit Category';
            document.getElementById('modalAction').value = 'update';
            document.getElementById('modalId').value = cat.id;
            document.getElementById('modalName').value = cat.name;
            document.getElementById('modalDesc').value = cat.description || '';
            document.getElementById('modalColor').value = cat.color;
            document.getElementById('modalIcon').value = cat.icon;
            modal.classList.add('active');
        }

        function closeModal() {
            modal.classList.remove('active');
        }

        modal.addEventListener('click', e => { if (e.target === modal) closeModal(); });

        // Mobile sidebar
        sidebarToggle.addEventListener('click', () => {
            sidebar.classList.add('active');
            overlay.classList.add('active');
        });
        sidebarClose.addEventListener('click', () => {
            sidebar.classList.remove('active');
            overlay.classList.remove('active');
        });
        overlay.addEventListener('click', () => {
            sidebar.classList.remove('active');
            overlay.classList.remove('active');
        });
    </script>
</body>
</html>