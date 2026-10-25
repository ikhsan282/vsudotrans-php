<?php
$failures = 0;
function check(bool $condition, string $message): void
{
    global $failures;
    echo ($condition ? 'ok  ' : 'FAIL') . $message . PHP_EOL;
    if (!$condition) $failures++;
}

$eventsPath = __DIR__ . '/../pages/dispatch/calendar_events.php';
$viewPath = __DIR__ . '/../pages/dispatch/calendar.php';
$sidebarPath = __DIR__ . '/../includes/sidebar.php';
$events = file_get_contents($eventsPath);
$view = file_get_contents($viewPath);
$sidebar = file_get_contents($sidebarPath);
check(str_contains($events, "require_permission('dispatch.view')"), 'event endpoint enforces RBAC');
check(substr_count($events, '{$companySql}') === 2, 'both event sources apply company scope for normal users');
check(str_contains($events, '$errors[\'warning_count\']') && str_contains($events, '$errors[\'error_count\']'), 'date validation rejects normalized invalid dates');
check(str_contains($events, "'travel' AS kind") && str_contains($events, "'rental' AS kind"), 'calendar feeds travel and rental');
check(str_contains($events, "b.start_date < ? AND b.end_date >= ?"), 'rental feed includes overlapping multi-day bookings');
check(str_contains($events, 'DATE_ADD(b.end_date, INTERVAL 1 DAY)'), 'rental event uses exclusive end date');
check(str_contains($view, 'dayGridMonth,timeGridWeek,timeGridDay,listWeek'), 'calendar exposes month/week/day/list views');
check(str_contains($sidebar, 'pages/dispatch/calendar.php'), 'calendar reachable from dispatch menu');
exit($failures ? 1 : 0);
