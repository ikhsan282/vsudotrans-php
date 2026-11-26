<?php
/** Form tambah/ubah Master Kategori Kas. */
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../includes/finance_sync.php';
require_once __DIR__ . '/../../masters/_engine.php';

finance_ensure_global_cash_categories();
master_form('cash_categories');
