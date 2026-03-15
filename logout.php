<?php
session_start();
session_unset();
session_destroy();

// Redirect to login page with JS to prevent back navigation
?>
<!DOCTYPE html>
<html>
<head>
    <title>Logging out...</title>
    <script>
        /* ---- Prevent Browser Back Button ---- */
        window.history.pushState(null, "", window.location.href);
        window.onpopstate = function () {
            window.history.pushState(null, "", window.location.href);
        };
        window.location.replace('/login.php');
    </script>
</head>
<body>
</body>
</html>