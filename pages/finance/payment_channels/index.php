<?php
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../travel/_helpers.php';

$cid = require_active_company();
$prov = (string) ($_GET['code'] ?? 'gopay');
redirect("pages/companies/form.php?id={$cid}&tab=payment&provider={$prov}");
