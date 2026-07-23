<?php
// register.php - User Registration with Email Verification
require_once 'config.php';
require_once 'auth.php';

$error = '';
$success = '';

$isAjax = ($_POST['ajax'] ?? '') === '1';

// Clean any accidental output before JSON response
if ($isAjax) {
    ob_start();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    $displayName = trim($_POST['display_name'] ?? '');
    $acceptTerms = isset($_POST['accept_terms']) && $_POST['accept_terms'] === '1';
    $isAjax = ($_POST['ajax'] ?? '') === '1';
    
    // ✅ Normalize phone number
    $normalizedPhone = normalizePhone($phone);
    
    // Validate inputs
    if (empty($phone) || empty($email) || empty($password)) {
        $error = 'All fields are required';
    } elseif (!$acceptTerms) {
        $error = 'You must accept the Terms & Conditions to create an account';
    } elseif (!preg_match('/^\+?[0-9]{9,15}$/', str_replace('+', '', $phone))) {
        $error = 'Invalid phone number format';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email address';
    } elseif ($password !== $confirmPassword) {
        $error = 'Passwords do not match';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters';
    } else {
        // ✅ Register with normalized phone
        $result = registerWebUser($normalizedPhone, $email, $password, $displayName ?: null);
        if ($result['success']) {
            // Auto-login immediately after registration
            $loginResult = loginWebUser($normalizedPhone, $password);
            if ($loginResult['success']) {
                session_regenerate_id(true);
                $_SESSION['user_id'] = $loginResult['user']['id'];
                $_SESSION['user_phone'] = $loginResult['user']['phone'];
                $_SESSION['is_demo'] = !empty($loginResult['user']['is_demo']);
                if ($isAjax) {
                    ob_end_clean();
                    header('Content-Type: application/json');
                    echo json_encode(['success' => true, 'redirect' => 'dashboard.php']);
                    exit;
                }
                header("Location: dashboard");
                exit;
            }
            if ($isAjax) {
                ob_end_clean();
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => 'Auto-login failed, please login manually.']);
                exit;
            }
            // Fallback if auto-login fails
            $success = 'Registration successful! Please check your email to verify your account.';
        } else {
            if ($isAjax) {
                ob_end_clean();
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => $result['message']]);
                exit;
            }
            $error = $result['message'];
        }
    }
    if ($isAjax) {
        ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => $error]);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <title>Signup - Predixa</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        :root {
            --primary: #8B5CF6;
            --primary-dark: #7C3AED;
            --primary-light: #A78BFA;
            --accent: #06B6D4;
            --accent-dark: #0891B2;
            --secondary: #161b22;
            --text-light: #e0e0e0;
            --text-muted: #8b949e;
            --border-color: #2a2e35;
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #111318 0%, #1c2130 100%);
            color: var(--text-light);
            min-height: 100vh;
            padding: 40px 20px;
        }
        
        .register-card {
            background: linear-gradient(135deg, rgba(139,92,246,0.2) 0%, rgba(6,182,212,0.1) 100%);
            border: 1px solid rgba(139,92,246,0.3);
            border-radius: 16px;
            padding: 40px;
            max-width: 500px;
            margin: 0 auto;
            box-shadow: 0 10px 40px rgba(139,92,246,0.12);
        }
        
        .form-control {
            background: rgba(22,27,34,0.7);
            border: 1.5px solid rgba(139,92,246,0.45);
            color: var(--text-light);
            padding: 12px 15px;
            border-radius: 8px;
        }
        
        .form-control:focus {
            background: rgba(22,27,34,0.9);
            border-color: var(--primary);
            box-shadow: 0 0 0 0.25rem rgba(139,92,246,0.2);
            color: var(--text-light);
        }
        
        .form-control::placeholder {
            color: var(--text-muted);
        }
        
        .btn-register {
            background: linear-gradient(135deg, var(--primary) 0%, var(--accent) 100%);
            color: white;
            border: none;
            font-weight: 700;
            padding: 14px 30px;
            border-radius: 8px;
            width: 100%;
            transition: all 0.3s;
        }
        
        .btn-register:hover {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--accent-dark) 100%);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(139,92,246,0.4);
        }
        
        .text-gradient {
            background: linear-gradient(135deg, var(--primary) 0%, var(--accent) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .login-link {
            color: var(--primary-light);
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        .login-link:hover {
            color: var(--accent);
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="register-card">
            <div class="text-center mb-4">
                <h2 class="text-gradient fw-bold">🛡️ Create Account</h2>
                <p style="color:var(--text-muted);">Join Predixa for premium betting analytics</p>
            </div>
            
            <?php if ($error): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            
            <?php if ($success): ?>
            <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
            <div class="text-center mt-3">
                <a href="login" class="btn btn-register">Go to Login</a>
            </div>
            <?php else: ?>
            <form method="POST">
                <div class="mb-3">
                    <label class="form-label">Phone Number *</label>
                    <input type="tel" name="phone" class="form-control" 
                           placeholder="Enter phone number" 
                           value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>" 
                           required>
                    <small style="color:var(--text-muted);">Include your country code (e.g. +255, +254, +256)</small>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Display Name (optional)</label>
                    <input type="text" name="display_name" class="form-control" 
                           placeholder="e.g., KingPunter, BetProMax" 
                           value="<?= htmlspecialchars($_POST['display_name'] ?? '') ?>">
                    <small style="color:var(--text-muted);">This name will appear when you sell codes in the marketplace</small>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Email Address *</label>
                    <input type="email" name="email" class="form-control" 
                           placeholder="your@email.com" required>
                    <small style="color:var(--text-muted);">We'll send a verification link here</small>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Password *</label>
                    <input type="password" name="password" class="form-control" 
                           placeholder="Minimum 6 characters" required minlength="6">
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Confirm Password *</label>
                    <input type="password" name="confirm_password" class="form-control" required>
                </div>
                
                <div class="mb-3">
                    <label style="display:flex;align-items:flex-start;gap:10px;background:rgba(22,27,34,0.5);border:1px solid rgba(139,92,246,0.3);border-radius:8px;padding:12px 15px;cursor:pointer;">
                        <input type="checkbox" name="accept_terms" value="1" id="acceptTerms" required
                               style="margin-top:3px;accent-color:var(--primary);flex-shrink:0;width:18px;height:18px;cursor:pointer;">
                        <span style="color:var(--text-light);font-size:0.88rem;">
                            I agree to the <a href="terms" target="_blank" style="color:var(--accent);text-decoration:underline;">Terms &amp; Conditions</a> and <a href="privacy" target="_blank" style="color:var(--accent);text-decoration:underline;">Privacy Policy</a>
                        </span>
                    </label>
                </div>
                
                <button type="submit" class="btn btn-register mb-3">
                    🚀 Create Account
                </button>
                <div class="text-center mb-3">
                    <span style="color:var(--text-muted);">Already have an account?</span>
                    <a href="login" class="login-link">Login here</a>
                </div>
            </form>
            
            
            <?php endif; ?>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
