<?php
// Agenda view. Variables are prepared by app/agenda/agenda_data.php.
?>
<section class="agenda-page">
    <?php if ($regions === []): ?>
        <section class="agenda-empty"><h2>De agenda wordt voorbereid</h2><p>Er zijn nog geen actieve regio's beschikbaar.</p></section>
    <?php else: ?>
        
        <?php if ($events === []): ?>
            <section class="agenda-empty"><h2>Geen evenementen gevonden</h2><p>Er zijn momenteel geen toekomstige gepubliceerde evenementen met deze filters.</p></section>
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
                    <article class="agenda-card"<?= $eventIndex >= $agendaPageSize ? ' hidden' : '' ?> style="--db-event-day-color:var(--db-weekday-<?= (int) $start->format('N') ?>,var(--db-primary))">
                        <div class="agenda-card__media-wrap">
                        <?php $eventMediaItems = $event['media_items'] ?? []; ?>
                        <?php if (count($eventMediaItems) > 1): ?>
                            <div class="agenda-card__gallery" data-gallery>
                                <div class="agenda-card__gallery-track" data-gallery-track>
                                    <?php foreach ($eventMediaItems as $mediaIndex => $mediaItem): ?>
                                        <div class="agenda-card__media-slide<?= $mediaItem['type'] === 'reel' && $mediaItem['source'] === 'instagram' ? ' agenda-card__media-slide--instagram' : '' ?>">
                                            <?php if ($mediaItem['type'] === 'image' && $mediaItem['source'] === 'upload'): ?>
                                                <div class="agenda-card__image-link">
                                                    <img class="agenda-card__image" src="/event-media.php?id=<?= (int) $mediaItem['id'] ?>" alt="<?= $escape($event['title']) ?> — foto <?= $mediaIndex + 1 ?>" loading="<?= $mediaIndex === 0 ? 'eager' : 'lazy' ?>">
                                                </div>
                                            <?php elseif ($mediaItem['type'] === 'video' && $mediaItem['source'] === 'upload'): ?>
                                                <video class="agenda-card__video" playsinline preload="metadata" controlslist="nodownload noremoteplayback" disablepictureinpicture disableremoteplayback src="/event-media.php?id=<?= (int) $mediaItem['id'] ?>"></video>
                                            <?php elseif ($mediaItem['type'] === 'reel' && $mediaItem['source'] === 'instagram'): ?>
                                                <div class="agenda-card__instagram">
                                                    <blockquote class="instagram-media" data-instgrm-permalink="<?= $escape($mediaItem['external_url']) ?>" data-instgrm-version="14">
                                                        <a href="<?= $escape($mediaItem['external_url']) ?>" target="_blank" rel="noopener noreferrer">Bekijk deze Reel op Instagram</a>
                                                    </blockquote>
                                                </div>
                                            <?php else: ?>
                                                <a class="agenda-card__external-media" href="<?= $escape($mediaItem['external_url']) ?>" target="_blank" rel="noopener noreferrer">
                                                    <span aria-hidden="true">↗</span>
                                                    <strong>Bekijk externe media</strong>
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <span class="agenda-card__media-count" data-gallery-count>1 / <?= count($eventMediaItems) ?></span>
                            </div>
                        <?php elseif (count($eventMediaItems) === 1): ?>
                            <?php $mediaItem = $eventMediaItems[0]; ?>
                            <?php if ($mediaItem['type'] === 'image' && $mediaItem['source'] === 'upload'): ?>
                                <div class="agenda-card__image-link">
                                    <img class="agenda-card__image" src="/event-media.php?id=<?= (int) $mediaItem['id'] ?>" alt="<?= $escape($event['title']) ?>" loading="lazy">
                                </div>
                            <?php elseif ($mediaItem['type'] === 'video' && $mediaItem['source'] === 'upload'): ?>
                                <video class="agenda-card__video" playsinline preload="metadata" controlslist="nodownload noremoteplayback" disablepictureinpicture disableremoteplayback src="/event-media.php?id=<?= (int) $mediaItem['id'] ?>"></video>
                            <?php elseif ($mediaItem['type'] === 'reel' && $mediaItem['source'] === 'instagram'): ?>
                                <div class="agenda-card__instagram">
                                    <blockquote class="instagram-media" data-instgrm-permalink="<?= $escape($mediaItem['external_url']) ?>" data-instgrm-version="14">
                                        <a href="<?= $escape($mediaItem['external_url']) ?>" target="_blank" rel="noopener noreferrer">Bekijk deze Reel op Instagram</a>
                                    </blockquote>
                                </div>
                            <?php else: ?>
                                <a class="agenda-card__external-media" href="<?= $escape($mediaItem['external_url']) ?>" target="_blank" rel="noopener noreferrer"><span aria-hidden="true">↗</span><strong>Bekijk externe media</strong></a>
                            <?php endif; ?>
                        <?php else: ?>
                            <a class="agenda-card__image-link" href="<?= $eventUrl ?>" aria-label="Bekijk <?= $escape($event['title']) ?>">
                                <div class="agenda-card__placeholder"><span>SALSA · BACHATA · KIZOMBA</span><strong><?= $escape($event['title']) ?></strong></div>
                            </a>
                        <?php endif; ?>
                        </div>
                        <div class="agenda-card__details" data-event-info>
                            <div class="agenda-card__quick">
                                <button type="button" class="agenda-attendance<?= !empty($event['is_attending']) ? ' is-attending' : '' ?>" data-event-id="<?= (int) $event['id'] ?>" data-event-title="<?= $escape($event['title']) ?>" aria-label="Ik kom dansen" aria-pressed="<?= !empty($event['is_attending']) ? 'true' : 'false' ?>" title="Tik: Ik kom dansen · Lang indrukken: deelnemers"><span aria-hidden="true">👠</span><b><?= (int) ($event['attendance_count'] ?? 0) ?></b></button>
                                <button type="button" class="agenda-icon-button agenda-share" data-share-title="<?= $escape($event['title']) ?>" data-share-url="<?= $escape($shareUrl) ?>" aria-label="Deel evenement" title="Deel evenement"><svg aria-hidden="true" viewBox="0 0 24 24" width="21" height="21" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="18" cy="5" r="3"></circle><circle cx="6" cy="12" r="3"></circle><circle cx="18" cy="19" r="3"></circle><path d="m8.7 10.7 6.6-4.4M8.7 13.3l6.6 4.4"></path></svg></button>
                                <?php if ($mapUrl !== ''): ?><a class="agenda-icon-button agenda-location" href="<?= $escape($mapUrl) ?>" target="_blank" rel="noopener noreferrer" aria-label="Toon locatie op kaart" title="Toon locatie op kaart"><svg aria-hidden="true" viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 5-8 12-8 12S4 15 4 10a8 8 0 1 1 16 0Z"></path><circle cx="12" cy="10" r="2.5"></circle></svg></a><?php endif; ?>
                                <button type="button" class="agenda-icon-button agenda-messages" data-event-messages data-url="/event-messages.php?id=<?= (int) $event['id'] ?>" aria-label="Berichten van evenement" title="Berichten van evenement"><svg aria-hidden="true" viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.4 8.4 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.4 8.4 0 0 1-3.8-.9L3 21l1.9-5.7a8.4 8.4 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.4 8.4 0 0 1 3.8-.9h.5a8.5 8.5 0 0 1 8 8v.5Z"></path></svg><b class="agenda-message-count"><?= (int) ($event['message_count'] ?? 0) ?></b></button>
                            </div>
                            <h2><a href="<?= $eventUrl ?>"><?= $escape($event['title']) ?></a></h2>
                            <?php
                            $hashtagText = trim((string) ($event['hashtags'] ?? ''));
                            $hashtagItems = preg_split('/[\\s,]+/u', $hashtagText, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                            $hashtagItems = array_values(array_filter(array_map(
                                static fn(string $tag): string => ltrim($tag, '#'),
                                $hashtagItems
                            ), static fn(string $tag): bool => preg_match('/^[\\p{L}\\p{N}_]+$/u', $tag) === 1));
                            ?>
                            <p class="agenda-card__date-line"><?= $escape(strtoupper($start->format('l, d M Y'))) ?> <span>◷</span> <?= $escape($start->format('H:i')) ?><?php if (!empty($event['ends_at'])): ?> – <?= $escape((new DateTimeImmutable((string) $event['ends_at'], $timezone))->format('H:i')) ?><?php endif; ?></p>
                            <?php
                            $displayTags = array_map(static fn(string $tag): string => '#' . $tag, $hashtagItems);
                            if ($locationLabel !== '') {
                                $displayTags[] = '#' . $locationLabel;
                            }
                            ?>
                            <?php if ($displayTags !== []): ?>
                                <p class="agenda-card__hashtags"><?= $escape(implode(' ', $displayTags)) ?></p>
                            <?php endif; ?>
                            <?php
                            $eventDescription = trim((string) ($event['description'] ?? ''));
                            $locationName = trim((string) ($event['location_name'] ?? ''));
                            $locationStreet = trim((string) ($event['location_street'] ?? ''));
                            $locationHouseNumber = trim((string) ($event['location_house_number'] ?? ''));
                            $locationPostalCode = trim((string) ($event['location_postal_code'] ?? ''));
                            $locationAddressLine = trim($locationStreet . ' ' . $locationHouseNumber);
                            $locationPostalCity = trim($locationPostalCode . ' ' . $locationLabel);
                            $locationPhone = trim((string) ($event['location_phone'] ?? ''));
                            $locationLogo = basename((string) ($event['location_logo_path'] ?? ''));
                            $hasLocationLogo = preg_match('/\A[a-f0-9]{32}\.(jpg|png|webp)\z/', $locationLogo) === 1;
                            $organizerName = trim((string) ($event['organizer_name'] ?? ''));
                            $organizerPhone = trim((string) ($event['organizer_phone'] ?? ''));
                            $organizerLogo = basename((string) ($event['organizer_logo_path'] ?? ''));
                            $hasOrganizerLogo = preg_match('/\A[a-f0-9]{32}\.(jpg|png|webp)\z/', $organizerLogo) === 1;
                            $hasMoreInfo = $eventDescription !== '' || $locationName !== '' || $locationAddressLine !== '' || $locationPhone !== '' || $organizerName !== '' || $organizerPhone !== '';
                            ?>
                            <?php if ($hasMoreInfo): ?>
                                <div class="agenda-card__description" data-event-description aria-hidden="true">
                                    <div class="agenda-card__description-inner">
                                        <?php if ($locationName !== '' || $locationAddressLine !== '' || $locationPostalCity !== '' || $locationPhone !== ''): ?>
                                            <div class="agenda-card__info-block">
                                                <h3 class="agenda-card__section-label">LOCATIE</h3>
                                                <?php if ($locationName !== ''): ?>
                                                    <div class="agenda-card__venue">
                                                        <?php if ($hasLocationLogo): ?>
                                                            <img class="agenda-card__venue-logo" src="/location-logo.php?file=<?= rawurlencode($locationLogo) ?>" alt="<?= $escape($locationName) ?>" loading="lazy">
                                                        <?php endif; ?>
                                                        <strong><?= $escape($locationName) ?></strong>
                                                    </div>
                                                <?php endif; ?>
                                                <?php if ($locationAddressLine !== '' || $locationPostalCity !== ''): ?>
                                                    <p class="agenda-card__venue-address">⌖ <?= $escape(implode(', ', array_filter([$locationAddressLine, $locationPostalCity]))) ?></p>
                                                <?php endif; ?>
                                                <?php if ($locationPhone !== ''): ?>
                                                    <p class="agenda-card__venue-phone">☎ <a href="tel:<?= $escape(preg_replace('/[^0-9+*#]/', '', $locationPhone)) ?>"><?= $escape($locationPhone) ?></a></p>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($organizerName !== '' || $organizerPhone !== ''): ?>
                                            <div class="agenda-card__info-block agenda-card__info-block--organizer">
                                                <h3 class="agenda-card__section-label">ORGANISATOR</h3>
                                                <div class="agenda-card__organizer">
                                                    <?php if ($hasOrganizerLogo): ?>
                                                        <img class="agenda-card__organizer-logo" src="/organizer-logo.php?file=<?= rawurlencode($organizerLogo) ?>" alt="<?= $escape($organizerName) ?>" loading="lazy">
                                                    <?php endif; ?>
                                                    <div class="agenda-card__organizer-info">
                                                        <?php if ($organizerName !== ''): ?><strong><?= $escape($organizerName) ?></strong><?php endif; ?>
                                                        <?php if ($organizerPhone !== ''): ?><span>☎ <a href="tel:<?= $escape(preg_replace('/[^0-9+*#]/', '', $organizerPhone)) ?>"><?= $escape($organizerPhone) ?></a></span><?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($eventDescription !== ''): ?>
                                            <div class="agenda-card__description-text"><?= nl2br($escape($eventDescription)) ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                            <p class="agenda-card__swipe-hint" data-swipe-hint>→ Swipe voor meer info</p>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
            <div class="agenda-page-navigation" aria-label="Navigatie tussen evenementreeksen">
                    <button type="button" class="agenda-page-nav-icon" id="agenda-page-previous" aria-label="Vorige reeks" title="Vorige reeks">‹</button>
                    <span id="agenda-page-range">1–<?= min($agendaPageSize, count($events)) ?> van <?= count($events) ?></span>
                    <button type="button" class="agenda-page-nav-icon" id="agenda-page-next" aria-label="Volgende reeks" title="Volgende reeks">›</button>
            </div>
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
