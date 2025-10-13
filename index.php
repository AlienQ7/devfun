<?php
// index.php (V7.0.0 - FIX: Maintenance Lockout Visuals)
// --- CRITICAL CONFIGURATION ---
ini_set('display_errors', 1);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_WARNING);
require_once 'config.php';
require_once 'DbManager.php';
// Set timezone from config (CRITICAL for DateTime objects)
date_default_timezone_set(defined('TIMEZONE_RESET') ? TIMEZONE_RESET : 'UTC'); 
// --- UNIVERSAL TIME GETTER FOR TESTING ---
function getCurrentTime() {
    $timezone = defined('TIMEZONE_RESET') ? TIMEZONE_RESET : 'UTC';
    $dtz = new DateTimeZone($timezone);
    
    // CRITICAL FIX: Explicitly check for PHP null keyword, empty string, AND the string literal "null" or "0".
    $is_override_enabled = defined('TEST_TIME_OVERRIDE') && 
                           TEST_TIME_OVERRIDE !== null && 
                           TEST_TIME_OVERRIDE !== '' &&
                           strtolower(TEST_TIME_OVERRIDE) !== 'null' && 
                           TEST_TIME_OVERRIDE !== '0';                      
    if ($is_override_enabled) {
        $date_part = date('Y-m-d'); 
          // This line only runs if TEST_TIME_OVERRIDE is a valid time string.
        return new DateTime("{$date_part} " . TEST_TIME_OVERRIDE, $dtz);
    }
    // If override is disabled, use the real time.
    return new DateTime('now', $dtz);
}
// --- INITIALIZATION ---
try {
    $dbManager = new DbManager(); 
} catch (Exception $e) {
    // If DbManager fails (e.g., SQLite3 missing, though your test showed it's now working)
    die("Application Setup Error: " . $e->getMessage());
}
// --- ALL FUNCTION DEFINITIONS GO HERE (No execution logic yet) ---
// --- SESSION & AUTHENTICATION MANAGEMENT ---
function getCurrentUser($dbManager) {
    $sessionToken = $_COOKIE['session'] ?? null;
    if (!$sessionToken) return null;

    $username = $dbManager->getUsernameFromSession($sessionToken);
    if (!$username) return null;

    $userData = $dbManager->getUserData($username);
    if (!$userData) return null;

    $userData['rank'] = getRankTitle($userData['sp_points']); 
    $userData['task_points'] = $userData['claimed_task_points'] - $userData['total_penalty_deduction'];
    
    return $userData;
}

function handleLogout() {
    global $dbManager;
    $sessionToken = $_COOKIE['session'] ?? null;
    if ($sessionToken) {
        $dbManager->deleteSession($sessionToken);
    }
    $ttl = defined('SESSION_TTL_SECONDS') ? SESSION_TTL_SECONDS : 30 * 86400; 
    setcookie('session', '', time() - $ttl, '/'); 
    header('Location: auth.php');
    exit;
}

function handleDeleteAccount($username) {
    global $dbManager;
    $dbManager->deleteUserAndData($username);
    handleLogout(); 
}

