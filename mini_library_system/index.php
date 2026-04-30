<?php
session_start();

// If already logged in, go straight to the dashboard
if (isset($_SESSION['admin_id'])) {
    header("Location: Dashboard/dashboard.php");
} else {
    header("Location: Admin/auth.php");
}
exit();
?>