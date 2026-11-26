<?php
/** Entry halaman Master Kategori Kas. 'cash_categories' diset oleh engine. */
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../includes/finance_sync.php';
require_once __DIR__ . '/../../masters/_engine.php';

finance_ensure_global_cash_categories();
master_list('cash_categories');