// --- USER DATA & RANKING UTILITIES ---
function getRankTitle($sp_points) {
    if (!defined('RANK_THRESHOLDS')) {
        return $sp_points >= 1000 ? 'Master Coder 👑' : 'Aspiring 🚀';
    }
    foreach (RANK_THRESHOLDS as $rank) {
        if ($sp_points >= $rank['sp']) {
            return $rank['title'];
        }
    }
    return 'Aspiring 🚀'; 
}
function updateUserData(&$user, $dbManager) {
    $newRank = getRankTitle($user['sp_points']);
    $user['rank'] = $newRank; 

    $user['task_points'] = $user['claimed_task_points'] - $user['total_penalty_deduction'];

    $dataToSave = [
        'rank' => $user['rank'],
        'sp_points' => $user['sp_points'],
        'claimed_task_points' => $user['claimed_task_points'], 
        'failed_points' => $user['failed_points'],                    
        'total_penalty_deduction' => $user['total_penalty_deduction'], 
        'daily_quota' => $user['daily_quota'],                        
        'is_failed_system_enabled' => $user['is_failed_system_enabled'], 
        'last_sp_collect' => $user['last_sp_collect'],
        'last_task_refresh' => $user['last_task_refresh'],
        'daily_completed_count' => $user['daily_completed_count'],
        'user_objective' => $user['user_objective']
    ];
    $dbManager->saveUserData($user['username'], $dataToSave);
}
/* [FIX #2 - RESET LAG FIX]*/
function checkDailyReset(&$user, $dbManager) {
    $now = getCurrentTime(); 
    $today_midnight_ts = (clone $now)->setTime(0, 0, 0)->getTimestamp();

    if ($user['last_task_refresh'] < $today_midnight_ts) {
        $user['last_task_refresh'] = $now->getTimestamp();
        $dbManager->saveUserData($user['username'], ['last_task_refresh' => $user['last_task_refresh']]);
        // --- CRITICAL LOCK/OPTIMIZATION END ---
        // --- FAILURE LOGIC (Flat fail count preserved as requested) ---
        if ($user['is_failed_system_enabled'] == 1 && defined('DAILY_FAILURE_PENALTY')) {
            $missingQuota = $user['daily_quota'] - $user['daily_completed_count'];
            
            if ($missingQuota > 0) {
                
                $penaltyAmount = DAILY_FAILURE_PENALTY; 
                $currentTaskPoints = $user['claimed_task_points'] - $user['total_penalty_deduction'];
                
                if ($penaltyAmount > $currentTaskPoints) {
                    $penaltyAmount = $currentTaskPoints;
                }  
                if ($penaltyAmount > 0) {
                    $user['failed_points']++; 
                    $user['total_penalty_deduction'] += $penaltyAmount;
                }
            }
        }
        // --- END FAILURE LOGIC ---
        $tasksJson = $dbManager->getTasks($user['username'], 'all_tasks');
        $tasks = json_decode($tasksJson, true) ?: [];
        $updatedTasks = [];

        foreach ($tasks as $task) {
            // FIX: Only permanent tasks are kept and reset. Non-permanent tasks are discarded (removed from $updatedTasks).
            if (($task['permanent'] ?? false) === true) {
                // Reset completed/claimed state for permanent tasks
                $task['completed'] = false;
                $task['claimed'] = false; 
                $updatedTasks[] = $task;
            } 
        }
        $dbManager->saveTasks($user['username'], 'all_tasks', json_encode($updatedTasks));
        $user['daily_completed_count'] = 0; 
        // last_task_refresh is already updated and saved above (the LOCK).
    }
    updateUserData($user, $dbManager);
}
function handleSpCollect(&$user, $dbManager) {
    header('Content-Type: application/json');

    $now = getCurrentTime();
    $today_midnight_ts = (clone $now)->setTime(0, 0, 0)->getTimestamp();
    $last_collect_ts = (int)($user['last_sp_collect'] ?? 0); 
    $isEligible = (
        $last_collect_ts < $today_midnight_ts
    );

    if (!$isEligible) {
        echo json_encode(['success' => false, 'message' => 'Error: Daily Diamond has already been collected today. Try again after 12:00 AM!']);
        return;
    }
    // --- V7.6.1 FIX: Use constant DAILY_CHECKIN_REWARD ---
    $reward = defined('DAILY_CHECKIN_REWARD') ? DAILY_CHECKIN_REWARD : 10;
    $user['sp_points'] += $reward;
    $user['last_sp_collect'] = $now->getTimestamp(); 
    $message = "💎 COLLECTED! +{$reward} 💎. Total: {$user['sp_points']}";
    
    updateUserData($user, $dbManager);
    
    echo json_encode([
        'success' => true, 
        'message' => $message, 
        'sp_points' => $user['sp_points'],
        'rank' => $user['rank']
    ]);
}
// --- AJAX ENDPOINTS ---
function handleTaskActions(&$user, $dbManager) {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    $taskId = $_POST['id'] ?? null;
    $taskText = $_POST['text'] ?? null;
    $isPermanent = (($_POST['permanent'] ?? 'false') === 'true'); 
    $tasksJson = $dbManager->getTasks($user['username'], 'all_tasks');
    $tasks = json_decode($tasksJson, true) ?: [];
    $response = ['success' => false, 'message' => ''];
    $taskFound = false;
    $reward = defined('TASK_COMPLETION_REWARD') ? TASK_COMPLETION_REWARD : 2;

    if ($action === 'add' && !empty($taskText)) {
        $newTask = [
            'id' => uniqid(),
            'text' => htmlspecialchars($taskText),
            'completed' => false,
            'claimed' => false, 
            'permanent' => $isPermanent
        ];
        $tasks[] = $newTask;
        $response = ['success' => true, 'task' => $newTask];
    } 
    else {
        foreach ($tasks as $key => &$task) {
            if (($task['id'] ?? null) === $taskId) {
                $taskFound = true;
                
                if ($action === 'toggle') {
                    $taskWasCompleted = ($task['completed'] ?? false);
                    $taskWasClaimed = ($task['claimed'] ?? false);
                    
                    $task['completed'] = !$taskWasCompleted;
                    $response = ['success' => true, 'id' => $taskId, 'completed' => $task['completed']];

                    if ($task['completed']) {
                        // --- A. Ticked (Completed = true) - REVERSIBLE TRANSACTION START ---
                        if (!$taskWasClaimed) {
                            $user['claimed_task_points'] += $reward;
                            $task['claimed'] = true; 
                            $user['daily_completed_count']++; // QUOTA COUNT INCREMENTED
                            $response['points_change'] = '+'.$reward;
                        } else {
                            $response['points_change'] = '+0 (Already claimed)';
                        }

                    } else {
                        // --- B. Un-Ticked (Completed = false) - REVERSIBLE TRANSACTION END ---
                        if ($taskWasClaimed) {
                            // Deduct points (reversible money)
                            $user['claimed_task_points'] -= $reward;
                            if ($user['claimed_task_points'] < 0) {
                                $user['claimed_task_points'] = 0;
                            }  
                            $user['daily_completed_count']--; 
                            $task['claimed'] = false; // Revert claimed status
                            $response['points_change'] = '-'.$reward . ' (Reverted)';
                        } else {
                            $response['points_change'] = '+0 (No claim to revert)';
                        }
                    }
                    if ($user['daily_completed_count'] < 0) $user['daily_completed_count'] = 0;

                    break;
                }
                if ($action === 'delete') {
                    // *** FINAL LOGIC: DELETE IS NON-REVERSING. POINTS ARE KEPT. *** 
                    $response['points_change'] = '+0 (Points retained on deletion)';
                    unset($tasks[$key]);
                    $tasks = array_values($tasks); 
                    $response = ['success' => true, 'id' => $taskId, 'message' => 'Task Deleted.'];
                    break;
                }

                if ($action === 'set_permanent') {
                    $task['permanent'] = $isPermanent; 
                    $response = ['success' => true, 'id' => $taskId, 'permanent' => $isPermanent];
                    break;
                }
            }
        }
        if (!$taskFound && $action !== 'add') {
            $response = ['success' => false, 'message' => 'Error: Task ID not found.'];
        }
    }

    if ($response['success']) {
        $dbManager->saveTasks($user['username'], 'all_tasks', json_encode($tasks));
        updateUserData($user, $dbManager); 
        
        $response['user_data'] = [
            'tp' => $user['task_points'], 
            'sp' => $user['sp_points'],
            'failed' => $user['failed_points'], 
            'rank' => $user['rank'],
            'daily_count' => $user['daily_completed_count']
        ];
    }
    
    echo json_encode($response);
}

