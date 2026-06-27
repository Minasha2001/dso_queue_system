<?php
session_start();
require_once 'db_connect.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $error = 'Please enter both username and password.';
    } else {

        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {

            if ($user['status'] !== 'active') {
                $error = 'Your account is inactive. Please contact administrator.';
            } else {

                $_SESSION['user_id'] = $user['user_id'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['full_name'] = $user['full_name'];

                header("Location: dashboard.php");
                exit;
            }

        } else {
            $error = 'Invalid credentials.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Divisional Secretariat Office</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- FontAwesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <div class="bg-overlay"></div>
    <div class="main-container">
        <div class="row align-items-center min-vh-100">
            <!-- Left Branding -->
            <div class="col-lg-7 branding-section d-none d-lg-block">
                <div class="mb-4"><br><br><br><br>
                <img src="image/emblem.png" alt="Sri Lanka Emblem" style="height: 100px;">
                </div>
                <h1>Divisional Secretariat</h1>
                <h2>Queue & Appointment Management System</h2>
                <div class="subtitle mt-3">
                &bull; Efficient Service &nbsp;&bull;&nbsp; Less Waiting &nbsp;&bull;&nbsp; Better Experience
                </div>
                <br><br><br>
                <div class="features-container mt-5">
                    <div class="feature-item">
                        <i class="fas fa-users-cog"></i>
                        <div>
                            <p>Manage Queue</p>
                            <span>Organized service</span>
                        </div>
                    </div>
                    <div class="feature-item">
                        <i class="far fa-calendar-check"></i>
                        <div>
                            <p>Book Appointment</p>
                            <span>Easy & Fast</span>
                        </div>
                    </div>
                    <div class="feature-item">
                        <i class="fas fa-shield-alt"></i>
                        <div>
                            <p>Secure System</p>
                            <span>Your data is safe</span>
                        </div>
                    </div>
                </div>
                
                <div class="mt-2" style="font-size: 0.8rem; color: #000000; text-shadow: 0 1px 2px rgba(0,0,0,0.8);">
                   &nbsp;&nbsp; &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&copy; <?php echo date('Y'); ?> Divisional Secretariat Office. All rights reserved.
                </div>
            </div>

            <!-- Right Login Card -->
            <div class="col-lg-5">
                <div class="auth-card">
                    <div class="auth-header">
                        <div class="icon-circle">
                            <i class="fas fa-user-lock"></i>
                        </div>
                        <h3>Welcome Back!</h3>
                        <p>Please login to continue</p>
                    </div>

                    <?php if ($error): ?>
                        <div class="alert alert-danger p-2 text-center" style="font-size:0.9rem;"><?php echo htmlspecialchars($error); ?></div>
                    <?php endif; ?>

                    <form method="POST" action="">
                        <!-- Username -->
                        <div class="form-group">
                            <i class="far fa-user input-icon"></i>
                            <input type="text" class="form-control" name="username" placeholder="Username (Email)" required value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>">
                        </div>

                        <!-- Password -->
                        <div class="form-group">
                            <i class="fas fa-lock input-icon"></i>
                            <input type="password" class="form-control" id="password" name="password" placeholder="Enter your password" required>
                            <i class="far fa-eye-slash password-toggle" id="togglePassword"></i>
                        </div>


                        <!-- Remember Me & Forgot Password -->
                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="rememberMe">
                                <label class="form-check-label" for="rememberMe" style="font-size: 0.9rem;">
                                    Remember me
                                </label>
                            </div>
                            <a href="forgot_password.php" style="font-size: 0.9rem;">Forgot Password?</a>
                        </div>

                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-sign-in-alt me-2"></i> Login
                        </button>
                    </form>

                    <div class="auth-footer">
                        <div class="divider">
                            <span>or</span>
                        </div>
                        <p>Don't have an account? <a href="signup.php">Create Account</a></p>
                        <p class="mt-3">
                            <i class="far fa-question-circle"></i> <a href="#" style="color:var(--text-muted);">Need Help? Contact System Administrator</a>
                        </p>
                    </div>
                </div>
            </div>
            
            <!-- Mobile Footer -->
            <div class="col-12 d-block d-lg-none mt-4 text-center text-white" style="text-shadow: 0 1px 2px rgba(0,0,0,0.8);">
                &copy; <?php echo date('Y'); ?> Divisional Secretariat Office. All rights reserved.
            </div>
        </div>
    </div>

    <!-- Bootstrap Bundle JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Password toggle logic
        const togglePassword = document.querySelector('#togglePassword');
        const password = document.querySelector('#password');

        togglePassword.addEventListener('click', function (e) {
            const type = password.getAttribute('type') === 'password' ? 'text' : 'password';
            password.setAttribute('type', type);
            this.classList.toggle('fa-eye-slash');
            this.classList.toggle('fa-eye');
        });
    </script>
</body>
</html>
