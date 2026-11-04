const thumbSvg = `<span class="thumb" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="10" r="1.4"/><path d="m21 16-5.2-5.2a1 1 0 0 0-1.4 0L7 18"/></svg></span>`;

const overviewRows = [
  {
    sku: "BP-PRIAM-4",
    name: "Коляска Cybex Priam 4",
    type: "Простий товар",
    brand: "Cybex",
    category: "Дитячі товари › Коляски › Прогулянкові",
    magento: { label: "Увімкнено", tone: "ok" },
    link: { label: "Пов'язано", tone: "ok" },
    problems: "—",
    updated: "18 хв тому",
    action: null,
    openDrawer: true,
  },
  {
    sku: "BP-ATON-B2",
    name: "Автокрісло Cybex Aton B2 i-Size",
    type: "Простий товар",
    brand: "Cybex",
    category: "Дитячі товари › Автокрісла",
    magento: { label: "Увімкнено", tone: "ok" },
    link: { label: "Пов'язано", tone: "ok" },
    problems: "—",
    updated: "18 хв тому",
  },
  {
    sku: "BP-AVENT-240",
    name: "Пляшечка Philips Avent Natural 240 мл",
    type: "Простий товар",
    brand: "Philips Avent",
    category: "Харчування › Пляшечки",
    magento: { label: "Увімкнено", tone: "ok" },
    link: { label: "Не пов'язано", tone: "warn" },
    updated: "2 год тому",
    action: "Пов'язати",
    linkReview: true,
  },
  {
    sku: "BP-PAMPERS-4",
    name: "Підгузки Pampers Premium Care 4",
    type: "Простий товар",
    brand: "Pampers",
    category: "Харчування › Підгузки",
    magento: { label: "Вимкнено", tone: "neutral" },
    link: { label: "Пов'язано", tone: "ok" },
    problems: "—",
    updated: "учора",
  },
  {
    sku: "BP-DUPLO-FARM",
    name: "Конструктор LEGO DUPLO Ферма",
    type: "Товар із варіантами",
    brand: "LEGO DUPLO",
    category: "Іграшки › Конструктори",
    magento: { label: "Увімкнено", tone: "ok" },
    link: { label: "Пов'язано", tone: "ok" },
    problems: "—",
    updated: "18 хв тому",
  },
  {
    sku: "BP-FP-PYR",
    name: "Іграшка Fisher-Price Пірамідка",
    type: "Простий товар",
    brand: "Fisher-Price",
    category: "Іграшки › Розвиток",
    magento: { label: "Увімкнено", tone: "ok" },
    link: { label: "Пов'язано", tone: "ok" },
    problems: "—",
    updated: "18 хв тому",
  },
  {
    sku: "BP-OMNI-360",
    name: "Слінгорюкзак Ergobaby Omni 360",
    type: "Простий товар",
    brand: "Ergobaby",
    category: "Дитячі товари › Слінги",
    magento: { label: "Увімкнено", tone: "ok" },
    link: { label: "Пов'язано", tone: "ok" },
    problems: "—",
    updated: "5 год тому",
  },
  {
    sku: "BP-STERIL-01",
    name: "Стерилізатор Philips Avent",
    type: "Простий товар",
    brand: "Philips Avent",
    category: "Харчування › Догляд",
    magento: { label: "Увімкнено", tone: "ok" },
    link: { label: "Пов'язано", tone: "ok" },
    problems: "—",
    updated: "18 хв тому",
  },
  {
    sku: "BP-WALKER-01",
    name: "Ходунки Chicco Baby Walker",
    type: "Простий товар",
    brand: "Chicco",
    category: "Дитячі товари › Ходунки",
    magento: { label: "Увімкнено", tone: "ok" },
    link: { label: "Пов'язано", tone: "ok" },
    problems: "—",
    updated: "18 хв тому",
  },
  {
    sku: "BP-N2ME",
    name: "Ліжечко Chicco Next2Me",
    type: "Простий товар",
    brand: "Chicco",
    category: "Дитячі товари › Ліжечка",
    magento: { label: "Увімкнено", tone: "ok" },
    link: { label: "Пов'язано", tone: "ok" },
    problems: "—",
    updated: "учора",
  },
  {
    sku: "BP-BOTTLE-125",
    name: "Пляшечка Philips Avent Natural 125 мл",
    type: "Простий товар",
    brand: "Philips Avent",
    category: "Харчування › Пляшечки",
    magento: { label: "Увімкнено", tone: "ok" },
    link: { label: "Пов'язано", tone: "ok" },
    problems: "—",
    updated: "18 хв тому",
  },
  {
    sku: "BP-PAMPERS-5",
    name: "Підгузки Pampers Premium Care 5",
    type: "Простий товар",
    brand: "Pampers",
    category: "Харчування › Підгузки",
    magento: { label: "Вимкнено", tone: "neutral" },
    link: { label: "Пов'язано", tone: "ok" },
    problems: "—",
    updated: "3 год тому",
  },
  {
    sku: "BP-LEGO-TOWN",
    name: "Конструктор LEGO DUPLO Містечко",
    type: "Товар із варіантами",
    brand: "LEGO DUPLO",
    category: "Іграшки › Конструктори",
    magento: { label: "Увімкнено", tone: "ok" },
    link: { label: "Пов'язано", tone: "ok" },
    problems: "—",
    updated: "18 хв тому",
  },
  {
    sku: "BP-FP-PIANO",
    name: "Піаніно Fisher-Price Laugh & Learn",
    type: "Простий товар",
    brand: "Fisher-Price",
    category: "Іграшки › Розвиток",
    magento: { label: "Увімкнено", tone: "ok" },
    link: { label: "Пов'язано", tone: "ok" },
    problems: "—",
    updated: "18 хв тому",
  },
];

