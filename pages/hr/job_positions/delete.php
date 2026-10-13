<?php
/** Hapus masters (POST + CSRF). 'job_positions' diset oleh file pemanggil. */
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../masters/_engine.php';

master_delete('job_positions');
