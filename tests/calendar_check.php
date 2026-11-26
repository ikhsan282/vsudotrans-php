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
    && str_contains($events, 'orig_city_short')
    && str_contains($events, 'dest_city_short')
    && str_contains($events, 'brand_name')
    && str_contains($events, 'category_code')
    && str_contains($events, 'category_name')
    && str_contains($view, 'compactRouteLabel')
    && str_contains($view, 'fc-vst-pill-route')
    && str_contains($view, 'fc-vst-plate')
    && str_contains($view, 'p.brand_name')
    && str_contains($view, 'Tanpa Mobil')
    && str_contains($view, 'Tanpa Sopir')
    && str_contains($view, 'listPrimaryText'),
    'calendar events display passenger + short route on row 1 and vehicle category + plate + driver on row 2'
);
check(str_contains($events, 'ORDER BY (t2.ended_at IS NULL) DESC, t2.id DESC LIMIT 1'), 'driver lookup keeps latest assignment including done trips');
check(str_contains($events, "'allDay'") && str_contains($events, "'display'") && str_contains($events, 'booking_status_label('), 'events distinguish rental allDay vs travel block and localize status labels');
check(str_contains($events, 'invoice_pay_status(') && str_contains($events, 'manifest_url'), 'events expose payment status and Surat Jalan url');
check(str_contains($events, '$kindFilter') && str_contains($events, '$statusFilter') && str_contains($events, '$hideCancelled') && str_contains($events, '$driverId') && str_contains($events, '$vehicleId'), 'event feed supports all operational filters');
check(str_contains($view, 'ui_page_header(') && str_contains($view, '$activeCid !== null'), 'calendar uses standard header and guards create CTA without active company');
check(!str_contains($view, 'cal-filter-kind') && !str_contains($view, 'cal-filter-driver') && !str_contains($view, 'cal-filter-vehicle') && str_contains($view, 'cal-filter-q') && str_contains($view, 'cal-filter-status') && str_contains($view, 'cal-filter-hide-cancelled') && str_contains($view, 'calEventModal') && str_contains($view, 'calDateCreateModal'), 'calendar renders simplified filter bar without kind/driver/vehicle, preview modal, and date-click create modal');
check(
    str_contains($view, 'ops-calendar-icon-legend')
    && str_contains($view, 'bi-clock')
    && str_contains($view, 'bi-pencil-square')
    && str_contains($view, 'bi-x-circle')
    && str_contains($view, 'fc-vst-live-dot'),
    'calendar displays icon legend row for all five booking statuses'
);
check(
    str_contains($view, "info.view.type.startsWith('list')")
    && str_contains($view, 'openEventModal(info.event)')
    && str_contains($css, '#ops-calendar .fc-list-event.fc-vst-event:hover > td'),
    'list view rows open event detail modal on click'
);
check(
    str_contains($view, "const listPrimaryText = p.passenger || '';")
    && !str_contains($view, "(p.kind === 'rental' && p.vehicle_number)"),
    'list view primary text shows customer name without duplicating rental plate'
);
check(
    !str_contains($view, 'p.kind === \'rental\' && p.period_label')
    && str_contains($view, "p.period_label ? '<i class=\"bi bi-calendar-range me-1\"></i>' + escHtml(p.period_label) : null"),
    'list view displays period_label (date and time) for both travel and rental'
);
check(str_contains($css, 'html[data-bs-theme="dark"] #ops-calendar') && str_contains($css, '.fc-vst-status-cancelled'), 'FullCalendar includes dark mode and status styles');
check(
    str_contains($css, '.fc-daygrid-event-harness-abs')
    && str_contains($css, 'max-width: none;')
    && str_contains($css, 'overflow: visible;'),
    'FullCalendar CSS allows multi-day .fc-daygrid-event-harness-abs bars to span across day cells without clipping'
);
check(str_contains($travelForm, "parse_valid_ymd(\$_GET['date']") && str_contains($rentalForm, "parse_valid_ymd(\$_GET['date']") && str_contains($rentalForm, "parse_valid_ymd(\$_GET['end_date']"), 'travel and rental forms support optional valid ?date and ?end_date prefill');
check(parse_valid_ymd('2026-11-28') === '2026-11-28' && parse_valid_ymd('2026-02-30') === null && parse_valid_ymd('2026-11-28T09:00:00') === null && parse_valid_ymd('') === null, 'parse_valid_ymd validates strict Y-m-d dates');

