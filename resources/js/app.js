// Password visibility (E3.10g): every password field has its own button; hidden by default, works with the keyboard.
document.querySelectorAll('[data-password-toggle]').forEach((button) => {
    const input = document.getElementById(button.getAttribute('aria-controls'));
    if (!input) {
        return;
    }
    button.hidden = false;
    button.addEventListener('click', () => {
        const reveal = input.type === 'password';
        input.type = reveal ? 'text' : 'password';
        button.setAttribute('aria-pressed', String(reveal));
        button.setAttribute('aria-label', reveal ? button.dataset.hideLabel : button.dataset.showLabel);
    });
});
