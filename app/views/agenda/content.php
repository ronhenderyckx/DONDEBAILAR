<?php
// Agenda view. Variables are prepared by app/agenda/agenda_data.php.
?>
<section class="agenda-page">
    <?php if ($regions === []): ?>
        <section class="agenda-empty"><h2>De agenda wordt voorbereid</h2><p>Er zijn nog geen actieve regio's beschikbaar.</p></section>
    <?php else: ?>
        
        <?php if ($events === []): ?>
            <section class="agenda-empty"><h2>Geen evenementen gevonden</h2><p>Er zijn momenteel geen gepubliceerde evenementen met deze filters.</p></section>
        <?php else: ?>
            <div class="agenda-feed">
                <?php foreach ($events as $eventIndex => $event): ?>
                    <?php
                    $start = new DateTimeImmutable((string) $event['starts_at'], $timezone);
                    $eventUrl = '/event.php?id=' . (int) $event['id'];
                    $locationLabel = trim((string) ($event['location_city'] ?? ''));
                    $mapUrl = (string) ($event['google_maps_url'] ?? '');
                    if ($mapUrl === '' && $event['latitude'] !== null && $event['longitude'] !== null) {
                        $mapUrl = 'https://www.google.com/maps?q=' . rawurlencode((string) $event['latitude'] . ',' . (string) $event['longitude']);
                    }
                    $shareScheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
                    $shareUrl = $shareScheme . ($_SERVER['HTTP_HOST'] ?? '') . $eventUrl;
                    ?>
                    <?php require __DIR__ . '/event-card.php'; ?>
                <?php endforeach; ?>
            </div>
            <?php if ($totalEvents > $agendaPageSize): ?>
                <?php
                $previousPageUrl = '?' . http_build_query(array_merge($_GET, ['page' => max(1, $agendaPage - 1)]));
                $nextPageUrl = '?' . http_build_query(array_merge($_GET, ['page' => min((int) ceil($totalEvents / $agendaPageSize), $agendaPage + 1)]));
                $firstEventNumber = (($agendaPage - 1) * $agendaPageSize) + 1;
                $lastEventNumber = min($agendaPage * $agendaPageSize, $totalEvents);
                ?>
                <nav class="agenda-page-navigation" aria-label="Navigatie tussen evenementreeksen">
                    <?php if ($agendaPage > 1): ?>
                        <a class="agenda-page-nav-icon" href="<?= $escape($previousPageUrl) ?>" aria-label="Vorige reeks" title="Vorige reeks">‹</a>
                    <?php else: ?>
                        <span class="agenda-page-nav-icon" aria-disabled="true">‹</span>
                    <?php endif; ?>
                    <span><?= $firstEventNumber ?>–<?= $lastEventNumber ?> van <?= $totalEvents ?></span>
                    <?php if ($lastEventNumber < $totalEvents): ?>
                        <a class="agenda-page-nav-icon" href="<?= $escape($nextPageUrl) ?>" aria-label="Volgende reeks" title="Volgende reeks">›</a>
                    <?php else: ?>
                        <span class="agenda-page-nav-icon" aria-disabled="true">›</span>
                    <?php endif; ?>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>
    <div class="agenda-message-modal" id="agenda-message-modal" hidden>
        <div class="agenda-message-modal__panel" role="dialog" aria-modal="true" aria-labelledby="agenda-message-modal-title">
            <div class="agenda-message-modal__top"><button type="button" class="agenda-message-modal__close" aria-label="Sluiten">×</button></div>
            <iframe title="Berichten bij evenement" src="about:blank" scrolling="no"></iframe>
        </div>
    </div>

</section>


<?php
$hasInstagramReels = false;
foreach ($events as $instagramCheckEvent) {
    foreach (($instagramCheckEvent['media_items'] ?? []) as $instagramCheckMedia) {
        if (($instagramCheckMedia['type'] ?? '') === 'reel' && ($instagramCheckMedia['source'] ?? '') === 'instagram') {
            $hasInstagramReels = true;
            break 2;
        }
    }
}
?>
<?php if ($hasInstagramReels): ?><script async src="https://www.instagram.com/embed.js"></script><?php endif; ?>
<script>
window.DONDEBAILAR_AGENDA_CONFIG = <?= json_encode([
    'pageSize' => (int) $agendaPageSize,
    'activeFilterIndex' => $activeFilter === 'city' ? 1 : 0,
    'soundMuted' => (bool) $savedSoundMuted,
    'csrfToken' => csrfToken(),
    'regionId' => (int) $regionId,
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
<script src="/assets/js/agenda.js"></script>
<script>window.DONDEBAILAR_AGENDA_PAGE_SIZE = <?= (int) $agendaPageSize ?>;</script>
<script src="/assets/js/agenda-filters.js"></script>
<?php require dirname(__DIR__, 3) . '/app/views/footer.php'; ?>
