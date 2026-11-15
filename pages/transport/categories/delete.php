<?php
/** Hapus Kategori Layanan (POST + CSRF). */
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../vehicles/_master.php';

vehicle_master_delete('category');
