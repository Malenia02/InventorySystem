document.addEventListener("DOMContentLoaded", () => {
    const shell = document.getElementById("dashboardChatbot");
    if (!shell) return;

    const launcher = document.getElementById("dashboardChatbotLauncher");
    const closeBtn = document.getElementById("dashboardChatbotClose");
    const voiceBtn = document.getElementById("dashboardChatbotVoice");
    const minimizeBtn = document.getElementById("dashboardChatbotMinimize");
    const speakingBadge = document.getElementById("dashboardChatbotSpeaking");
    const form = document.getElementById("dashboardChatbotForm");
    const input = document.getElementById("dashboardChatbotInput");
    const messages = document.getElementById("dashboardChatbotMessages");
    const greeting = document.getElementById("dashboardChatbotGreeting");
    const quickBar = shell.querySelector(".dashboard-chatbot-quick");
    const quickBtns = document.querySelectorAll("[data-chat-question]");
    const userRole = (shell.dataset.userRole || "admin").toLowerCase();
    const csrfToken = shell.dataset.csrfToken || "";
    const speechSupported = "speechSynthesis" in window && "SpeechSynthesisUtterance" in window;
    let voiceEnabled = speechSupported && localStorage.getItem("dashboard_chatbot_voice") !== "off";
    let hasSpokenGreeting = false;
    let availableVoices = [];

    function getTimeGreeting() {
        const hour = new Date().getHours();
        const roleLabel = userRole === "cashier" ? "Cashier" : "Boss";
        const roleSuffix = userRole === "cashier"
            ? "Your sales tools, shift summary, and recent transactions are ready."
            : "Your inventory and sales intelligence are ready.";

        if (hour < 12) {
            return `Good morning, ${roleLabel}! StockWise AI online. ${roleSuffix}`;
        }

        if (hour < 18) {
            return `Good afternoon, ${roleLabel}! StockWise AI online. ${roleSuffix}`;
        }

        return `Good evening, ${roleLabel}! StockWise AI online. ${roleSuffix}`;
    }

    function stopSpeaking() {
        if (!speechSupported) return;
        window.speechSynthesis.cancel();
    }

    function setSpeakingState(isSpeaking) {
        if (!speakingBadge) return;
        speakingBadge.hidden = !isSpeaking;
    }

    function loadVoices() {
        if (!speechSupported) return;
        availableVoices = window.speechSynthesis.getVoices();
    }

    function pickPreferredVoice() {
        if (!speechSupported || !availableVoices.length) return null;

        const preferredPatterns = [
            /google uk english male/i,
            /microsoft david/i,
            /microsoft mark/i,
            /george/i,
            /james/i,
            /male/i,
        ];

        const ukEnglishVoices = availableVoices.filter((voice) => (
            /^en[-_]gb$/i.test(voice.lang || "")
            || /uk english|british english|english united kingdom/i.test(voice.name || "")
        ));

        for (const pattern of preferredPatterns) {
            const exactMatch = ukEnglishVoices.find((voice) => pattern.test(voice.name || ""));
            if (exactMatch) return exactMatch;
        }

        if (ukEnglishVoices.length) {
            return ukEnglishVoices[0];
        }

        return availableVoices.find((voice) => /^en[-_]/i.test(voice.lang || "")) || null;
    }

    function speakText(text) {
        if (!speechSupported || !voiceEnabled) return;

        stopSpeaking();

        const plainText = String(text || "").replace(/\s+/g, " ").trim();
        if (!plainText) return;

        const utterance = new SpeechSynthesisUtterance(plainText);
        const preferredVoice = pickPreferredVoice();

        if (preferredVoice) {
            utterance.voice = preferredVoice;
            utterance.lang = preferredVoice.lang || "en-GB";
        } else {
            utterance.lang = "en-GB";
        }

        utterance.rate = 1;
        utterance.pitch = 1;
        utterance.onstart = () => setSpeakingState(true);
        utterance.onend = () => setSpeakingState(false);
        utterance.onerror = () => setSpeakingState(false);
        window.speechSynthesis.speak(utterance);
    }

    function buildSpeechText(answer, rows = []) {
        const summary = String(answer || "").replace(/\s+/g, " ").trim();
        if (!summary) return "";

        if (!Array.isArray(rows) || rows.length === 0) {
            return summary;
        }

        const shortList = rows
            .map((row) => String(row.title || "").trim())
            .filter(Boolean)
            .slice(0, 3);

        if (shortList.length === 0) {
            return summary;
        }

        if (rows.length <= 3) {
            return `${summary} ${shortList.join(", ")}.`;
        }

        return `${summary} Please review the list on screen for full details.`;
    }

    function renderControls(suggestions = [], actions = []) {
        const suggestionButtons = Array.isArray(suggestions) && suggestions.length
            ? `
                <div class="chatbot-response-controls chatbot-response-suggestions">
                    ${suggestions.map((suggestion) => `
                        <button type="button" class="chatbot-response-chip" data-chat-question="${escapeHtml(suggestion)}">
                            ${escapeHtml(suggestion)}
                        </button>
                    `).join("")}
                </div>
            `
            : "";

        const actionButtons = Array.isArray(actions) && actions.length
            ? `
                <div class="chatbot-response-controls chatbot-response-actions">
                    ${actions.map((action) => `
                        <button type="button" class="chatbot-response-chip is-action" data-chat-action-url="${escapeHtml(action.url || "")}" data-chat-action-label="${escapeHtml(action.label || "")}">
                            ${action.icon ? `<i class="bi ${escapeHtml(action.icon)}"></i>` : ""}
                            <span>${escapeHtml(action.label || "")}</span>
                        </button>
                    `).join("")}
                </div>
            `
            : "";

        return `${suggestionButtons}${actionButtons}`;
    }

    function updateVoiceButton() {
        if (!voiceBtn) return;

        if (!speechSupported) {
            voiceBtn.disabled = true;
            voiceBtn.title = "Speech is not supported in this browser";
            voiceBtn.setAttribute("aria-label", "Speech is not supported in this browser");
            voiceBtn.innerHTML = '<i class="bi bi-volume-mute-fill"></i>';
            return;
        }

        voiceBtn.classList.toggle("is-active", voiceEnabled);
        voiceBtn.title = voiceEnabled ? "Voice replies on" : "Voice replies off";
        voiceBtn.setAttribute("aria-label", voiceEnabled ? "Turn voice replies off" : "Turn voice replies on");
        voiceBtn.innerHTML = voiceEnabled
            ? '<i class="bi bi-volume-up-fill"></i>'
            : '<i class="bi bi-volume-mute-fill"></i>';
    }

    function openChatbot() {
        shell.classList.add("is-open");
        shell.setAttribute("aria-hidden", "false");
        launcher?.classList.add("is-open");
        launcher?.setAttribute("aria-expanded", "true");
        launcher?.querySelector(".dashboard-chatbot-launcher-dot")?.remove();
        setTimeout(() => input?.focus(), 80);

        if (!hasSpokenGreeting && greeting?.textContent) {
            speakText(greeting.textContent);
            hasSpokenGreeting = true;
        }
    }

    function closeChatbot() {
        shell.classList.remove("is-open");
        shell.setAttribute("aria-hidden", "true");
        launcher?.classList.remove("is-open");
        launcher?.setAttribute("aria-expanded", "false");
        stopSpeaking();
        setSpeakingState(false);
    }

    launcher?.addEventListener("click", () => {
        shell.classList.contains("is-open") ? closeChatbot() : openChatbot();
    });

    closeBtn?.addEventListener("click", closeChatbot);
    minimizeBtn?.addEventListener("click", closeChatbot);
    voiceBtn?.addEventListener("click", () => {
        if (!speechSupported) return;

        voiceEnabled = !voiceEnabled;
        localStorage.setItem("dashboard_chatbot_voice", voiceEnabled ? "on" : "off");
        updateVoiceButton();

        if (!voiceEnabled) {
            stopSpeaking();
            setSpeakingState(false);
            return;
        }

        const latestBotText = messages?.querySelector(".chatbot-message.bot:last-child .chatbot-message-text")?.textContent || "";
        if (latestBotText) {
            speakText(latestBotText);
        }
    });

    updateVoiceButton();
    loadVoices();

    if (speechSupported && typeof window.speechSynthesis.onvoiceschanged !== "undefined") {
        window.speechSynthesis.onvoiceschanged = loadVoices;
    }

    if (greeting) {
        const greetingText = getTimeGreeting();
        greeting.textContent = greetingText;
    }

    document.addEventListener("keydown", (e) => {
        if (e.key === "Escape" && shell.classList.contains("is-open")) {
            closeChatbot();
        }
    });

    function escapeHtml(value) {
        const div = document.createElement("div");
        div.textContent = value ?? "";
        return div.innerHTML;
    }

    function scrollToBottom() {
        messages.scrollTop = messages.scrollHeight;
    }

    function renderRows(rows) {
        if (!Array.isArray(rows) || rows.length === 0) return "";

        return `<div class="chatbot-result-list">
            ${rows.map((row) => {
                let cls = "";
                const noteText = String(row.note || "").toLowerCase();

                if (noteText.includes("out of stock") || noteText.includes("critical")) {
                    cls = "is-danger";
                } else if (noteText.includes("low") || noteText.includes("reorder")) {
                    cls = "is-warning";
                }

                return `<div class="chatbot-result-item ${cls}">
                    <div class="chatbot-result-head">
                        <strong>${escapeHtml(row.title || "")}</strong>
                        <span>${escapeHtml(row.value || "")}</span>
                    </div>
                    ${row.meta ? `<div class="chatbot-result-meta">${escapeHtml(row.meta)}</div>` : ""}
                    ${row.note ? `<div class="chatbot-result-note">${escapeHtml(row.note)}</div>` : ""}
                </div>`;
            }).join("")}
        </div>`;
    }

    function appendMessage(type, content, rows = [], suggestions = [], actions = []) {
        const msg = document.createElement("div");
        msg.className = `chatbot-message ${type}`;

        const bubbleCls = type === "bot" && !rows.length && String(content).length < 80
            ? "chatbot-bubble chatbot-bubble-muted"
            : "chatbot-bubble";

        msg.innerHTML = `
            <div class="${bubbleCls}">
                <div class="chatbot-message-text">${escapeHtml(content)}</div>
                ${renderRows(rows)}
                ${renderControls(suggestions, actions)}
            </div>`;

        messages.appendChild(msg);
        scrollToBottom();
    }

    function appendTyping() {
        const typing = document.createElement("div");
        typing.className = "chatbot-message bot";
        typing.id = "chatbotTyping";
        typing.innerHTML = `
            <div class="chatbot-bubble">
                <span class="chatbot-typing-dot"></span>
                <span class="chatbot-typing-dot"></span>
                <span class="chatbot-typing-dot"></span>
            </div>`;
        messages.appendChild(typing);
        scrollToBottom();
    }

    function removeTyping() {
        document.getElementById("chatbotTyping")?.remove();
    }

    async function askQuestion(question) {
        const trimmed = String(question || "").trim();
        if (!trimmed) return;

        appendMessage("user", trimmed);
        appendTyping();

        if (input) input.disabled = true;
        const sendBtn = form?.querySelector("button[type='submit']");
        if (sendBtn) sendBtn.disabled = true;

        try {
            const body = new FormData();
            body.append("question", trimmed);
            body.append("csrf_token", csrfToken);

            const response = await fetch("/inventory_system/http/ajax/dashboard_chatbot.php", {
                method: "POST",
                headers: {
                    "X-Requested-With": "XMLHttpRequest",
                    "Accept": "application/json",
                },
                body,
            });

            const payload = await response.json();
            removeTyping();

            if (!response.ok || !payload.success) {
                const errorText = payload.error || "I couldn't answer that right now. Please try again.";
                appendMessage("bot", errorText);
                speakText(errorText);
                return;
            }

            const answerText = payload.answer || "Here is what I found.";
            const resultRows = Array.isArray(payload.rows) ? payload.rows : [];
            appendMessage("bot", answerText, resultRows, payload.suggestions || [], payload.actions || []);
            speakText(buildSpeechText(answerText, resultRows));
        } catch (err) {
            removeTyping();
            const fallbackText = "Unable to reach the assistant. Please check your connection.";
            appendMessage("bot", fallbackText);
            speakText(fallbackText);
            console.error("[chatbot]", err);
        } finally {
            if (input) {
                input.disabled = false;
                input.focus();
            }
            if (sendBtn) sendBtn.disabled = false;
        }
    }

    form?.addEventListener("submit", (e) => {
        e.preventDefault();
        const question = input?.value || "";
        if (input) input.value = "";
        askQuestion(question);
    });

    quickBtns.forEach((btn) => {
        btn.addEventListener("click", () => {
            const question = btn.dataset.chatQuestion || "";
            openChatbot();
            askQuestion(question);
        });
    });

    messages?.addEventListener("click", (event) => {
        const questionBtn = event.target.closest("[data-chat-question]");
        if (questionBtn) {
            event.preventDefault();
            const question = questionBtn.dataset.chatQuestion || "";
            if (question) {
                askQuestion(question);
            }
            return;
        }

        const actionBtn = event.target.closest("[data-chat-action-url]");
        if (actionBtn) {
            event.preventDefault();
            const actionUrl = actionBtn.dataset.chatActionUrl || "";
            if (actionUrl) {
                window.location.href = actionUrl;
            }
        }
    });

    quickBar?.addEventListener("wheel", (e) => {
        if (window.innerWidth <= 767.98) return;
        if (Math.abs(e.deltaY) <= Math.abs(e.deltaX)) return;

        e.preventDefault();
        quickBar.scrollLeft += e.deltaY;
    }, { passive: false });

    input?.addEventListener("input", () => {
        if (input.tagName === "TEXTAREA") {
            input.style.height = "auto";
            input.style.height = Math.min(input.scrollHeight, 100) + "px";
        }
    });

    input?.addEventListener("keydown", (e) => {
        if (e.key === "Enter" && !e.shiftKey && input.tagName === "TEXTAREA") {
            e.preventDefault();
            form?.dispatchEvent(new Event("submit", { bubbles: true, cancelable: true }));
        }
    });
});
