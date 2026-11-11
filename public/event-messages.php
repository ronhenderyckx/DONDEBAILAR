<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/Security.php';
require_once dirname(__DIR__) . '/app/Authorization.php';
require_once dirname(__DIR__) . '/app/Events.php';

$db = Database::connection();
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id || $id < 1) {
    http_response_code(404);
    exit('Evenement niet gevonden.');
}
$popupMode = isset($_GET['popup']) && $_GET['popup'] === '1';
$messagePageUrl = '/event-messages.php?id=' . $id . ($popupMode ? '&popup=1' : '');

$countQuery = $db->prepare(
    "SELECT COUNT(*) FROM event_messages WHERE event_id = :event_id AND is_deleted = 0"
);
$countQuery->execute(['event_id' => $id]);
$messageCount = (int) $countQuery->fetchColumn();


$statement = $db->prepare(
    "SELECT e.id, e.title, e.region_id, e.status, e.created_by, r.name AS region_name,
            r.logo_path, r.timezone
     FROM events e INNER JOIN regions r ON r.id = e.region_id
     WHERE e.id = :id AND e.status = 'published' LIMIT 1"
);
$statement->execute(['id' => $id]);
$event = $statement->fetch();
if (!$event) {
    http_response_code(404);
    exit('Evenement niet gevonden.');
}

$user = currentUser();
$canModerate = $user !== null && eventCanEdit($db, $event, $user);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = requireLogin();
    $canModerate = eventCanEdit($db, $event, $user);

    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'De beveiligingscontrole is verlopen. Vernieuw de pagina.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        $message = trim((string) ($_POST['message'] ?? ''));
        $messageId = filter_var($_POST['message_id'] ?? null, FILTER_VALIDATE_INT) ?: 0;

        try {
            if ($action === 'post' || $action === 'reply') {
                if ($message === '' || mb_strlen($message) > 2000) {
                    throw new RuntimeException('Een bericht moet tussen 1 en 2000 tekens bevatten.');
                }
                $parentId = null;
                if ($action === 'reply') {
                    $parentId = filter_var($_POST['parent_id'] ?? null, FILTER_VALIDATE_INT);
                    if (!$parentId || $parentId < 1) {
                        throw new RuntimeException('Het bericht waarop je wilt reageren bestaat niet.');
                    }
                    $parent = $db->prepare(
                        "SELECT id FROM event_messages
                         WHERE id = :id AND event_id = :event_id
                           AND is_deleted = 0 LIMIT 1"
                    );
                    $parent->execute(['id' => $parentId, 'event_id' => $id]);
                    if (!$parent->fetchColumn()) {
                        throw new RuntimeException('Je kunt niet op dit bericht reageren.');
                    }
                }
                $insert = $db->prepare(
                    "INSERT INTO event_messages (event_id, user_id, parent_id, message)
                     VALUES (:event_id, :user_id, :parent_id, :message)"
                );
                $insert->execute([
                    'event_id' => $id,
                    'user_id' => (int) $user['id'],
                    'parent_id' => $parentId,
                    'message' => $message,
                ]);
                header('Location: ' . $messagePageUrl . '&sent=1#messages');
                exit;
            }

            if ($action === 'edit') {
                if ($messageId < 1 || $message === '' || mb_strlen($message) > 2000) {
                    throw new RuntimeException('Controleer het bericht (maximaal 2000 tekens).');
                }
                $owner = $db->prepare(
                    "SELECT user_id FROM event_messages
                     WHERE id = :id AND event_id = :event_id AND is_deleted = 0"
                );
                $owner->execute(['id' => $messageId, 'event_id' => $id]);
                $messageOwner = $owner->fetchColumn();
                if ($messageOwner === false || ((int) $messageOwner !== (int) $user['id'] && !$canModerate)) {
                    throw new RuntimeException('Je mag dit bericht niet wijzigen.');
                }
                $update = $db->prepare(
                    "UPDATE event_messages SET message = :message
                     WHERE id = :id AND event_id = :event_id"
                );
                $update->execute(['message' => $message, 'id' => $messageId, 'event_id' => $id]);
                header('Location: ' . $messagePageUrl . '&updated=1#message-' . $messageId);
                exit;
            }

            if ($action === 'delete') {
                if ($messageId < 1) {
                    throw new RuntimeException('Bericht niet gevonden.');
                }
                $owner = $db->prepare(
                    "SELECT user_id FROM event_messages
                     WHERE id = :id AND event_id = :event_id AND is_deleted = 0"
                );
                $owner->execute(['id' => $messageId, 'event_id' => $id]);
                $messageOwner = $owner->fetchColumn();
                if ($messageOwner === false || ((int) $messageOwner !== (int) $user['id'] && !$canModerate)) {
                    throw new RuntimeException('Je mag dit bericht niet verwijderen.');
                }
                $delete = $db->prepare(
                    "UPDATE event_messages SET is_deleted = 1, deleted_by = :deleted_by
                     WHERE id = :id AND event_id = :event_id"
                );
                $delete->execute(['deleted_by' => (int) $user['id'], 'id' => $messageId, 'event_id' => $id]);
                header('Location: ' . $messagePageUrl . '&deleted=1#messages');
                exit;
            }

            throw new RuntimeException('Onbekende actie.');
        } catch (Throwable $exception) {
            $errors[] = $exception instanceof RuntimeException
                ? $exception->getMessage()
                : 'Het bericht kon niet worden verwerkt.';
        }
    }
}

