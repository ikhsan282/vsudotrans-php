<?php
/**
 * Self-check modul Kendaraan (tanpa DB). Jalankan: php tests/vehicles_check.php
 */
require __DIR__ . '/../pages/vehicles/_helpers.php';

$today = new DateTimeImmutable('2026-10-12');
assert(vehicle_due_soon(null, $today) === false);
assert(vehicle_due_soon('2026-11-11', $today) === true);   // 30 hari
assert(vehicle_due_soon('2026-11-12', $today) === false);  // 31 hari
assert(vehicle_due_soon('2026-09-01', $today) === true);   // sudah lewat tetap ditandai
assert(vehicle_due_soon('bukan-tanggal', $today) === false);

$ok = [
    'brand_id' => 1, 'model_unit_id' => 2, 'category_vehicle_id' => 4,
    'vehicle_number' => 'B 1234 ABC', 'frame_number' => 'MHX1', 'machine_number' => 'M1',
    'production_year' => 2020, 'vehicle_color' => 'Putih', 'seat' => 7, 'ownership' => 'Internal',
    'tax_due' => null, 'stnk_due' => null, 'kir_due' => null,
];
assert(vehicle_validate($ok) === []);
assert(vehicle_validate([...$ok, 'ownership' => 'Sewa']) !== []);
assert(vehicle_validate([...$ok, 'seat' => 0]) !== []);
assert(vehicle_validate([...$ok, 'vehicle_number' => 'B;DROP']) !== []);
assert(vehicle_validate([...$ok, 'tax_due' => false]) !== []);
assert(vehicle_validate([...$ok, 'brand_id' => 0]) !== []);

assert(str_contains(vehicle_dup_message("Duplicate entry 'X' for key 'vehicles.uq_vehicles_number'"), 'Nomor polisi'));
assert(str_contains(vehicle_dup_message("Duplicate entry 'X' for key 'vehicles.uq_vehicles_frame'"), 'Nomor rangka'));
assert(str_contains(vehicle_dup_message("Duplicate entry 'X' for key 'brand_vehicles.uq_brand_vehicles_code'"), 'Kode'));

echo "Semua kasus vehicles lolos.\n";
