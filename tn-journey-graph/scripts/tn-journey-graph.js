(function () {
  const root = window.TNJG || {};
  const launch = document.querySelector(".tnjg-launch");
  const panel = document.querySelector("#tnjg-panel");

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
    launch.setAttribute("aria-expanded", "true");
    panel.setAttribute("aria-hidden", "false");
    panel.classList.add("tnjg-panel--open");
    loadJourney();
  }

  function closePanel() {
    state.open = false;
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
    const max = Math.max(...hops.map((hop) => Number(hop.count) || 0), 1);
    return `
      <div class="tnjg-tabs" role="tablist">
        ${hops
          .map((hop) => {
            const selected = hop.key === state.hop;
            const intensity = Math.max(0.16, Math.min(1, (Number(hop.count) || 0) / max)).toFixed(2);
            return `<button class="tnjg-tab${selected ? " tnjg-tab--active" : ""}" type="button" role="tab" data-hop="${escapeAttr(hop.key)}" aria-selected="${selected ? "true" : "false"}" title="${escapeAttr(number(hop.count))} journeys" style="--tnjg-intensity:${intensity}">${escapeHtml(hop.label)}</button>`;
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
    const options = [
      ["all", "All"],
      ["pages", "Pages"],
      ["posts", "Posts"],
      ["campaigns", "Campaigns"],
      ["custom_post_types", "Custom post types"],
      ["unknown_urls", "Unknown URLs"],
      ["external", "External"],
      ["exit", "Exit"],
    ];

    return `
      <label class="tnjg-filter">
        <span>${escapeHtml(root.labels.filter)}</span>
        <select>
          ${options.map(([value, label]) => `<option value="${value}"${value === state.filter ? " selected" : ""}>${label}</option>`).join("")}
        </select>
      </label>
    `;
  }

  function panelMarkup(panelData) {
    const items = panelData.items || [];
    const body = items.length
      ? items.map(itemMarkup).join("")
      : `<div class="tnjg-empty-row">No data</div>`;

    return `
      <article class="tnjg-card">
        <div class="tnjg-card__head">
          <h3>${escapeHtml(panelData.title)}</h3>
        </div>
        <div class="tnjg-bars">${body}</div>
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
})();
