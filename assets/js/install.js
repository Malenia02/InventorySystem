/**
 * install.js - Setup wizard JS
 * Place in: /inventory_system/assets/js/install.js
 */
document.addEventListener("DOMContentLoaded", () => {
    const STEP_META = [
        {
            badge: "Step 1 of 4",
            title: "Database configuration",
            sub: "Connect to your MySQL database. The application database will be created automatically.",
            progress: "25%",
        },
        {
            badge: "Step 2 of 4",
            title: "Store profile",
            sub: "Configure the POS store name, receipt details, VAT rate, and operating hours.",
            progress: "50%",
        },
        {
            badge: "Step 3 of 4",
            title: "Admin account",
            sub: "Create the first administrator who will manage products, staff, and reports.",
            progress: "75%",
        },
        {
            badge: "Step 4 of 4",
            title: "Review & install",
            sub: "Confirm your settings and complete the setup. The installer will be locked afterwards.",
            progress: "100%",
        },
    ];

    const TOTAL_STEPS = STEP_META.length;

    let current = parseInt(document.getElementById("activeStepInput")?.value ?? "0", 10);
    if (Number.isNaN(current) || current < 0 || current >= TOTAL_STEPS) {
        current = 0;
    }

    const stepItems = document.querySelectorAll(".install-step");
    const panels = document.querySelectorAll(".install-step-panel");
    const btnBack = document.getElementById("btnBack");
    const btnNext = document.getElementById("btnNext");
    const badgeEl = document.getElementById("topStepBadge");
    const titleEl = document.getElementById("topStepTitle");
    const subEl = document.getElementById("topStepSub");
    const progressFill = document.getElementById("progressFill");
    const activeInput = document.getElementById("activeStepInput");
    const form = document.getElementById("installForm");

    function render() {
        const meta = STEP_META[current];

        if (badgeEl) badgeEl.textContent = meta.badge;
        if (titleEl) titleEl.textContent = meta.title;
        if (subEl) subEl.textContent = meta.sub;
        if (progressFill) progressFill.style.width = meta.progress;
        if (activeInput) activeInput.value = String(current);

        panels.forEach((panel, index) => {
            panel.classList.toggle("is-active", index === current);
        });

        stepItems.forEach((item, index) => {
            item.classList.toggle("is-active", index === current);
            item.classList.toggle("is-done", index < current);
        });

        if (btnBack) {
            btnBack.style.visibility = current === 0 ? "hidden" : "visible";
        }

        if (btnNext) {
            if (current === TOTAL_STEPS - 1) {
                btnNext.innerHTML = 'Complete setup <i class="bi bi-check-lg"></i>';
                btnNext.classList.add("is-final");
            } else {
                btnNext.innerHTML = 'Continue <i class="bi bi-arrow-right"></i>';
                btnNext.classList.remove("is-final");
            }
        }
    }

    function validateStep(step) {
        const get = (name) => form.querySelector(`[name="${name}"]`)?.value?.trim() ?? "";

        if (step === 0) {
            if (!get("db_host")) return "Database host is required.";
            if (!get("db_port")) return "Database port is required.";
            if (!/^\d+$/.test(get("db_port"))) return "Database port must be a number.";
            if (!get("db_name")) return "Database name is required.";
            if (!/^[A-Za-z0-9_]+$/.test(get("db_name"))) return "Database name may only contain letters, numbers, and underscores.";
            if (!get("db_user")) return "Database username is required.";
            if (!get("app_url")) return "Application URL is required.";
        }

        if (step === 1) {
            if (!get("store_name")) return "Store name is required.";
            const tax = get("tax_rate");
            if (!tax || Number.isNaN(parseFloat(tax)) || parseFloat(tax) < 0) {
                return "VAT rate must be a valid non-negative number.";
            }
            const storeEmail = get("store_email");
            if (storeEmail && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(storeEmail)) {
                return "Store email must be a valid email address.";
            }
        }

        if (step === 2) {
            if (!get("admin_first_name")) return "First name is required.";
            if (!get("admin_last_name")) return "Last name is required.";
            if (!get("admin_username")) return "Username is required.";

            const password = get("admin_password");
            if (!password || password.length < 8) return "Password must be at least 8 characters.";
            if (password !== get("admin_password_confirm")) return "Password confirmation does not match.";

            const adminEmail = get("admin_email");
            if (adminEmail && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(adminEmail)) {
                return "Admin email must be a valid email address.";
            }
        }

        if (step === 3) {
            const confirmInstall = document.getElementById("confirmInstall");
            if (!confirmInstall?.checked) {
                return "Please confirm you have reviewed the settings before proceeding.";
            }
        }

        return null;
    }

    function showError(message) {
        let errorEl = document.getElementById("stepInlineError");

        if (!errorEl) {
            errorEl = document.createElement("div");
            errorEl.id = "stepInlineError";
            errorEl.className = "install-alert install-alert--danger";
            errorEl.style.marginBottom = "14px";
            const body = document.getElementById("installBody");
            body?.insertBefore(errorEl, body.firstChild);
        }

        errorEl.innerHTML = `<i class="bi bi-exclamation-circle-fill"></i><div>${message}</div>`;
    }

    function clearError() {
        document.getElementById("stepInlineError")?.remove();
    }

    btnNext?.addEventListener("click", () => {
        clearError();
        const error = validateStep(current);

        if (error) {
            showError(error);
            return;
        }

        if (current === TOTAL_STEPS - 1) {
            form?.submit();
            return;
        }

        current += 1;
        render();
        document.getElementById("installBody")?.scrollTo({ top: 0, behavior: "smooth" });
    });

    btnBack?.addEventListener("click", () => {
        if (current === 0) return;
        clearError();
        current -= 1;
        render();
    });

    stepItems.forEach((item, index) => {
        item.addEventListener("click", () => {
            if (index >= current) return;
            clearError();
            current = index;
            render();
        });
    });

    const pwInput = document.getElementById("adminPasswordInput");
    const pwFill = document.getElementById("pwStrengthFill");
    const pwLabel = document.getElementById("pwStrengthLabel");

    function measureStrength(password) {
        if (!password) {
            return { width: 0, color: "", label: "Minimum 8 characters" };
        }

        let score = 0;
        if (password.length >= 8) score += 1;
        if (/[A-Z]/.test(password)) score += 1;
        if (/[0-9]/.test(password)) score += 1;
        if (/[^A-Za-z0-9]/.test(password)) score += 1;

        const map = [
            { width: 0, color: "", label: "Minimum 8 characters" },
            { width: 25, color: "#E24B4A", label: "Weak - add uppercase letters or numbers" },
            { width: 50, color: "#BA7517", label: "Fair - add a symbol or number" },
            { width: 75, color: "#EF9F27", label: "Good - one more type of character will make it strong" },
            { width: 100, color: "#1D9E75", label: "Strong password" },
        ];

        return map[score];
    }

    pwInput?.addEventListener("input", () => {
        const strength = measureStrength(pwInput.value);

        if (pwFill) {
            pwFill.style.width = `${strength.width}%`;
            pwFill.style.background = strength.color;
        }

        if (pwLabel) {
            pwLabel.textContent = strength.label;
            pwLabel.style.color = strength.color || "";
        }
    });

    render();
});
