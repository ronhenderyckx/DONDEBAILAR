<?php
declare(strict_types=1);
?>
</main>

<?php if (!empty($isAgendaPage)): ?>
    <?php $footerRegionId = (int) ($regionId ?? ($region['id'] ?? 0)); ?>
    <?php $footerSearchEscape = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
    <footer class="site-footer site-footer--agenda">
        <div class="site-footer__inner site-footer__inner--agenda">
            <div class="site-footer__agenda-brand">
                <span>&copy; <?= date('Y') ?> DONDEBAILAR</span>
                <span>Salsa · Bachata · Kizomba</span>
            </div>
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
        </div>
    </footer>
<?php else: ?>
    <footer class="site-footer">
        <div class="site-footer__inner">
            <span>&copy; <?= date('Y') ?> DONDEBAILAR</span>
            <span>Salsa · Bachata · Kizomba</span>
        </div>
    </footer>
<?php endif; ?>

<script src="/assets/js/site.js" defer></script>
</body>
</html>
