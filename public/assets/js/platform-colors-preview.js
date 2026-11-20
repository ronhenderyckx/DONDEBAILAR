(function () {
    const preview = document.getElementById('platform-color-preview');
    if (!preview) return;

    const color = (key, fallback) =>
        preview.querySelector('[data-preview-color="' + key + '"]')?.value || fallback;

    function applyPreviewColors() {
        const primary = color('primary', '#0A4D9F');
        const dark = color('primary_dark', '#083B79');
        const actionButton = color('action_button', primary);
        const listText = color('list_text', '#202B38');
        const accent = color('accent', '#00AFC5');
        const header = color('header', '#FFFFFF');
        const text = color('text', '#202B38');
        const menu = color('menu', '#FFFFFF');
        const menuText = color('menu_text', text);
        const menuHoverBg = color('menu_hover_bg', '#F3F6F9');
        const menuHoverText = color('menu_hover_text', primary);
        const background = color('background', '#F7F8FA');
        const surface = color('surface', '#FFFFFF');
        const muted = color('muted', '#687586');
        const border = color('border', '#E7EAF0');
        const listHeaderBg = color('list_header_bg', '#F1F3F6');
        const listHeaderText = color('list_header_text', muted);
        const listRowBg = color('list_row_bg', surface);
        const listHoverBg = color('list_hover_bg', dark);
        const listHoverText = color('list_hover_text', '#FFFFFF');
        const actionButtonText = color('action_button_text', '#FFFFFF');
        const actionHoverBg = color('action_hover_bg', dark);
        const actionHoverText = color('action_hover_text', '#FFFFFF');
        const activeBg = color('status_active_bg', '#EAF7F0');
        const activeText = color('status_active_text', '#16845B');
        const inactiveBg = color('status_inactive_bg', '#F0F1F3');
        const inactiveText = color('status_inactive_text', '#68717C');

        preview.style.backgroundColor = background;
        preview.style.color = text;
        preview.style.borderColor = border;

        const setBackground = (selector, value) =>
            preview.querySelectorAll(selector).forEach(el => el.style.backgroundColor = value);
        const setText = (selector, value) =>
            preview.querySelectorAll(selector).forEach(el => el.style.color = value);
        const setBorder = (selector, value) =>
            preview.querySelectorAll(selector).forEach(el => el.style.borderColor = value);

        setBackground('#preview-header', header);
        setBackground('#preview-menu', menu);
        setBackground('#preview-menu .preview-menu-item--hover', menuHoverBg);
        setText('#preview-menu .preview-menu-item', menuText);
        setText('#preview-menu .preview-menu-item--hover', menuHoverText);
        preview.querySelectorAll('#preview-menu .preview-menu-item').forEach(item => { item.style.padding = '5px 8px'; item.style.borderRadius = '5px'; });
        setBackground('#preview-page', background);
        setBackground('#preview-panel, #preview-admin-list', surface);
        const formPanelBg = color('form_panel_bg', background);
        const formLabelText = color('form_label_text', text);
        const formInputBg = color('form_input_bg', surface);
        const formInputText = color('form_input_text', text);
        const formInputBorder = color('form_input_border', border);
        const formInputHoverBorder = color('form_input_hover_border', primary);
        const formButtonBg = color('form_button_bg', primary);
        const formButtonText = color('form_button_text', '#FFFFFF');
        const formButtonHoverBg = color('form_button_hover_bg', dark);
        const formButtonHoverText = color('form_button_hover_text', '#FFFFFF');
        setBackground('#preview-form-panel', formPanelBg);
        preview.querySelectorAll('#preview-form-panel input:not([type="color"]), #preview-form-panel select, #preview-form-panel .preview-form-button--secondary').forEach(el => el.style.setProperty('background-color', formInputBg, 'important'));
        setBackground('#preview-form-panel .preview-form-button:not(.preview-form-button--secondary)', formButtonBg);
        setText('#preview-form-panel', formLabelText);
        setText('#preview-form-panel input:not([type="color"]), #preview-form-panel select, #preview-form-panel .preview-form-button--secondary', formInputText);
        setText('#preview-form-panel .preview-form-button:not(.preview-form-button--secondary)', formButtonText);
        setBorder('#preview-form-panel, #preview-form-panel input:not([type="color"]), #preview-form-panel select, #preview-form-panel .preview-form-button', formInputBorder);
        preview.querySelectorAll('#preview-form-panel input:not([type="color"]), #preview-form-panel select').forEach(field => {
            field.style.setProperty('background-color', formInputBg, 'important');
            field.style.transition = 'background-color .15s ease, border-color .15s ease, box-shadow .15s ease';
            field.onmouseenter = () => { field.style.borderColor = formInputHoverBorder; };
            field.onmouseleave = () => { if (document.activeElement !== field) field.style.borderColor = formInputBorder; };
            field.onfocus = () => { field.style.borderColor = formInputHoverBorder; field.style.boxShadow = '0 0 0 2px ' + formInputHoverBorder + '33'; };
            field.onblur = () => { field.style.borderColor = formInputBorder; field.style.boxShadow = 'none'; };
        });
        preview.querySelectorAll('#preview-form-panel .preview-form-button').forEach(button => {
            button.style.cursor = 'pointer';
            button.style.transition = 'background-color .15s ease, color .15s ease, border-color .15s ease';
            button.onmouseenter = () => {
                button.style.backgroundColor = formButtonHoverBg;
                button.style.color = formButtonHoverText;
                button.style.borderColor = formButtonHoverBg;
            };
            button.onmouseleave = () => {
                const secondary = button.classList.contains('preview-form-button--secondary');
                button.style.backgroundColor = secondary ? formInputBg : formButtonBg;
                button.style.color = secondary ? formInputText : formButtonText;
                button.style.borderColor = secondary ? formInputBorder : formButtonBg;
            };
        });
        setBackground('#preview-admin-list thead', listHeaderBg);
        setBackground('#preview-admin-list tbody tr', listRowBg);
        setBackground('.preview-status-active', activeBg);
        setBackground('.preview-status-inactive', inactiveBg);
        setBackground('#preview-primary-button', primary);
        setBackground('#preview-hover-button', primary);
        setBackground('#preview-accent', accent);

        setText('#preview-header, #preview-menu, #preview-page, #preview-panel', text);
        setText('#preview-muted', muted);
        setText('#preview-admin-list th', listHeaderText);
        setText('.preview-status-active', activeText);
        setText('.preview-status-inactive', inactiveText);
        setText('#preview-primary-button, #preview-hover-button', '#FFFFFF');
        setText('#preview-accent', '#202B38');
        setText('#preview-admin-list tbody td', listText);
        setText('#preview-admin-list .preview-action-button', actionButtonText);
        setBackground('#preview-admin-list .preview-action-button', actionButton);
        setBorder('#preview-admin-list .preview-action-button', actionButton);

        setBorder('#platform-color-preview, #preview-header, #preview-menu, #preview-panel, #preview-admin-list', border);
        setBorder('#preview-admin-list th, #preview-admin-list td', border);
        setBorder('#preview-admin-list .preview-action-button', actionButton);

        preview.querySelectorAll('#preview-admin-list tbody tr').forEach(row => {
            row.style.transition = 'background-color .15s ease, color .15s ease';
            row.onmouseenter = () => {
                row.style.backgroundColor = listHoverBg;
                row.querySelectorAll('td').forEach(cell => cell.style.color = listHoverText);
                row.querySelectorAll('.preview-action-button').forEach(button => {
                    button.style.backgroundColor = actionHoverBg;
                    button.style.borderColor = actionHoverBg;
                    button.style.color = actionHoverText;
                });
            };
            row.onmouseleave = () => {
                row.style.backgroundColor = listRowBg;
                row.querySelectorAll('td').forEach(cell => cell.style.color = listText);
                row.querySelectorAll('.preview-action-button').forEach(button => {
                    button.style.backgroundColor = actionButton;
                    button.style.borderColor = actionButton;
                    button.style.color = actionButtonText;
                });
            };
        });

        const hoverButton = preview.querySelector('#preview-hover-button');
        if (hoverButton) {
            hoverButton.onmouseenter = () => {
                hoverButton.style.backgroundColor = dark;
                hoverButton.style.color = '#FFFFFF';
            };
            hoverButton.onmouseleave = () => {
                hoverButton.style.backgroundColor = primary;
                hoverButton.style.color = '#FFFFFF';
            };
            hoverButton.onfocus = hoverButton.onmouseenter;
            hoverButton.onblur = hoverButton.onmouseleave;
        }

        preview.querySelectorAll('#preview-admin-list .preview-action-button').forEach(button => {
            button.style.cursor = 'pointer';
            button.style.transition = 'background-color .15s ease, color .15s ease';
            button.onmouseenter = () => {
                button.style.backgroundColor = actionHoverBg;
                button.style.borderColor = actionHoverBg;
                button.style.color = actionHoverText;
            };
            button.onmouseleave = () => {
                button.style.backgroundColor = actionButton;
                button.style.borderColor = actionButton;
                button.style.color = actionButtonText;
            };
        });
    }

    function syncColorPicker(picker) {
        const key = picker.dataset.previewColor;
        const original = document.getElementById('platform_color_' + key);
        if (original) original.value = picker.value;

        // Houd eventuele tweede kleurkiezer voor dezelfde instelling gelijk.
        preview.querySelectorAll('[data-preview-color="' + key + '"]').forEach(other => {
            if (other !== picker) other.value = picker.value;
        });

        // Werk het voorbeeld onmiddellijk bij terwijl de kleurkiezer wordt bediend.
        applyPreviewColors();
    }

    // Gebruik eventdelegatie zodat iedere kleurkiezer direct reageert.
    function handleColorInput(event) {
        const picker = event.target;
        if (!(picker instanceof HTMLInputElement) || !picker.matches('[data-preview-color]')) return;
        syncColorPicker(picker);
    }
    preview.addEventListener('input', handleColorInput, true);
    preview.addEventListener('change', handleColorInput, true);

    // Toon bij het openen meteen alle opgeslagen kleuren in het voorbeeld.
    applyPreviewColors();
})();