function handleObjectiveSave(&$user, $dbManager) {
    header('Content-Type: application/json');
    $objective = trim($_POST['objective'] ?? '');

    if (!empty($objective) || $objective === '') { 
        $user['user_objective'] = htmlspecialchars($objective);
        updateUserData($user, $dbManager);
        // CRITICAL FIX: Ensure a success message is returned for JS alert
        echo json_encode(['success' => true, 'objective' => $user['user_objective'], 'message' => 'Objective Saved!']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Objective error.']);
    }
}

function handleQuotaSave(&$user, $dbManager) {
    header('Content-Type: application/json');
    $quota = (int)($_POST['quota'] ?? 1); 
    if ($quota < 1) {
        $quota = 1;
    }
    // The max limit was removed in V7.6.1
    $user['daily_quota'] = $quota;
    updateUserData($user, $dbManager);
    echo json_encode(['success' => true, 'quota' => $quota, 'message' => 'Daily quota saved.']);
}
function handleFailureToggle(&$user, $dbManager) {
    header('Content-Type: application/json');
    
    $user['is_failed_system_enabled'] = ($user['is_failed_system_enabled'] == 1) ? 0 : 1;
    
    updateUserData($user, $dbManager);

    $statusText = ($user['is_failed_system_enabled'] == 1) ? 'Enabled' : 'Disabled';
    echo json_encode([
        'success' => true, 
        'status' => $user['is_failed_system_enabled'],
        'status_text' => $statusText,
        'message' => "Failure System is now {$statusText}."
    ]);
}
// --- MAINTENANCE & TIME UTILITIES ---
function checkMaintenanceStatus() {
    $now = getCurrentTime();
    // --- FIX #6: Simplified and corrected time calculation ---
    $midnight = (clone $now)->setTime(0, 0, 0);
    // Time window targets
    $t_start = '23:58:00';
    $t_end = '00:02:00';
    $maintenanceStart = (clone $now)->setTime(23, 58, 0);
    $maintenanceEnd = (clone $now)->setTime(0, 2, 0)->modify('+1 day'); // Start of maintenance is 23:58 today, ends 00:02 tomorrow
    // If we are past 00:02 but before 23:58, the maintenance window is 23:58 yesterday to 00:02 today
    if ($now->getTimestamp() > $midnight->getTimestamp() && $now->getTimestamp() < (clone $midnight)->modify('+2 minutes')->getTimestamp()) {
        // We are currently in the post-midnight part of maintenance
        $maintenanceStart = (clone $now)->modify('-1 day')->setTime(23, 58, 0);
        $maintenanceEnd = (clone $now)->setTime(0, 2, 0);
    } elseif ($now->format('H:i:s') < $t_start) {
        // We are earlier in the day, so maintenance is tonight/tomorrow morning
        $maintenanceEnd = (clone $now)->setTime(0, 2, 0)->modify('+1 day');
    }

    $isMaintenance = ($now >= $maintenanceStart && $now < $maintenanceEnd);
    $warningStart = (clone $maintenanceStart)->modify('-1 minute');
    $warningEnd = $maintenanceStart;
    
    $isWarning = ($now >= $warningStart && $now < $warningEnd);
    
    return [
        'isMaintenance' => $isMaintenance,
        'isWarning' => $isWarning,
        'maintenanceEndTimestamp' => $maintenanceEnd->getTimestamp(),
        'maintenanceStartTimestamp' => $maintenanceStart->getTimestamp(), 
        'simulatedNowTimestamp' => $now->getTimestamp() 
    ];
}
// --- MAIN REQUEST HANDLER & HTML VIEW ---
function handleRequest(&$user, $dbManager) { 
    // Helper function used by generateHtml to render a single task slot
    function renderTaskHtml($task) {
        $completedClass = ($task['completed'] ?? false) ? 'completed-slot' : '';
        $completedAttr = ($task['completed'] ?? false) ? 'checked' : '';
        $permanentIndicator = ($task['permanent'] ?? false) ? '<span class="permanent-indicator" title="Permanent Daily Task">🔒</span>' : '';
        $permanentBtnText = ($task['permanent'] ?? false) ? 'Unlock' : 'Lock';
        $nextStatus = ($task['permanent'] ?? false) ? 'false' : 'true';
        return '
            <div class="task-slot ' . $completedClass . '" id="task-' . ($task['id'] ?? '') . '" data-id="' . ($task['id'] ?? '') . '" data-permanent="' . (($task['permanent'] ?? false) ? 'true' : 'false') . '" data-completed="' . (($task['completed'] ?? false) ? 'true' : 'false') . '">
                <input type="checkbox" class="task-checkbox" ' . $completedAttr . ' onchange="toggleTask(\'' . ($task['id'] ?? '') . '\')">
                <div class="task-description-wrapper">
                    ' . $permanentIndicator . '
                    <span class="task-description ' . ($completedAttr ? 'completed' : '') . '">' . htmlspecialchars($task['text'] ?? '') . '</span>
                </div>
                <button class="permanent-btn" data-permanent="' . (($task['permanent'] ?? false) ? 'true' : 'false') . '" onclick="togglePermanent(\'' . ($task['id'] ?? '') . '\', ' . $nextStatus . ')">' . $permanentBtnText . '</button>
                <button class="remove-btn" onclick="deleteTask(\'' . ($task['id'] ?? '') . '\')">REMOVE</button>
            </div>
        ';
    }
    // Check status based on getCurrentTime()
    $status = checkMaintenanceStatus();
    $isMaintenance = $status['isMaintenance'];
    $isContentFetch = ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['content_only']));
    if (!$isMaintenance) {
        checkDailyReset($user, $dbManager); // [FIX #2 - RESET LAG FIX]: Now fast on subsequent loads
    }
    if (isset($_GET['action'])) {
        if ($_GET['action'] === 'logout') {
            handleLogout();
        } elseif ($user && $_GET['action'] === 'delete_account') {
            handleDeleteAccount($user['username']); 
        }
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['endpoint'])) {
        // --- MAINTENANCE CHECK: BLOCK ALL POST ACTIONS ---
        if ($isMaintenance) {
            header('Content-Type: application/json');
            http_response_code(503); // Service Unavailable
            echo json_encode([
                'success' => false, 
                'message' => 'System is in Hospital. Please wait for the daily reset to complete.'
            ]);
            exit;
        }
        // --- END MAINTENANCE CHECK ---
        $endpoint = $_POST['endpoint'];
        
        if ($endpoint === 'task_action') {
            handleTaskActions($user, $dbManager);
        } elseif ($endpoint === 'sp_collect') {
            handleSpCollect($user, $dbManager);
        } elseif ($endpoint === 'save_objective') {
            handleObjectiveSave($user, $dbManager);
        }
        elseif ($endpoint === 'save_quota') {
            handleQuotaSave($user, $dbManager);
        } elseif ($endpoint === 'toggle_failure') {
            handleFailureToggle($user, $dbManager);
        } 
        else {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Unknown endpoint.']);
        }
        exit;
    }
    // CRITICAL FIX: Pass arguments matching the new signature
    $htmlContent = generateHtml($user, $dbManager, $status, 'renderTaskHtml', $isContentFetch);
    if ($isContentFetch) {
        echo $htmlContent;
        exit;
    }
    echo $htmlContent;
}
// CRITICAL FIX: Reordered parameters to fix Deprecated warning on optional argument
function generateHtml($user, $dbManager, $status, $renderTaskHtmlCallback, $isContentOnly = false) {
    $tasksJson = $dbManager->getTasks($user['username'], 'all_tasks');
    $tasks = json_decode($tasksJson, true) ?: [];
    $now = getCurrentTime();
    $today_midnight_ts = (clone $now)->setTime(0, 0, 0)->getTimestamp();
    // FIX V7.5.3: Explicitly cast last_sp_collect to an integer to ensure comparison 
    $last_collect_ts = (int)($user['last_sp_collect'] ?? 0); 
    // FIX #1: Eligibility relies ONLY on the last collection time vs midnight.
    $canCollectSp = (
        $last_collect_ts < $today_midnight_ts 
    );
    // V7.6.39 FIX: Changed display text from Collect/Collected to CLAIM/CLAIMED
    $spButtonText = $canCollectSp ? 'CLAIM' : 'CLAIMED';
    // FIX #4: Cleaner logic for objective placeholder display
    $isDefaultObjective = ($user['user_objective'] === 'Pro max programmer xd.');
    $objectiveDisplay = ($isDefaultObjective || $user['user_objective'] === '') ? '' : htmlspecialchars($user['user_objective']);
    $isFailureEnabled = ($user['is_failed_system_enabled'] == 1);
    $failureToggleText = $isFailureEnabled ? 'Off' : 'On'; //'Disable System' : 'Enable System';
    $failureStatusText = $isFailureEnabled ? '⟨ON 🟢⟩' : '⟨OFF 🔴⟩'; // 'Enabled' : 'Disabled';
    $isMaintenance = $status['isMaintenance'];
    $isWarning = $status['isWarning'];
    $maintenanceEndTime = $status['maintenanceEndTimestamp'];
    $maintenanceStartTime = $status['maintenanceStartTimestamp'];
    $simulatedNowTime = $status['simulatedNowTimestamp'];
    ob_start(); 
    if (!$isContentOnly):
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dev Console: <?php echo htmlspecialchars($user['username']); ?></title>
    <link rel="stylesheet" href="style.css"> 
    <link rel="stylesheet" href="ui.css">
    <style>
        /* --- KEYFRAMES FOR GLOW (FROM lockout.css) --- */
        @keyframes pulseGlow {
            from {
                box-shadow: 0 0 5px #ffaa00, 0 0 10px #ffd700;
            }
            to {
                box-shadow: 0 0 15px #ffaa00, 0 0 20px #ffd700;
            }
        }
        /* --- CRITICAL LOCKOUT STYLES (FROM lockout.css) --- */
        .maintenance-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.6); /* Darker background */
            display: none; /* Hidden by default */
            justify-content: center;
            align-items: center;
            z-index: 1000;
            user-select: none;
            text-align: center;
        }
        /* 2. The ACTIVE state (makes the overlay visible) */
        .maintenance-overlay.active {
            display: flex; 
        }

        /* 3. The content gray-out/disable effect (Applied to the #app-wrapper) */
        .lockout-active {
            filter: none; /*opacity(50%); /* Dim the content to focus on the overlay */
            pointer-events: none; /* CRITICAL: Prevents clicks on everything EXCEPT the overlay */
            user-select: none;
            transition: filter 0.5s ease-in-out;
        }
        /* 4. The message box (The pulsing glow) */
        .lockout-message {
            /* Classy Gold/Orange Theme */
            background-color: #4a3e21;
            color: #ffd700; /* Gold text#fff8e1; */
            border: 3px solid #ff9800;
            padding: 30px; 
            border-radius: 8px;
            font-weight: bold;
            text-align: center;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.5);
            font-size: 1.3em; 
            line-height: 1.4;
            animation: pulseGlow 1.5s infinite alternate; /* The missing glow animation! */
            max-width: 90%;
            font-family: monospace; /* Added for consistency, adjust if needed */
        }
        .lockout-message h2 {
            color: #ffc107;
            margin: 0 0 10px 0;
            text-shadow: 0 0 8px #ffd700; /* Added a subtle text shadow */
        }
        .lockout-message .countdown {
            display: block;
            margin-top: 10px;
            font-size: 1.5em;
            /*color: #ffaa00; /* Orange highlight for timer */
            color: #ff9800;
            text-shadow: 0 0 5px #ffaa00;
        }
        /* --- MARQUEE STYLES --- */
        .pre-warning-marquee {
            width: 100%;
            background-color: #FFBF00;
            color: white;
            padding: 5px 0;
            font-size: 0.9em;
            text-align: center;
            border-bottom: 2px solid #ff9900;
        }
            opacity: 0.5;
            pointer-events: none; /* CRITICAL: Prevents clicks while AJAX is running */
            cursor: default;
        }
    </style>
