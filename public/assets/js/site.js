document.addEventListener('DOMContentLoaded', () => {
    const menu = document.querySelector('.site-menu');

    if (!menu) {
        return;
    }

    // Sluit het menu met Escape.
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            menu.open = false;
        }
    });

    // Sluit het menu wanneer buiten het menu wordt geklikt.
    document.addEventListener('click', (event) => {
        if (!menu.contains(event.target)) {
            menu.open = false;
        }
    });

    // Sluit het menu na het kiezen van een navigatielink.
    menu.querySelectorAll('.site-menu__panel a').forEach((link) => {
        link.addEventListener('click', () => {
            menu.open = false;
        });
    });
});
