<?php
// logout.php
session_start();

// Check if logout was confirmed
if (!isset($_GET['confirm']) || $_GET['confirm'] !== 'yes') {
    // Show confirmation page
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Logout Confirmation - ProgressMate</title>
        <link rel="stylesheet" href="assets/css/style.css">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <style>
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }
            
            body {
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 20px;
            }
            
            .logout-container {
                width: 100%;
                max-width: 400px;
            }
            
            .logout-card {
                background: white;
                border-radius: 20px;
                padding: 40px;
                box-shadow: 0 20px 60px rgba(0,0,0,0.3);
                text-align: center;
                animation: slideUp 0.5s ease;
            }
            
            @keyframes slideUp {
                from {
                    opacity: 0;
                    transform: translateY(20px);
                }
                to {
                    opacity: 1;
                    transform: translateY(0);
                }
            }
            
            .logout-icon {
                font-size: 64px;
                color: #ef4444;
                margin-bottom: 20px;
            }
            
            .logout-card h2 {
                color: #111827;
                margin-bottom: 15px;
            }
            
            .logout-card p {
                color: #6b7280;
                margin-bottom: 30px;
                line-height: 1.6;
            }
            
            .logout-buttons {
                display: flex;
                gap: 15px;
                justify-content: center;
            }
            
            .btn {
                padding: 12px 30px;
                border-radius: 10px;
                font-weight: 600;
                cursor: pointer;
                border: none;
                transition: all 0.3s;
                font-size: 16px;
                display: inline-flex;
                align-items: center;
                gap: 10px;
                text-decoration: none;
            }
            
            .btn-danger {
                background: #ef4444;
                color: white;
            }
            
            .btn-danger:hover {
                background: #dc2626;
                transform: translateY(-2px);
            }
            
            .btn-outline {
                background: white;
                color: #6b7280;
                border: 2px solid #e5e7eb;
            }
            
            .btn-outline:hover {
                background: #f9fafb;
                transform: translateY(-2px);
            }
            
            .user-info {
                display: flex;
                align-items: center;
                gap: 15px;
                justify-content: center;
                margin-bottom: 25px;
                padding: 15px;
                background: #f9fafb;
                border-radius: 10px;
            }
            
            .user-avatar {
                width: 50px;
                height: 50px;
                border-radius: 50%;
                background: linear-gradient(135deg, #4f46e5, #8b5cf6);
                color: white;
                display: flex;
                align-items: center;
                justify-content: center;
                font-weight: bold;
                font-size: 20px;
            }
            
            .user-details h4 {
                margin: 0 0 5px 0;
                color: #111827;
            }
            
            .user-details p {
                margin: 0;
                font-size: 14px;
                color: #6b7280;
            }
        </style>
    </head>
    <body>
        <div class="logout-container">
            <div class="logout-card">
                <div class="logout-icon">
                    <i class="fas fa-sign-out-alt"></i>
                </div>
                
                <?php if (isset($_SESSION['name'])): ?>
                    <div class="user-info">
                        <div class="user-avatar">
                            <?php echo strtoupper(substr($_SESSION['name'], 0, 1)); ?>
                        </div>
                        <div class="user-details">
                            <h4><?php echo htmlspecialchars($_SESSION['name']); ?></h4>
                            <p><?php echo htmlspecialchars($_SESSION['email']); ?></p>
                            <span style="font-size: 12px; background: #e0e7ff; color: #4f46e5; padding: 2px 8px; border-radius: 12px;">
                                <?php echo strtoupper($_SESSION['role']); ?>
                            </span>
                        </div>
                    </div>
                <?php endif; ?>
                
                <h2>Are you sure you want to logout?</h2>
                <p>You will be signed out of your account and redirected to the login page.</p>
                
                <div class="logout-buttons">
                    <a href="logout.php?confirm=yes" class="btn btn-danger">
                        <i class="fas fa-sign-out-alt"></i> Yes, Logout
                    </a>
                    <a href="javascript:history.back()" class="btn btn-outline">
                        <i class="fas fa-times"></i> Cancel
                    </a>
                </div>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// If confirmed, destroy session and redirect
session_destroy();

// Clear session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Redirect to login page with success message
session_start();
$_SESSION['logout_success'] = "You have been successfully logged out.";
header("Location: login.php");
exit;
?>