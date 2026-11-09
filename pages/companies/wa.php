<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../travel/_helpers.php';

$cid = require_active_company();
redirect("pages/companies/form.php?id={$cid}&tab=whatsapp");
