<?php
$failures = 0;
function check(bool $condition, string $message): void
{
    global $failures;
    echo ($condition ? 'ok  ' : 'FAIL') . $message . PHP_EOL;
    if (!$condition) $failures++;
}

require_once __DIR__ . '/../includes/functions.php';

$eventsPath = __DIR__ . '/../pages/dispatch/calendar_events.php';
$viewPath = __DIR__ . '/../pages/dispatch/calendar.php';
$sidebarPath = __DIR__ . '/../includes/sidebar.php';
$cssPath = __DIR__ . '/../assets/css/style.css';
$travelFormPath = __DIR__ . '/../pages/travel/bookings/form.php';
$rentalFormPath = __DIR__ . '/../pages/rental/bookings/form.php';
$events = file_get_contents($eventsPath);
$view = file_get_contents($viewPath);
$sidebar = file_get_contents($sidebarPath);
$css = file_get_contents($cssPath);
$travelForm = file_get_contents($travelFormPath);
$rentalForm = file_get_contents($rentalFormPath);

check(str_contains($events, "require_permission('dispatch.view')"), 'event endpoint enforces RBAC');
check(str_contains($events, "can('travel.view')") && str_contains($events, "can('rental.view')"), 'feed checks booking-specific view permissions');
check(str_contains($events, '$travelVisibility') && str_contains($events, '$rentalVisibility'), 'unauthorized booking categories are omitted');
check(str_contains($events, 'T\\d{2}:\\d{2}:\\d{2}') && str_contains($events, "format('Y-m-d\\\\TH:i:s')"), 'feed validates full ISO datetime timestamps');
check(str_contains($events, 'catch (Throwable)'), 'malformed ISO datetime is rejected without fatal error');
check(substr_count($events, '{$companySql}') === 2, 'both event sources apply company scope for normal users');
check(str_contains($events, '$errors[\'warning_count\']') && str_contains($events, '$errors[\'error_count\']'), 'date validation rejects normalized invalid dates');
check(str_contains($events, "'travel' AS kind") && str_contains($events, "'rental' AS kind"), 'calendar feeds travel and rental');
check(str_contains($events, "b.start_date < ? AND b.end_date >= ?"), 'rental feed includes overlapping multi-day bookings');
check(str_contains($events, 'DATE_ADD(b.end_date, INTERVAL 1 DAY)'), 'rental event uses exclusive end date');
check(str_contains($view, 'dayGridMonth,timeGridWeek,timeGridDay,listWeek'), 'calendar exposes month/week/day/list views');
check(str_contains($sidebar, 'pages/dispatch/calendar.php'), 'calendar reachable from dispatch menu');

// Peningkatan kalender: CDN 404 fix, sopir done, allDay rental, filter, preview modal, dark mode, ?date prefill
check(!str_contains($view, 'index.global.min.css'), 'broken FullCalendar CDN stylesheet link removed');
check(!str_contains($view, "'Sewa'") && !str_contains($view, "'Trv'"), 'redundant SEWA/TRV pill tags omitted when colors distinguish types');
check(
    str_contains($events, "\$kind === 'rental' && \$plate !== ''")
    && str_contains($view, 'isRentalWithPlate')
    && str_contains($view, 'hasTravelPlate')
    && str_contains($view, 'fc-vst-plate')
    && str_contains($view, 'listPrimaryText'),
    'calendar events prioritize plate visibility for rental and travel pills'
);
check(str_contains($events, 'ORDER BY (t2.ended_at IS NULL) DESC, t2.id DESC LIMIT 1'), 'driver lookup keeps latest assignment including done trips');
check(str_contains($events, "'allDay'") && str_contains($events, "'display'") && str_contains($events, 'booking_status_label('), 'events distinguish rental allDay vs travel block and localize status labels');
check(str_contains($events, 'invoice_pay_status(') && str_contains($events, 'manifest_url'), 'events expose payment status and Surat Jalan url');
check(str_contains($events, '$kindFilter') && str_contains($events, '$statusFilter') && str_contains($events, '$hideCancelled') && str_contains($events, '$driverId') && str_contains($events, '$vehicleId'), 'event feed supports all operational filters');
check(str_contains($view, 'ui_page_header(') && str_contains($view, '$activeCid !== null'), 'calendar uses standard header and guards create CTA without active company');
check(str_contains($view, 'cal-filter-driver') && str_contains($view, 'cal-filter-vehicle') && str_contains($view, 'calEventModal') && str_contains($view, 'calDateCreateModal'), 'calendar renders filter bar, preview modal, and date-click create modal');
check(
    str_contains($view, 'ops-calendar-icon-legend')
    && str_contains($view, 'bi-clock')
    && str_contains($view, 'bi-pencil-square')
    && str_contains($view, 'bi-x-circle')
    && str_contains($view, 'fc-vst-live-dot'),
    'calendar displays icon legend row for all five booking statuses'
);
check(str_contains($css, 'html[data-bs-theme="dark"] #ops-calendar') && str_contains($css, '.fc-vst-status-cancelled'), 'FullCalendar includes dark mode and status styles');
check(str_contains($travelForm, "parse_valid_ymd(\$_GET['date']") && str_contains($rentalForm, "parse_valid_ymd(\$_GET['date']"), 'travel and rental forms support optional valid ?date prefill');
check(parse_valid_ymd('2026-11-28') === '2026-11-28' && parse_valid_ymd('2026-02-30') === null && parse_valid_ymd('2026-11-28T09:00:00') === null && parse_valid_ymd('') === null, 'parse_valid_ymd validates strict Y-m-d dates');
exit($failures ? 1 : 0);
