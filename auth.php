<?php
// auth.php (V7.1 - FINAL FIX: HTML display, Login/Redirect execution)
// --- 1. CONFIGURATION & DEPENDENCIES ---
ini_set('display_errors', 0); 
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

require_once 'config.php';
require_once 'DbManager.php';
// Set timezone (important for session expiration calculation)
if (defined('TIMEZONE_RESET')) {
    date_default_timezone_set(TIMEZONE_RESET); 
}
// --- 2. INITIALIZATION ---
try {
    $dbManager = new DbManager();
} catch (Exception $e) {
    // In case DB_FILE_PATH is missing or database connection fails
    die("Database Setup Error: " . htmlspecialchars($e->getMessage()));
}
$error = '';
$username = ''; // Initialize username for the HTML form value
// --- 3. CHECK FOR EXISTING SESSION & REDIRECT ---
$sessionToken = $_COOKIE['session'] ?? null;
if ($sessionToken) {
    $username = $dbManager->getUsernameFromSession($sessionToken);
    if ($username) {
        header('Location: index.php');
        exit;
    }
}

// --- 4. CORE AUTHENTICATION LOGIC ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $action = $_POST['action'] ?? 'login';
    
    $ttl = defined('SESSION_TTL_SECONDS') ? SESSION_TTL_SECONDS : 30 * 86400; 

    if (empty($username) || empty($password)) {
        $error = 'Username and password are required.';
    } elseif ($action === 'register') {
        
        // --- A. REGISTER ACCOUNT ---
        if ($dbManager->userExists($username)) {
            $error = 'Username already taken.';
        } else {
            $password_hash = password_hash($password, PASSWORD_BCRYPT);
            if ($dbManager->createUser($username, $password_hash)) {
                
                // Successful registration automatically logs them in
                $token = $dbManager->createSession($username);
                
                setcookie('session', $token, time() + $ttl, '/', '', false, true); 

                header('Location: index.php');
                exit; 
            } else {
                $error = 'Registration failed due to a database error.';
            }
        }
    } elseif ($action === 'login') {
        
        // --- B. LOGIN ACCOUNT ---
        $userData = $dbManager->getUserData($username);

        // CRITICAL FIX from V2.6: Safely retrieve the stored hash. 
        $storedHash = $userData['password_hash'] ?? ''; 

        if (empty($storedHash) || !password_verify($password, $storedHash)) {
            $error = 'Invalid username or password.';
        } else {
            // Successful login
            $token = $dbManager->createSession($username);

            setcookie('session', $token, time() + $ttl, '/', '', false, true); 
            
            header('Location: index.php');
            exit; 
        }
    }
}

$dbManager->close(); // Close DB connection before rendering HTML
// HTML VIEW
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Authentication - Dev Console</title>
    <link rel="stylesheet" href="style.css"> 
    <style>
        /* Basic styling specific to the auth page */
        body {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
        }
        .auth-container {
            background-color: var(--color-modal-bg, #282828);
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 0 20px rgba(0, 255, 153, 0.3);
            width: 100%;
            max-width: 350px;
        }
        .auth-container h2 {
            text-align: center;
            color: var(--color-main-text, #00ff99);
            margin-bottom: 20px;
        }
        .error-message {
            color: var(--color-dropdown-alert, #ff0000);
            text-align: center;
            margin-bottom: 15px;
            font-weight: bold;
        }
        .auth-input {
            width: 100%;
            padding: 10px;
            margin-bottom: 15px;
            background-color: var(--color-main-bg, #0d0d0d);
            border: 1px solid var(--color-header-accent, #32CD32);
            color: var(--color-main-text, #00ff99);
            border-radius: 4px;
            box-sizing: border-box;
            font-family: inherit;
        }
        .auth-btn {
            width: 100%;
            padding: 12px;
            background-color: var(--color-button-action, #0099ff);
            color: var(--color-main-bg, #0d0d0d);
            border: none;
            border-radius: 4px;
            font-weight: 700;
            cursor: pointer;
            transition: background-color 0.2s;
            font-family: inherit;
        }
        .auth-btn:hover {
            background-color: #0077cc;
        }
        .toggle-text {
            text-align: center;
            margin-top: 15px;
            font-size: 0.9em;
        }
        .toggle-text a {
            color: var(--color-rank-label, #ff9900);
            text-decoration: none;
        }
    </style>
</head>
<body>

<div class="auth-container">
    <h2 id="auth-title"><?php echo (($_POST['action'] ?? 'login') === 'register') ? 'Register New Account' : 'Login'; ?></h2>
    
    <?php if ($error): ?>
        <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <form id="auth-form" method="POST" action="auth.php">
        <input type="hidden" name="action" id="auth-action" value="<?php echo ($_POST['action'] ?? 'login'); ?>">
        
        <input type="text" name="username" class="auth-input" placeholder="Username (Coder Tag)" required value="<?php echo htmlspecialchars($username); ?>">
        <input type="password" name="password" class="auth-input" placeholder="Password (Access Key)" required>
        
        <button type="submit" id="auth-submit-btn" class="auth-btn"><?php echo (($_POST['action'] ?? 'login') === 'register') ? 'REGISTER' : 'LOGIN'; ?></button>
    </form>
    
    <div class="toggle-text">
        <span id="toggle-prompt">
            <?php echo (($_POST['action'] ?? 'login') === 'register') ? 'Already have an account?' : "Don't have an account?"; ?>
        </span> 
        <a href="#" onclick="toggleAuth()">
            <?php echo (($_POST['action'] ?? 'login') === 'register') ? 'Login' : 'Register'; ?>
        </a>
    </div>
</div>

<script>
    // JavaScript to handle the client-side switch between Login and Register views
    function toggleAuth() {
        const title = document.getElementById('auth-title');
        const actionInput = document.getElementById('auth-action');
        const submitBtn = document.getElementById('auth-submit-btn');
        const promptSpan = document.getElementById('toggle-prompt');
        const toggleLink = document.querySelector('.toggle-text a');
        
        // This is a simple state toggle logic
        if (actionInput.value === 'login') {
            title.textContent = 'Register New Account';
            actionInput.value = 'register';
            submitBtn.textContent = 'REGISTER';
            promptSpan.textContent = 'Already have an account?';
            toggleLink.textContent = 'Login';
        } else {
            title.textContent = 'Login';
            actionInput.value = 'login';
            submitBtn.textContent = 'LOGIN';
            promptSpan.textContent = "Don't have an account?";
            toggleLink.textContent = 'Register';
        }
    }

    // Initialize the toggle state on page load based on post data, if any
    document.addEventListener('DOMContentLoaded', () => {
        if (document.getElementById('auth-action').value === 'register') {
            toggleAuth();
            toggleAuth();
        }
    });
</script>

</body>
</html>
