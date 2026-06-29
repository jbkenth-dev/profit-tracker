<?php
/**
 * Root redirect to dashboard
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

start_session();
require_auth();

redirect('dashboard.php');
