<?php
header('Location: ' . (file_exists(__DIR__ . '/config.php') ? 'admin/' : 'install.php'));
