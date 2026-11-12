/* DONDEBAILAR agenda AJAX filters. Extracted without changing behavior. */
/*
 * Agenda-filters worden binnen dezelfde pagina bijgewerkt.
 * De header en beide merklogo's blijven daardoor in de bestaande DOM staan.
 */
(function () {
    if (window.__agendaAjaxFiltersInstalled) return;
    window.__agendaAjaxFiltersInstalled = true;

    let requestController = null;

    function refreshAgendaPage(parsedDocument, targetUrl, addHistory) {
        const currentPage = document.querySelector('.site-main > .agenda-page');
        const incomingPage = parsedDocument.querySelector('.site-main > .agenda-page');
        if (!currentPage || !incomingPage) {
            window.location.assign(targetUrl.href);
            return;
        }

        // Behoud het bestaande berichtenvenster: de bestaande bediening verwijst hiernaar.
        const existingMessageModal = currentPage.querySelector('#agenda-message-modal');
        const incomingMessageModal = incomingPage.querySelector('#agenda-message-modal');
        if (existingMessageModal && incomingMessageModal) {
            incomingMessageModal.replaceWith(existingMessageModal);
        }

        currentPage.replaceWith(incomingPage);

        // Ververs ook het zoekformulier buiten .agenda-page. Zo verdwijnt een
        // oude zoekterm én de wislink zodra een filterkeuze de zoekopdracht wist.
        const currentSearchForm = document.querySelector('.site-footer--agenda .agenda-footer-search');
        const incomingSearchForm = parsedDocument.querySelector('.site-footer--agenda .agenda-footer-search');
        if (currentSearchForm && incomingSearchForm) {
            currentSearchForm.replaceWith(incomingSearchForm);
        }

        // Vervang alleen de inhoud van de filterstrips; de filterknop en header blijven staan.
        ['organizer', 'city'].forEach(function (panelName) {
            const currentStrip = document.querySelector('[data-panel="' + panelName + '"] .agenda-organizer-strip');
            const incomingStrip = parsedDocument.querySelector('[data-panel="' + panelName + '"] .agenda-organizer-strip');
            if (currentStrip && incomingStrip) currentStrip.innerHTML = incomingStrip.innerHTML;
        });

        // Vernieuw de URL zonder de eenmalige agenda_change-vlag.
        const cleanUrl = new URL(targetUrl.href);
        cleanUrl.searchParams.delete('agenda_change');
        const historyMethod = addHistory ? 'pushState' : 'replaceState';
        window.history[historyMethod]({agendaAjax:true}, '', cleanUrl.pathname + cleanUrl.search + cleanUrl.hash);

        // Nieuwe reeks en paginaknoppen.
        const cards = Array.from(document.querySelectorAll('.agenda-feed .agenda-card'));
        const previous = document.getElementById('agenda-page-previous');
        const next = document.getElementById('agenda-page-next');
        const range = document.getElementById('agenda-page-range');
        const pageSize = (Number(window.DONDEBAILAR_AGENDA_PAGE_SIZE) || 5);
        let start = 0;
        let end = Math.min(pageSize, cards.length);

        function updatePagination() {
            cards.forEach(function (card, index) {
                card.hidden = index < start || index >= end;
            });
            if (range) range.textContent = cards.length ? (start + 1) + '–' + end + ' van ' + cards.length : '';
            if (previous) previous.disabled = start === 0;
            if (next) next.disabled = start + pageSize >= cards.length;
        }
        if (cards.length) updatePagination();

        if (previous) previous.addEventListener('click', function () {
            start = Math.max(0, start - pageSize);
            end = Math.min(start + pageSize, cards.length);
            updatePagination();
            document.querySelector('.agenda-feed')?.scrollIntoView({behavior:'smooth', block:'start'});
        });
        if (next) next.addEventListener('click', function () {
            if (start + pageSize >= cards.length) return;
            start += pageSize;
            end = Math.min(start + pageSize, cards.length);
            updatePagination();
            document.querySelector('.agenda-feed')?.scrollIntoView({behavior:'smooth', block:'start'});
        });

        // Initialiseer de galerijen en video's in de nieuw geladen evenementkaarten.
        document.querySelectorAll('.agenda-page [data-gallery]').forEach(function (gallery) {
            if (gallery.dataset.ajaxInitialized === '1') return;
            gallery.dataset.ajaxInitialized = '1';
            const track = gallery.querySelector('[data-gallery-track]');
            const counter = gallery.querySelector('[data-gallery-count]');
            if (!track || !counter) return;
            const slides = Array.from(track.querySelectorAll('.agenda-card__media-slide'));
            const updateCounter = function () {
                if (!slides.length) return;
                const width = track.clientWidth || 1;
                const index = Math.min(slides.length, Math.max(1, Math.round(track.scrollLeft / width) + 1));
                counter.textContent = index + ' / ' + slides.length;
                slides.forEach(function (slide, slideIndex) {
                    slide.querySelectorAll('video.agenda-card__video').forEach(function (video) {
                        if (slideIndex !== index - 1) video.pause();
                    });
                });
            };
            track.addEventListener('scroll', updateCounter, {passive:true});
            window.addEventListener('resize', updateCounter);
            updateCounter();
        });

        document.querySelectorAll('.agenda-page [data-event-info]').forEach(function (info) {
            if (info.dataset.ajaxSwipeInitialized === '1') return;
            info.dataset.ajaxSwipeInitialized = '1';
            if (typeof setEventDescription !== 'function' || !info.querySelector('[data-event-description]')) return;
            let touchStart = null;
            info.addEventListener('touchstart', function (event) {
                if (!event.changedTouches.length) return;
                touchStart = {x:event.changedTouches[0].clientX, y:event.changedTouches[0].clientY};
            }, {passive:true});
            info.addEventListener('touchend', function (event) {
                if (!touchStart || !event.changedTouches.length) return;
                const touch = event.changedTouches[0];
                const dx = touch.clientX - touchStart.x;
                const dy = touch.clientY - touchStart.y;
                touchStart = null;
                if (Math.abs(dx) >= 45 && Math.abs(dx) >= Math.abs(dy) * 1.25) {
                    setEventDescription(info, dx > 0);
                }
            }, {passive:true});
        });

        if (typeof agendaVideoObserver !== 'undefined') {
            document.querySelectorAll('.agenda-page video.agenda-card__video').forEach(function (video) {
                video.muted = typeof agendaVideosMuted !== 'undefined' ? agendaVideosMuted : true;
                video.setAttribute('autoplay', '');
                video.setAttribute('muted', '');
                agendaVideoObserver.observe(video);
            });
        }
        if (window.instgrm && window.instgrm.Embeds) {
            window.instgrm.Embeds.process();
        }
        if (typeof updateAgendaStripIndicators === 'function') updateAgendaStripIndicators();
        window.scrollTo({top:0, behavior:'instant'});
    }

    async function loadAgendaFilter(url, addHistory) {
        if (requestController) requestController.abort();
        requestController = new AbortController();
        const response = await fetch(url.href, {
            credentials:'same-origin',
            headers:{'X-Requested-With':'XMLHttpRequest'},
            signal:requestController.signal
        });
        if (!response.ok) throw new Error('De agenda kon niet worden bijgewerkt.');
        const html = await response.text();
        const parsed = new DOMParser().parseFromString(html, 'text/html');
        refreshAgendaPage(parsed, url, addHistory);
    }

    document.addEventListener('click', function (event) {
        // Wisselen tussen STEDEN en ORGANISATOREN wist een actieve vrije zoekterm.
        const filterSwitch = event.target instanceof Element ? event.target.closest('#agenda-filter-switch') : null;
        if (filterSwitch) {
            const url = new URL(window.location.href);
            if (url.searchParams.has('q') && url.searchParams.get('q') !== '') {
                url.searchParams.delete('q');
                url.searchParams.set('agenda_change', '1');
                loadAgendaFilter(url, true).catch(function (error) {
                    if (error.name !== 'AbortError') window.location.assign(url.href);
                });
            }
            return;
        }

        const link = event.target instanceof Element ? event.target.closest('.agenda-organizer-strip a[href]') : null;
        if (!link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        const url = new URL(link.href, window.location.origin);
        if (url.origin !== window.location.origin || url.pathname !== '/' || url.searchParams.get('agenda_change') !== '1') return;
        event.preventDefault();
        loadAgendaFilter(url, true).catch(function (error) {
            if (error.name !== 'AbortError') window.location.assign(url.href);
        });
    });

    window.addEventListener('popstate', function () {
        const url = new URL(window.location.href);
        url.searchParams.set('agenda_change', '1');
        loadAgendaFilter(url, false).catch(function () {
            window.location.reload();
        });
    });
})();
