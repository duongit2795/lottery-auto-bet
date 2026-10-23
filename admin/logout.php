<?php
require __DIR__ . '/_init.php';
session_destroy();
redirect('login.php');