const publicationRows = [
  {
    sku: "BP-PRIAM-4",
    name: "Коляска Cybex Priam 4",
    category: "Дитячі товари › Коляски › Прогулянкові",
    attrSet: "Default",
    ready: { label: "Готово", tone: "ok" },
    problems: "0",
    next: null,
    result: "Оновлено сьогодні, 11:20",
    openDrawer: true,
  },
  {
    sku: "BP-AVENT-240",
    name: "Пляшечка Philips Avent Natural 240 мл",
    category: "Не вказано",
    attrSet: "Автоматично · Feeding",
    ready: { label: "Не готово", tone: "danger" },
    problems: "1",
    next: { label: "Вказати категорію", kind: "link" },
    result: "Ще не передавався",
    openDrawer: true,
  },
  {
    sku: "BP-PAMPERS-4",
    name: "Підгузки Pampers Premium Care 4",
    category: "Харчування › Підгузки",
    attrSet: "Default",
    ready: { label: "Перевірка застаріла", tone: "warn" },
    problems: "0",
    next: null,
    result: "Перевірено 16.09, 09:14",
  },
  {
    sku: "BP-DUPLO-FARM",
    name: "Конструктор LEGO DUPLO Ферма",
    category: "Іграшки › Конструктори",
    attrSet: "Автоматично · Toys",
    ready: { label: "Ще немає в Magento", tone: "neutral" },
    problems: "0",
    next: null,
    result: "—",
  },
];

const linkRows = [
  {
    sku: "BP-PRIAM-4",
    name: "Коляска Cybex Priam 4",
    state: { label: "Пов'язано", tone: "ok" },
    master: "Коляска Cybex Priam 4",
    action: "Відкрити",
    openDrawer: true,
  },
  {
    sku: "BP-AVENT-240",
    name: "Пляшечка Philips Avent Natural 240 мл",
    state: { label: "Не пов'язано", tone: "warn" },
    master: "—",
    action: "Пов'язати",
    linkReview: true,
  },
  {
    sku: "BP-PAMPERS-4",
    name: "Підгузки Pampers Premium Care 4",
    state: { label: "Не пов'язано", tone: "warn" },
    master: "—",
    action: "Пов'язати",
    linkReview: true,
  },
  {
    sku: "BP-ATON-B2",
    name: "Автокрісло Cybex Aton B2 i-Size",
    state: { label: "Пов'язано", tone: "ok" },
    master: "Автокрісло Cybex Aton B2 i-Size",
    action: "Відкрити",
  },
];

