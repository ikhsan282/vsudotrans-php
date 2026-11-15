<?php
require_once __DIR__ . '/../../config/config.php';

$qs = $_GET ? ('?' . http_build_query($_GET)) : '';
redirect('pages/employees/detail.php' . $qs);
