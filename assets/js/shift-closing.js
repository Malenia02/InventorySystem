document.addEventListener('DOMContentLoaded', () => {
    const startForm = document.getElementById('startShiftForm');
    const startButton = document.getElementById('startShiftButton');
    const celebration = document.getElementById('shiftStartCelebration');

    if (startForm && startButton) {
        startForm.addEventListener('submit', () => {
            startButton.disabled = true;
            startButton.classList.add('is-starting');
            startButton.innerHTML = '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Starting shift...';
        });
    }

    if (celebration) {
        window.setTimeout(() => celebration.classList.add('show'), 80);
        window.setTimeout(() => celebration.classList.remove('show'), 2100);
        window.setTimeout(() => celebration.remove(), 2500);
    }
});
