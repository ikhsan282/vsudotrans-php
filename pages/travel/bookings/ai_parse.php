<?php
/**
 * Endpoint bantu isi form Travel: teks bebas -> kandidat field booking (JSON).
 */
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../includes/booking_ai_parse.php';

booking_ai_parse_handle('travel');