$list = $db->prepare(
    "SELECT m.id, m.user_id, m.parent_id, m.message, m.is_deleted,
            m.created_at, m.updated_at, u.display_name
     FROM event_messages m
     LEFT JOIN users u ON u.id = m.user_id
     WHERE m.event_id = :event_id
     ORDER BY m.created_at ASC, m.id ASC"
);
$list->execute(['event_id' => $id]);
$allMessages = $list->fetchAll();
$topLevel = [];
$replies = [];
foreach ($allMessages as $item) {
    if ($item['parent_id'] === null) {
        $topLevel[] = $item;
    } else {
        $replies[(int) $item['parent_id']][] = $item;
    }
}

$pageTitle = 'Berichten van evenement — ' . (string) $event['title'] . ' — DONDEBAILAR';
$pageRobots = 'noindex, nofollow';
$region = [
    'id' => (int) $event['region_id'],
    'name' => (string) $event['region_name'],
    'logo_path' => $event['logo_path'],
];
require dirname(__DIR__) . '/app/views/header.php';
$escape = static fn($value): string => escapeHtml((string) $value);
?>
<section class="event-chat<?= $popupMode ? ' event-chat--popup' : '' ?>" id="messages">
    <div class="event-chat__heading" style="display:block!important;width:100%;height:70px;min-height:70px;box-sizing:border-box;overflow:hidden;padding:0 12px;background:var(--db-primary,#1456a0);color:#fff">
        <strong style="display:block!important;margin:0;padding:0;line-height:70px;color:#fff;font-size:16px;white-space:nowrap!important;overflow:hidden;text-overflow:ellipsis">
            <svg aria-hidden="true" viewBox="0 0 24 24" width="25" height="25" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:middle;margin-right:8px;color:#fff"><path d="M21 11.5a8.4 8.4 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.4 8.4 0 0 1-3.8-.9L3 21l1.9-5.7a8.4 8.4 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.4 8.4 0 0 1 3.8-.9h.5a8.5 8.5 0 0 1 8 8v.5Z"></path></svg><?= $escape($event['title']) ?> <span style="display:inline;font-size:14px;font-weight:600;white-space:nowrap">· <?= $messageCount ?> <?= $messageCount === 1 ? 'bericht' : 'berichten' ?></span>
        </strong>
    </div>

    <?php if ($errors !== []): ?>
        <div class="event-chat__notice event-chat__notice--error"><?php foreach ($errors as $error): ?><?= $escape($error) ?><?php endforeach; ?></div>
    <?php elseif (isset($_GET['sent'])): ?>
        <div class="event-chat__notice">Bericht geplaatst</div>
    <?php elseif (isset($_GET['updated'])): ?>
        <div class="event-chat__notice">Bericht aangepast</div>
    <?php elseif (isset($_GET['deleted'])): ?>
        <div class="event-chat__notice">Bericht verwijderd</div>
    <?php endif; ?>

    <div class="event-chat__messages">
        <?php if ($topLevel === []): ?>
            <p class="event-chat__empty">Nog geen berichten. Begin het gesprek!</p>
        <?php endif; ?>
        <?php foreach ($topLevel as $item): ?>
            <?php
            $messageId = (int) $item['id'];
            $isOwner = $user !== null && (int) $item['user_id'] === (int) $user['id'];
            $canChange = $user !== null && ($isOwner || $canModerate);
            ?>
            <article class="chat-message" id="message-<?= $messageId ?>">
                <div class="chat-message__bubble">
                    <div class="chat-message__meta"><strong><?= $escape($item['display_name'] ?: 'Gebruiker') ?></strong><time><?= $escape(date('d-m H:i', strtotime((string) $item['created_at']))) ?></time></div>
                    <?php if ((int) $item['is_deleted'] === 1): ?>
                        <p class="chat-message__deleted">Bericht verwijderd</p>
                    <?php else: ?>
                        <p><?= nl2br($escape($item['message'])) ?></p>
                        <?php if (!empty($item['updated_at']) && $item['updated_at'] !== $item['created_at']): ?><small>bewerkt</small><?php endif; ?>
                        <?php if ($user !== null): ?>
                            <button type="button" class="chat-message__reply-button" data-reply-toggle aria-expanded="false">Reageren</button>
                            <form method="post" class="chat-message__reply-form" hidden>
                                <input type="hidden" name="csrf_token" value="<?= $escape(csrfToken()) ?>">
                                <input type="hidden" name="action" value="reply">
                                <input type="hidden" name="parent_id" value="<?= $messageId ?>">
                                <textarea name="message" rows="2" maxlength="2000" required placeholder="Schrijf een reactie..."></textarea>
                                <button type="submit">Versturen</button>
                            </form>
                        <?php endif; ?>
                        <?php if ($canChange): ?>
                            <details class="chat-message__manage"><summary>⋯</summary>
                                <form method="post">
                                    <input type="hidden" name="csrf_token" value="<?= $escape(csrfToken()) ?>">
                                    <input type="hidden" name="action" value="edit">
                                    <input type="hidden" name="message_id" value="<?= $messageId ?>">
                                    <textarea name="message" rows="2" maxlength="2000" required><?= $escape($item['message']) ?></textarea>
                                    <button type="submit">Opslaan</button>
                                </form>
                                <form method="post" onsubmit="return confirm('Bericht verwijderen?')">
                                    <input type="hidden" name="csrf_token" value="<?= $escape(csrfToken()) ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="message_id" value="<?= $messageId ?>">
                                    <button type="submit">Verwijderen</button>
                                </form>
                            </details>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
                <?php foreach ($replies[$messageId] ?? [] as $reply): ?>
                    <?php $replyId = (int) $reply['id']; ?>
                    <div class="chat-message__reply" id="message-<?= $replyId ?>">
                        <div><strong><?= $escape($reply['display_name'] ?: 'Gebruiker') ?></strong>
                        <span><?= (int) $reply['is_deleted'] === 1 ? 'Reactie verwijderd' : nl2br($escape($reply['message'])) ?></span></div>
                        <time><?= $escape(date('d-m H:i', strtotime((string) $reply['created_at']))) ?></time>
                        <?php if ($user !== null && (int) $reply['is_deleted'] !== 1): ?>
                            <button type="button" class="chat-message__reply-button" data-reply-toggle aria-expanded="false">Reageren</button>
                            <form method="post" class="chat-message__reply-form" hidden>
                                <input type="hidden" name="csrf_token" value="<?= $escape(csrfToken()) ?>">
                                <input type="hidden" name="action" value="reply">
                                <input type="hidden" name="parent_id" value="<?= $replyId ?>">
                                <textarea name="message" rows="2" maxlength="2000" required placeholder="Reageer op deze reactie..."></textarea>
                                <button type="submit">Versturen</button>
                            </form>
                        <?php endif; ?>
                        <?php foreach ($replies[$replyId] ?? [] as $nestedReply): ?>
                            <div class="chat-message__nested-reply">
                                <strong><?= $escape($nestedReply['display_name'] ?: 'Gebruiker') ?></strong>
                                <span><?= (int) $nestedReply['is_deleted'] === 1 ? 'Reactie verwijderd' : nl2br($escape($nestedReply['message'])) ?></span>
                                <time><?= $escape(date('d-m H:i', strtotime((string) $nestedReply['created_at']))) ?></time>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </article>
        <?php endforeach; ?>
    </div>

    <div class="event-chat__composer">
        <?php if ($user !== null): ?>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= $escape(csrfToken()) ?>">
                <input type="hidden" name="action" value="post">
                <textarea name="message" rows="2" maxlength="2000" required placeholder="Schrijf een bericht..."></textarea>
                <button type="submit" aria-label="Bericht versturen" title="Versturen">➤</button>
            </form>
        <?php else: ?>
            <a href="/login.php?return=<?= rawurlencode($messagePageUrl) ?>">Log in om een bericht te schrijven</a>
        <?php endif; ?>
    </div>
