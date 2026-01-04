<?php
session_start();
require_once '../includes/db_connection.php';
require_once '../includes/functions.php';
checkAuth('admin');

$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'create' || $action === 'update') {
            $name = trim($_POST['name'] ?? '');
            $color = $_POST['color'] ?? '#6366f1';
            $icon = trim($_POST['icon'] ?? 'fas fa-book');
            $description = $_POST['description'] ?? '';

            if (empty($name)) throw new Exception("Category name required.");

            if ($action === 'create') {
                $pdo->prepare("INSERT INTO categories (name, description, color, icon, is_global, created_by, created_at) VALUES (?, ?, ?, ?, 1, ?, NOW())")
                    ->execute([$name, $description, $color, $icon, $_SESSION['user_id']]);
                $_SESSION['success'] = "Category created.";
            } else {
                $id = (int)$_POST['id'];
                $pdo->prepare("UPDATE categories SET name=?, description=?, color=?, icon=? WHERE id=? AND deleted_at IS NULL")
                    ->execute([$name, $description, $color, $icon, $id]);
                $_SESSION['success'] = "Category updated.";
            }
        } elseif ($action === 'delete') {
            $id = (int)$_POST['id'];
            $pdo->prepare("UPDATE categories SET deleted_at = NOW() WHERE id = ?")->execute([$id]);
            $_SESSION['success'] = "Category deleted (soft).";
        }
    } catch (Exception $e) {
        $_SESSION['error'] = $e->getMessage();
    }
    header("Location: categories.php");
    exit;
}

// Fetch categories
$categories = $pdo->query("SELECT * FROM categories WHERE deleted_at IS NULL ORDER BY name")->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Categories - ProgressMate Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        .category-tag { display: inline-flex; align-items: center; gap: 8px; padding: 4px 12px; border-radius: 20px; color: white; font-size: 0.9rem; }
        .card { background: white; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.1); padding: 20px; }
        @media (max-width: 768px) { .grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <div class="layout">
        <!-- Sidebar (same as other admin pages) -->
        <?php include '../includes/admin_sidebar.php'; ?>

        <main class="main-content">
            <header class="page-header">
                <h1>Manage Categories (Topics)</h1>
                <button class="btn btn-primary" onclick="openModal('createModal')">Add Category</button>
            </header>

            <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
            <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

            <div class="grid" style="display:grid; gap:20px; grid-template-columns: repeat(auto-fill, minmax(300px,1fr));">
                <?php foreach ($categories as $cat): ?>
                    <div class="card">
                        <div class="category-tag" style="background: <?= htmlspecialchars($cat['color']) ?>;">
                            <i class="<?= htmlspecialchars($cat['icon']) ?>"></i>
                            <?= htmlspecialchars($cat['name']) ?>
                        </div>
                        <p><?= htmlspecialchars($cat['description'] ?? 'No description') ?></p>
                        <button class="btn btn-outline" onclick='editCategory(<?= json_encode($cat) ?>)'>Edit</button>
                        <button class="btn btn-danger" onclick='deleteCategory(<?= $cat['id'] ?>)'>Delete</button>
                    </div>
                <?php endforeach; ?>
            </div>
        </main>
    </div>

    <!-- Modals (create/edit) -->
    <div class="modal" id="createModal">
        <div class="modal-content">
            <h2 id="modalTitle">Add Category</h2>
            <form method="POST">
                <input type="hidden" name="action" id="modalAction" value="create">
                <input type="hidden" name="id" id="modalId">
                <div class="form-group">
                    <label>Name *</label>
                    <input type="text" name="name" id="modalName" required>
                </div>
                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" id="modalDesc"></textarea>
                </div>
                <div class="form-group">
                    <label>Color</label>
                    <input type="color" name="color" id="modalColor" value="#6366f1">
                </div>
                <div class="form-group">
                    <label>Icon (FontAwesome class)</label>
                    <input type="text" name="icon" id="modalIcon" value="fas fa-book" placeholder="e.g. fas fa-graduation-cap">
                </div>
                <button type="submit" class="btn btn-primary">Save</button>
                <button type="button" class="btn btn-outline" onclick="closeModal('createModal')">Cancel</button>
            </form>
        </div>
    </div>

    <script>
        function openModal(id) { document.getElementById(id).classList.add('active'); }
        function closeModal(id) { document.getElementById(id).classList.remove('active'); }
        function editCategory(cat) {
            document.getElementById('modalTitle').textContent = 'Edit Category';
            document.getElementById('modalAction').value = 'update';
            document.getElementById('modalId').value = cat.id;
            document.getElementById('modalName').value = cat.name;
            document.getElementById('modalDesc').value = cat.description || '';
            document.getElementById('modalColor').value = cat.color;
            document.getElementById('modalIcon').value = cat.icon;
            openModal('createModal');
        }
        function deleteCategory(id) {
            if (confirm('Delete this category? Goals will keep their category name.')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `<input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="${id}">`;
                document.body.appendChild(form);
                form.submit();
            }
        }
    </script>
</body>
</html>