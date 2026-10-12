<?php
/** Hapus masters (POST + CSRF). 'banks' diset oleh file pemanggil. */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/_engine.php';

master_delete('banks');