const annotations = {
  hybrid: {
    overview:
      "<ol><li>Hybrid: B-style title + one-line View purpose; Filament-like ~14px / 42px rows.</li><li>Огляд is the remote Magento catalogue. Search is primary; refresh is secondary.</li><li>Phase 1 has no Thumbnail/Brand/Category. V2 adds them only as future projection.</li><li>Magento state and link state stay separate. No Problems column: unlinked is already Зв'язок.</li></ol>",
    publication:
      "<ol><li>No row checkboxes: first-scope Publication has no safe bulk action.</li><li>Вибрати товари remains the membership action. Execution sits above the table as Перевірити / Перевірити знову / Передати зміни.</li><li>Row Наступна дія is remediation only. Ready rows have no Передати зміни.</li><li>Ще немає в Magento is a quiet state, not a disabled CREATE button.</li></ol>",
    links:
      "<ol><li>Factual trust only: Пов'язано or Не пов'язано. No similarity scores or pre-ranked candidates.</li><li>Пов'язати opens review with merchant search. Exact SKU lookup may appear after the action, not as auto-evidence in the grid.</li></ol>",
    drawer:
      "<ol><li>First-scope tabs: Основне and Magento only.</li><li>Basic fields are read-only 1C/catalogue ownership.</li><li>Magento tab keeps Category tree + Набір характеристик and Повернути автоматичний вибір.</li></ol>",
    select:
      "<ol><li>Product selector is a table with search, filters and multi-select because publication membership is a real operation.</li><li>First implementation may route to the current ProductResource channel-context grid.</li></ol>",
  },
  a: {
    overview:
      "<ol><li>Historical A density only. Hybrid is the freeze target.</li></ol>",
    publication:
      "<ol><li>Historical A density. Semantic corrections still apply: no decorative checkboxes, no per-row Live.</li></ol>",
    links:
      "<ol><li>Historical A density. No invented similarity.</li></ol>",
    drawer:
      "<ol><li>Historical A density. Hybrid drawer is wider.</li></ol>",
    select:
      "<ol><li>Selector is shared across densities.</li></ol>",
  },
  b: {
    overview:
      "<ol><li>Historical B density only. Hybrid is the freeze target.</li></ol>",
    publication:
      "<ol><li>Historical B density. Semantic corrections still apply.</li></ol>",
    links:
      "<ol><li>Historical B density. No invented similarity.</li></ol>",
    drawer:
      "<ol><li>Historical B density. First-scope tabs remain Основне / Magento.</li></ol>",
    select:
      "<ol><li>Selector is shared across densities.</li></ol>",
  },
};

function badge(item) {
  return `<span class="badge ${item.tone}">${item.label}</span>`;
}

function actionButton(label, kind, extra = "") {
  if (kind === "disabled") {
    return `<button class="btn disabled" type="button" disabled>${label}</button>`;
  }
  if (kind === "primary") {
    return `<button class="btn primary" type="button" ${extra}>${label}</button>`;
  }
  return `<button class="btn link" type="button" ${extra}>${label}</button>`;
}

function renderOverview() {
  const body = document.getElementById("overview-body");
  body.innerHTML = overviewRows
    .map((row) => {
      const action = row.action
        ? `<button class="btn link" type="button" data-open="link-review">${row.action}</button>`
        : row.openDrawer
          ? `<button class="btn link" type="button" data-open="drawer">Відкрити</button>`
          : "";
      return `<tr ${row.openDrawer ? 'data-open="drawer"' : ""}>
        <td class="only-v2">${thumbSvg}</td>
        <td class="sku">${row.sku}</td>
        <td class="name">${row.name}</td>
        <td class="only-p1 nowrap">${row.type}</td>
        <td class="only-v2">${row.brand}</td>
        <td class="only-v2"><span class="breadcrumb">${row.category}</span></td>
        <td>${badge(row.magento)}</td>
        <td>${badge(row.link)}</td>
        <td class="muted nowrap">${row.updated}</td>
        <td class="action-cell">${action}</td>
      </tr>`;
    })
    .join("");
}

