<?php
/** Entry halaman Metode Pembayaran. 'payment_methods' diset oleh engine. */
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../includes/finance_sync.php';
require_once __DIR__ . '/../../masters/_engine.php';

finance_ensure_global_payment_methods();
master_list('payment_methods');

