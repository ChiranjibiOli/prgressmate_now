<?php
// Student sidebar component
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

    <!-- Student Profile -->
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
            <span style="font-size: 11px; background: #e0e7ff; color: #4f46e5; padding: 2px 8px; border-radius: 12px;">
                STUDENT
            </span>
        </div>
    </div>

    <!-- Student Navigation Menu -->
    <nav class="nav-menu">
        <a href="../student/dashboard.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : ''; ?>">
            <i class="fas fa-tachometer-alt"></i>
            <span>Dashboard</span>
        </a>
        <a href="goals.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'goals.php' ? 'active' : ''; ?>">
            <i class="fas fa-bullseye"></i>
            <span>My Goals</span>
            <?php 
            $total_goals = getStat($pdo, "SELECT COUNT(*) FROM student_goals WHERE student_id = ?", [$student_id]);
            if ($total_goals > 0): ?>
                <span class="badge"><?php echo $total_goals; ?></span>
            <?php endif; ?>
        </a>
        <a href="create_goal.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'create_goal.php' ? 'active' : ''; ?>">
            <i class="fas fa-plus-circle"></i>
            <span>Create Goal</span>
        </a>
        <a href="achievements.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'achievements.php' ? 'active' : ''; ?>">
            <i class="fas fa-trophy"></i>
            <span>Achievements</span>
            <?php 
            $total_points = getStat($pdo, "SELECT COALESCE(SUM(a.points), 0) FROM user_achievements ua JOIN achievements a ON ua.achievement_id = a.id WHERE ua.user_id = ?", [$student_id]);
            if ($total_points > 0): ?>
                <span class="badge"><?php echo $total_points; ?> pts</span>
            <?php endif; ?>
        </a>
        <a href="reminders.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'reminders.php' ? 'active' : ''; ?>">
            <i class="fas fa-bell"></i>
            <span>Reminders</span>
        </a>
        <a href="notifications.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'notifications.php' ? 'active' : ''; ?>">
            <i class="fas fa-inbox"></i>
            <span>Notifications</span>
            <?php 
            $unread = getStat($pdo, "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0", [$student_id]);
            if ($unread > 0): ?>
                <span class="badge"><?php echo $unread; ?></span>
            <?php endif; ?>
        </a>
        <a href="profile.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'profile.php' ? 'active' : ''; ?>">
            <i class="fas fa-user"></i>
            <span>Profile</span>
        </a>
    </nav>

    <!-- Quick Stats -->
    <div style="padding: 20px; border-top: 1px solid #e5e7eb;">
        <div style="display: flex; align-items: center; gap: 15px; margin-bottom: 15px;">
            <div style="width: 40px; height: 40px; border-radius: 10px; background: #eef2ff; color: #4f46e5; display: flex; align-items: center; justify-content: center;">
                <i class="fas fa-bullseye"></i>
            </div>
            <div>
                <span style="display: block; font-size: 12px; color: #6b7280;">Goals</span>
                <span style="display: block; font-size: 16px; font-weight: 600; color: #111827;">
                    <?php 
                    $completed = getStat($pdo, "SELECT COUNT(*) FROM student_goals WHERE student_id = ? AND status = 'completed'", [$student_id]);
                    echo $completed . '/' . $total_goals;
                    ?>
                </span>
            </div>
        </div>
        <div style="display: flex; align-items: center; gap: 15px;">
            <div style="width: 40px; height: 40px; border-radius: 10px; background: #eef2ff; color: #4f46e5; display: flex; align-items: center; justify-content: center;">
                <i class="fas fa-star"></i>
            </div>
            <div>
                <span style="display: block; font-size: 12px; color: #6b7280;">Points</span>
                <span style="display: block; font-size: 16px; font-weight: 600; color: #111827;"><?php echo $total_points; ?></span>
            </div>
        </div>
    </div>

    <!-- Logout -->
    <div class="sidebar-footer">
        <a href="../logout.php" class="logout-btn" onclick="return confirmLogout()">
            <i class="fas fa-sign-out-alt"></i>
            <span>Logout</span>
        </a>
    </div>
</aside>