</head>
<body> 

<div id="app-wrapper" class="<?php echo $isMaintenance ? 'lockout-active' : ''; ?>"> 
<?php if ($isWarning): ?>
<?php endif; ?>
<div class="header-bar">
    <div class="rank-display">
        <a href="ranks.php" style="text-decoration: none; color: inherit;">
            <span class="rank-label">RANK:</span>
            <span id="user-rank-title" class="rank-title"><?php echo htmlspecialchars($user['rank']); ?></span>
        </a>
    </div>
    <div class="header-menu">
        <button class="hamburger-btn" onclick="toggleMenu()">☰</button>
        <div id="dropdown-menu" class="menu-dropdown">
            <div class="menu-section-row menu-top-row">
                
                <div class="menu-stats-block">
                    <span class="menu-stats-line">Coins(🪙): <span id="tp-display"><?php echo $user['task_points']; ?></span></span>
                    <span class="menu-stats-line">Diamonds(💎): <span id="sp-display-menu"><?php echo $user['sp_points']; ?></span></span>
                </div>
                
                <a href="shop.php" class="menu-shop-link" title="Shop">
                    🛒 </a>
            </div>

            <div class="menu-section-row menu-fail-system" id="failure-toggle-display">
                <span id="failure-status-text">Fail System: <?php echo $failureStatusText; ?></span>
                
                <button id="failure-toggle-btn" class="auth-btn toggle-small-btn" data-enabled="<?php echo $isFailureEnabled ? 'true' : 'false'; ?>" onclick="toggleFailureSystem()">
                    <?php echo $failureToggleText; ?>
                </button>
            </div>

            <div class="menu-section-row menu-logout-delete-row">
                <a href="?action=logout" class="menu-logout-link">   Log Out   </a>
                <button onclick="confirmDelete()" class="delete-btn menu-delete-link">Delete Account</button>
            </div>
            <div class="shop1"><a href="shop1.php" class="menu-section-row menu-future-space menu-shop-text1">
            Don't Click
             </a></div>
        </div>
    </div>
