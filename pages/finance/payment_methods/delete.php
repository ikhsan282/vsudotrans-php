<?php
/** Hapus Metode Pembayaran (POST + CSRF). */
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../masters/_engine.php';

master_delete('payment_methods');
