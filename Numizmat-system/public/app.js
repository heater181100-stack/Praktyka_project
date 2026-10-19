(function () {
  let isExtensionReady = false;

  window.addEventListener("message", (event) => {
    if (event.source !== window || !event.data) return;

    if (event.data.type === "ALLEGRO_EXT_READY" || event.data.type === "ALLEGRO_PONG") {
      isExtensionReady = true;
      const extId = event.data.extensionId;

      if (extId) {
        sessionStorage.setItem("allegro_extension_id", extId);
        localStorage.setItem("allegro_extension_id", extId);

        const extInput = document.getElementById("extensionId");
        if (extInput) extInput.value = extId;
      }

      const statusBadge = document.getElementById("extensionStatusBadge");
      if (statusBadge) {
        statusBadge.textContent = "✓ Połączono automatycznie";
        statusBadge.className = "badge badge-success";
      }

      const progressStatus = document.getElementById("progressStatus");
      if (progressStatus && progressStatus.textContent.includes("Wykrywanie")) {
        progressStatus.textContent = "Gotowe do uruchomienia synchronizacji na dzień dzisiejszy.";
      }
    }
  });

  window.postMessage({ type: "ALLEGRO_PING" }, "*");

  window.startAllegroSync = async function () {
    const startBtn = document.getElementById("startSyncBtn");
    const progressStatus = document.getElementById("progressStatus");
    const rows = document.querySelectorAll("#coinsTableBody tr");

    window.postMessage({ type: "ALLEGRO_PING" }, "*");
    await new Promise((r) => setTimeout(r, 100));

    const extInput = document.getElementById("extensionId");
    const activeId = extInput?.value?.trim() || sessionStorage.getItem("allegro_extension_id") || localStorage.getItem("allegro_extension_id") || "";

    if (!activeId && !isExtensionReady) {
      alert("Rozszerzenie Chrome nie zostało wykryte. Uruchom system przez plik start.bat!");
      return;
    }

    if (startBtn) startBtn.disabled = true;
    if (progressStatus) progressStatus.textContent = "Inicjalizacja wyszukiwania...";

    let completed = 0;
    const total = rows.length;

    for (const row of rows) {
      const coinName = row.dataset.coinName;
      const statusCell = row.querySelector(".sync-status");
      const minCell = row.querySelector(".min-price");
      const maxCell = row.querySelector(".max-price");
      const timeCell = row.querySelector(".scan-time");

      if (statusCell) statusCell.innerHTML = '<span class="badge badge-loading">Szukanie...</span>';
      if (progressStatus) progressStatus.textContent = `Przetwarzanie: ${completed + 1} z ${total} (${coinName})`;

      try {
        const response = await queryExtension(activeId, coinName);
        const currentTime = new Date().toLocaleTimeString("pl-PL");

        if (response && response.status === "ok" && response.min?.price != null) {
          const minOffer = response.min;
          const maxOffer = response.max;

          minCell.innerHTML = `<a href="${minOffer.url}" target="_blank" rel="noopener noreferrer" class="price-link">${formatCurrency(minOffer.price)} zł ↗</a>`;
          maxCell.innerHTML = maxOffer?.price != null 
            ? `<a href="${maxOffer.url}" target="_blank" rel="noopener noreferrer" class="price-link">${formatCurrency(maxOffer.price)} zł ↗</a>` 
            : '<span class="text-muted">—</span>';

          if (timeCell) timeCell.textContent = currentTime;

          const count = response.debug?.accepted?.length ?? 1;
          if (statusCell) statusCell.innerHTML = `<span class="badge badge-success">Zapisano dziś (${count})</span>`;

          await fetch("api.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
              coin_name: coinName,
              min_price: minOffer.price,
              max_price: maxOffer?.price ?? minOffer.price,
              min_url: minOffer.url,
              max_url: maxOffer?.url ?? minOffer.url
            })
          });
        } else if (response && response.status === "blocked") {
          if (statusCell) statusCell.innerHTML = '<span class="badge badge-danger">Captcha / Blokada</span>';
          if (timeCell) timeCell.textContent = currentTime;
        } else {
          minCell.innerHTML = '<span class="text-muted">—</span>';
          maxCell.innerHTML = '<span class="text-muted">—</span>';
          if (statusCell) statusCell.innerHTML = '<span class="badge badge-warning">Brak ofert</span>';
          if (timeCell) timeCell.textContent = currentTime;

          await fetch("api.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
              coin_name: coinName,
              min_price: null,
              max_price: null,
              min_url: null,
              max_url: null
            })
          });
        }
      } catch (err) {
        console.error("Błąd zapytania dla monety:", coinName, err);
        if (statusCell) statusCell.innerHTML = '<span class="badge badge-danger">Błąd połączenia</span>';
        if (timeCell) timeCell.textContent = new Date().toLocaleTimeString("pl-PL");
      }

      completed++;
      await new Promise((r) => setTimeout(r, 1500));
    }

    if (progressStatus) progressStatus.textContent = "Synchronizacja zakończona!";
    if (startBtn) startBtn.disabled = false;
  };

  function queryExtension(extId, productName) {
    return new Promise((resolve, reject) => {
      const requestId = "req_" + Math.random().toString(36).slice(2, 11);
      let isResolved = false;

      const timeout = setTimeout(() => {
        window.removeEventListener("message", messageHandler);
        if (!isResolved) {
          reject(new Error("Timeout zapytania do rozszerzenia (45s)"));
        }
      }, 45000);

      function messageHandler(event) {
        if (event.source !== window || !event.data || event.data.type !== "ALLEGRO_PAGE_RESPONSE") {
          return;
        }

        if (event.data.requestId === requestId) {
          isResolved = true;
          clearTimeout(timeout);
          window.removeEventListener("message", messageHandler);

          if (event.data.error) {
            reject(new Error(event.data.error));
          } else {
            resolve(event.data.response || { status: "not_found" });
          }
        }
      }

      window.addEventListener("message", messageHandler);

      window.postMessage(
        {
          type: "ALLEGRO_PAGE_REQUEST",
          requestId: requestId,
          payload: {
            productName: productName,
            category: "coins",
            closeTabs: true,
            timeoutMs: 30000
          }
        },
        "*"
      );
    });
  }

  function formatCurrency(val) {
    return Number(val).toLocaleString("pl-PL", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }
})();