</div>
<div id="app-wrapper" class="<?php echo $isMaintenance ? 'lockout-active' : ''; ?>">
<?php if ($isWarning): ?>
<div class="pre-warning-marquee" id="warning-marquee">
    <marquee behavior="scroll" direction="left" scrollamount="6">
        🚨 SYSTEM ALERT: Daily maintenance and reset will begin in less than one minute (at 11:58 PM)! Please finish your current tasks. 🚨
    </marquee>
</div>
<?php endif; ?>
<div class="container">
    <div class="profile-container">
        <h2>Dev: <?php echo htmlspecialchars($user['username']); ?></h2>
        
        <div class="stats-line">
            Diamonds(💎): <strong id="sp-stats"><?php echo $user['sp_points']; ?></strong>
        </div>
        <div class="stats-line">
            Coins(🪙): <strong id="tp-stats"><?php echo $user['task_points']; ?></strong>
        </div>
        
        <?php if ($isFailureEnabled): ?>
        <div class="stats-line" id="failed-stat-line">
            Failed (❌): <strong id="failed-stats"><?php echo $user['failed_points']; ?></strong>
        </div>
        <?php endif; ?>
        
        <div class="stats-line">
            Daily Completed(🎯): <strong id="daily-count-stats"><?php echo $user['daily_completed_count']; ?></strong>
        </div>
        
        <?php if ($isFailureEnabled): ?>
        <div class="quota-input-container" id="quota-input-container">
            <label for="daily-quota-input" class="quota-label">Daily Quota:</label>
            <input type="number" id="daily-quota-input" min="1" value="<?php echo $user['daily_quota']; ?>" class="quota-input">
            <button onclick="saveDailyQuota()" class="auth-btn set-quota-btn">Set Quota</button>
        </div>
        <?php endif; ?>
        <div class="quota-separator-skew"></div>
          <div class="sp-btn-wrapper">
             <div
                 id="sp-collect-btn"      
                 class="auth-btn"
                 onclick="collectSp()"
                 data-collected="<?php echo $canCollectSp ? 'false' : 'true'; ?>">
                 <?php echo $spButtonText; ?>
             </div>
          </div>
        <h3>Current Objective:</h3>
        <div class="quota-separator-skew1"></div>
        <div class="objective-container">
            <input type="text" id="objective-input" placeholder="Set Your Objective" value="<?php echo $objectiveDisplay; ?>">
            <button onclick="saveObjective()">SAVE</button>
        </div>

    </div>

    <div class="task-manager">
        <h2>Task Log</h2>
        <div class="add-task-controls">
            <input type="text" id="new-task-input" placeholder="Add New Task...." onkeydown="if(event.key === 'Enter') document.getElementById('add-task-btn').click();">
            <select id="task-type-select">
                <option value="false">Task🔓</option>
                <option value="true">Task🔒</option>
            </select>
            <button id="add-task-btn" onclick="addTask()" class="add-btn">ADD</button>
        </div>
        
        <div id="task-list">
            <?php foreach ($tasks as $task): ?>
                <?php echo $renderTaskHtmlCallback($task); ?>
            <?php endforeach; ?>
            <?php if (empty($tasks)): ?>
                <p id="no-tasks-message">No active missions. Add a new one to begin!</p>
            <?php endif; ?>
        </div>
    </div>
</div>
</div> 
<?php endif; // <-- This is the closing statement for if (!$isContentOnly): ?>
<div class="maintenance-overlay <?php echo $isMaintenance ? 'active' : ''; ?>" id="maintenance-overlay">
    <div class="lockout-message">
        <h2>System is in Hospital 🏥</h2>
        The daily reset is running to update tasks and penalties.
        <span class="countdown">
            Please wait <strong id="countdown-timer">04:00</strong>
        </span>
    </div>
