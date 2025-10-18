<?php
// config.php (V7.0.0 - )
const DB_FILE_PATH = __DIR__ . '/data.db';
define('TEST_TIME_OVERRIDE', '23:57:50');
const TIMEZONE_RESET = 'Asia/Kolkata';
define('SESSION_TTL_SECONDS', 220898400); // 7 years 
const TASK_COMPLETION_REWARD = 2;     
const DAILY_CHECKIN_REWARD = 2;
const DAILY_FAILURE_PENALTY = 2;      
const RANK_THRESHOLDS = [
    ['sp' => 16500, 'title' => 'Code Wizard 🧙', 'desc' => 'The ultimate level of mastery. You command technology with effortless grace, optimizing systems and pioneering new solutions. You are the architect of the digital world.'],
    ['sp' => 14000, 'title' => 'Software Master 🏆', 'desc' => 'Your understanding of software engineering principles is profound. You design, build, and deploy complex applications with flawless execution and robust architecture.'],
    ['sp' => 12000, 'title' => 'System Architect 🏗️', 'desc' => 'You excel at seeing the big picture, designing the core structure and interactions of massive software systems. Your blueprints guide entire development teams.'],
    ['sp' => 10000, 'title' => 'Senior Specialist 🌟', 'desc' => 'You are an expert in your primary domain, tackling the hardest bugs and mentoring others. You deliver high-quality code and lead projects autonomously.'],
    ['sp' => 8000, 'title' => 'Refactor Engineer 🛠️', 'desc' => 'Your focus is on code quality and long-term maintainability. You skillfully refactor legacy systems, making them clean, fast, and scalable for the future.'],
    ['sp' => 6000, 'title' => 'Domain Specialist 🖥️', 'desc' => 'You possess deep knowledge in a critical area of development (e.g., backend, graphics, DevOps). You are the go-to person for complex domain issues.'],
    ['sp' => 4500, 'title' => 'Senior Developer ✨', 'desc' => 'You work independently on large features, making sound technical decisions and contributing significantly to the codebase. You start coaching junior members.'],
    ['sp' => 3000, 'title' => 'Associate Software Engineer 💡', 'desc' => 'You can implement complex features from start to finish with moderate supervision. You have a solid grasp of fundamental concepts and data structures.'],
    ['sp' => 1800, 'title' => 'Full Stack Dev 🌐', 'desc' => 'You are comfortable working across both the frontend and backend, understanding the full lifecycle of a web application and its dependencies.'],
    ['sp' => 900, 'title' => 'Developer 💾', 'desc' => 'You consistently deliver functional code, resolve simple bugs, and actively participate in code reviews. You are building confidence and speed.'],
    ['sp' => 400, 'title' => 'Junior Developer 💻', 'desc' => 'You have finished your basic training and are now contributing real code. You are focused on solving small problems and learning best practices.'],
    ['sp' => 150, 'title' => 'Front End Dev 🎨', 'desc' => 'Your initial focus is on the user interface and experience. You master HTML, CSS, and basic JavaScript to make things look and feel great.'],
    ['sp' => 50, 'title' => 'Newbie Coder 🌱', 'desc' => 'You have mastered the absolute basics of coding. You are focused on syntax, simple logic, and setting up your first development environment.'],
    ['sp' => 0, 'title' => 'Aspiring 🚀', 'desc' => 'This is the starting line. You are ready to learn, experiment, and begin your journey into the world of software development. Every line of code counts!']
];
