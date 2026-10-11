<?php
/**
 * Self-check: aturan level role (angka lebih besar = role lebih rendah).
 * Jalankan: php tests/level_rule_check.php
 */
function role_rank_ok(int $actor_level, int $target_level): bool
{
    // Non-Super: boleh menetapkan/mengubah ke level sama atau lebih rendah (angka >=).
    return $target_level >= $actor_level;
}

$cases = [
    // [actor_level, target_level, expected]
    [2, 3, true],   // Admin (2) menetapkan HRD (3, lebih rendah) -> boleh
    [2, 2, true],   // Admin menetapkan Admin (sama) -> boleh
    [2, 1, false],  // Admin menetapkan Super Admin (1, lebih tinggi) -> tolak
    [3, 2, false],  // HRD menetapkan Admin (lebih tinggi) -> tolak
    [3, 3, true],   // HRD menetapkan HRD (sama) -> boleh
    [3, 4, true],   // HRD menetapkan level 4 (lebih rendah) -> boleh
];

foreach ($cases as [$actor, $target, $expected]) {
    $got = role_rank_ok($actor, $target);
    assert($got === $expected, "actor=$actor target=$target expected=" . var_export($expected, true));
    printf("actor=%d target=%d -> %s %s\n", $actor, $target, $got ? 'boleh' : 'tolak', $got === $expected ? 'OK' : 'FAIL');
}
echo "Semua kasus lolos.\n";
