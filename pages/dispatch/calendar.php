<?php
require_once __DIR__ . '/../../config/config.php';
require_permission('dispatch.view');

$active = 'dispatch';
$title = 'Kalender Operasional';
require __DIR__ . '/../../includes/header.php';
$eventsUrl = APP_URL . '/pages/dispatch/calendar_events.php';
?>
<main class="col-12 col-md-9 col-lg-10 p-3 p-lg-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
            <h4 class="fw-bold mb-1">Kalender Operasional</h4>
            <div class="small text-muted"><span class="text-primary">■</span> Travel &nbsp; <span class="text-success">■</span> Rental</div>
        </div>
        <div class="d-flex gap-2">
            <?php if (can('travel.manage')): ?><a class="btn btn-sm btn-outline-primary" href="<?= e(APP_URL) ?>/pages/travel/bookings/form.php">Booking Travel</a><?php endif; ?>
            <?php if (can('rental.manage')): ?><a class="btn btn-sm btn-outline-success" href="<?= e(APP_URL) ?>/pages/rental/bookings/form.php">Pemesanan Sewa Kendaraan</a><?php endif; ?>
        </div>
    </div>
    <div class="card shadow-sm border-0"><div class="card-body"><div id="ops-calendar" aria-label="Kalender booking travel dan rental"></div></div></div>
</main>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.20/index.global.min.css">
<style>#ops-calendar .fc-toolbar-title{font-size:1.2rem}#ops-calendar .fc-event{cursor:pointer}#ops-calendar .fc .fc-button{font-size:.85rem} @media(max-width:575px){#ops-calendar .fc-toolbar{flex-direction:column;gap:.5rem}#ops-calendar .fc-toolbar-chunk{display:flex;justify-content:center}}</style>
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.20/index.global.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const calendar = new FullCalendar.Calendar(document.getElementById('ops-calendar'), {
        initialView: window.matchMedia('(max-width: 767px)').matches ? 'listWeek' : 'dayGridMonth',
        locale: 'id', height: 'auto', nowIndicator: true,
        headerToolbar: { left: 'prev,next today', center: 'title', right: 'dayGridMonth,timeGridWeek,timeGridDay,listWeek' },
        events: { url: <?= json_encode($eventsUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?> },
        eventDidMount: function(info) { if(info.event.extendedProps.details) info.el.title=info.event.extendedProps.details; },
        eventClick: function(info) { if(info.event.url){info.jsEvent.preventDefault();window.location.href=info.event.url;} }
    });
    calendar.render();
});
</script>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
