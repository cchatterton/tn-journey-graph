(function () {
  const root = window.TNJG || {};
  const launch = document.querySelector(".tnjg-launch");
  const panel = document.querySelector("#tnjg-panel");
  const storageKey = "tnjg.panelOpen";
  const maxRows = 5;

  if (!launch || !panel || !root.restUrl) {
    return;
  }

  const state = {
    open: false,
    hop: "",
    filter: "all",
    data: null,
    loading: false,
  };

  function requestUrl() {
    const url = new URL(root.restUrl);
    const context = root.context || {};
    url.searchParams.set("object_id", context.object_id || "0");
    url.searchParams.set("object_type", context.object_type || "");
    url.searchParams.set("url", context.url || window.location.href);
    url.searchParams.set("filter", state.filter);
    if (state.hop) {
      url.searchParams.set("hop", state.hop);
    }
    return url;
  }

  function loadJourney() {
    state.loading = true;
    render();

    fetch(requestUrl(), {
      headers: {
        "X-WP-Nonce": root.nonce,
      },
      credentials: "same-origin",
    })
      .then((response) => {
        if (!response.ok) {
          throw new Error("Request failed");
        }
        return response.json();
      })
      .then((data) => {
        state.data = data;
        state.hop = data.selectedHop || "";
        state.loading = false;
        render();
      })
      .catch(() => {
        state.data = null;
        state.loading = false;
        renderError();
      });
  }

  function openPanel() {
    state.open = true;
    setPanelPreference(true);
    launch.setAttribute("aria-expanded", "true");
    panel.setAttribute("aria-hidden", "false");
    panel.classList.add("tnjg-panel--open");
    loadJourney();
  }

  function closePanel() {
    state.open = false;
    setPanelPreference(false);
    launch.setAttribute("aria-expanded", "false");
    panel.setAttribute("aria-hidden", "true");
    panel.classList.remove("tnjg-panel--open");
  }

  function render() {
    const content = panel.querySelector(".tnjg-panel__inner");

    if (state.loading) {
      content.innerHTML = shellMarkup(`${closeControlsMarkup()}<div class="tnjg-state">${escapeHtml(root.labels.loading)}</div>`);
      bindPanelEvents();
      return;
    }

    if (!state.data || !state.data.hops || state.data.hops.length === 0) {
      content.innerHTML = shellMarkup(`${closeControlsMarkup()}<div class="tnjg-state">${escapeHtml((state.data && state.data.emptyMessage) || root.labels.empty)}</div>`);
      bindPanelEvents();
      return;
    }

    content.innerHTML = shellMarkup(`
      ${controlsMarkup(state.data.hops)}
      <div class="tnjg-flow">${(state.data.groups || []).map(groupMarkup).join("")}</div>
    `);
    bindPanelEvents();
  }

  function shellMarkup(body) {
    return body;
  }

  function controlsMarkup(hops) {
    const heatHops = hops.filter((hop) => String(hop.key) !== "0");
    const heatSource = heatHops.length ? heatHops : hops;
    const max = Math.max(...heatSource.map((hop) => Number(hop.count) || 0), 1);
    return `
      <div class="tnjg-tabs" role="tablist">
        ${hops
          .map((hop) => {
            const selected = hop.key === state.hop;
            const intensity = Math.max(0.16, Math.min(1, (Number(hop.count) || 0) / max)).toFixed(2);
            const borderAlpha = (0.18 + (Number(intensity) * 0.45)).toFixed(2);
            const backgroundAlpha = (0.12 + (Number(intensity) * 0.42)).toFixed(2);
            const opacity = (0.62 + (Number(intensity) * 0.38)).toFixed(2);
            return `<button class="tnjg-tab${selected ? " tnjg-tab--active" : ""}" type="button" role="tab" data-hop="${escapeAttr(hop.key)}" aria-selected="${selected ? "true" : "false"}" title="${escapeAttr(number(hop.count))} journeys" style="--tnjg-border-alpha:${borderAlpha};--tnjg-bg-alpha:${backgroundAlpha};--tnjg-opacity:${opacity}">${escapeHtml(hop.label)}</button>`;
          })
          .join("")}
        ${filtersMarkup()}
        ${closeButtonMarkup()}
      </div>
    `;
  }

  function groupMarkup(group) {
    const panels = group.panels || [];
    return `
      <section class="tnjg-group tnjg-group--${escapeAttr(group.key || "")}">
        <h2>${escapeHtml(group.title || "")}</h2>
        <div class="tnjg-grid">${panels.map(panelMarkup).join("")}</div>
      </section>
    `;
  }

  function closeControlsMarkup() {
    return `<div class="tnjg-tabs">${closeButtonMarkup()}</div>`;
  }

  function closeButtonMarkup() {
    return `<button class="tnjg-close" type="button" aria-label="${escapeHtml(root.labels.close)}">×</button>`;
  }

  function filtersMarkup() {
    const options = contentTypeOptions();

    return `
      <label class="tnjg-filter">
        <select aria-label="${escapeAttr(root.labels.filter)}">
          ${options.map(([value, label]) => `<option value="${value}"${value === state.filter ? " selected" : ""}>${label}</option>`).join("")}
        </select>
      </label>
    `;
  }

  function contentTypeOptions() {
    const options = [["all", "All"]];
    const seen = new Set(["all"]);
    const contentTypes = (state.data && state.data.contentTypes) || [];

    contentTypes.forEach((contentType) => {
      const value = String(contentType.value || "").trim();
      const label = String(contentType.label || "").trim();

      if (value && label && !seen.has(value)) {
        seen.add(value);
        options.push([value, label]);
      }
    });

    if (!seen.has(state.filter)) {
      state.filter = "all";
    }

    return options;
  }

  function panelMarkup(panelData) {
    const items = (panelData.items || []).slice(0, maxRows);
    const rows = items.map(itemMarkup);

    if (rows.length === 0) {
      rows.push(emptyRowMarkup("No data"));
    }

    while (rows.length < maxRows) {
      rows.push(emptyRowMarkup(""));
    }

    return `
      <article class="tnjg-card">
        <div class="tnjg-card__head">
          <h3>${escapeHtml(panelData.title)}</h3>
        </div>
        <div class="tnjg-bars">${rows.join("")}</div>
      </article>
    `;
  }

  function itemMarkup(item) {
    const percent = Math.max(0, Math.min(100, Number(item.percentage) || 0));
    const label = escapeHtml(item.label || "Unknown");
    const labelMarkup = item.url ? `<a href="${escapeAttr(item.url)}">${label}</a>` : `<span>${label}</span>`;
    const title = `${item.label || "Unknown"} — ${number(item.count)} (${percent}%)`;

    return `
      <div class="tnjg-bar-row" title="${escapeAttr(title)}">
        <div class="tnjg-bar-row__top">
          ${labelMarkup}
        </div>
        <div class="tnjg-bar-row__track">
          <span style="width:${percent}%"></span>
        </div>
      </div>
    `;
  }

  function emptyRowMarkup(label) {
    return `
      <div class="tnjg-bar-row tnjg-bar-row--empty">
        <div class="tnjg-bar-row__top">
          <span>${escapeHtml(label)}</span>
        </div>
        <div class="tnjg-bar-row__track">
          <span style="width:0"></span>
        </div>
      </div>
    `;
  }

  function bindPanelEvents() {
    const close = panel.querySelector(".tnjg-close");
    if (close) {
      close.addEventListener("click", closePanel);
    }

    panel.querySelectorAll("[data-hop]").forEach((button) => {
      button.addEventListener("click", () => {
        state.hop = button.getAttribute("data-hop") || "";
        loadJourney();
      });
    });

    const filter = panel.querySelector(".tnjg-filter select");
    if (filter) {
      filter.addEventListener("change", () => {
        state.filter = filter.value;
        loadJourney();
      });
    }
  }

  function renderError() {
    const content = panel.querySelector(".tnjg-panel__inner");
    content.innerHTML = shellMarkup(`${closeControlsMarkup()}<div class="tnjg-state tnjg-state--error">${escapeHtml(root.labels.error)}</div>`);
    bindPanelEvents();
  }

  function escapeHtml(value) {
    return String(value).replace(/[&<>"']/g, (char) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#039;" }[char]));
  }

  function escapeAttr(value) {
    return escapeHtml(value).replace(/`/g, "&#096;");
  }

  function number(value) {
    return new Intl.NumberFormat().format(Number(value) || 0);
  }

  function getPanelPreference() {
    try {
      return window.localStorage.getItem(storageKey) === "1";
    } catch (error) {
      return false;
    }
  }

  function setPanelPreference(open) {
    try {
      window.localStorage.setItem(storageKey, open ? "1" : "0");
    } catch (error) {
      // Ignore storage failures; the drawer still works for this page view.
    }
  }

  launch.addEventListener("click", () => {
    if (state.open) {
      closePanel();
    } else {
      openPanel();
    }
  });

  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape" && state.open) {
      closePanel();
    }
  });

  if (getPanelPreference()) {
    openPanel();
  }
})();
