<?php
require __DIR__ . '/lib/triops.php';

t_logout();
header('Location: ./login.php');
