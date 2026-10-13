<?php
/** Hapus masters (POST + CSRF). 'organization_structures' diset oleh file pemanggil. */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/_engine.php';

master_delete('organization_structures');
