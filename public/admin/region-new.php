<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_private-root.php';

require_once DONDEBAILAR_PRIVATE_ROOT . '/app/bootstrap.php';
require_once DONDEBAILAR_PRIVATE_ROOT . '/app/Authorization.php';
require_once DONDEBAILAR_PRIVATE_ROOT . '/app/Security.php';

$user = requirePlatformAdmin();
$db = Database::connection();

$pageTitle = 'Nieuwe regio - DONDEBAILAR';
$platformAdminPage = true;
$errorMessage = '';

$platformFormColors = [
    'form_panel_bg' => '#F7F8FA',
    'form_label_text' => '#202B38',
    'form_input_bg' => '#FFFFFF',
    'form_input_text' => '#202B38',
    'form_input_border' => '#E7EAF0',
    'form_input_hover_border' => '#0A4D9F',
    'form_button_bg' => '#0A4D9F',
    'form_button_text' => '#FFFFFF',
    'form_button_hover_bg' => '#083B79',
    'form_button_hover_text' => '#FFFFFF',
    'border' => '#E7EAF0',
];
$platformFormColorsFile = DONDEBAILAR_PRIVATE_ROOT . '/storage/platform-admin-colors.json';
if (is_file($platformFormColorsFile)) {
    $savedFormColors = json_decode((string) file_get_contents($platformFormColorsFile), true);
    if (is_array($savedFormColors)) {
        foreach ($platformFormColors as $colorKey => $fallbackColor) {
            $candidateColor = strtoupper(trim((string) ($savedFormColors[$colorKey] ?? '')));
            if (preg_match('/^#[0-9A-F]{6}$/', $candidateColor)) {
                $platformFormColors[$colorKey] = $candidateColor;
            }
        }
    }
}

$form = [
    'name' => '',
    'subdomain' => '',
    'country_code' => 'ES',
    'timezone' => 'Europe/Madrid',
    'default_language' => 'es',
    'is_active' => '1',
];

