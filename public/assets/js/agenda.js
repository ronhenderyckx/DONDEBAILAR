/* DONDEBAILAR agenda interactions. PHP values are supplied through a JSON config object. */
// agenda_change is een eenmalige opdracht om een bewuste filterkeuze te tonen.
// Verwijder de vlag uit de adresbalk zodat ze na uitloggen/inloggen
// niet opnieuw de opgeslagen persoonlijke voorkeuren uitschakelt.
const agendaCurrentUrl = new URL(window.location.href);
if (agendaCurrentUrl.searchParams.get('agenda_change') === '1') {
    agendaCurrentUrl.searchParams.delete('agenda_change');
    window.history.replaceState({}, '', agendaCurrentUrl.pathname + agendaCurrentUrl.search + agendaCurrentUrl.hash);
}
const agendaFilterIcons = ['👥', '🗺️'];
const agendaFilterLabels = ['Organisator', 'Stad'];
let agendaFilterIndex = window.DONDEBAILAR_AGENDA_CONFIG.activeFilterIndex;
const agendaFilterSwitch = document.getElementById('agenda-filter-switch');


function updateAgendaStripIndicators() {
    document.querySelectorAll('.agenda-selector-panel').forEach(function (panel) {
        const strip = panel.querySelector('.agenda-organizer-strip');
        if (!strip || panel.hidden) {
            panel.classList.remove('has-more', 'has-prev');
            return;
        }
        const hasMore = strip.scrollWidth > strip.clientWidth + 2
            && strip.scrollLeft + strip.clientWidth < strip.scrollWidth - 2;
        const hasPrev = strip.scrollLeft > 2;
        panel.classList.toggle('has-more', hasMore);
        panel.classList.toggle('has-prev', hasPrev);
    });
}
document.querySelectorAll('.agenda-organizer-strip').forEach(function (strip) {
    strip.addEventListener('scroll', updateAgendaStripIndicators, { passive: true });
});
window.addEventListener('resize', updateAgendaStripIndicators);
function showAgendaFilter(index) {
    agendaFilterIndex = index % agendaFilterIcons.length;
    if (!agendaFilterSwitch) return;
    // Toon het ingestelde icoon dat hoort bij het actieve filter.
    const citySwitchIcon = agendaFilterSwitch.querySelector('.agenda-filter-switch-icon--city');
    const organizerSwitchIcon = agendaFilterSwitch.querySelector('.agenda-filter-switch-icon--organizer');
    // De wisselknop toont het icoon en de naam van het filter waarnaar je kunt wisselen.
    if (citySwitchIcon) citySwitchIcon.hidden = agendaFilterIndex !== 0;
    if (organizerSwitchIcon) organizerSwitchIcon.hidden = agendaFilterIndex === 0;
    const switchLabel = agendaFilterSwitch.querySelector('.agenda-filter-switch-label');
    if (switchLabel) switchLabel.textContent = agendaFilterIndex === 0 ? 'STEDEN' : 'ORGANISATOREN';
    agendaFilterSwitch.setAttribute('aria-label', 'Filter: ' + agendaFilterLabels[agendaFilterIndex] + '. Klik om te wisselen');
    agendaFilterSwitch.setAttribute('title', 'Filter: ' + agendaFilterLabels[agendaFilterIndex] + ' — klik om te wisselen');
    document.querySelectorAll('[data-panel]').forEach(function (panel) {
        const active = panel.dataset.panel === ['organizer', 'city'][agendaFilterIndex];
        panel.hidden = !active;
        panel.classList.toggle('is-open', active);
        const select = panel.querySelector('select');
        if (select) select.disabled = !active;
    });
    updateAgendaStripIndicators();
}
if (agendaFilterSwitch) {
    agendaFilterSwitch.addEventListener('click', function () {
        showAgendaFilter(agendaFilterIndex + 1);
    });
    showAgendaFilter(agendaFilterIndex);
}
// Swipe over de evenementinformatie: rechts toont de uitleg, links verbergt ze.
function setEventDescription(info, show) {
    const description = info ? info.querySelector('[data-event-description]') : null;
    if (!description) return;
    description.classList.toggle('is-visible', show);
    description.setAttribute('aria-hidden', show ? 'false' : 'true');
    const hint = info.querySelector('[data-swipe-hint]');
    if (hint) hint.textContent = show
        ? '← Swipe voor minder info'
        : '→ Swipe voor meer info';
}
document.querySelectorAll('[data-event-info]').forEach(function (info) {
    if (!info.querySelector('[data-event-description]')) return;
    let start = null;
    info.addEventListener('touchstart', function (event) {
        if (!event.changedTouches.length) return;
        const touch = event.changedTouches[0];
        start = {x: touch.clientX, y: touch.clientY};
    }, {passive:true});
    info.addEventListener('touchend', function (event) {
        if (!start || !event.changedTouches.length) return;
        const touch = event.changedTouches[0];
        const dx = touch.clientX - start.x;
        const dy = touch.clientY - start.y;
        start = null;
        if (Math.abs(dx) < 45 || Math.abs(dx) < Math.abs(dy) * 1.25) return;
        setEventDescription(info, dx > 0);
    }, {passive:true});
    info.addEventListener('touchcancel', function () { start = null; }, {passive:true});
});
let eventInfoPointerStart = null;
document.addEventListener('pointerdown', function (event) {
    if (event.pointerType === 'touch') return;
    const info = event.target.closest('[data-event-info]');
    if (!info || !info.querySelector('[data-event-description]')) return;
    eventInfoPointerStart = {info:info, pointerId:event.pointerId, x:event.clientX, y:event.clientY};
}, {passive:true});
document.addEventListener('pointerup', function (event) {
    if (!eventInfoPointerStart || event.pointerId !== eventInfoPointerStart.pointerId) return;
    const start = eventInfoPointerStart;
    eventInfoPointerStart = null;
    const dx = event.clientX - start.x;
    const dy = event.clientY - start.y;
    if (Math.abs(dx) < 45 || Math.abs(dx) < Math.abs(dy) * 1.25) return;
    setEventDescription(start.info, dx > 0);
}, {passive:true});
document.addEventListener('pointercancel', function () { eventInfoPointerStart = null; }, {passive:true});