function renderPublication() {
  const body = document.getElementById("publication-body");
  body.innerHTML = publicationRows
    .map((row) => {
      const next = row.next
        ? actionButton(row.next.label, row.next.kind, row.openDrawer ? 'data-open="drawer"' : "")
        : '<span class="muted">—</span>';
      return `<tr>
        <td>${thumbSvg}</td>
        <td class="sku">${row.sku}</td>
        <td class="name">${row.name}</td>
        <td><span class="breadcrumb">${row.category}</span></td>
        <td>${row.attrSet}</td>
        <td>${badge(row.ready)}</td>
        <td>${row.problems}</td>
        <td class="action-cell">${next}</td>
        <td class="muted">${row.result}</td>
      </tr>`;
    })
    .join("");
}

function renderLinks() {
  const body = document.getElementById("links-body");
  body.innerHTML = linkRows
    .map((row) => {
      const extra = row.linkReview ? 'data-open="link-review"' : row.openDrawer ? 'data-open="drawer"' : "";
      return `<tr>
        <td class="sku">${row.sku}</td>
        <td class="name">${row.name}</td>
        <td>${badge(row.state)}</td>
        <td>${row.master}</td>
        <td class="action-cell">${actionButton(row.action, "link", extra)}</td>
      </tr>`;
    })
    .join("");
}

const hints = {
  overview: "Товари, які зараз є у вашому магазині Magento.",
  publication: "Товари з вашого каталогу, які готуєте до цього Magento.",
  links: "Що в Magento відповідає вашому каталогу — і що ще треба підтвердити.",
};

let activeView = "overview";

function currentView() {
  return activeView === "empty" ? "overview" : activeView;
}

function setView(view) {
  activeView = view;
  ["overview", "publication", "links", "empty"].forEach((name) => {
    const el = document.getElementById(`view-${name}`);
    if (el) el.hidden = name !== view;
  });
  const tabView = view === "empty" ? "overview" : view;
  document.querySelectorAll("[data-view-set]").forEach((btn) => {
    btn.classList.toggle("active", btn.getAttribute("data-view-set") === tabView);
  });
  document.querySelectorAll(".tab").forEach((btn) => {
    btn.classList.toggle("active", btn.getAttribute("data-view-set") === tabView);
  });
  document.getElementById("view-hint").textContent = hints[tabView] || "";
  document.querySelector("[data-overview-only]").style.display = tabView === "overview" ? "flex" : "none";
  updateAnnotation();
}

function setVariant(variant) {
  document.documentElement.dataset.variant = variant;
  document.querySelectorAll("[data-variant-set]").forEach((btn) => {
    btn.classList.toggle("active", btn.getAttribute("data-variant-set") === variant);
  });
  updateAnnotation();
}

function setPhase(phase) {
  document.documentElement.classList.toggle("hidden-phase-v2", phase === "p1");
  document.documentElement.classList.toggle("show-phase-v2", phase === "v2");
  document.querySelectorAll("[data-phase-set]").forEach((btn) => {
    btn.classList.toggle("active", btn.getAttribute("data-phase-set") === phase);
  });
  updateAnnotation();
}

function updateAnnotation() {
  const variant = document.documentElement.dataset.variant;
  const view = activeView === "empty" ? "overview" : activeView;
  const drawerOpen = document.getElementById("drawer").classList.contains("open");
  const selectOpen = document.getElementById("select-products").classList.contains("open");
  const key = selectOpen ? "select" : drawerOpen ? "drawer" : view;
  const titleMap = {
    overview: "Огляд",
    publication: "Публікація",
    links: "Зв'язки",
    drawer: "Product drawer",
    select: "Вибрати товари",
  };
  const variantLabel = variant === "hybrid" ? "Hybrid" : `Variant ${variant.toUpperCase()}`;
  document.getElementById("annotation-title").textContent =
    `${variantLabel} · ${titleMap[key]}`;
  document.getElementById("annotation-body").innerHTML = annotations[variant][key];
  syncHash();
}

let applyingHash = false;

function syncHash() {
  if (applyingHash) return;
  const variant = document.documentElement.dataset.variant;
  const phase = document.documentElement.classList.contains("show-phase-v2") ? "v2" : "p1";
  const extras = [];
  if (document.getElementById("drawer").classList.contains("open")) extras.push("drawer");
  if (document.getElementById("filters").classList.contains("open")) extras.push("filters");
  if (document.getElementById("link-review").classList.contains("open")) extras.push("link");
  if (document.getElementById("select-products").classList.contains("open")) extras.push("select");
  const extra = extras.length ? `/${extras.join(",")}` : "";
  const hash = `#${variant}/${activeView}/${phase}${extra}`;
  if (location.hash !== hash) history.replaceState(null, "", hash);
}

