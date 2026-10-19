(() => {
  const currentExtensionId = chrome.runtime.id;

  function syncIdToPage() {
    const input = document.getElementById("extensionId");
    if (input) {
      input.value = currentExtensionId;
      input.dispatchEvent(new Event("input", { bubbles: true }));
      input.dispatchEvent(new Event("change", { bubbles: true }));
    }

    const statusBadge = document.getElementById("extensionStatusBadge");
    if (statusBadge) {
      statusBadge.textContent = "✓ Połączono automatycznie";
      statusBadge.className = "badge badge-success";
    }

    window.postMessage(
      {
        type: "ALLEGRO_EXT_READY",
        extensionId: currentExtensionId
      },
      "*"
    );
  }

  syncIdToPage();
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", syncIdToPage);
  } else {
    setTimeout(syncIdToPage, 200);
  }

  window.addEventListener("message", (event) => {
    if (event.source !== window || !event.data) return;

    if (event.data.type === "ALLEGRO_PING") {
      syncIdToPage();
      window.postMessage(
        {
          type: "ALLEGRO_PONG",
          extensionId: currentExtensionId
        },
        "*"
      );
      return;
    }

    if (event.data.type === "ALLEGRO_PAGE_REQUEST") {
      const { requestId, payload } = event.data;

      chrome.runtime.sendMessage(
        {
          type: "ALLEGRO_PRICE_RANGE",
          payload: payload
        },
        (response) => {
          const lastError = chrome.runtime.lastError ? chrome.runtime.lastError.message : null;
          window.postMessage(
            {
              type: "ALLEGRO_PAGE_RESPONSE",
              requestId: requestId,
              response: response || { status: "error", message: lastError || "Brak odpowiedzi z rozszerzenia" },
              error: lastError
            },
            "*"
          );
        }
      );
    }
  });
})();