// Mobiele browsers blokkeren automatisch afspelen met geluid.
const agendaIsMobileVideo = window.matchMedia('(max-width: 700px)').matches;
let agendaVideosMuted = agendaIsMobileVideo ? true : window.DONDEBAILAR_AGENDA_CONFIG.soundMuted;
let activeAgendaVideo = null;
let agendaVideoIdleTimer = null;

// Houd het geheugengebruik laag: nooit meerdere video's tegelijk.
function playAgendaVideo(video) {
    // Start niet zolang de browser nog geen eerste videobeeld heeft ontvangen.
    // Haal voor de gekozen zichtbare video tijdelijk voldoende data op.
    if (video.readyState < HTMLMediaElement.HAVE_CURRENT_DATA) {
        video.setAttribute('preload', 'auto');
        if (video.dataset.waitingForFrame !== '1') {
            video.dataset.waitingForFrame = '1';
            video.addEventListener('loadeddata', function () {
                delete video.dataset.waitingForFrame;
                scheduleVisibleAgendaVideo();
            }, {once:true});
        }
        // load() opnieuw aanroepen nadat preload is verhoogd, zodat ook de
        // eerste zichtbare video daadwerkelijk een frame kan ophalen.
        video.load();
        return;
    }

    document.querySelectorAll('.agenda-card__video').forEach(function (otherVideo) {
        if (otherVideo !== video && !otherVideo.paused) otherVideo.pause();
    });
    activeAgendaVideo = video;
    video.muted = agendaVideosMuted;
    const playPromise = video.play();
    if (playPromise && typeof playPromise.catch === 'function') {
        playPromise.catch(function () {});
    }
}