function applyHash() {
  applyingHash = true;
  const q = new URLSearchParams(location.search);
  if (q.get("capture") === "1") {
    document.body.classList.add("capture");
  }

  const raw = location.hash.replace(/^#/, "");
  if (raw) {
    const [variant, view, phase, extra] = raw.split("/");
    if (variant === "a" || variant === "b" || variant === "hybrid") setVariant(variant);
    if (view) setView(view);
    if (phase === "p1" || phase === "v2") setPhase(phase);
    const extras = new Set((extra || "").split(",").filter(Boolean));
    ["drawer", "filters", "link-review", "select-products"].forEach((id) => {
      const token = id === "link-review" ? "link" : id === "select-products" ? "select" : id;
      document.getElementById(id).classList.toggle("open", extras.has(token));
    });
  }

  if (q.get("variant") === "a" || q.get("variant") === "b" || q.get("variant") === "hybrid") {
    setVariant(q.get("variant"));
  }
  if (q.get("view")) setView(q.get("view"));
  if (q.get("phase") === "p1" || q.get("phase") === "v2") setPhase(q.get("phase"));
  if (q.get("drawer") === "1") document.getElementById("drawer").classList.add("open");
  if (q.get("select") === "1") document.getElementById("select-products").classList.add("open");
  if (q.get("pane")) {
    const name = q.get("pane");
    document.querySelectorAll("#drawer-tabs button").forEach((btn) => {
      btn.classList.toggle("active", btn.getAttribute("data-pane") === name);
    });
    ["basic", "magento"].forEach((id) => {
      const pane = document.getElementById(`pane-${id}`);
      if (pane) pane.hidden = id !== name;
    });
  }

  applyingHash = false;
  updateAnnotation();
}

function openOverlay(id) {
  const el = document.getElementById(id);
  if (!el) return;
  el.classList.add("open");
  updateAnnotation();
}

function closeOverlay(id) {
  const el = document.getElementById(id);
  if (!el) return;
  el.classList.remove("open");
  updateAnnotation();
}

document.addEventListener("click", (event) => {
  const variant = event.target.closest("[data-variant-set]");
  if (variant) setVariant(variant.getAttribute("data-variant-set"));

  const view = event.target.closest("[data-view-set]");
  if (view) setView(view.getAttribute("data-view-set"));

  if (event.target.closest("[data-empty]")) setView("empty");

  const phase = event.target.closest("[data-phase-set]");
  if (phase) setPhase(phase.getAttribute("data-phase-set"));

  const open = event.target.closest("[data-open]");
  if (open) openOverlay(open.getAttribute("data-open"));

  const close = event.target.closest("[data-close]");
  if (close) closeOverlay(close.getAttribute("data-close"));

  if (event.target.closest("[data-toggle='annotation']")) {
    document.getElementById("annotation").classList.toggle("open");
  }

  if (event.target.closest("[data-toggle='chrome']")) {
    document.body.classList.toggle("capture");
  }

  if (event.target.classList.contains("drawer-backdrop") || event.target.classList.contains("panel-backdrop")) {
    event.target.classList.remove("open");
    updateAnnotation();
  }

  const pane = event.target.closest("[data-pane]");
  if (pane) {
    const name = pane.getAttribute("data-pane");
    document.querySelectorAll("#drawer-tabs button").forEach((btn) => {
      btn.classList.toggle("active", btn === pane);
    });
    ["basic", "magento"].forEach((id) => {
      const pane = document.getElementById(`pane-${id}`);
      if (pane) pane.hidden = id !== name;
    });
  }
});

renderOverview();
renderPublication();
renderLinks();
setVariant("hybrid");
setPhase("p1");
setView("overview");
applyHash();
if (new URLSearchParams(location.search).get("capture") === "1") {
  document.body.classList.add("capture");
}
updateAnnotation();
window.addEventListener("hashchange", applyHash);
