<?php
require_once 'db_connect.php';

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = trim($_POST['email'] ?? '');

    if (empty($email)) {
        $message = 'Please enter your email address.';
        $messageType = 'danger';
    } else {
        // Just mock the process for now
        $stmt = $pdo->prepare("SELECT user_id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        
        if ($stmt->rowCount() > 0) {
            $message = 'If this email exists in our system, a password reset link has been sent.';
            $messageType = 'success';
        } else {
            // Standard security practice: Don't reveal if email exists or not
            $message = 'If this email exists in our system, a password reset link has been sent.';
            $messageType = 'success';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - Divisional Secretariat</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <div class="bg-overlay"></div>
    <div class="main-container">
        <div class="row justify-content-center align-items-center min-vh-100">
            <div class="col-md-6 col-lg-5">
                <div class="auth-card">
                    <div class="auth-header mb-3">
                        <div class="icon-circle">
                            <i class="fas fa-key"></i>
                        </div>
                        <h3>Forgot Password?</h3>
                        <p>Enter your email to reset your password</p>
                    </div>

                    <?php if ($message): ?>
                        <div class="alert alert-<?php echo $messageType; ?> p-2 text-center" style="font-size:0.9rem;">
                            <?php echo htmlspecialchars($message); ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="">
                        <div class="form-group">
                            <i class="fas fa-envelope input-icon"></i>
                            <input type="email" class="form-control" name="email" placeholder="Email Address" required>
                        </div>

                        <button type="submit" class="btn btn-primary mt-2">
                            <i class="fas fa-paper-plane me-2"></i> Send Reset Link
                        </button>
                    </form>

                    <div class="auth-footer">
                        <div class="divider">
                            <span>or</span>
                        </div>
                        <p><i class="fas fa-arrow-left"></i> <a href="index.php">Back to Login</a></p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
