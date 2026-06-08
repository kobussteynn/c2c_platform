function updatePasswordToggleState(input, button) {
    if (!input || !button) return;

    const isVisible = input.type === "text";
    const nextLabel = isVisible ? "Hide password" : "Show password";

    button.classList.toggle("is-visible", isVisible);
    button.setAttribute("aria-pressed", isVisible ? "true" : "false");
    button.setAttribute("aria-label", nextLabel);
    button.setAttribute("title", nextLabel);
}

function togglePassword(inputId, buttonId) {
    const input = document.getElementById(inputId);
    const button = document.getElementById(buttonId);

    if (!input || !button) return;

    input.type = input.type === "password" ? "text" : "password";
    updatePasswordToggleState(input, button);
}

function initPasswordToggles() {
    const buttons = document.querySelectorAll(".password-toggle-btn[data-target]");

    buttons.forEach((button) => {
        const targetId = button.getAttribute("data-target");
        if (!targetId) return;

        const input = document.getElementById(targetId);
        if (!input) return;

        updatePasswordToggleState(input, button);
    });
}

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initPasswordToggles);
} else {
    initPasswordToggles();
}