// Start pas een video nadat het scrollen even gestopt is.
function scheduleVisibleAgendaVideo() {
    window.clearTimeout(agendaVideoIdleTimer);
    agendaVideoIdleTimer = window.setTimeout(function () {
        let bestVideo = null;
        let bestRatio = 0;
        document.querySelectorAll('.agenda-card__video').forEach(function (video) {
            const rect = video.getBoundingClientRect();
            const visibleWidth = Math.max(0, Math.min(rect.right, window.innerWidth) - Math.max(rect.left, 0));
            const visibleHeight = Math.max(0, Math.min(rect.bottom, window.innerHeight) - Math.max(rect.top, 0));
            const ratio = rect.width > 0 && rect.height > 0
                ? (visibleWidth * visibleHeight) / (rect.width * rect.height)
                : 0;
            if (ratio > bestRatio) {
                bestRatio = ratio;
                bestVideo = video;
            }
        });
        if (bestVideo && bestRatio >= 0.2) playAgendaVideo(bestVideo);
    }, 50);
}
window.addEventListener('scroll', scheduleVisibleAgendaVideo, {passive:true});
window.addEventListener('resize', scheduleVisibleAgendaVideo, {passive:true});
document.querySelectorAll('[data-gallery]').forEach(function (gallery) {
    const track = gallery.querySelector('[data-gallery-track]');
    const counter = gallery.querySelector('[data-gallery-count]');
    if (!track || !counter) return;
    const slides = Array.from(track.querySelectorAll('.agenda-card__media-slide'));
    const updateCounter = function () {
        if (!slides.length) return;
        const slideWidth = track.clientWidth || 1;
        const currentIndex = Math.min(slides.length, Math.max(1, Math.round(track.scrollLeft / slideWidth) + 1));
        counter.textContent = currentIndex + ' / ' + slides.length;

        // Stop video's zodra een andere foto, video of Reel actief wordt.
        slides.forEach(function (slide, index) {
            const isActive = index === currentIndex - 1;
            slide.querySelectorAll('video.agenda-card__video').forEach(function (video) {
                if (!isActive) {
                    video.pause();
                    return;
                }

                // Hervat de video wanneer deze galerij-slide weer zichtbaar is.
                const rect = video.getBoundingClientRect();
                const visibleWidth = Math.max(0, Math.min(rect.right, window.innerWidth) - Math.max(rect.left, 0));
                const visibleHeight = Math.max(0, Math.min(rect.bottom, window.innerHeight) - Math.max(rect.top, 0));
                const visibleRatio = rect.width > 0 && rect.height > 0
                    ? (visibleWidth * visibleHeight) / (rect.width * rect.height)
                    : 0;
                if (visibleRatio >= 0.2) {
                    playAgendaVideo(video);
                }
            });
        });
    };
    // Ga automatisch naar het volgende media-item zodra een video afgelopen is.
    slides.forEach(function (slide, index) {
        slide.querySelectorAll('video.agenda-card__video').forEach(function (video) {
            video.addEventListener('ended', function () {
                if (index >= slides.length - 1) return;
                track.scrollTo({
                    left: (index + 1) * track.clientWidth,
                    behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth'
                });
            });
        });
    });
    track.addEventListener('scroll', updateCounter, { passive: true });
    window.addEventListener('resize', updateCounter);
    updateCounter();
});
const messageModal = document.getElementById('agenda-message-modal');
if (messageModal) {
    const messageFrame = messageModal.querySelector('iframe');
    const closeMessageModal = function () {
        messageModal.hidden = true;
        messageFrame.src = 'about:blank';
        document.documentElement.style.overflow = '';
        document.body.style.overflow = '';
    };
    messageModal.querySelector('.agenda-message-modal__close').addEventListener('click', closeMessageModal);
    messageModal.addEventListener('click', function (event) {
        if (event.target === messageModal) closeMessageModal();
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !messageModal.hidden) closeMessageModal();
    });
    document.addEventListener('click', function (event) {
        const button = event.target.closest('[data-event-messages]');
        if (!button) return;
        event.preventDefault();
        const eventCard = button.closest('.agenda-card');
        const eventTitle = eventCard?.querySelector('.agenda-card__details h2 a')?.textContent.trim() || 'Evenement';
        const count = Number(button.querySelector('.agenda-message-count')?.textContent || 0);
        const titleText = eventTitle + ' · ' + count + ' ' + (count === 1 ? 'bericht' : 'berichten');
        const cardStyles = eventCard ? getComputedStyle(eventCard) : null;
        const dayColor = cardStyles ? cardStyles.getPropertyValue('--db-event-day-color').trim() : '';
        const dayTextColor = cardStyles ? cardStyles.getPropertyValue('--db-event-day-text-color').trim() : '';
        const chatPanel = messageModal.querySelector('.agenda-message-modal__panel');
        chatPanel.style.setProperty('--db-chat-day-color', dayColor || 'var(--db-primary,#E23A2C)');
        chatPanel.style.setProperty('--db-chat-text-color', dayTextColor || '#222222');
        messageFrame.addEventListener('load', function applyChatDayColors() {
            try {
                const chatDocument = messageFrame.contentDocument;
                if (!chatDocument) return;
                chatDocument.documentElement.style.setProperty('--db-chat-day-color', dayColor || 'var(--db-primary,#E23A2C)');
                chatDocument.documentElement.style.setProperty('--db-chat-text-color', dayTextColor || '#222222');
            } catch (error) {}
        }, {once:true});
        const modalTitle = document.getElementById('agenda-message-modal-title');
        if (modalTitle) modalTitle.textContent = titleText;
        messageFrame.title = titleText;
        messageFrame.src = button.dataset.url + '&popup=1';
        messageModal.hidden = false;
        document.documentElement.style.overflow = 'hidden';
        document.body.style.overflow = 'hidden';
    });
}
const attendanceDialog = document.createElement('div');
attendanceDialog.className = 'agenda-attendance-list';
attendanceDialog.hidden = true;
attendanceDialog.innerHTML = '<div class="agenda-attendance-list__panel" role="dialog" aria-modal="true" aria-labelledby="attendance-list-title"><button type="button" class="agenda-attendance-list__close" aria-label="Sluiten">×</button><h3 id="attendance-list-title">Wie komt dansen?</h3><p class="agenda-attendance-list__event-title"></p><div class="agenda-attendance-list__content">Laden…</div></div>';
document.body.appendChild(attendanceDialog);
attendanceDialog.addEventListener('click', function (event) {
    if (event.target === attendanceDialog || event.target.closest('.agenda-attendance-list__close')) attendanceDialog.hidden = true;
});
function escapeAttendanceText(value) {
    return String(value).replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char]));
}
let attendanceLongPressTimer = null;
let attendanceLongPressTriggered = false;
let attendancePointerButton = null;