function createRegionSlug(string $name): string
{
    $value = trim($name);

    if (function_exists('iconv')) {
        $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if ($converted !== false) {
            $value = $converted;
        }
    }

    $value = strtolower($value);
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';

    return trim($value, '-');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($form as $key => $default) {
        if ($key === 'is_active') {
            $form[$key] = isset($_POST[$key]) ? '1' : '0';
        } else {
            $form[$key] = trim((string) ($_POST[$key] ?? $default));
        }
    }

    $token = $_POST['csrf_token'] ?? null;

    if (!verifyCsrfToken(is_string($token) ? $token : null)) {
        $errorMessage = 'De beveiligingscontrole is mislukt. Vernieuw de pagina.';
    } elseif ($form['name'] === '' || strlen($form['name']) > 150) {
        $errorMessage = 'Vul een geldige regionaam in (maximaal 150 tekens).';
    } elseif (!preg_match('/^[a-z0-9-]{2,100}$/', $form['subdomain'])) {
        $errorMessage = 'Het subdomein mag alleen kleine letters, cijfers en koppeltekens bevatten.';
    } elseif (!preg_match('/^[A-Z]{2}$/', $form['country_code'])) {
        $errorMessage = 'Gebruik een landcode van twee hoofdletters, bijvoorbeeld ES of BE.';
    } elseif (!in_array($form['default_language'], ['nl', 'en', 'es', 'fr'], true)) {
        $errorMessage = 'Kies een geldige standaardtaal.';
    } elseif (!in_array($form['timezone'], DateTimeZone::listIdentifiers(), true)) {
        $errorMessage = 'Kies een geldige tijdzone.';
    } else {
        $slug = createRegionSlug($form['name']);

        if ($slug === '' || strlen($slug) > 100) {
            $errorMessage = 'De regionaam kan niet worden omgezet naar een geldige slug.';
        } else {
            try {
                $db->beginTransaction();

                $insert = $db->prepare(
                    "INSERT INTO regions
                        (name, slug, subdomain, country_code, timezone,
                         default_language, is_active)
                     VALUES
                        (:name, :slug, :subdomain, :country_code, :timezone,
                         :default_language, :is_active)"
                );

                $insert->execute([
                    'name' => $form['name'],
                    'slug' => $slug,
                    'subdomain' => $form['subdomain'],
                    'country_code' => $form['country_code'],
                    'timezone' => $form['timezone'],
                    'default_language' => $form['default_language'],
                    'is_active' => (int) $form['is_active'],
                ]);

                $regionId = (int) $db->lastInsertId();

                // Neem bij het aanmaken de actuele platformkleuren als startwaarden.
                // Latere wijzigingen in een regio blijven volledig onafhankelijk.
                $platformColorDefaults = [
                    'primary' => '#0A4D9F',
                    'primary_dark' => '#083B79',
                    'accent' => '#00AFC5',
                    'header' => '#FFFFFF',
                    'menu' => '#FFFFFF',
                    'background' => '#F7F8FA',
                    'surface' => '#FFFFFF',
                    'text' => '#202B38',
                    'muted' => '#687586',
                    'border' => '#E7EAF0',
                ];
                $platformColorsFile = DONDEBAILAR_PRIVATE_ROOT . '/storage/platform-admin-colors.json';
                if (is_file($platformColorsFile)) {
                    $savedPlatformColors = json_decode((string) file_get_contents($platformColorsFile), true);
                    if (is_array($savedPlatformColors)) {
                        foreach ($platformColorDefaults as $colorKey => $fallbackColor) {
                            $candidate = strtoupper(trim((string) ($savedPlatformColors[$colorKey] ?? '')));
                            if (preg_match('/^#[0-9A-F]{6}$/', $candidate)) {
                                $platformColorDefaults[$colorKey] = $candidate;
                            }
                        }
                    }
                }

                $appearance = $db->prepare(
                    "INSERT INTO region_appearance
                        (region_id, primary_color, primary_dark, accent_color, secondary_color,
                         tertiary_color, header_color, menu_color, organizer_color, filter_hover_color,
                         filter_bar_color, filter_text_color, search_button_color, back_top_color,
                         search_field_color, search_text_color, footer_color, background_color,
                         text_color, weekday_colors, updated_by)
                     VALUES
                        (:region_id, :primary_color, :primary_dark, :accent_color, :secondary_color,
                         :tertiary_color, :header_color, :menu_color, :organizer_color, :filter_hover_color,
                         :filter_bar_color, :filter_text_color, :search_button_color, :back_top_color,
                         :search_field_color, :search_text_color, :footer_color, :background_color,
                         :text_color, :weekday_colors, :updated_by)"
                );
                $appearance->execute([
                    'region_id' => $regionId,
                    'primary_color' => $platformColorDefaults['primary'],
                    'primary_dark' => $platformColorDefaults['primary_dark'],
                    'accent_color' => $platformColorDefaults['accent'],
                    'secondary_color' => $platformColorDefaults['accent'],
                    'tertiary_color' => $platformColorDefaults['accent'],
                    'header_color' => $platformColorDefaults['header'],
                    'menu_color' => $platformColorDefaults['menu'],
                    'organizer_color' => $platformColorDefaults['primary'],
                    'filter_hover_color' => $platformColorDefaults['primary_dark'],
                    'filter_bar_color' => $platformColorDefaults['surface'],
                    'filter_text_color' => $platformColorDefaults['text'],
                    'search_button_color' => $platformColorDefaults['primary'],
                    'back_top_color' => $platformColorDefaults['primary'],
                    'search_field_color' => $platformColorDefaults['surface'],
                    'search_text_color' => $platformColorDefaults['text'],
                    'footer_color' => $platformColorDefaults['surface'],
                    'background_color' => $platformColorDefaults['background'],
                    'text_color' => $platformColorDefaults['text'],
                    'weekday_colors' => json_encode([
                        'selector_strip_background_color' => $platformColorDefaults['surface'],
                        'event_separator_color' => $platformColorDefaults['border'],
                        'event_title_color' => $platformColorDefaults['text'],
                    ], JSON_THROW_ON_ERROR),
                    'updated_by' => (int) $user['id'],
                ]);

                $db->commit();

                header('Location: /admin/regions.php?created=1');
                exit;
            } catch (Throwable $exception) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }

                if ($exception instanceof PDOException && $exception->getCode() === '23000') {
                    $errorMessage = 'Deze slug of dit subdomein bestaat al. Kies een andere.';
                } else {
                    error_log('DONDEBAILAR regio aanmaken: ' . $exception->getMessage());
                    $errorMessage = 'De regio kon niet worden aangemaakt. Controleer de serverlog.';
                }
            }
        }
    }
}

