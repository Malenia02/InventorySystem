document.addEventListener("DOMContentLoaded", () => {
  const form = document.getElementById("chatbotConsoleForm");
  if (!form) return;

  const questionInput = document.getElementById("chatbotConsoleQuestion");
  const clearButton = document.getElementById("chatbotConsoleClear");
  const intentNode = document.getElementById("chatbotConsoleIntent");
  const answerNode = document.getElementById("chatbotConsoleAnswer");
  const confidenceNode = document.getElementById("chatbotConsoleConfidence");
  const periodNode = document.getElementById("chatbotConsolePeriod");
  const successNode = document.getElementById("chatbotConsoleSuccess");
  const rowsNode = document.getElementById("chatbotConsoleRows");
  const phrasesNode = document.getElementById("chatbotConsolePhrases");
  const conceptsNode = document.getElementById("chatbotConsoleConcepts");
  const rawNode = document.getElementById("chatbotConsoleRaw");
  const csrfToken = form.querySelector('[name="csrf_token"]')?.value || "";

  const escapeHtml = (value) =>
    String(value ?? "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");

  const renderTags = (node, values, emptyLabel) => {
    if (!node) return;
    const items = Array.isArray(values) ? values : [];
    node.innerHTML = items.length
      ? items.map((item) => `<span>${escapeHtml(item)}</span>`).join("")
      : `<em>${escapeHtml(emptyLabel)}</em>`;
  };

  const setLoading = (isLoading) => {
    form.querySelectorAll("button, textarea").forEach((element) => {
      element.disabled = isLoading;
    });
    if (confidenceNode) {
      confidenceNode.className = "ops-status is-info";
      confidenceNode.textContent = isLoading ? "Testing" : "Ready";
    }
  };

  const renderResult = (data) => {
    const debug = data.intent_debug || {};
    const confidence = debug.confidence;

    if (intentNode) intentNode.textContent = debug.intent || data.intent || "No intent";
    if (answerNode) answerNode.textContent = data.answer || data.error || "No assistant answer returned.";
    if (periodNode) periodNode.textContent = debug.period || "-";
    if (successNode) successNode.textContent = data.success ? "Yes" : "No";
    if (rowsNode) rowsNode.textContent = Array.isArray(data.rows) ? data.rows.length.toLocaleString() : "0";
    if (rawNode) rawNode.textContent = JSON.stringify(data, null, 2);

    if (confidenceNode) {
      const label = confidence === null || typeof confidence === "undefined"
        ? "Exact"
        : `${Math.round(Number(confidence) * 100)}%`;
      confidenceNode.textContent = label;
      confidenceNode.className = `ops-status ${Number(confidence || 1) >= 0.75 ? "is-success" : "is-warning"}`;
    }

    renderTags(phrasesNode, debug.matched_phrases, "No phrase match recorded");
    renderTags(conceptsNode, debug.matched_concepts, "No concept match recorded");
  };

  const submitPrompt = async () => {
    const question = (questionInput?.value || "").trim();
    if (!question) {
      questionInput?.focus();
      return;
    }

    setLoading(true);

    const body = new URLSearchParams();
    body.set("csrf_token", csrfToken);
    body.set("question", question);
    body.set("debug_intent", "1");

    try {
      const response = await fetch("/inventory_system/http/ajax/dashboard_chatbot.php", {
        method: "POST",
        headers: {
          "Content-Type": "application/x-www-form-urlencoded",
          "X-Requested-With": "XMLHttpRequest",
          Accept: "application/json",
        },
        body,
      });

      const text = await response.text();
      let data = {};
      try {
        data = JSON.parse(text);
      } catch (error) {
        data = {
          success: false,
          error: "Invalid JSON response from chatbot endpoint.",
          raw: text,
        };
      }

      renderResult(data);
    } catch (error) {
      renderResult({
        success: false,
        error: error.message || "Unable to reach chatbot endpoint.",
      });
    } finally {
      setLoading(false);
    }
  };

  form.addEventListener("submit", (event) => {
    event.preventDefault();
    submitPrompt();
  });

  clearButton?.addEventListener("click", () => {
    if (questionInput) questionInput.value = "";
    renderResult({ success: false, answer: "Console cleared." });
    questionInput?.focus();
  });

  document.querySelectorAll(".chatbot-sample-question").forEach((button) => {
    button.addEventListener("click", () => {
      if (questionInput) questionInput.value = button.dataset.question || button.textContent || "";
      submitPrompt();
    });
  });
});