document.addEventListener('pointerdown', function (event) {
    const button = event.target.closest('.agenda-attendance');
    if (!button) return;
    attendancePointerButton = button;
    attendanceLongPressTriggered = false;
    clearTimeout(attendanceLongPressTimer);
    attendanceLongPressTimer = window.setTimeout(function () {
        attendanceLongPressTriggered = true;
        const eventId = button.dataset.eventId;
        const eventCard = button.closest('.agenda-card');
        const eventTitle = button.dataset.eventTitle || eventCard?.querySelector('.agenda-card__details h2 a')?.textContent.trim() || '';
        const cardStyles = eventCard ? getComputedStyle(eventCard) : null;
        const dayColor = cardStyles ? cardStyles.getPropertyValue('--db-event-day-color').trim() : '';
        const dayTextColor = cardStyles ? cardStyles.getPropertyValue('--db-event-day-text-color').trim() : '';
        attendanceDialog.style.setProperty('--db-attendance-day-color', dayColor || 'var(--db-primary,#E23A2C)');
        attendanceDialog.style.setProperty('--db-attendance-day-text-color', dayTextColor || '#222222');
        attendanceDialog.querySelector('.agenda-attendance-list__event-title').textContent = eventTitle;
        fetch('/event-attendance.php?action=names&event_id=' + encodeURIComponent(eventId), {credentials:'same-origin'})
            .then(response => response.json()).then(data => {
                if (!data.ok) throw new Error(data.message || 'De deelnemerslijst kon niet worden geladen.');
                const content = attendanceDialog.querySelector('.agenda-attendance-list__content');
                const names = Array.isArray(data.names) ? data.names : [];
                content.innerHTML = names.length ? '<ul>' + names.map(name => '<li>' + escapeAttendanceText(name) + '</li>').join('') + '</ul>' : '<p>Er zijn nog geen ingelogde deelnemers.</p>';
                if (data.guests > 0) content.innerHTML += '<p>' + data.guests + ' anonieme deelnemer' + (data.guests === 1 ? '' : 's') + '</p>';
                attendanceDialog.hidden = false;
            }).catch(error => {
                attendanceDialog.querySelector('.agenda-attendance-list__content').textContent = error.message || 'De deelnemerslijst kon niet worden geladen.';
                attendanceDialog.hidden = false;
            });
    }, 650);
});

