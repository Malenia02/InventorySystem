document.addEventListener("DOMContentLoaded", () => {
  const form = document.getElementById("backupRestoreForm");
  if (!form) return;

  const fileInput = document.getElementById("backupRestoreFile");
  const confirmInput = document.getElementById("backupRestoreConfirm");
  const passwordInput = document.getElementById("backupRestorePassword");
  const submitButton = document.getElementById("backupRestoreSubmit");
  const preview = document.getElementById("backupFilePreview");

  const formatBytes = (bytes) => {
    const size = Number(bytes || 0);
    if (size < 1024) return `${size} B`;
    if (size < 1024 * 1024) return `${(size / 1024).toFixed(1)} KB`;
    return `${(size / (1024 * 1024)).toFixed(1)} MB`;
  };

  const updateState = () => {
    const file = fileInput?.files?.[0] || null;
    const confirmed = (confirmInput?.value || "").trim().toUpperCase() === "RESTORE";
    const hasPassword = (passwordInput?.value || "").trim() !== "";

    if (preview) {
      preview.classList.toggle("has-file", Boolean(file));
      preview.innerHTML = file
        ? `<i class="bi bi-file-earmark-check"></i><div><strong>${file.name}</strong><span>${formatBytes(file.size)} selected. Type RESTORE to unlock restore.</span></div>`
        : `<i class="bi bi-file-earmark-lock"></i><div><strong>No file selected</strong><span>Select a backup file to inspect its name and size before restore.</span></div>`;
    }

    if (submitButton) submitButton.disabled = !file || !confirmed || !hasPassword;
  };

  fileInput?.addEventListener("change", updateState);
  confirmInput?.addEventListener("input", updateState);
  passwordInput?.addEventListener("input", updateState);

  form.addEventListener("submit", (event) => {
    const file = fileInput?.files?.[0] || null;
    const confirmed = (confirmInput?.value || "").trim().toUpperCase() === "RESTORE";
    const hasPassword = (passwordInput?.value || "").trim() !== "";

    if (!file || !confirmed || !hasPassword) {
      event.preventDefault();
      updateState();
      return;
    }

    const ok = window.confirm("Restore will replace current system data with the uploaded backup. Continue?");
    if (!ok) {
      event.preventDefault();
    }
  });

  updateState();
});
