<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/Security.php';
require_once dirname(__DIR__) . '/app/Authorization.php';

$user = requireLogin();
$db = Database::connection();
$userId = (int) $user['id'];

// Controleer eerst of de database-upgrade de meldingtabel heeft aangemaakt.
$tableCheck = $db->prepare(
    "SELECT COUNT(*) FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_notifications'"
);
$tableCheck->execute();
$notificationsTableMissing = (int) $tableCheck->fetchColumn() === 0;
$notifications = [];

if (!$notificationsTableMissing) {
    // Zodra de gebruiker het meldingenoverzicht opent, zijn de meldingen bekeken.
    $markRead = $db->prepare(
        "UPDATE user_notifications SET is_read = 1 WHERE user_id = :user_id AND is_read = 0"
    );
    $markRead->execute(['user_id' => $userId]);

    $query = $db->prepare(
        "SELECT n.id, n.event_id, n.message_id, n.created_at,
                actor.display_name AS actor_name,
                e.title AS event_title,
                m.message
         FROM user_notifications n
         INNER JOIN users actor ON actor.id = n.actor_user_id
         INNER JOIN events e ON e.id = n.event_id
         INNER JOIN event_messages m ON m.id = n.message_id
         WHERE n.user_id = :user_id
         ORDER BY n.created_at DESC, n.id DESC
         LIMIT 100"
    );
    $query->execute(['user_id' => $userId]);
    $notifications = $query->fetchAll();
}

$pageTitle = 'Meldingen — DONDEBAILAR';
$pageRobots = 'noindex, nofollow';
$region = null;
require dirname(__DIR__) . '/app/views/header.php';
$escape = static fn($value): string => escapeHtml((string) $value);
?>
<section class="admin-page notifications-page">
    <div class="admin-heading">
        <div>
            <p class="eyebrow">DONDEBAILAR</p>
            <h1>Meldingen</h1>
        </div>
    </div>

    <section class="admin-section">
        <?php if ($notificationsTableMissing): ?>
            <p>De meldingentabel ontbreekt nog in de database. Ga naar Platformbeheer en klik op UPDATE om de database bij te werken.</p>
        <?php elseif ($notifications === []): ?>
            <p>Je hebt nog geen meldingen.</p>
        <?php else: ?>
            <div class="notification-list">
                <?php foreach ($notifications as $notification): ?>
                    <?php
                    $messageText = trim((string) $notification['message']);
                    $messageText = mb_strimwidth($messageText, 0, 180, '…', 'UTF-8');
                    $target = '/event-messages.php?id=' . (int) $notification['event_id']
                        . '&popup=1#message-' . (int) $notification['message_id'];
                    ?>
                    <a class="notification-item" href="<?= $escape($target) ?>" data-notification-chat="<?= $escape('/event-messages.php?id=' . (int) $notification['event_id'] . '&popup=1#message-' . (int) $notification['message_id']) ?>" data-event-title="<?= $escape($notification['event_title']) ?>">
                        <span class="notification-item__icon" aria-hidden="true">＠</span>
                        <span class="notification-item__body">
                            <strong><?= $escape($notification['actor_name'] ?: 'Een gebruiker') ?> heeft je genoemd</strong>
                            <span class="notification-item__event"><?= $escape($notification['event_title']) ?></span>
                            <span class="notification-item__message"><?= $escape($messageText) ?></span>
                            <time><?= $escape(date('d-m-Y H:i', strtotime((string) $notification['created_at']))) ?></time>
                        </span>
                        <span class="notification-item__arrow" aria-hidden="true">›</span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
    <div class="agenda-message-modal notification-chat-modal" id="notification-chat-modal" hidden>
        <div class="agenda-message-modal__panel" role="dialog" aria-modal="true" aria-labelledby="notification-chat-modal-title">
            <div class="agenda-message-modal__top">
                <span id="notification-chat-modal-title" class="notification-chat-modal__title">Berichten bij evenement</span>
                <button type="button" class="agenda-message-modal__close" aria-label="Sluiten">×</button>
            </div>
            <iframe title="Berichten bij evenement" src="about:blank" scrolling="no"></iframe>
        </div>
    </div>
</section>

<style>
.notification-chat-modal .agenda-message-modal__top{left:12px;right:8px;justify-content:space-between;align-items:center;min-height:38px}
.notification-chat-modal__title{max-width:calc(100% - 48px);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:13px;font-weight:700;color:var(--db-text,#222)}
.notification-chat-modal .agenda-message-modal__panel iframe{padding-top:0}
.notifications-page .notification-list{display:flex;flex-direction:column;gap:0}
.notifications-page .notification-item{display:flex;align-items:center;gap:13px;padding:14px 4px;border-bottom:1px solid var(--db-border);color:var(--db-text);text-decoration:none}
.notifications-page .notification-item:first-child{border-top:1px solid var(--db-border)}
.notifications-page .notification-item:hover{background:var(--db-surface,#f7f8fa)}
.notification-item__icon{display:grid;place-items:center;width:40px;height:40px;flex:none;border-radius:50%;background:#eaf2fb;color:var(--db-primary);font-size:23px;font-weight:800}
.notification-item__body{display:flex;flex:1;min-width:0;flex-direction:column;gap:3px}
.notification-item__body strong{font-size:14px}
.notification-item__event{font-size:12px;font-weight:700;color:var(--db-primary)}
.notification-item__message{font-size:13px;color:var(--db-muted);overflow-wrap:anywhere}
.notification-item__body time{font-size:11px;color:var(--db-muted)}
.notification-item__arrow{font-size:25px;color:var(--db-muted)}
</style>

<script>
(function () {
    const modal = document.getElementById('notification-chat-modal');
    if (!modal) return;
    const frame = modal.querySelector('iframe');
    const title = document.getElementById('notification-chat-modal-title');
    function closeModal() {
        modal.hidden = true;
        frame.src = 'about:blank';
        document.documentElement.style.overflow = '';
        document.body.style.overflow = '';
    }
    modal.querySelector('.agenda-message-modal__close').addEventListener('click', closeModal);
    modal.addEventListener('click', function (event) {
        if (event.target === modal) closeModal();
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !modal.hidden) closeModal();
    });
    document.addEventListener('click', function (event) {
        const link = event.target.closest('[data-notification-chat]');
        if (!link) return;
        event.preventDefault();
        title.textContent = link.dataset.eventTitle || 'Berichten bij evenement';
        frame.title = title.textContent;
        frame.src = link.dataset.notificationChat;
        modal.hidden = false;
        document.documentElement.style.overflow = 'hidden';
        document.body.style.overflow = 'hidden';
    });
})();
</script>

<?php require dirname(__DIR__) . '/app/views/footer.php'; ?>
