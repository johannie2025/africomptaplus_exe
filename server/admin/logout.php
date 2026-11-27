<?php
require_once __DIR__ . '/../inc/auth.php';
start_session(); $_SESSION = []; session_destroy(); redirect('login.php');