</section>
<style>
html,body{height:100%;margin:0!important;padding:0!important;overflow:hidden!important}
body:has(.event-chat--popup) .site-header,body:has(.event-chat--popup) footer{display:none!important}
body:has(.event-chat--popup) .site-main{height:100%;max-width:none!important;margin:0!important;padding:0!important;overflow:hidden}
.event-chat{height:100%;min-height:0;display:flex;flex-direction:column;background:#fff;color:#222;font:14px/1.45 system-ui,sans-serif}
.event-chat__heading{display:flex!important;flex-direction:row!important;flex-wrap:nowrap!important;align-items:center!important;gap:8px;}
*{scrollbar-width:none;-ms-overflow-style:none}*::-webkit-scrollbar{display:none;width:0;height:0}.event-chat__messages{flex:1;min-height:0;overflow-y:auto;scrollbar-width:none;-ms-overflow-style:none;padding:12px;display:flex;flex-direction:column;gap:10px;overscroll-behavior:contain}.event-chat__messages::-webkit-scrollbar{display:none;width:0;height:0}
.event-chat__empty{margin:auto;color:#888;text-align:center}
.chat-message{max-width:88%;align-self:flex-start}.chat-message__bubble{position:relative;padding:9px 12px;border-radius:14px 14px 14px 4px;background:#f0f2f5;overflow-wrap:anywhere}
.chat-message__meta{display:flex;gap:10px;align-items:center;margin-bottom:4px;font-size:11px;color:#777}.chat-message__meta strong{color:#444;font-size:12px}.chat-message__meta time{white-space:nowrap}
.chat-message__bubble p{margin:0;white-space:normal}.chat-message__bubble small{display:block;color:#888;font-size:10px}
.chat-message__deleted{color:#888;font-style:italic}
.chat-message__reply-button{display:inline-block;margin-top:6px;padding:0;border:0;background:none;color:var(--db-primary,#2878c8);font:600 11px system-ui,sans-serif;cursor:pointer}
.chat-message__reply-form{display:flex;gap:6px;align-items:flex-end;margin-top:7px}
.chat-message__reply-form[hidden]{display:none}
.chat-message__reply-form textarea{flex:1;min-width:0;resize:vertical;border:1px solid #ddd;border-radius:10px;padding:7px 9px;font:12px/1.4 system-ui,sans-serif}
.chat-message__reply-form button{border:0;border-radius:9px;padding:8px 10px;background:var(--db-primary,#2878c8);color:#fff;font-size:11px;font-weight:600;cursor:pointer}
.chat-message__reply{margin:5px 0 0 16px;padding:7px 10px;border-left:2px solid #ddd;color:#555;font-size:12px;overflow-wrap:anywhere}
.chat-message__reply strong{margin-right:6px}.chat-message__reply time{display:block;color:#999;font-size:10px}
.chat-message__nested-reply{margin:6px 0 0 14px;padding:6px 9px;border-left:2px solid #c9d3df;background:#f8f9fb;color:#555;font-size:11px;overflow-wrap:anywhere}
.chat-message__nested-reply strong{margin-right:5px}.chat-message__nested-reply time{display:block;color:#999;font-size:10px}
.chat-message__manage{margin-top:4px;font-size:11px}.chat-message__manage summary{cursor:pointer;color:#777}.chat-message__manage form{display:flex;gap:5px;margin-top:5px}.chat-message__manage textarea{min-width:0;width:100%;font:inherit}.chat-message__manage button{border:0;border-radius:6px;padding:5px 8px;background:#eee;cursor:pointer;white-space:nowrap}
.event-chat__composer{flex:none;padding:9px 10px;padding-bottom:max(9px,env(safe-area-inset-bottom));border-top:1px solid #e5e5e5;background:#fff}
.event-chat__composer form{display:flex;align-items:flex-end;gap:8px}.event-chat__composer textarea{flex:1;min-width:0;resize:none;max-height:100px;border:1px solid #ddd;border-radius:18px;padding:10px 13px;font:14px/1.4 system-ui,sans-serif;outline:none}.event-chat__composer textarea:focus{border-color:var(--db-primary,#2878c8)}
.event-chat__composer button{flex:none;width:40px;height:40px;border:0;border-radius:50%;background:var(--db-primary,#2878c8);color:#fff;font-size:19px;cursor:pointer}
.event-chat__composer>a{display:block;text-align:center;padding:8px;color:var(--db-primary,#2878c8);font-weight:600;text-decoration:none}
.event-chat__notice{flex:none;padding:5px 12px;background:#e8f6ed;color:#246b3c;font-size:12px}.event-chat__notice--error{background:#fff0f0;color:#a22}
</style>
<script>
(() => {
    const list = document.querySelector('.event-chat__messages');
    if (list) list.scrollTop = list.scrollHeight;
    document.addEventListener('click', event => {
        const button = event.target.closest('[data-reply-toggle]');
        if (!button) return;
        const form = button.nextElementSibling;
        if (!form || !form.classList.contains('chat-message__reply-form')) return;
        const opening = form.hidden;
        form.hidden = !opening;
        button.setAttribute('aria-expanded', opening ? 'true' : 'false');
        button.textContent = opening ? 'Annuleren' : 'Reageren';
        if (opening) form.querySelector('textarea')?.focus();
    });
    const input = document.querySelector('.event-chat__composer textarea');
    if (input) {
        input.addEventListener('keydown', event => {
            if (event.key === 'Enter' && !event.shiftKey) {
                event.preventDefault();
                input.form.requestSubmit();
            }
        });
    }
})();
</script>
<?php require dirname(__DIR__) . '/app/views/footer.php'; ?>
