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
                           AND parent_id IS NULL AND is_deleted = 0 LIMIT 1"
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
                header('Location: /event-messages.php?id=' . $id . '&sent=1#messages');
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
                header('Location: /event-messages.php?id=' . $id . '&updated=1#message-' . $messageId);
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
                header('Location: /event-messages.php?id=' . $id . '&deleted=1#messages');
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

$countQuery = $db->prepare(
    "SELECT COUNT(*) FROM event_messages WHERE event_id = :event_id AND is_deleted = 0"
);
$countQuery->execute(['event_id' => $id]);
$messageCount = (int) $countQuery->fetchColumn();

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

$pageTitle = 'Berichten — ' . (string) $event['title'] . ' — DONDEBAILAR';
$pageRobots = 'noindex, nofollow';
$region = [
    'id' => (int) $event['region_id'],
    'name' => (string) $event['region_name'],
    'logo_path' => $event['logo_path'],
];
require dirname(__DIR__) . '/app/views/header.php';
$escape = static fn($value): string => escapeHtml((string) $value);
?>
<section class="admin-page event-messages-page" id="messages">
    <p class="eyebrow"><?= $escape($event['region_name']) ?></p>
    <div class="admin-heading">
        <div><h1>Berichten</h1><p><a href="/event.php?id=<?= (int) $id ?>"><?= $escape($event['title']) ?></a></p></div>
        <a class="button" href="/event.php?id=<?= (int) $id ?>">Terug naar evenement</a>
    </div>

    <?php foreach ($errors as $error): ?><div class="message message--error"><?= $escape($error) ?></div><?php endforeach; ?>
    <?php if (isset($_GET['sent'])): ?><div class="message message--success">Je bericht is geplaatst.</div><?php endif; ?>
    <?php if (isset($_GET['updated'])): ?><div class="message message--success">Je bericht is aangepast.</div><?php endif; ?>
    <?php if (isset($_GET['deleted'])): ?><div class="message message--success">Het bericht is verwijderd.</div><?php endif; ?>

    <section class="admin-section">
        <h2><?= $messageCount ?> <?= $messageCount === 1 ? 'bericht' : 'berichten' ?></h2>
        <?php if ($user !== null): ?>
            <form method="post" class="auth-form event-message-form">
                <input type="hidden" name="csrf_token" value="<?= $escape(csrfToken()) ?>">
                <input type="hidden" name="action" value="post">
                <label for="new-message">Plaats een bericht</label>
                <textarea id="new-message" name="message" rows="4" maxlength="2000" required placeholder="Schrijf een bericht over dit evenement..."></textarea>
                <button class="button button--primary" type="submit">Bericht plaatsen</button>
            </form>
        <?php else: ?>
            <p><a class="button button--primary" href="/login.php?return=<?= rawurlencode('/event-messages.php?id=' . $id) ?>">Log in om een bericht te plaatsen</a></p>
        <?php endif; ?>
    </section>

    <section class="event-message-list">
        <?php if ($topLevel === []): ?>
            <div class="admin-section"><p>Nog geen berichten. Plaats als eerste een bericht.</p></div>
        <?php endif; ?>
        <?php foreach ($topLevel as $item): ?>
            <?php
            $messageId = (int) $item['id'];
            $isOwner = $user !== null && (int) $item['user_id'] === (int) $user['id'];
            $canChange = $user !== null && ($isOwner || $canModerate);
            ?>
            <article class="event-message" id="message-<?= $messageId ?>">
                <div class="event-message__meta">
                    <strong><?= $escape($item['display_name'] ?: 'Gebruiker') ?></strong>
                    <time datetime="<?= $escape(date(DATE_ATOM, strtotime((string) $item['created_at']))) ?>"><?= $escape(date('d-m-Y H:i', strtotime((string) $item['created_at']))) ?></time>
                </div>
                <?php if ((int) $item['is_deleted'] === 1): ?>
                    <p class="event-message__deleted">Dit bericht is verwijderd.</p>
                <?php else: ?>
                    <p class="event-message__text"><?= nl2br($escape($item['message'])) ?></p>
                    <?php if (!empty($item['updated_at']) && $item['updated_at'] !== $item['created_at']): ?><small class="event-message__edited">bewerkt</small><?php endif; ?>
                    <?php if ($canChange): ?>
                        <details class="event-message__tools">
                            <summary>Beheren</summary>
                            <form method="post" class="auth-form">
                                <input type="hidden" name="csrf_token" value="<?= $escape(csrfToken()) ?>">
                                <input type="hidden" name="action" value="edit">
                                <input type="hidden" name="message_id" value="<?= $messageId ?>">
                                <textarea name="message" rows="3" maxlength="2000" required><?= $escape($item['message']) ?></textarea>
                                <button class="button" type="submit">Wijzig bericht</button>
                            </form>
                            <form method="post" onsubmit="return confirm('Dit bericht verwijderen?')">
                                <input type="hidden" name="csrf_token" value="<?= $escape(csrfToken()) ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="message_id" value="<?= $messageId ?>">
                                <button class="button" type="submit">Verwijderen</button>
                            </form>
                        </details>
                    <?php endif; ?>
                    <?php if ($user !== null): ?>
                        <details class="event-message__reply">
                            <summary>Reageren</summary>
                            <form method="post" class="auth-form">
                                <input type="hidden" name="csrf_token" value="<?= $escape(csrfToken()) ?>">
                                <input type="hidden" name="action" value="reply">
                                <input type="hidden" name="parent_id" value="<?= $messageId ?>">
                                <textarea name="message" rows="2" maxlength="2000" required placeholder="Schrijf een reactie..."></textarea>
                                <button class="button" type="submit">Reactie plaatsen</button>
                            </form>
                        </details>
                    <?php endif; ?>
                <?php endif; ?>

                <?php foreach ($replies[$messageId] ?? [] as $reply): ?>
                    <?php
                    $replyId = (int) $reply['id'];
                    $replyOwner = $user !== null && (int) $reply['user_id'] === (int) $user['id'];
                    $replyCanChange = $user !== null && ($replyOwner || $canModerate);
                    ?>
                    <div class="event-message event-message--reply" id="message-<?= $replyId ?>">
                        <div class="event-message__meta">
                            <strong><?= $escape($reply['display_name'] ?: 'Gebruiker') ?></strong>
                            <time><?= $escape(date('d-m-Y H:i', strtotime((string) $reply['created_at']))) ?></time>
                        </div>
                        <?php if ((int) $reply['is_deleted'] === 1): ?>
                            <p class="event-message__deleted">Deze reactie is verwijderd.</p>
                        <?php else: ?>
                            <p class="event-message__text"><?= nl2br($escape($reply['message'])) ?></p>
                            <?php if ($replyCanChange): ?>
                                <details class="event-message__tools">
                                    <summary>Beheren</summary>
                                    <form method="post" class="auth-form">
                                        <input type="hidden" name="csrf_token" value="<?= $escape(csrfToken()) ?>">
                                        <input type="hidden" name="action" value="edit">
                                        <input type="hidden" name="message_id" value="<?= $replyId ?>">
                                        <textarea name="message" rows="3" maxlength="2000" required><?= $escape($reply['message']) ?></textarea>
                                        <button class="button" type="submit">Wijzig reactie</button>
                                    </form>
                                    <form method="post" onsubmit="return confirm('Deze reactie verwijderen?')">
                                        <input type="hidden" name="csrf_token" value="<?= $escape(csrfToken()) ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="message_id" value="<?= $replyId ?>">
                                        <button class="button" type="submit">Verwijderen</button>
                                    </form>
                                </details>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </article>
        <?php endforeach; ?>
    </section>
