<?php
/**
 * Kompatibilitas URL lama (`master.php?t=brand|category|model`):
 * alihkan permanen secara aman ke halaman terpisah masing-masing.
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/_master.php';
require_permission('vehicles.view');

$key = (string) ($_REQUEST['t'] ?? 'brand');
$map = vehicle_masters_map();
if (!isset($map[$key])) {
    redirect('pages/vehicles/index.php');
}

$m = $map[$key];
$editId = (int) ($_GET['edit'] ?? 0);
if ($editId > 0) {
    redirect(vehicle_master_route($m, 'form.php', ['id' => $editId]));
}
if (isset($_GET['new'])) {
    redirect(vehicle_master_route($m, 'form.php'));
}

$pass = [];
foreach (['company_id', 'brand_id', 'status', 'q', 'page'] as $param) {
    if (array_key_exists($param, $_GET) && $_GET[$param] !== '') {
        $pass[$param] = (string) $_GET[$param];
    }
}
redirect(vehicle_master_route($m, 'index.php', $pass));
