<?php


session_start();
require_once 'includes/db_connection.php';



$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $name             = trim($_POST['name']             ?? '');
    $email            = trim($_POST['email']            ?? '');
    $student_id       = trim($_POST['student_id']       ?? '');
    $department       = trim($_POST['department']       ?? '');
    $semester         = trim($_POST['semester']         ?? '');
    $password         = $_POST['password']         ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    // Validation
    if (empty($name) || empty($email) || empty($password)) {
        $error = "Please fill in all required fields.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } elseif ($password !== $confirm_password) {
        $error = "Passwords do not match.";
    } elseif (strlen($password) < 6) {
        $error = "Password must be at least 6 characters.";
    } else {
        try {
            // Check if email already exists
            $check_stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $check_stmt->execute([$email]);

            if ($check_stmt->fetch()) {
                $error = "Email already registered. Please use a different email or log in.";
            } else {
                // Check student_id uniqueness only if provided
                if (!empty($student_id)) {
                    $sid_check = $pdo->prepare("SELECT id FROM users WHERE student_id = ?");
                    $sid_check->execute([$student_id]);
                    if ($sid_check->fetch()) {
                        $error = "Student ID already in use. Please check your ID or leave it blank.";
                    }
                }

                if (empty($error)) {
                    // Hash password securely
                    $hashed_password = password_hash($password, PASSWORD_BCRYPT);

                    // Insert user — trigger will handle welcome notification,
                    // achievement, and auto-assign admin goals
                    $stmt = $pdo->prepare("
                        INSERT INTO users (name, email, student_id, department, semester, password, role, status)
                        VALUES (?, ?, ?, ?, ?, ?, 'student', 'active')
                    ");
                    $stmt->execute([
                        $name,
                        $email,
                        !empty($student_id) ? $student_id : null,  // NULL if blank (avoids unique key conflict)
                        $department,
                        $semester,
                        $hashed_password
                    ]);

                    $user_id = $pdo->lastInsertId();

                    // FIX 2: session_start() is now at top so this actually works
                    $_SESSION['user_id']         = $user_id;
                    $_SESSION['name']            = $name;
                    $_SESSION['email']           = $email;
                    $_SESSION['role']            = 'student';
                    $_SESSION['profile_picture'] = null;

                    // Update last_login
                    $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute([$user_id]);

                    // FIX 3: consistent redirect path (was 'students/dashboard.php', matches login.php)
                    header("Location: students/dashboard.php");
                    exit;
                }
            }
        } catch (Exception $e) {
            $error = "Registration failed: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - ProgressMate</title>
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

        .register-container {
            width: 100%;
            max-width: 500px;
        }

        .register-card {
            background: white;
            border-radius: 20px;
            padding: 40px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            animation: slideUp 0.5s ease;
        }

        @keyframes slideUp {
            from { opacity: 0; transform: translateY(20px); }
            to   { opacity: 1; transform: translateY(0);    }
        }

        .logo {
            text-align: center;
            margin-bottom: 30px;
        }

        .logo i {
            font-size: 48px;
            color: #667eea;
            margin-bottom: 10px;
        }

        .logo h1 {
            color: #333;
            font-size: 28px;
            font-weight: 700;
        }

        .logo p {
            color: #666;
            font-size: 14px;
            margin-top: 5px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #555;
            font-weight: 500;
            font-size: 14px;
        }

        .form-group label.required::after {
            content: " *";
            color: #ff4444;
        }

        .input-with-icon {
            position: relative;
        }

        .input-with-icon i {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #999;
            pointer-events: none;
        }

        .input-with-icon input,
        .input-with-icon select {
            width: 100%;
            padding: 12px 15px 12px 45px;
            border: 2px solid #e1e5eb;
            border-radius: 10px;
            font-size: 16px;
            transition: all 0.3s;
            background: white;
        }

        .input-with-icon input:focus,
        .input-with-icon select:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        @media (max-width: 480px) {
            .form-row { grid-template-columns: 1fr; }
            .register-card { padding: 25px; }
        }

        .btn-register {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin-top: 10px;
        }

        .btn-register:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
        }

        .btn-register:active { transform: translateY(0); }

        .error-message {
            background: #fee;
            color: #c33;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .success-message {
            background: #efe;
            color: #363;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .links {
            text-align: center;
            margin-top: 25px;
            padding-top: 25px;
            border-top: 1px solid #eee;
        }

        .links a {
            color: #667eea;
            text-decoration: none;
            font-size: 14px;
        }

        .links a:hover { text-decoration: underline; }

        .password-strength {
            margin-top: 5px;
            font-size: 12px;
        }

        .strength-weak   { color: #ff4444; }
        .strength-medium { color: #ffaa00; }
        .strength-strong { color: #00C851; }
    </style>
</head>

<body>
    <div class="register-container">
        <div class="register-card">
            <div class="logo">
                <i class="fas fa-star"></i>
                <h1>Join ProgressMate</h1>
                <p>Start your journey to success</p>
            </div>

            <?php if ($error): ?>
                <div class="error-message">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="success-message">
                    <i class="fas fa-check-circle"></i>
                    <span><?php echo htmlspecialchars($success); ?></span>
                </div>
            <?php endif; ?>

            <form method="POST" action="" novalidate>
                <div class="form-row">
                    <div class="form-group">
                        <label for="name" class="required">Full Name</label>
                        <div class="input-with-icon">
                            <i class="fas fa-user"></i>
                            <input type="text" id="name" name="name"
                                   value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>"
                                   placeholder="John Doe" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="email" class="required">Email Address</label>
                        <div class="input-with-icon">
                            <i class="fas fa-envelope"></i>
                            <input type="email" id="email" name="email"
                                   value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                                   placeholder="john@example.com" required>
                        </div>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="student_id">Student ID <small style="color:#999">(optional)</small></label>
                        <div class="input-with-icon">
                            <i class="fas fa-id-card"></i>
                            <input type="text" id="student_id" name="student_id"
                                   value="<?php echo htmlspecialchars($_POST['student_id'] ?? ''); ?>"
                                   placeholder="e.g. STU006">
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="department">Department</label>
                        <div class="input-with-icon">
                            <i class="fas fa-building"></i>
                            <input type="text" id="department" name="department"
                                   value="<?php echo htmlspecialchars($_POST['department'] ?? ''); ?>"
                                   placeholder="Computer Science">
                        </div>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="semester">Semester</label>
                        <div class="input-with-icon">
                            <i class="fas fa-calendar"></i>
                            <select id="semester" name="semester">
                                <option value="">Select Semester</option>
                                <?php for ($i = 1; $i <= 8; $i++): ?>
                                    <option value="<?php echo $i; ?>"
                                        <?php echo (($_POST['semester'] ?? '') == $i) ? 'selected' : ''; ?>>
                                        Semester <?php echo $i; ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="password" class="required">Password</label>
                        <div class="input-with-icon">
                            <i class="fas fa-lock"></i>
                            <input type="password" id="password" name="password"
                                   placeholder="At least 6 characters" required>
                        </div>
                        <div id="password-strength" class="password-strength"></div>
                    </div>

                    <div class="form-group">
                        <label for="confirm_password" class="required">Confirm Password</label>
                        <div class="input-with-icon">
                            <i class="fas fa-lock"></i>
                            <input type="password" id="confirm_password" name="confirm_password"
                                   placeholder="Repeat your password" required>
                        </div>
                        <div id="password-match" class="password-strength"></div>
                    </div>
                </div>

                <button type="submit" class="btn-register">
                    <i class="fas fa-user-plus"></i>
                    <span>Create Account</span>
                </button>
            </form>

            <div class="links">
                <a href="login.php">Already have an account? Sign In</a>
            </div>
        </div>
    </div>

    <script>
        const passwordInput  = document.getElementById('password');
        const confirmInput   = document.getElementById('confirm_password');
        const strengthDisplay = document.getElementById('password-strength');
        const matchDisplay   = document.getElementById('password-match');

        passwordInput.addEventListener('input', function () {
            const pw = this.value;
            let strength = '', cls = '';

            if (pw.length === 0) {
                strength = '';
            } else if (pw.length < 6) {
                strength = 'Too short (min 6 chars)'; cls = 'strength-weak';
            } else {
                const score = [/[A-Z]/, /[a-z]/, /\d/, /[^A-Za-z0-9]/].filter(r => r.test(pw)).length;
                if (score >= 3)      { strength = '✓ Strong';  cls = 'strength-strong'; }
                else if (score >= 2) { strength = '~ Medium';  cls = 'strength-medium'; }
                else                 { strength = '✗ Weak';    cls = 'strength-weak';   }
            }

            strengthDisplay.textContent  = strength;
            strengthDisplay.className    = 'password-strength ' + cls;
            checkMatch();
        });

        confirmInput.addEventListener('input', checkMatch);

        function checkMatch() {
            const pw  = passwordInput.value;
            const cfm = confirmInput.value;
            if (!cfm) { matchDisplay.textContent = ''; return; }
            if (pw === cfm) {
                matchDisplay.textContent = '✓ Passwords match';
                matchDisplay.className   = 'password-strength strength-strong';
            } else {
                matchDisplay.textContent = '✗ Passwords do not match';
                matchDisplay.className   = 'password-strength strength-weak';
            }
        }

        document.querySelector('form').addEventListener('submit', function (e) {
            const name    = document.getElementById('name').value.trim();
            const email   = document.getElementById('email').value.trim();
            const pw      = passwordInput.value;
            const cfm     = confirmInput.value;

            if (!name || !email || !pw || !cfm) {
                e.preventDefault();
                alert('Please fill in all required fields.');
                return false;
            }
            if (pw !== cfm) {
                e.preventDefault();
                alert('Passwords do not match.');
                return false;
            }
            if (pw.length < 6) {
                e.preventDefault();
                alert('Password must be at least 6 characters.');
                return false;
            }
        });
    </script>
</body>
</html>