</section>
<style>
.event-messages-page{max-width:900px;margin:0 auto}.event-message-form{max-width:700px}.event-message-list{display:grid;gap:14px;margin-top:18px}.event-message{padding:16px;border:1px solid var(--db-border);border-radius:12px;background:#fff;color:var(--db-text)}.event-message__meta{display:flex;align-items:center;justify-content:space-between;gap:12px;color:var(--db-muted);font-size:13px}.event-message__meta strong{color:var(--db-text)}.event-message__text{margin:12px 0;line-height:1.6;overflow-wrap:anywhere}.event-message__deleted,.event-message__edited{color:var(--db-muted);font-style:italic}.event-message--reply{margin:12px 0 0 22px;padding:12px;background:#f7f8fa}.event-message__tools,.event-message__reply{margin-top:10px;font-size:13px}.event-message__tools summary,.event-message__reply summary{cursor:pointer;color:var(--db-primary);font-weight:700}.event-message__tools form,.event-message__reply form{margin-top:10px;max-width:650px}.event-message__tools textarea,.event-message__reply textarea{width:100%;box-sizing:border-box}
@media(max-width:600px){.event-message{padding:13px}.event-message--reply{margin-left:10px}.event-message__meta{align-items:flex-start;flex-direction:column;gap:3px}}
</style>
<?php require dirname(__DIR__) . '/app/views/footer.php'; ?>
