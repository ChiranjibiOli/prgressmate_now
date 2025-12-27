<?php
// Admin sidebar component
?>
<aside class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <div class="logo">
            <i class="fas fa-star"></i>
            <span>ProgressMate</span>
        </div>
        <button class="sidebar-close" id="sidebarClose">
            <i class="fas fa-times"></i>
        </button>
    </div>

    <!-- Admin Profile -->
    <div class="user-profile">
        <?php if (!empty($_SESSION['profile_picture'])): ?>
            <img src="../<?php echo htmlspecialchars($_SESSION['profile_picture']); ?>" alt="Profile" class="profile-pic">
        <?php else: ?>
            <div class="profile-pic default">
                <?php echo strtoupper(substr($_SESSION['name'], 0, 1)); ?>
            </div>
        <?php endif; ?>
        <div class="user-info">
            <h4><?php echo htmlspecialchars($_SESSION['name']); ?></h4>
            <p><?php echo htmlspecialchars($_SESSION['email']); ?></p>
            <span style="font-size: 11px; background: #dc2626; color: white; padding: 2px 8px; border-radius: 12px;">
                ADMIN
            </span>
        </div>
    </div>

    <!-- Admin Navigation Menu -->
    <nav class="nav-menu">
        <a href="admin.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'admin.php' ? 'active' : ''; ?>">
            <i class="fas fa-tachometer-alt"></i>
            <span>Dashboard</span>
        </a>
        <a href="students.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'students.php' ? 'active' : ''; ?>">
            <i class="fas fa-users"></i>
            <span>Students</span>
            <?php 
            $total_students = getStat($pdo, "SELECT COUNT(*) FROM users WHERE role = 'student' AND status = 'active'");
            if ($total_students > 0): ?>
                <span class="badge"><?php echo $total_students; ?></span>
            <?php endif; ?>
        </a>
        <a href="goals.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'goals.php' ? 'active' : ''; ?>">
            <i class="fas fa-bullseye"></i>
            <span>System Goals</span>
            <?php 
            $total_goals = getStat($pdo, "SELECT COUNT(*) FROM admin_goals WHERE status = 'active'");
            if ($total_goals > 0): ?>
                <span class="badge"><?php echo $total_goals; ?></span>
            <?php endif; ?>
        </a>
        <a href="assign_goals.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'assign_goals.php' ? 'active' : ''; ?>">
            <i class="fas fa-tasks"></i>
            <span>Assign Goals</span>
        </a>
        <a href="reports.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'reports.php' ? 'active' : ''; ?>">
            <i class="fas fa-chart-bar"></i>
            <span>Reports</span>
        </a>
        <a href="achievements.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'achievements.php' ? 'active' : ''; ?>">
            <i class="fas fa-trophy"></i>
            <span>Achievements</span>
        </a>
        <a href="settings.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'settings.php' ? 'active' : ''; ?>">
            <i class="fas fa-cog"></i>
            <span>Settings</span>
        </a>
    </nav>

    <!-- Quick Stats -->
    <div style="padding: 20px; border-top: 1px solid #e5e7eb;">
        <div style="display: flex; align-items: center; gap: 15px; margin-bottom: 15px;">
            <div style="width: 40px; height: 40px; border-radius: 10px; background: #eef2ff; color: #4f46e5; display: flex; align-items: center; justify-content: center;">
                <i class="fas fa-users"></i>
            </div>
            <div>
                <span style="display: block; font-size: 12px; color: #6b7280;">Students</span>
                <span style="display: block; font-size: 16px; font-weight: 600; color: #111827;"><?php echo $total_students; ?></span>
            </div>
        </div>
        <div style="display: flex; align-items: center; gap: 15px;">
            <div style="width: 40px; height: 40px; border-radius: 10px; background: #eef2ff; color: #4f46e5; display: flex; align-items: center; justify-content: center;">
                <i class="fas fa-bullseye"></i>
            </div>
            <div>
                <span style="display: block; font-size: 12px; color: #6b7280;">System Goals</span>
                <span style="display: block; font-size: 16px; font-weight: 600; color: #111827;"><?php echo $total_goals; ?></span>
            </div>
        </div>
    </div>

    <!-- Logout -->
    <div class="sidebar-footer">
        <a href="../student/dashboard.php"" class="btn btn-outline" style="margin-bottom: 10px; text-align: center; display: block;">
            <i class="fas fa-user"></i> Student View
        </a>
        <a href="../logout.php" class="logout-btn">
            <i class="fas fa-sign-out-alt"></i>
            <span>Logout</span>
        </a>
    </div>
</aside>