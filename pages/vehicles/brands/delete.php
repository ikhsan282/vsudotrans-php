<?php
/** Hapus Merek Kendaraan (POST + CSRF). */
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../_master.php';

vehicle_master_delete('brand');
