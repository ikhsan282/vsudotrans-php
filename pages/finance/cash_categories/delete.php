<?php
/** Hapus Master Kategori Kas (POST + CSRF). */
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../masters/_engine.php';

master_delete('cash_categories');
