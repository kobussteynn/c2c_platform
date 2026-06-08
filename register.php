<?php
include("includes/db.php");

$message = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $name = trim($_POST["name"]);
    $email = trim($_POST["email"]);
    $password = $_POST["password"];
    $confirm_password = $_POST["confirm_password"];

    if ($password !== $confirm_password) {
        $message = "Passwords do not match.";
    } else {
        $check = $conn->prepare("SELECT user_id FROM users WHERE email = ?");
        $check->bind_param("s", $email);
        $check->execute();
        $check->store_result();

        if ($check->num_rows > 0) {
            $message = "Email already registered.";
        } else {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);

            $stmt = $conn->prepare("INSERT INTO users (name, email, password) VALUES (?, ?, ?)");
            $stmt->bind_param("sss", $name, $email, $hashed_password);

            if ($stmt->execute()) {
                $user_id = $stmt->insert_id;

                $roleQuery = $conn->prepare("SELECT role_id FROM roles WHERE role_name = 'Buyer' LIMIT 1");
                $roleQuery->execute();
                $roleResult = $roleQuery->get_result();
                $role = $roleResult->fetch_assoc();

                if ($role) {
                    $role_id = $role["role_id"];
                    $assignRole = $conn->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)");
                    $assignRole->bind_param("ii", $user_id, $role_id);
                    $assignRole->execute();
                }

                header("Location: login.php?registered=success");
                exit();
            } else {
                $message = "Registration failed. Please try again.";
            }
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Register - C2C Platform</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="css/style.css?v=20260522ui2" rel="stylesheet">
</head>
<body>

<div class="auth-page">
    <div class="auth-card">
        <p class="auth-eyebrow">C2C Platform</p>
        <h1 class="auth-title">Create Account</h1>
        <p class="auth-subtitle">Join the marketplace and start trading.</p>

        <?php if (!empty($message)): ?>
            <div class="alert alert-danger">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="register.php">
            <div class="mb-3">
                <label class="form-label">Full Name</label>
                <input 
                    type="text" 
                    name="name" 
                    class="form-control" 
                    placeholder="Enter your full name" 
                    autocomplete="name"
                    required
                >
            </div>

            <div class="mb-3">
                <label class="form-label">Email Address</label>
                <input 
                    type="email" 
                    name="email" 
                    class="form-control" 
                    placeholder="you@example.com" 
                    autocomplete="email"
                    required
                >
            </div>

            <div class="mb-3 password-field">
                <label class="form-label">Password</label>
                <div class="password-input-wrap">
                    <input 
                        type="password" 
                        name="password" 
                        id="registerPassword"
                        class="form-control" 
                        placeholder="Create a password" 
                        autocomplete="new-password"
                        required
                    >

                    <button 
                        type="button"
                        id="registerPasswordToggle"
                        class="password-toggle-btn"
                        onclick="togglePassword('registerPassword', 'registerPasswordToggle')"
                        data-target="registerPassword"
                        aria-label="Show password"
                        aria-pressed="false"
                        title="Show password"
                    >
                        <span class="password-toggle-icon" aria-hidden="true"></span>
                    </button>
                </div>
            </div>

            <div class="mb-4 password-field">
                <label class="form-label">Confirm Password</label>
                <div class="password-input-wrap">
                    <input 
                        type="password" 
                        name="confirm_password" 
                        id="confirmPassword"
                        class="form-control" 
                        placeholder="Confirm your password" 
                        autocomplete="new-password"
                        required
                    >

                    <button 
                        type="button"
                        id="confirmPasswordToggle"
                        class="password-toggle-btn"
                        onclick="togglePassword('confirmPassword', 'confirmPasswordToggle')"
                        data-target="confirmPassword"
                        aria-label="Show password"
                        aria-pressed="false"
                        title="Show password"
                    >
                        <span class="password-toggle-icon" aria-hidden="true"></span>
                    </button>
                </div>
            </div>

            <button type="submit" class="btn-auth">Sign Up</button>
        </form>

        <div class="auth-footer">
            Already have an account?
            <a href="login.php">Login</a>
        </div>
    </div>
</div>

<script src="js/script.js?v=20260522pw1"></script>
</body>
</html>








