<?php
//  ranks.php (V7.1 FINAL - FIX: Full 14-Rank List and Descriptions)
// --- INITIALIZATION: MUST BE FIRST ---
ini_set('display_errors', 0);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_WARNING);

// 1. Include the necessary files
require_once 'config.php';
require_once 'DbManager.php';

// Check if TIMEZONE_RESET is defined (it should be, via config.php)
if (defined('TIMEZONE_RESET')) {
    date_default_timezone_set(TIMEZONE_RESET); 
}

// 2. Database Connection
try {
    $dbManager = new DbManager(); 
} catch (Exception $e) {
    die("Application Setup Error: " . $e->getMessage());
}

if (!function_exists('getRankTitle')) {
     function getRankTitle($sp_points) {
         if (defined('RANK_THRESHOLDS')) {
             foreach (RANK_THRESHOLDS as $rank) {
                 if ($sp_points >= $rank['sp']) {
                     return $rank['title'];
                 }
             }
         }
         return 'Aspiring 🚀'; 
     }
}


// 3. User Authentication/Retrieval
function getCurrentUser($dbManager) {
    $sessionToken = $_COOKIE['session'] ?? null;
    if (!$sessionToken) return null;

    $username = $dbManager->getUsernameFromSession($sessionToken);
    if (!$username) return null;

    $userData = $dbManager->getUserData($username);
    if (!$userData) return null;
    
    // CALCULATE DYNAMIC RANK TITLE based on user's points
    $userData['rank'] = getRankTitle($userData['sp_points']); 
    $userData['task_points'] = $userData['claimed_task_points'] - $userData['total_penalty_deduction'];
    
    return $userData;
}

$user = getCurrentUser($dbManager);

// If not logged in, redirect them back to the login page
if (!$user) {
    header('Location: auth.php');
    exit;
}
if (!defined('RANK_THRESHOLDS')) {
    define('RANK_THRESHOLDS', [
        ['sp' => 16500, 'title' => 'Code Wizard 🧙'],
        ['sp' => 14000, 'title' => 'Software Master 🏆️️️'],
        ['sp' => 12000, 'title' => 'System Architect 🏗️'],
        ['sp' => 10000, 'title' => 'Senior Specialist 🌟'],
        ['sp' => 8000, 'title' => 'Refactor Engineer 🛠️'],
        ['sp' => 6000, 'title' => 'Domain Specialist 🖥️'],
        ['sp' => 4500, 'title' => 'Senior Developer ✨'],
        ['sp' => 3000, 'title' => 'Associate Software Engineer 💡'],
        ['sp' => 1800, 'title' => 'Full Stack Dev 🌐'],
        ['sp' => 900, 'title' => 'Developer 💾'],
        ['sp' => 400, 'title' => 'Junior Developer 💻'],
        ['sp' => 150, 'title' => 'Front End Dev 🎨'],
        ['sp' => 50, 'title' => 'Newbie Coder 🌱'],
        ['sp' => 0, 'title' => 'Aspiring 🚀']
    ]);
}

// Ensure the ranks are sorted descending for display (highest rank first)
$ranks = array_reverse(RANK_THRESHOLDS);


// --- HTML RENDERING ---
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rank System</title>
    <link href="https://fonts.googleapis.com/css2?family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
    <style>
        .ranks-header-container {
            /* Positioning the back/rank block */
            position: absolute;
            top: 20px;
            left: 20px;
            z-index: 100;
            display: flex;
            flex-direction: column;
            align-items: flex-start;
        }

        .ranks-header-container .back-link {
            /* Color Fix for ⟨BACK⟩ */
            color: #00ff7f !important; /* Bright green for high visibility */
            text-decoration: none; 
            font-size: 1.2em; 
            font-weight: bold;
            margin-bottom: 5px; 
            padding: 2px 0; 
            transition: color 0.2s;
        }

        .ranks-header-container .back-link:hover {
            color: #fff !important; 
        }

        .current-rank-display {
            /* Fix for "CURRENT RANK:" color */
            color: #ffd700;
            font-size: 0.9em;
            padding: 2px 0;
            text-transform: uppercase;
        }

        .current-rank-display .rank-title-display {
            /* Dynamic Rank Title Color (Aspiring 🚀) */
            color: #ffd700 !important; 
            font-weight: bold;
            text-shadow: 0 0 10px #ffd700, 0 0 5px #00ff7f; 
        }
        
        /* Ensure the main content is pushed down */
        .rank-thresholds-container {
            margin-top: 100px; 
            padding-top: 20px;
        }
        
        /* Basic styles for the rank blocks */
        .rank-entry {
            border: 2px solid #555;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 8px;
        }
        .rank-entry h3 {
            margin-top: 0;
            color: #00ff7f; /* Green color for the rank title in the list */
        }
        .rank-entry .rank-requirement {
            font-weight: bold;
            color: #ffd700; /* Gold color for the required points */
        }

    </style>
</head>
<body>

<div class="container">
    
    <div class="ranks-header-container">
        <a href="index.php" class="back-link">⟨BACK⟩</a>
        <div class="current-rank-display">
            CURRENT RANK: <span class="rank-title-display"><?php echo htmlspecialchars($user['rank']); ?></span>
        </div>
    </div>
    <div class="rank-thresholds-container">
        <h1>Rank System</h1>
        <p>Ascend through the ranks by earning Diamonds (Self-Improvement Points) from daily check-ins.</p>

        <?php foreach ($ranks as $rank): ?>
            <div class="rank-entry">
                <h3><?php echo htmlspecialchars($rank['title']); ?></h3>
                <p class="rank-requirement">Requires: <?php echo number_format($rank['sp']); ?> 💎</p>
                
                <?php
                
                $description = htmlspecialchars($rank['desc'] ?? "Description not found for this rank."); 
                
                echo '<p>' . $description . '</p>';
                ?>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<?php
// Close the database connection
if (isset($dbManager)) {
    $dbManager->close();
}
?>
</body>
</html>