function cancelAttendanceLongPress() {
    clearTimeout(attendanceLongPressTimer);
    attendanceLongPressTimer = null;
    attendancePointerButton = null;
}
['pointerup','pointercancel','pointerleave'].forEach(type => {
    document.addEventListener(type, function (event) {
        if (attendancePointerButton && (event.target === attendancePointerButton || attendancePointerButton.contains(event.target))) {
            cancelAttendanceLongPress();
        }
    }, true);
});
document.addEventListener('contextmenu', function (event) {
    if (event.target.closest('.agenda-attendance')) event.preventDefault();
});

document.addEventListener('click', function (event) {
    const button = event.target.closest('.agenda-attendance');
    if (!button) return;
    event.preventDefault();
    if (attendanceLongPressTriggered) {
        attendanceLongPressTriggered = false;
        return;
    }
    if (button.dataset.busy === '1') return;
    button.dataset.busy = '1';
    const eventId = button.dataset.eventId;
    fetch('/event-attendance.php', {
        method:'POST', credentials:'same-origin',
        headers:{'Content-Type':'application/json','X-CSRF-Token':window.DONDEBAILAR_AGENDA_CONFIG.csrfToken},
        body:JSON.stringify({event_id:Number(eventId)})
    }).then(async response => {
        const data = await response.json();
        if (!response.ok || !data.ok) throw new Error(data.message || 'Je keuze kon niet worden opgeslagen.');
        button.querySelector('b').textContent = String(data.count);
        button.classList.toggle('is-attending', data.attending);
        button.setAttribute('aria-pressed', data.attending ? 'true' : 'false');
    }).catch(error => {
        window.alert(error.message || 'Je keuze kon niet worden opgeslagen. Probeer opnieuw.');
    }).finally(() => {
        button.dataset.busy = '0';
    });
});

// Laad uploadmedia per evenement pas wanneer de evenementkaart in de buurt van het scherm komt.
// Zo worden niet alle mediabestanden van alle evenementen tegelijk aangevraagd.
function loadAgendaCardMedia(card) {
    card.querySelectorAll('[data-media-src]').forEach(function (media) {
        if (media.hasAttribute('src')) return;
        const source = media.getAttribute('data-media-src');
        if (!source) return;
        media.setAttribute('src', source);
        media.removeAttribute('data-media-src');
        if (media.tagName === 'VIDEO') {
            if (media.dataset.initialPriority !== '1') {
                media.setAttribute('preload', 'none');
            }
            // Forceer de browser om de nieuwe videobron te openen.
            media.load();
            media.addEventListener('loadeddata', scheduleVisibleAgendaVideo, {once:true});
        }
    });
}
const agendaMediaCardObserver = new IntersectionObserver(function (entries) {
    entries.forEach(function (entry) {
        if (!entry.isIntersecting) return;
        loadAgendaCardMedia(entry.target);
        agendaMediaCardObserver.unobserve(entry.target);
    });
}, {rootMargin:'250px 0px', threshold:0});
document.querySelectorAll('.agenda-feed .agenda-card').forEach(function (card) {
    agendaMediaCardObserver.observe(card);
});

// Pauzeer video's zodra ze niet meer voldoende zichtbaar zijn.
// Het starten gebeurt pas nadat het scrollen is gestopt.
const agendaVideoObserver = new IntersectionObserver(function (entries) {
    entries.forEach(function (entry) {
        const video = entry.target;
        if (entry.isIntersecting) {
            // Laad alvast de metadata van video's die op het scherm komen.
            // Zo kan de eerste frame sneller verschijnen zonder alle video's vooraf te downloaden.
            video.setAttribute('preload', 'metadata');
        }
        if (!entry.isIntersecting || entry.intersectionRatio < 0.6) {
            video.pause();
            if (activeAgendaVideo === video) activeAgendaVideo = null;
        }
    });
}, {threshold:[0, 0.6]});
document.querySelectorAll('.agenda-card__video').forEach(function (video) {
    video.muted = agendaVideosMuted;
    video.removeAttribute('autoplay');
    // Haal metadata van de eerste zichtbare video meteen op; overige video's blijven uitgesteld.
    if (video.dataset.initialPriority !== '1') {
        video.setAttribute('preload', 'none');
    }
    video.setAttribute('muted', '');
    agendaVideoObserver.observe(video);
});
scheduleVisibleAgendaVideo();

