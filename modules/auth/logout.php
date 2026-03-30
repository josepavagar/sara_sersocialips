<?php
require_once __DIR__ . '/../../core/auth.php';
session_destroy();
header('Location: ' . BASE_URL . '/modules/auth/login.php');
exit;
