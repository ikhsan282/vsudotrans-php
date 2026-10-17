<?php
/** Aksi urut naik/turun Metode Pembayaran (POST + CSRF). */
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../masters/_engine.php';

$dir = match ($_POST['dir'] ?? '') { 'up' => -1, 'down' => 1, default => 0 };
master_move('payment_methods', (int) ($_POST['id'] ?? 0), $dir);