// Geluid van geüploade video's centraal aan- en uitzetten.
// De footer wordt pas na dit script geladen; daarom gebruiken we event delegation.
function applyAgendaVideoSound(button) {
    document.querySelectorAll('.agenda-card__video').forEach(function (video) {
        video.muted = agendaVideosMuted;
    });
    button.setAttribute('aria-pressed', agendaVideosMuted ? 'true' : 'false');
    button.setAttribute('aria-label', agendaVideosMuted ? 'Geluid van video\'s inschakelen' : 'Geluid van video\'s uitschakelen');
    button.setAttribute('title', agendaVideosMuted ? 'Geluid van video\'s inschakelen' : 'Geluid van video\'s uitschakelen');
    button.innerHTML = agendaVideosMuted
        ? '<svg aria-hidden="true" viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 5 6 9H2v6h4l5 4z"></path><path d="m3 3 18 18"></path></svg>'
        : '<svg aria-hidden="true" viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 5 6 9H2v6h4l5 4z"></path><path d="M15.5 8.5a5 5 0 0 1 0 7"></path><path d="M19 5a10 10 0 0 1 0 14"></path></svg>';
}
// De footerknop staat pas na dit script in de HTML. Initialiseer hem zodra de pagina volledig is opgebouwd.
document.addEventListener('DOMContentLoaded', function () {
    const soundButton = document.getElementById('agenda-toggle-sound');
    if (soundButton) applyAgendaVideoSound(soundButton);
});

document.addEventListener('click', function (event) {
    const target = event.target instanceof Element ? event.target : null;
    const soundButton = target ? target.closest('#agenda-toggle-sound') : null;
    if (!soundButton) return;

    // De geluidsknop mag nooit een filter-, navigatie- of andere klikactie activeren.
    event.preventDefault();
    event.stopPropagation();
    event.stopImmediatePropagation();

    agendaVideosMuted = !agendaVideosMuted;
    applyAgendaVideoSound(soundButton);
}, true);


// Bewaar de huidige regio, filter en geluidskeuze bij het ingelogde account.
// SAVE krijgt voorrang op alle andere klikacties: deze klik mag geen
// filter-, navigatie- of reset-handler van de agenda activeren.
document.addEventListener('click', function (event) {
    const target = event.target instanceof Element ? event.target : null;
    const saveButton = target ? target.closest('#agenda-save-preferences') : null;
    if (!saveButton) return;
    event.preventDefault();
    event.stopPropagation();
    event.stopImmediatePropagation();
    if (saveButton.dataset.busy === '1') return;
    saveButton.dataset.busy = '1';
    saveButton.setAttribute('aria-busy', 'true');
    const activeFilter = agendaFilterIndex === 1 ? 'city' : 'organizer';
    const activePanel = document.querySelector('[data-panel="' + activeFilter + '"]');
    const selectedItem = activePanel ? activePanel.querySelector('.agenda-organizer-item.is-selected') : null;
    const selectedUrl = selectedItem ? new URL(selectedItem.href, window.location.origin) : null;
    const payload = {
        csrf_token: window.DONDEBAILAR_AGENDA_CONFIG.csrfToken,
        region_id: window.DONDEBAILAR_AGENDA_CONFIG.regionId,
        filter: activeFilter,
        organizer_id: activeFilter === 'organizer' && selectedItem
            ? Number(selectedItem.dataset.organizerId || 0) : 0,
        city: activeFilter === 'city' && selectedItem ? selectedItem.dataset.city || '' : '',
        sound_muted: agendaVideosMuted
    };
    fetch('/save-agenda-preferences.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(payload)
    }).then(async function (response) {
        const data = await response.json();
        if (!response.ok || !data.ok) throw new Error(data.message || 'Opslaan is niet gelukt.');
        saveButton.classList.add('is-saved');
        saveButton.setAttribute('title', 'Instellingen bewaard');
        saveButton.setAttribute('aria-label', 'Instellingen bewaard');
    }).catch(function (error) {
        window.alert(error.message || 'Je instellingen konden niet worden bewaard.');
    }).finally(function () {
        saveButton.dataset.busy = '0';
        saveButton.removeAttribute('aria-busy');
    });
}, true);

document.addEventListener('click', function (event) {
    const backToTopButton = event.target.closest('#agenda-back-to-top');
    if (!backToTopButton) return;
    event.preventDefault();
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    window.scrollTo({top:0, behavior:reduceMotion ? 'auto' : 'smooth'});
});