</div>
<?php if (!$isContentOnly): ?>
<script>
    // --- MAINTENANCE VARIABLES PASSED FROM PHP ---
    const IS_MAINTENANCE = <?php echo $isMaintenance ? 'true' : 'false'; ?>;
    const IS_WARNING = <?php echo $isWarning ? 'true' : 'false'; ?>; 
    const MAINTENANCE_END_TS = <?php echo $maintenanceEndTime; ?>;
    const MAINTENANCE_START_TS = <?php echo $maintenanceStartTime; ?>; 
    const SIMULATED_NOW_TS = <?php echo $simulatedNowTime; ?>;
    // --- END MAINTENANCE VARIABLES ---
    
    // Use PHP to expose the necessary constants to JavaScript
    const TASK_COMPLETION_REWARD = <?php echo defined('TASK_COMPLETION_REWARD') ? TASK_COMPLETION_REWARD : 2; ?>;
    const DAILY_CHECKIN_REWARD = <?php echo defined('DAILY_CHECKIN_REWARD') ? DAILY_CHECKIN_REWARD : 10; ?>;
    
    // Global variable to track simulated time (seconds)
    let simulatedSecondsElapsed = 0; 
    
    // --- NEW: WARNING TO MAINTENANCE TRANSITION TIMER ---
    if (IS_WARNING) {
        const timeToMaintenance = MAINTENANCE_START_TS - SIMULATED_NOW_TS; 
        
        if (timeToMaintenance > 0 && timeToMaintenance < 60) {
            setTimeout(() => {
                const appWrapper = document.getElementById('app-wrapper');
                const overlay = document.getElementById('maintenance-overlay');
                const marquee = document.querySelector('.pre-warning-marquee');

                // Apply the lockout state and show the overlay
                appWrapper.classList.add('lockout-active');
                overlay.classList.add('active');
                if (marquee) marquee.style.display = 'none';

                // Recalculate elapsed time to fix initial jump 
                simulatedSecondsElapsed = SIMULATED_NOW_TS - MAINTENANCE_START_TS;
                
                updateCountdown(); 
            }, timeToMaintenance * 1000); 
        }
    }
    
    // --- MAINTENANCE MODE HANDLER ---
    if (IS_MAINTENANCE || IS_WARNING) {
        
        document.addEventListener('DOMContentLoaded', () => {
             if (IS_MAINTENANCE) {
                 // Calculate how many seconds have passed since maintenance started
                 simulatedSecondsElapsed = SIMULATED_NOW_TS - MAINTENANCE_START_TS;
                 updateCountdown();
             }
        });
    }
    
    function updateCountdown() {
        const countdownElement = document.getElementById('countdown-timer');
        const overlay = document.getElementById('maintenance-overlay');
        const appWrapper = document.getElementById('app-wrapper');

        if (!appWrapper.classList.contains('lockout-active') && !overlay.classList.contains('active')) {
            return;
        }
        // Use the elapsed time to project the current simulated time
        const currentTime = SIMULATED_NOW_TS + simulatedSecondsElapsed;
        const remainingSeconds = MAINTENANCE_END_TS - currentTime;
        
        if (remainingSeconds <= 0) {
            countdownElement.textContent = '00:00';
            
            // Remove the lockout and re-enable clicks
            overlay.classList.remove('active');
            appWrapper.classList.remove('lockout-active');
            
            // Fetch the newly reset page content (FORCE RELOAD FIX)
            window.location.reload(); 
            return;
        }

        const minutes = Math.floor(remainingSeconds / 60);
        const seconds = remainingSeconds % 60;
        
        const formattedTime = 
            String(minutes).padStart(2, '0') + ':' + 
            String(seconds).padStart(2, '0');
        
        countdownElement.textContent = formattedTime;
        
        simulatedSecondsElapsed++;

        setTimeout(updateCountdown, 1000);
    } 
    async function fetchUpdatedContent() {
        const response = await fetch('index.php?content_only=1&t=' + Date.now());
        const newHtml = await response.text();
        
        const appWrapper = document.getElementById('app-wrapper');
        if (appWrapper && newHtml) {
             // FIX V7.5.3: Change from innerHTML replacement to full page reload to clear state and cache
             window.location.reload(); 
        } else {
             window.location.reload();
        }
    }
    
    // --- UTILITY FUNCTIONS ---
    
    function toggleMenu() {
        const menu = document.getElementById('dropdown-menu');
        menu.classList.toggle('show');
    }
    
    function confirmDelete() {
        if (!document.getElementById('app-wrapper').classList.contains('lockout-active') && confirm("WARNING: All data (tasks, points, progress) will be permanently deleted. Are you sure you wish to delete your account?")) {
            window.location.href = '?action=delete_account';
        }
    }

    function renderTaskHtml(task) {
        const completedClass = task.completed ? 'completed-slot' : '';
        const completedAttr = task.completed ? 'checked' : '';
        const permanentIndicator = task.permanent ? '<span class="permanent-indicator" title="Permanent Daily Task">🔒</span>' : '';
        const permanentBtnText = task.permanent ? 'Unlock' : 'Lock';
        const nextStatus = task.permanent ? 'false' : 'true';
        return `
            <div class="task-slot ${completedClass}" id="task-${task.id}" data-id="${task.id}" data-permanent="${task.permanent}" data-completed="${task.completed}">
                <input type="checkbox" class="task-checkbox" ${completedAttr} onchange="toggleTask('${task.id}')">
                <div class="task-description-wrapper">
                    ${permanentIndicator}
                    <span class="task-description ${completedAttr ? 'completed' : ''}">${task.text}</span>
                </div>
                <button class="permanent-btn" data-permanent="${task.permanent}" onclick="togglePermanent('${task.id}', ${nextStatus})">${permanentBtnText}</button>
                <button class="remove-btn" onclick="deleteTask('${task.id}')">REMOVE</button>
            </div>
        `;
    }

    function updateStatsDisplay(data) {
        document.getElementById('tp-stats').textContent = data.tp;
        document.getElementById('sp-stats').textContent = data.sp;
        document.getElementById('daily-count-stats').textContent = data.daily_count;
        document.getElementById('user-rank-title').textContent = data.rank;
        document.getElementById('tp-display').textContent = data.tp;
        document.getElementById('sp-display-menu').textContent = data.sp;
        
        const failedStats = document.getElementById('failed-stats');
        if (failedStats) {
             failedStats.textContent = data.failed; 
        }
    }

    async function postAction(data) {
        if (document.getElementById('app-wrapper').classList.contains('lockout-active')) {
            alert("System is in Hospital 🏥. Please wait for the daily reset.");
            return { success: false, message: 'Maintenance Mode' };
        }
        
        try {
            const response = await fetch('index.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams(data)
            });
            const result = await response.json();
            
            if (response.status === 503) {
                 alert("System is in Hospital 🏥. Please wait for the daily reset.");
                 if (result.message && result.message.includes('Hospital')) {
                     window.location.reload(); 
                 }
                 return { success: false, message: result.message || 'Maintenance Mode' };
            }
            
            return result;

        } catch (error) {
            console.error('Network or parsing error:', error);
            alert('A network error occurred. Check your connection.');
            return { success: false, message: 'Network Error' };
        }
    }

    async function addTask() {
        const input = document.getElementById('new-task-input');
        const text = input.value.trim();
        const permanent = document.getElementById('task-type-select').value;
        
        if (!text) return;

        const result = await postAction({ 
            endpoint: 'task_action', 
            action: 'add', 
            text: text, 
            permanent: permanent
        });

        if (result.success) {
            document.getElementById('task-list').insertAdjacentHTML('beforeend', renderTaskHtml(result.task));
            input.value = '';
            document.getElementById('no-tasks-message')?.remove();
        } else if (result.message !== 'Maintenance Mode') {
            alert(result.message);
        }
    }
    async function toggleTask(id) {
        const slot = document.getElementById(`task-${id}`);
        const isCompleted = slot.dataset.completed === 'true';

        const result = await postAction({ 
            endpoint: 'task_action', 
            action: 'toggle', 
            id: id 
        });

        if (result.success) {
            slot.dataset.completed = result.completed;
            slot.querySelector('.task-checkbox').checked = result.completed;
            slot.querySelector('.task-description').classList.toggle('completed', result.completed);
            slot.classList.toggle('completed-slot', result.completed);
            
            updateStatsDisplay(result.user_data);
        } else if (result.message !== 'Maintenance Mode') {
            // Revert checkbox state on failure
            slot.querySelector('.task-checkbox').checked = isCompleted;
            alert(result.message);
        } else {
             // Maintenance mode block. Revert state.
             slot.querySelector('.task-checkbox').checked = isCompleted;
        }
    }
    async function deleteTask(id) {
        if (document.getElementById('app-wrapper').classList.contains('lockout-active')) {
            alert("System is in Hospital 🏥. Please wait for the daily reset.");
            return;
        }
        if (!confirm("Confirm mission abort (REMOVE)?")) return; 
        
        const result = await postAction({ 
            endpoint: 'task_action', 
            action: 'delete', 
            id: id 
        });

        if (result.success) {
            document.getElementById(`task-${id}`).remove();
            updateStatsDisplay(result.user_data);
            
            if (document.getElementById('task-list').children.length === 0) {
                 document.getElementById('task-list').innerHTML = '<p id="no-tasks-message">No active missions. Add a new one to begin!</p>';
            }
        } else if (result.message !== 'Maintenance Mode') {
            alert(result.message);
        }
    }

    async function togglePermanent(id, newPermanentStatus) {
        const result = await postAction({ 
            endpoint: 'task_action', 
            action: 'set_permanent', 
            id: id,
            permanent: newPermanentStatus 
        });

        if (result.success) {
            const slot = document.getElementById(`task-${id}`);
            slot.dataset.permanent = result.permanent;
            
            const indicator = slot.querySelector('.permanent-indicator');
            const button = slot.querySelector('.permanent-btn');
            
            if (result.permanent) {
                // Switching to Locked/Permanent
                if (!indicator) { 
                    const wrapper = slot.querySelector('.task-description-wrapper');
                    wrapper.insertAdjacentHTML('afterbegin', '<span class="permanent-indicator" title="Permanent Daily Task">🔒</span>');
                }
                button.textContent = 'Unlock'; 
                button.setAttribute('data-permanent', 'true');
                button.setAttribute('onclick', `togglePermanent('${id}', false)`);
            } else {
                // Switching to Unlocked/Temporary
                indicator?.remove();
                button.textContent = 'Lock'; 
                button.setAttribute('data-permanent', 'false');
                button.setAttribute('onclick', `togglePermanent('${id}', true)`);
            }
            // NO MODIFICATIONS TO completed, claimed, points, or daily count here.
            
        } else if (result.message !== 'Maintenance Mode') {
            alert(result.message);
        }
    }