require DONDEBAILAR_PRIVATE_ROOT . '/app/views/header.php';
?>
<style>
#region-new-form {
    background: <?= escapeHtml($platformFormColors['form_panel_bg']) ?> !important;
    color: <?= escapeHtml($platformFormColors['form_label_text']) ?> !important;
    border: 1px solid <?= escapeHtml($platformFormColors['border']) ?> !important;
    border-radius: 12px;
    padding: 24px;
}
#region-new-form label,
#region-new-form h2,
#region-new-form p,
#region-new-form .input-with-suffix span {
    color: <?= escapeHtml($platformFormColors['form_label_text']) ?> !important;
}
#region-new-form input:not([type="checkbox"]),
#region-new-form select,
#region-new-form textarea,
#region-new-form .input-with-suffix span {
    background-color: <?= escapeHtml($platformFormColors['form_input_bg']) ?> !important;
    color: <?= escapeHtml($platformFormColors['form_input_text']) ?> !important;
    border-color: <?= escapeHtml($platformFormColors['form_input_border']) ?> !important;
}
#region-new-form input:not([type="checkbox"]):hover,
#region-new-form select:hover,
#region-new-form textarea:hover,
#region-new-form input:not([type="checkbox"]):focus,
#region-new-form select:focus,
#region-new-form textarea:focus {
    border-color: <?= escapeHtml($platformFormColors['form_input_hover_border']) ?> !important;
}
#region-new-form input[type="checkbox"] {
    accent-color: <?= escapeHtml($platformFormColors['form_button_bg']) ?>;
}
#region-new-form .button,
#region-new-form button,
.admin-page .admin-heading .button {
    background: <?= escapeHtml($platformFormColors['form_button_bg']) ?> !important;
    color: <?= escapeHtml($platformFormColors['form_button_text']) ?> !important;
    border-color: <?= escapeHtml($platformFormColors['form_button_bg']) ?> !important;
}
#region-new-form .button:hover,
#region-new-form button:hover,
.admin-page .admin-heading .button:hover {
    background: <?= escapeHtml($platformFormColors['form_button_hover_bg']) ?> !important;
    color: <?= escapeHtml($platformFormColors['form_button_hover_text']) ?> !important;
    border-color: <?= escapeHtml($platformFormColors['form_button_hover_bg']) ?> !important;
}
</style>

<?php
?>

<section class="admin-page region-new-page">
    <div class="admin-heading">
        <div>
            <p class="eyebrow">PLATFORMBEHEER</p>
            <h1>Nieuwe regio</h1>
            <p>Vul de basisgegevens van de regionale agenda in.</p>
        </div>
        <a class="button" href="/admin/regions.php">Terug naar overzicht</a>
    </div>

    <section class="admin-section">
        <?php if ($errorMessage !== ''): ?>
            <div class="message message--error" role="alert">
                <?= escapeHtml($errorMessage) ?>
            </div>
        <?php endif; ?>

        <form method="post" action="/admin/region-new.php" class="auth-form" id="region-new-form">
            <input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()) ?>">

            <label for="name">Naam van de regio</label>
            <input type="text" id="name" name="name" maxlength="150"
                   value="<?= escapeHtml($form['name']) ?>"
                   placeholder="Bijvoorbeeld Costa Blanca" required>

            <label for="subdomain">Subdomein</label>
            <div class="input-with-suffix">
                <input type="text" id="subdomain" name="subdomain" maxlength="100"
                       pattern="[a-z0-9-]{2,100}"
                       value="<?= escapeHtml($form['subdomain']) ?>"
                       placeholder="costa-blanca" required>
                <span>.dondebailar.eu</span>
            </div>

            <label for="country_code">Landcode</label>
            <input type="text" id="country_code" name="country_code"
                   minlength="2" maxlength="2" pattern="[A-Z]{2}"
                   value="<?= escapeHtml($form['country_code']) ?>" required>

            <label for="timezone">Tijdzone</label>
            <select id="timezone" name="timezone" required>
                <?php foreach ([
                    'Europe/Madrid', 'Europe/Brussels', 'Europe/Amsterdam',
                    'Europe/Paris', 'Europe/London', 'Europe/Berlin',
                    'Europe/Rome', 'Europe/Lisbon',
                ] as $timezone): ?>
                    <option value="<?= escapeHtml($timezone) ?>"
                        <?= $form['timezone'] === $timezone ? 'selected' : '' ?>>
                        <?= escapeHtml($timezone) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label for="default_language">Standaardtaal</label>
            <select id="default_language" name="default_language" required>
                <option value="nl" <?= $form['default_language'] === 'nl' ? 'selected' : '' ?>>Nederlands</option>
                <option value="en" <?= $form['default_language'] === 'en' ? 'selected' : '' ?>>English</option>
                <option value="es" <?= $form['default_language'] === 'es' ? 'selected' : '' ?>>Español</option>
                <option value="fr" <?= $form['default_language'] === 'fr' ? 'selected' : '' ?>>Français</option>
            </select>

            <label class="checkbox-label">
                <input type="checkbox" name="is_active" value="1"
                    <?= $form['is_active'] === '1' ? 'checked' : '' ?>>
                Regio onmiddellijk activeren
            </label>

            <div class="form-actions">
                <button type="submit" class="button button--primary">Regio aanmaken</button>
                <a class="button" href="/admin/regions.php">Annuleren</a>
            </div>
        </form>
    </section>
</section>

<?php require DONDEBAILAR_PRIVATE_ROOT . '/app/views/footer.php'; ?>