// Deelvenster voor evenementen: WhatsApp, Facebook, kopiëren en de native deeloptie.
const eventShareDialog = document.createElement('div');
eventShareDialog.className = 'agenda-share-dialog';
eventShareDialog.hidden = true;
eventShareDialog.innerHTML = '<div class="agenda-share-dialog__panel" role="dialog" aria-modal="true" aria-labelledby="agenda-share-title"><button type="button" class="agenda-share-dialog__close" aria-label="Sluiten">×</button><h3 id="agenda-share-title">Deel dit evenement</h3><p class="agenda-share-dialog__event"></p><div class="agenda-share-dialog__actions"><a class="agenda-share-option agenda-share-option--whatsapp" data-share-whatsapp target="_blank" rel="noopener noreferrer"><span aria-hidden="true">◉</span> WhatsApp</a><a class="agenda-share-option agenda-share-option--facebook" data-share-facebook target="_blank" rel="noopener noreferrer"><span aria-hidden="true">f</span> Facebook</a><button type="button" class="agenda-share-option" data-share-copy><span aria-hidden="true">▢</span> Link kopiëren</button><button type="button" class="agenda-share-option" data-share-native><span aria-hidden="true">↗</span> Andere apps</button></div><p class="agenda-share-dialog__status" role="status" aria-live="polite"></p></div>';
document.body.appendChild(eventShareDialog);
let activeShareData = null;
function closeEventShareDialog() {
    eventShareDialog.hidden = true;
    activeShareData = null;
}
async function copyEventShareLink() {
    if (!activeShareData) return;
    const status = eventShareDialog.querySelector('.agenda-share-dialog__status');
    try {
        if (navigator.clipboard && window.isSecureContext) {
            await navigator.clipboard.writeText(activeShareData.url);
        } else {
            const temporaryInput = document.createElement('textarea');
            temporaryInput.value = activeShareData.url;
            temporaryInput.setAttribute('readonly', '');
            temporaryInput.style.position = 'fixed';
            temporaryInput.style.opacity = '0';
            document.body.appendChild(temporaryInput);
            temporaryInput.select();
            const copied = document.execCommand('copy');
            temporaryInput.remove();
            if (!copied) throw new Error('copy_failed');
        }
        status.textContent = 'Link gekopieerd. Je kunt hem nu in een bericht plakken.';
    } catch (error) {
        status.textContent = 'Kopiëren lukte niet. Selecteer en kopieer de link: ' + activeShareData.url;
    }
}
eventShareDialog.addEventListener('click', function (event) {
    if (event.target === eventShareDialog || event.target.closest('.agenda-share-dialog__close')) {
        closeEventShareDialog();
        return;
    }
    if (event.target.closest('[data-share-copy]')) {
        copyEventShareLink();
        return;
    }
    if (event.target.closest('[data-share-native]')) {
        if (!activeShareData) return;
        const shareData = {title:activeShareData.title, text:activeShareData.title, url:activeShareData.url};
        if (navigator.share && (!navigator.canShare || navigator.canShare(shareData))) {
            navigator.share(shareData).then(closeEventShareDialog).catch(function (error) {
                if (error.name !== 'AbortError') eventShareDialog.querySelector('.agenda-share-dialog__status').textContent = 'Delen is niet gelukt. Kies WhatsApp of kopieer de link.';
            });
        } else {
            copyEventShareLink();
        }
    }
});
document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' && !eventShareDialog.hidden) closeEventShareDialog();
});
document.addEventListener('click', function (event) {
    const shareButton = event.target.closest('[data-share-url]');
    if (!shareButton) return;
    event.preventDefault();
    const title = shareButton.dataset.shareTitle || 'Evenement';
    const url = shareButton.dataset.shareUrl || window.location.href;
    activeShareData = {title:title, url:url};
    const message = title + ' — ' + url;
    eventShareDialog.querySelector('.agenda-share-dialog__event').textContent = title;
    eventShareDialog.querySelector('[data-share-whatsapp]').href = 'https://wa.me/?text=' + encodeURIComponent(message);
    eventShareDialog.querySelector('[data-share-facebook]').href = 'https://www.facebook.com/sharer/sharer.php?u=' + encodeURIComponent(url);
    eventShareDialog.querySelector('[data-share-native]').hidden = !(navigator.share && (!navigator.canShare || navigator.canShare({title:title,text:title,url:url})));
    eventShareDialog.querySelector('.agenda-share-dialog__status').textContent = '';
    eventShareDialog.hidden = false;
});
