<?php
session_start();
require_once 'db_connect.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $full_name = trim($_POST['full_name'] ?? '');
    $nic = trim($_POST['nic'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $mobile_no = trim($_POST['mobile_no'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

   $user_type = trim($_POST['user_type'] ?? '');

if (empty($full_name) || empty($nic) || empty($email) || empty($mobile_no) || empty($password) || empty($confirm_password) || empty($user_type)) {
    $error = 'All fields are required.';
}
elseif (!in_array($user_type, ['public', 'staff', 'admin'])) {
    $error = 'Please select a valid user type.';

}
elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $error = 'Invalid email address.';
}
elseif (!preg_match('/^[0-9]{10}$/', $mobile_no)) {
    $error = 'Invalid mobile number.';
}
elseif (!preg_match('/^([0-9]{9}[vVxX]|[0-9]{12})$/', $nic)) {
    $error = 'Invalid NIC number.';
}
elseif (strlen($password) < 8) {
    $error = 'Password must be at least 8 characters.';
}
elseif ($password !== $confirm_password) {
    $error = 'Passwords do not match.';
}
else {
    // Check if email or NIC already exists
    $stmt = $pdo->prepare("SELECT user_id FROM users WHERE email = ? OR nic = ?");
    $stmt->execute([$email, $nic]);

    if ($stmt->rowCount() > 0) {
        $error = 'User with this email or NIC already exists.';
    } else {
        // Hash password and insert
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare(
            "INSERT INTO users (full_name, email, password, nic, mobile_no, role)
             VALUES (?, ?, ?, ?, ?, ?)"
        );

        if ($stmt->execute([$full_name, $email, $hashed_password, $nic, $mobile_no, $user_type])) {
            $success = 'Account created successfully! You can now <a href="index.php">login</a>.';
        } else {
            $error = 'Something went wrong. Please try again.';
        }
    }
}
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Account - Divisional Secretariat</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <div class="bg-overlay"></div>
    <div class="container-fluid">
    <div class="row justify-content-center align-items-center min-vh-100">

        <div class="col-md-8 col-lg-6 offset-lg-0">
            <div class="auth-card" style="max-width: 600px;">
                    <div class="auth-header mb-3">
                        <div class="icon-circle">
                            <i class="fas fa-user-plus"></i>
                        </div>
                        <h3>Create Account</h3>
                        <p>Join the Queue & Appointment System</p>
                    </div>

                    <?php if ($error): ?>
                        <div class="alert alert-danger p-2 text-center" style="font-size:0.9rem;"><?php echo htmlspecialchars($error); ?></div>
                    <?php endif; ?>
                    <?php if ($success): ?>
                        <div class="alert alert-success p-2 text-center" style="font-size:0.9rem;"><?php echo $success; ?></div>
                    <?php else: ?>

                    <form method="POST" action="">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <i class="fas fa-user input-icon"></i>
                                    <input type="text" class="form-control" name="full_name" placeholder="Full Name" required value="<?php echo htmlspecialchars($_POST['full_name'] ?? ''); ?>">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <i class="fas fa-id-card input-icon"></i>
                                    <input type="text" class="form-control" name="nic" placeholder="NIC Number" required value="<?php echo htmlspecialchars($_POST['nic'] ?? ''); ?>">
                                </div>
                            </div>

                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <i class="fas fa-envelope input-icon"></i>
                                    <input type="email" class="form-control" name="email" placeholder="Email Address" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <i class="fas fa-phone input-icon"></i>
                                    <input type="text" class="form-control" name="mobile_no" placeholder="Mobile Number" required value="<?php echo htmlspecialchars($_POST['mobile_no'] ?? ''); ?>">
                                </div>
                            </div>
                        </div>
                        <div class="form-group">
                        <i class="fas fa-users input-icon"></i>
                        <select class="form-control" name="user_type" required>
                            <option value="" disabled selected>Select User Type</option>
                            <option value="public">Public User</option>
                            <option value="staff">Staff Member</option>
                            <option value="admin">Admin</option>
                        </select>
                       </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <i class="fas fa-lock input-icon"></i>
                                    <input type="password" class="form-control" name="password" placeholder="Password" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <i class="fas fa-lock input-icon"></i>
                                    <input type="password" class="form-control" name="confirm_password" placeholder="Confirm Password" required>
                                </div>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary mt-2">
                            <i class="fas fa-check-circle me-2"></i> Register
                        </button>
                    </form>
                    <?php endif; ?>

                    <div class="auth-footer">
                        <div class="divider">
                            <span>or</span>
                        </div>
                        <p>Already have an account? <a href="index.php">Login here</a></p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>