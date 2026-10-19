const ALLEGRO_SEARCH_URL = "https://allegro.pl/listing";

chrome.runtime.onMessage.addListener((message, _sender, sendResponse) => {
  if (message?.type !== "ALLEGRO_PRICE_RANGE") return false;
  findPriceRange(message.payload).then(sendResponse).catch((err) => sendError(message, err, sendResponse));
  return true;
});

chrome.runtime.onMessageExternal.addListener((message, _sender, sendResponse) => {
  if (message?.type !== "ALLEGRO_PRICE_RANGE") return false;
  findPriceRange(message.payload).then(sendResponse).catch((err) => sendError(message, err, sendResponse));
  return true;
});

function sendError(message, error, sendResponse) {
  sendResponse({
    status: "error",
    query: message.payload?.productName ?? "",
    message: error instanceof Error ? error.message : String(error),
    checked_at: new Date().toISOString()
  });
}

async function findPriceRange(payload = {}) {
  const rawName = String(payload.coin?.name || payload.productName || "").trim();

  const baseCoinName = rawName.replace(/\(.*?\)/g, "").trim();
  const cleanSearchQuery = baseCoinName
    .replace(/[–—-]/g, " ")
    .replace(/[„”"']/g, " ")
    .replace(/[.,]/g, " ")
    .replace(/\s+/g, " ")
    .trim();

  if (!cleanSearchQuery) {
    return { status: "error", message: "Brak nazwy monety" };
  }

  const searchUrl = new URL(ALLEGRO_SEARCH_URL);
  searchUrl.searchParams.set("string", cleanSearchQuery);
  searchUrl.searchParams.set("buyNow", "1"); // Только фиксированная цена Kup Teraz
  searchUrl.searchParams.set("order", "p"); // Сортировка по возрастанию цены

  const timeoutMs = Number(payload.timeoutMs ?? 30000);
  const closeTabs = payload.closeTabs !== false;

  const pageResult = await scanAllegroListing(searchUrl.toString(), rawName, timeoutMs, closeTabs);

  if (!pageResult || pageResult.status === "blocked") {
    return {
      status: "blocked",
      query: cleanSearchQuery,
      reason: pageResult?.reason || "captcha_or_antibot",
      checked_at: new Date().toISOString()
    };
  }

  const validOffers = pageResult.offers || [];
  validOffers.sort((a, b) => a.price - b.price);

  return {
    status: validOffers.length > 0 ? "ok" : "not_found",
    source: "allegro.pl",
    query: cleanSearchQuery,
    min: validOffers.length > 0 ? validOffers[0] : null,
    max: validOffers.length > 0 ? validOffers[validOffers.length - 1] : null,
    checked_at: new Date().toISOString(),
    debug: pageResult.debug
  };
}

async function scanAllegroListing(url, fullCoinName, timeoutMs, closeTabs) {
  const tab = await chrome.tabs.create({ url, active: false });
  let isBlocked = false;

  try {
    const existingTab = await chrome.tabs.get(tab.id);
    if (existingTab.status !== "complete") {
      await waitForTabComplete(tab.id, timeoutMs);
    }

    await sleep(3000);

    const injection = await chrome.scripting.executeScript({
      target: { tabId: tab.id },
      func: extractFilterAndCategorizeOffers,
      args: [fullCoinName]
    });

    const result = injection?.[0]?.result ?? { status: "not_found", offers: [], debug: null };
    if (result.status === "blocked") {
      isBlocked = true;
      chrome.tabs.update(tab.id, { active: true }).catch(() => {});
    }

    return result;
  } catch (error) {
    return { status: "blocked", reason: error.message, offers: [], debug: null };
  } finally {
    if (closeTabs && tab.id && !isBlocked) {
      await chrome.tabs.remove(tab.id).catch(() => {});
    }
  }
}

function waitForTabComplete(tabId, timeoutMs) {
  return new Promise((resolve) => {
    const timeout = setTimeout(() => {
      chrome.tabs.onUpdated.removeListener(listener);
      resolve();
    }, timeoutMs);

    const listener = (updatedTabId, changeInfo) => {
      if (updatedTabId === tabId && changeInfo.status === "complete") {
        clearTimeout(timeout);
        chrome.tabs.onUpdated.removeListener(listener);
        resolve();
      }
    };

    chrome.tabs.onUpdated.addListener(listener);
  });
}

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

function extractFilterAndCategorizeOffers(fullCoinName) {
  try {
    if (isRealBotChallengePage()) {
      return { status: "blocked", reason: "captcha_detected", offers: [], debug: null };
    }

    const parsedRules = parseCoinRules(fullCoinName);

    const anchors = [...document.querySelectorAll('a[href*="/oferta/"], a[href*="/produkt/"]')];
    const candidateMap = new Map();

    for (const anchor of anchors) {
      if (anchor.closest('[data-box-name*="carousel"], [data-box-name*="recommendation"]')) {
        continue;
      }

      const href = new URL(anchor.href, location.href).href;
      const cleanUrl = href.split("?")[0];

      const container = anchor.closest("article") || findSafeContainer(anchor);
      if (!container) continue;

      const title =
        container.querySelector("h1, h2, h3")?.innerText?.trim() ||
        anchor.innerText?.trim() ||
        "";

      const price = extractOfferPrice(container);
      const isAuction = isAuctionOffer(container);
      const containerText = container.innerText || "";

      const existing = candidateMap.get(cleanUrl);
      if (!existing || (!existing.title && title) || (existing.price === null && price !== null)) {
        candidateMap.set(cleanUrl, {
          url: href,
          title: title || existing?.title || "",
          price: price ?? existing?.price ?? null,
          isAuction: isAuction ?? existing?.isAuction ?? false,
          containerText: containerText || existing?.containerText || ""
        });
      }
    }

    const accepted = [];
    const rejected = [];

    for (const candidate of candidateMap.values()) {
      if (candidate.price === null) {
        rejected.push({
          title: candidate.title,
          price: "—",
          url: candidate.url,
          reason: "brak_ceny"
        });
        continue;
      }

      if (candidate.isAuction) {
        rejected.push({
          title: candidate.title,
          price: `${candidate.price} zł`,
          url: candidate.url,
          reason: "odrzucono: aukcja / licytacja"
        });
        continue;
      }

      if (isBundleOrBulkLot(candidate.title, candidate.containerText)) {
        rejected.push({
          title: candidate.title,
          price: `${candidate.price} zł`,
          url: candidate.url,
          reason: "odrzucono: zestaw / komplet / konstrukcja '+' / hurt"
        });
        continue;
      }

      if (isFiveZlotyBulkAnomaly(parsedRules, candidate.price)) {
        rejected.push({
          title: candidate.title,
          price: `${candidate.price} zł`,
          url: candidate.url,
          reason: "odrzucono: anomalia ceny dla monety 5 zł"
        });
        continue;
      }

      if (isFolderBeforeTitle(candidate.title, parsedRules.requiredWords)) {
        rejected.push({
          title: candidate.title,
          price: `${candidate.price} zł`,
          url: candidate.url,
          reason: "odrzucono: slowo 'folder' przed nazwa"
        });
        continue;
      }

      const titleMatch = checkTitleMatchesCoin(candidate.title, parsedRules.requiredWords);
      if (!titleMatch.ok) {
        rejected.push({
          title: candidate.title,
          price: `${candidate.price} zł`,
          url: candidate.url,
          reason: titleMatch.reason
        });
        continue;
      }

      if (parsedRules.targetNominal) {
        const nominalMatch = checkNominalMatch(candidate.title, parsedRules.targetNominal);
        if (!nominalMatch.ok) {
          rejected.push({
            title: candidate.title,
            price: `${candidate.price} zł`,
            url: candidate.url,
            reason: nominalMatch.reason
          });
          continue;
        }
      }

      accepted.push({
        title: candidate.title,
        price: candidate.price,
        currency: "PLN",
        url: candidate.url
      });
    }

    accepted.sort((a, b) => a.price - b.price);

    return {
      status: accepted.length > 0 ? "ok" : "not_found",
      offers: accepted,
      debug: { accepted, rejected }
    };

    function isRealBotChallengePage() {
      if (document.querySelector('iframe[src*="captcha-delivery.com"], iframe[src*="datadome"], #datadome-captcha')) {
        return true;
      }

      const pageText = (document.body?.innerText ?? "").toLowerCase();
      const botPhrases = [
        "potwierdź, że nie jesteś robotem",
        "potwierdz, ze nie jestes robotem",
        "udowodnij, że nie jesteś robotem",
        "udowodnij, ze nie jestes robotem",
        "wykryliśmy nietypowy ruch",
        "wykryto nietypowy ruch",
        "zabezpieczenie przed botami",
        "prosimy o rozwiązanie testu",
        "twoje połączenie zostało zablokowane"
      ];

      return botPhrases.some((phrase) => pageText.includes(phrase));
    }

    function isAuctionOffer(container) {
      const text = (container.innerText || "").toLowerCase();
      if (/\blicytac|\blicytuj|os[oó]b\s+licytuje|do\s+ko[nń]ca\s+licytacji/i.test(text)) {
        return true;
      }
      const ariaElements = container.querySelectorAll('[aria-label*="licytac"], [aria-label*="licytuj"]');
      return ariaElements.length > 0;
    }

    function parseCoinRules(rawName) {
      const nominalMatch = rawName.match(/\((\d+)/);
      const targetNominal = nominalMatch ? nominalMatch[1] : null;

      const baseName = rawName.replace(/\(.*?\)/g, "").trim();
      const clean = baseName
        .replace(/[–—-]/g, " ")
        .replace(/[„”"']/g, " ")
        .replace(/[.,]/g, " ")
        .trim();

      const prepositions = new Set(["w", "o", "z", "i", "do", "na", "od", "u"]);

      const words = clean
        .split(/\s+/)
        .filter((w) => w.length >= 2 && !prepositions.has(w.toLowerCase()))
        .map((w) => ({
          word: w,
          stem: getPolishStem(w)
        }));

      const isFiveZloty = /odkryj\s+polsk/i.test(clean) || /\b5\s*(?:zł|zl)\b/i.test(rawName);

      return {
        targetNominal,
        requiredWords: words,
        isFiveZloty
      };
    }

    function isBundleOrBulkLot(rawTitle, rawContainerText) {
      if (/\b\d+\w*\s*\+\s*\d+\w*/i.test(rawTitle)) return true;
      if (/\b\d+\s*\+\s*\d+/i.test(rawTitle)) return true;
      if (/\+\s*\d+\s*(?:zł|zl|pln|r\b|rocznic)/i.test(rawTitle)) return true;
      if (/\d+\s*(?:zł|zl|pln)\s*\+\s*/i.test(rawTitle)) return true;

      const nominalMatches = rawTitle.match(/\b(500|200|100|50|20|10|5|2)\s*(?:zł|zl|pln)\b/gi) || [];
      const uniqueNominals = new Set(nominalMatches.map((n) => n.toLowerCase().replace(/\s+/g, "")));
      if (uniqueNominals.size >= 2) return true;

      if (/\bliczba\s+komplet/i.test(rawContainerText) || /\bkomplet[oó]w\b/i.test(rawContainerText)) return true;

      const normTitle = normalizeText(rawTitle);
      if (/\b(?:worek|worki|workow|worka|worecz\w*)\b/i.test(normTitle)) return true;
      if (/\b(?:rolk\w*|rolek|rolce|tub\w*|zgrzewk\w*|karton\w*|box\b)\b/i.test(normTitle)) return true;

      if (/\b\d+\s*x\s*\d+/i.test(normTitle)) return true;
      if (/\b[2-9]\s*x\b/i.test(normTitle)) return true;
      if (/\b\d{2,}\s*x\b/i.test(normTitle)) return true;
      if (/\b\d{2,}\s*szt\w*\b/i.test(normTitle)) return true;

      if (/\b(?:zestaw\w*|pakiet\w*|lot\b|kolekcj\w*|hurt\w*)\b/i.test(normTitle)) {
        return true;
      }

      if (/\b(?:10oz|5oz|20oz)\b/i.test(normTitle)) return true;

      return false;
    }

    function isFiveZlotyBulkAnomaly(rules, price) {
      return rules.isFiveZloty && price > 120;
    }

    function checkTitleMatchesCoin(offerTitle, requiredWords) {
      const normTitle = normalizeText(offerTitle);

      for (const item of requiredWords) {
        if (!normTitle.includes(item.stem)) {
          return {
            ok: false,
            reason: `brak_slowa_nazwy: [${item.word}]`
          };
        }
      }

      return { ok: true };
    }

    function checkNominalMatch(offerTitle, targetNominal) {
      const normTitle = normalizeText(offerTitle);

      const targetPattern = new RegExp(`(?:^|\\D)${targetNominal}\\s*(?:zl|pln|zlot\\w*)\\b`, "i");
      const targetReversePattern = new RegExp(`\\b(?:zl|pln)\\s*${targetNominal}(?:\\D|$)`, "i");
      const targetWordPattern = new RegExp(`\\bnominal\\w*\\s*${targetNominal}\\b`, "i");

      const hasTarget = targetPattern.test(normTitle) || targetReversePattern.test(normTitle) || targetWordPattern.test(normTitle);

      if (!hasTarget) {
        return { ok: false, reason: `brak_docelowego_nominalu: [${targetNominal} zł]` };
      }

      const otherNominals = ["500", "200", "100", "50", "20", "10", "5"].filter((n) => n !== String(targetNominal));

      for (const other of otherNominals) {
        const otherPattern = new RegExp(`(?:^|\\D)${other}\\s*(?:zl|pln|zlot\\w*)\\b`, "i");
        const otherReverse = new RegExp(`\\b(?:zl|pln)\\s*${other}(?:\\D|$)`, "i");

        if (otherPattern.test(normTitle) || otherReverse.test(normTitle)) {
          return { ok: false, reason: `wykryto_inny_nominal: [${other} zł] (oczekiwano ${targetNominal} zł)` };
        }
      }

      return { ok: true };
    }

    function isFolderBeforeTitle(offerTitle, wordsList) {
      const normTitle = normalizeText(offerTitle);
      const folderMatch = normTitle.match(/\bfolder\w*/i);
      if (!folderMatch) return false;

      const folderIndex = folderMatch.index;

      let firstCoinWordIndex = Infinity;
      for (const item of wordsList) {
        const idx = normTitle.indexOf(item.stem);
        if (idx !== -1 && idx < firstCoinWordIndex) {
          firstCoinWordIndex = idx;
        }
      }

      return folderIndex < firstCoinWordIndex;
    }

    function getPolishStem(word) {
      let norm = normalizeText(word);
      if (norm.length <= 3) return norm;

      const endings = [
        "skiego", "ckie", "skiej", "ckiej", "skich", "ckich",
        "skim", "ckim", "skimi", "ckimi", "ski", "ska", "skie",
        "owej", "owych", "owym", "owego", "owa", "owe", "owy",
        "iego", "iemu", "iej", "ich", "iem",
        "ami", "ach", "om", "ow",
        "ego", "owi", "em", "ie", "ym", "im",
        "a", "u", "y", "e", "o", "ą", "ę", "i"
      ];

      for (const end of endings) {
        if (norm.endsWith(end) && norm.length - end.length >= 3) {
          return norm.slice(0, -end.length);
        }
      }

      return norm;
    }

    function extractOfferPrice(container) {
      const ariaPrice = container.querySelector('[aria-label*="aktualna cena"], [aria-label*="cena:"]');
      if (ariaPrice) {
        const text = ariaPrice.getAttribute("aria-label") || "";
        const match = text.match(/(?:aktualna\s+cena|cena)?:?\s*(\d+(?:[\s\u00a0.]\d{3})*(?:[,\.]\d{2})?)\s*(?:zł|PLN)/i);
        if (match) {
          const parsed = parsePrice(match[1]);
          if (parsed > 0) return parsed;
        }
      }

      const elements = container.querySelectorAll("div, span, p");
      const candidates = [];

      for (const el of elements) {
        if (el.closest("del, s, [class*='strikethrough'], [class*='originalPrice']")) continue;
        if (el.closest("h1, h2, h3, h4, h5, h6")) continue;

        const parentLink = el.closest("a");
        if (parentLink && (parentLink.innerText || "").trim().length > 30) continue;

        const rawText = (el.innerText || "").replace(/\u00a0/g, " ").trim();

        if (!/(?:zł|PLN)/i.test(rawText) || rawText.length > 30) continue;
        if (/rat[ay]|mies|dostaw|\/g\b|\/szt\b|smart/i.test(rawText)) continue;

        const lettersOnly = rawText.replace(/[\d.,\s/–—+:-]/g, " ").trim();
        const words = lettersOnly.split(/\s+/).filter(Boolean);
        const hasCoinWords = words.some((w) => !/^(?:zł|pln|gr|od|cena)\$/i.test(w) && w.length >= 3);
        if (hasCoinWords) continue;

        const match = rawText.match(/(?<![\d,.])(\d+(?:[\s.]\d{3})*(?:[,\.]\d{2})?)\s*(?:zł|PLN)/i);
        if (match) {
          const val = parsePrice(match[1]);
          if (val > 0) {
            candidates.push({ price: val, len: rawText.length });
          }
        }
      }

      if (candidates.length === 0) return null;

      candidates.sort((a, b) => a.len - b.len);
      return candidates[0].price;
    }

    function parsePrice(rawStr) {
      const clean = rawStr.replace(/[\s.]/g, "");
      return clean.includes(",") ? parseFloat(clean.replace(",", ".")) : parseFloat(clean);
    }

    function findSafeContainer(anchor) {
      let node = anchor.parentElement;
      for (let depth = 0; depth < 5 && node && node !== document.body; depth++) {
        if (node.querySelectorAll("article").length > 1) break;
        if (/\d+(?:[,\.]\d{2})?\s*zł/i.test(node.innerText || "")) return node;
        node = node.parentElement;
      }
      return null;
    }

    function normalizeText(text) {
      return (text || "")
        .toLowerCase()
        .normalize("NFD")
        .replace(/[\u0300-\u036f]/g, "")
        .replace(/\u0142/g, "l")
        .replace(/[^a-z0-9\s]/gi, " ")
        .replace(/\s+/g, " ")
        .trim();
    }
  } catch (error) {
    return { status: "blocked", reason: error.message, offers: [], debug: null };
  }
}