// --- DEFINITIVE COLLECT SP FUNCTION (FIXED UI UPDATE) ---
    async function collectSp() {
        const button = document.getElementById('sp-collect-btn');
        // V7.6.41 FIX: Target the element itself, not a non-existent span
        
        if (document.getElementById('app-wrapper').classList.contains('lockout-active')) {
             alert("System is in Hospital 🏥. Please wait for the daily reset.");
             return;
        }
        // Fix 1: Instant feedback for 'CLAIMED' state
        if (button.getAttribute('data-collected') === 'true') {
            alert('Error: Daily Diamond has already been collected today. Try again after 12:00 AM!');
            return;
        }

        // CRITICAL FIX: To prevent double-clicks, we visually disable it
        button.classList.add('collecting-in-progress'); 

        const result = await postAction({ 
            endpoint: 'sp_collect' 
        });

        // 1. ALERT THE MESSAGE FIRST 
        if (result.message && result.message !== 'Maintenance Mode') {
             setTimeout(() => {
                 alert(result.message);
             }, 0); 
        }

        if (result.success) {
            // 2. UPDATE STATS AND UI
            updateStatsDisplay({
                tp: document.getElementById('tp-stats').textContent, 
                sp: result.sp_points,
                failed: document.getElementById('failed-stats')?.textContent ?? '0', 
                rank: result.rank,
                daily_count: document.getElementById('daily-count-stats').textContent
            });
            
            // --- THE CRITICAL UI FIX ---
            button.textContent = 'CLAIMED'; 
            button.setAttribute('data-collected', 'true');
            button.classList.remove('collecting-in-progress'); 

        } else if (result.message !== 'Maintenance Mode') {
            // 3. ON FAILURE (e.g., network error): RE-ENABLE VISUAL LOCK
            button.classList.remove('collecting-in-progress');
        }
    }
    async function saveDailyQuota() {
        const input = document.getElementById('daily-quota-input');
        let quota = parseInt(input.value.trim(), 10);

        if (isNaN(quota) || quota < 1) {
            quota = 1;
            input.value = quota;
        }

        const result = await postAction({ 
            endpoint: 'save_quota', 
            quota: quota 
        });
        
        if (result.success) {
            alert(`Daily quota set to ${result.quota} tasks.`);
        } else if (result.message !== 'Maintenance Mode') {
            alert(result.message);
        }
    }
    async function toggleFailureSystem() {
        if (document.getElementById('app-wrapper').classList.contains('lockout-active')) {
             alert("System is in Hospital 🏥. Please wait for the daily reset.");
             return;
        }
        
        if (!confirm("Confirm toggle? This will affect your daily penalty logic. OK to proceed.")) return;
        
        const result = await postAction({ 
            endpoint: 'toggle_failure' 
        });

        if (result.success) {
            const isEnabled = result.status == 1;
            
            const toggleBtn = document.getElementById('failure-toggle-btn');
            const statusTextSpan = document.getElementById('failure-status-text');
            const profileContainer = document.querySelector('.profile-container');
            const quotaContainer = document.getElementById('quota-input-container');
            
            // 1. Update text and attributes
            toggleBtn.textContent = isEnabled ? 'Off' : 'On'; 
            statusTextSpan.textContent = `Fail System: ${isEnabled ? '⟨ON 🟢⟩' : '⟨OFF 🔴⟩'}`;
            toggleBtn.setAttribute('data-enabled', isEnabled ? 'true' : 'false');
            // 2. CRITICAL UI FIX: Hide/Show elements instantly
            if (isEnabled) {
                // If turning ON, create/show the elements that PHP hid
                
                // --- A. Failed Stat Line ---
                let failedStatLine = document.getElementById('failed-stat-line');
                if (!failedStatLine) {
                    // Create the HTML since PHP removed it
                    const newHtml = `
                        <div class="stats-line" id="failed-stat-line">
                            Failed (❌): <strong id="failed-stats">0</strong>
                        </div>
                    `;
                    // Insert it after the TP stats (or wherever the TP stats are)
                    const tpStatsLine = document.querySelector('.stats-line:nth-child(4)'); 
                    if (tpStatsLine) {
                        tpStatsLine.insertAdjacentHTML('afterend', newHtml);
                    }
                    // Since we insert '0', we need to re-fetch/update the stats:
                    updateStatsDisplay({
                         // Pull current stats from UI (since we don't have the full object)
                        tp: document.getElementById('tp-stats').textContent, 
                        sp: document.getElementById('sp-stats').textContent, 
                        daily_count: document.getElementById('daily-count-stats').textContent,
                        // And explicitly use the failed count returned by the server on login/refresh
                        failed: '0', // The server only returns a simple '0' on toggle
                        rank: document.getElementById('user-rank-title').textContent
                    });
                } else {
                    failedStatLine.style.display = 'block';
                }

                // --- B. Quota Input Container ---
                if (quotaContainer) {
                    quotaContainer.style.display = 'flex';
                }
                
            } else {
                // If turning OFF, simply hide the elements
                const failedStatLine = document.getElementById('failed-stat-line');
                if (failedStatLine) {
                    failedStatLine.style.display = 'none';
                }
                if (quotaContainer) {
                    quotaContainer.style.display = 'none';
                }
            }
            
            alert(result.message);
            
        } else if (result.message !== 'Maintenance Mode') {
            alert(result.message);
        }
    }

    async function saveObjective() {
        const input = document.getElementById('objective-input');
        const objective = input.value.trim();

        const result = await postAction({ 
            endpoint: 'save_objective', 
            objective: objective 
        });
        
        // FIX 3: Add confirmation message
        if (result.success) {
            alert(result.message);
        } else if (result.message !== 'Maintenance Mode') {
            alert(result.message);
        }
    }

    document.addEventListener('click', (event) => {
        const menu = document.getElementById('dropdown-menu');
        const button = document.querySelector('.hamburger-btn');
        if (menu && button && !menu.contains(event.target) && !button.contains(event.target)) {
            menu.classList.remove('show');
        }
    });

</script>
<?php endif; // End of if (!$isContentOnly): ?>
</body>
</html>
<?php
    return ob_get_clean(); 
}
// --- APPLICATION EXECUTION START (The final fix for the crash) ---
$loggedIn = false;

try {
    // This is the call that was crashing the script silently before.
    $loggedInUser = getCurrentUser($dbManager); 
    
    if ($loggedInUser) {
        $loggedIn = true;
    }

} catch (Throwable $e) {
    // This robustly catches the fatal error (white screen) and allows the script 
    error_log("CRITICAL AUTH FAILURE: " . $e->getMessage()); 
    $loggedInUser = null;
    $loggedIn = false;
}
// --- 2. MAIN REQUEST HANDLING ---
if ($loggedInUser) {
    handleRequest($loggedInUser, $dbManager);
} else {
    header('Location: auth.php'); 
    exit;
}
if (isset($dbManager)) {
    $dbManager->close();
}
?>
