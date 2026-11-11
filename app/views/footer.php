<?php
declare(strict_types=1);
?>
</main>

<?php if (!empty($isAgendaPage)): ?>
    <?php $footerRegionId = (int) ($regionId ?? ($region['id'] ?? 0)); ?>
    <?php $footerSearchEscape = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
    <footer class="site-footer site-footer--agenda">
        <div class="site-footer__inner site-footer__inner--agenda">
            <div class="agenda-footer-tools">
                <form class="agenda-footer-search" method="get" action="/" role="search">
                    <?php if ($footerRegionId > 0): ?>
                        <input type="hidden" name="region_id" value="<?= $footerRegionId ?>">
                    <?php endif; ?>
                    <label class="sr-only" for="agenda-footer-search-input">Zoek in de agenda</label>
                    <input
                        type="search"
                        id="agenda-footer-search-input"
                        name="q"
                        value="<?= $footerSearchEscape($agendaSearch ?? '') ?>"
                        placeholder="Zoek evenement, stad of organisator…"
                        maxlength="120"
                        autocomplete="off"
                    >
                    <button type="submit" aria-label="Zoek in de agenda" title="Zoek in de agenda">
                        <svg aria-hidden="true" viewBox="0 0 24 24" width="21" height="21" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="10.8" cy="10.8" r="6.8"></circle><path d="m16 16 5 5"></path></svg>
                    </button>
                    <?php if (trim((string) ($agendaSearch ?? '')) !== ''): ?>
                        <a class="agenda-footer-search__clear" href="/?region_id=<?= $footerRegionId ?>" aria-label="Wis zoekopdracht" title="Wis zoekopdracht">×</a>
                    <?php endif; ?>
                </form>
                <button type="button" class="agenda-back-to-top agenda-toggle-sound" id="agenda-toggle-sound" aria-label="Geluid van video's uitschakelen" aria-pressed="false" title="Geluid van video's uitschakelen">
                    <svg aria-hidden="true" viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M11 5 6 9H2v6h4l5 4z"></path>
                        <path d="M15.5 8.5a5 5 0 0 1 0 7"></path>
                        <path d="M19 5a10 10 0 0 1 0 14"></path>
                    </svg>
                </button>
                <button type="button" class="agenda-back-to-top" id="agenda-back-to-top" aria-label="Terug naar boven" title="Terug naar boven">
                    <svg aria-hidden="true" viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
                        <path d="m5 14 7-7 7 7"></path>
                        <path d="M12 7v13"></path>
                    </svg>
                </button>
            </div>
        </div>
    </footer>
<?php else: ?>
    <footer class="site-footer">
        <div class="site-footer__inner">
        </div>
    </footer>
<?php endif; ?>

<script src="/assets/js/site.js" defer></script>
</body>
</html>