// UI/UX upgrades: quick status endpoint, category differentiation, KPI strip, modal quick actions, URL sync, jump date, mobile drawer, drag & hover
$statusEndpointPath = __DIR__ . '/../pages/dispatch/calendar_status.php';
$statusEndpoint = is_file($statusEndpointPath) ? file_get_contents($statusEndpointPath) : '';
check(
    $statusEndpoint !== ''
    && str_contains($statusEndpoint, "require_permission('dispatch.view')")
    && str_contains($statusEndpoint, 'hash_equals(csrf_token()')
    && str_contains($statusEndpoint, 'booking_find($kind, $bookingId)')
    && str_contains($statusEndpoint, 'booking_change_status('),
    'quick status endpoint enforces dispatch RBAC, CSRF, tenant guard, and booking_change_status'
);
check(
    str_contains($events, '$categoryId')
    && str_contains($events, '$categoryGroup')
    && str_contains($events, '$classifyServiceGroup')
    && str_contains($events, "'service_group'")
    && str_contains($events, "'needs_dispatch'")
    && str_contains($events, "'is_unpaid'")
    && str_contains($events, "'can_manage'")
    && str_contains($events, 'pricing_unit'),
    'event feed exposes service_group differentiation, dispatch/payment flags, and category filters'
);
check(
    str_contains($view, 'cal-filter-category')
    && str_contains($view, 'ops-cal-chip')
    && str_contains($view, 'ops-cal-kpi-strip')
    && str_contains($view, 'cal-kpi-unassigned-num')
    && str_contains($view, 'cal-kpi-unpaid-num')
    && str_contains($view, 'calModalCategoryBadge')
    && str_contains($view, 'id="calEventModal"')
    && str_contains($view, 'data-bs-focus="false"')
    && str_contains($view, 'ensureQuickAssignTs')
    && !str_contains($view, 'calModalCopyDriverBtn')
    && !str_contains($view, 'Salin Info Sopir')
    && str_contains($view, 'calModalQuickStatusWrap')
    && str_contains($view, 'handleQuickStatusChange')
    && str_contains($view, 'history.replaceState')
    && str_contains($view, 'cal-jump-date')
    && str_contains($view, 'listMonth')
    && str_contains($view, "scrollTime: '06:30:00'")
    && str_contains($view, 'cal-error-banner')
    && str_contains($view, 'cal-mobile-filter-toggle')
    && str_contains($view, 'selectable: canCreateAny')
    && str_contains($view, 'ops-cal-hover-card')
    && str_contains($view, 'calendar.today()'),
    'calendar UI includes interactive chips, KPI strip, modal quick actions, URL persistence, jump picker, listMonth, error banner, mobile drawer, drag select, hover card, and shortcuts'
);
check(
    str_contains($css, '.ops-cal-kpi-strip')
    && str_contains($css, '.ops-cal-chip.active')
    && str_contains($css, '.ops-cal-hover-card')
    && str_contains($css, '.fc-vst-missing-chip'),
    'stylesheet defines calendar KPI, chip, hover card, and alert pill styles'
);

// Upgrade operasional lanjutan: Quick Assign dan Drag & Drop Reschedule
$assignEndpoint = is_file(__DIR__ . '/../pages/dispatch/calendar_assign.php')
    ? file_get_contents(__DIR__ . '/../pages/dispatch/calendar_assign.php') : '';
$reschedEndpoint = is_file(__DIR__ . '/../pages/dispatch/calendar_reschedule.php')
    ? file_get_contents(__DIR__ . '/../pages/dispatch/calendar_reschedule.php') : '';

check(
    $assignEndpoint !== ''
    && str_contains($assignEndpoint, "require_permission('dispatch.view')")
    && str_contains($assignEndpoint, 'hash_equals(csrf_token()')
    && str_contains($assignEndpoint, 'booking_assign_driver(')
    && str_contains($assignEndpoint, 'booking_auto_advance_status(')
    && str_contains($assignEndpoint, '409'),
    'quick assign endpoint enforces RBAC, CSRF, Rental conflict guard, driver sync, and auto status advance'
);
check(
    $reschedEndpoint !== ''
    && str_contains($reschedEndpoint, "require_permission('dispatch.view')")
    && str_contains($reschedEndpoint, 'hash_equals(csrf_token()')
    && str_contains($reschedEndpoint, 'parse_valid_ymd(')
    && str_contains($reschedEndpoint, 'invoice_snapshot(')
    && str_contains($reschedEndpoint, '409'),
    'drag reschedule endpoint enforces RBAC, CSRF, date validation, Rental overlap check, and invoice snapshot'
);
check(
    !is_file(__DIR__ . '/../pages/dispatch/manifest_group.php')
    && !str_contains($view, 'manifest_group.php'),
    'group manifest feature has been removed cleanly from dispatch'
);
check(
    str_contains($events, "'startEditable'")
    && str_contains($events, "'company_id'"),
    'calendar events expose drag-drop editability and company_id'
);
check(
    str_contains($view, 'editable: true')
    && str_contains($view, 'eventDrop:')
    && str_contains($view, 'calModalQuickAssignBox')
    && str_contains($view, 'calRescheduleModal'),
    'calendar view implements quick assign panel and drag-drop reschedule modal'
);
exit($failures ? 1 : 0);
