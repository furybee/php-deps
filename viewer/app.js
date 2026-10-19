/**
 * PHP Deps viewer
 * Uses Sigma.js v2 + Graphology + ForceAtlas2
 */
(function () {
  "use strict";

  // Color palette — assigned dynamically to layers in order of frequency
  const COLOR_PALETTE = [
    "#3B82F6", "#10B981", "#F59E0B", "#8B5CF6", "#EC4899",
    "#06B6D4", "#14B8A6", "#6366F1", "#A78BFA", "#34D399",
    "#F472B6", "#FB923C", "#E879F9", "#FBBF24", "#818CF8",
    "#94A3B8", "#67E8F9", "#F87171", "#FB7185", "#A3E635",
    "#C084FC", "#38BDF8", "#2DD4BF", "#FACC15", "#4ADE80",
    "#EF4444", "#F97316", "#22D3EE", "#84CC16", "#7DD3FC",
    "#A3A3A3", "#78716C", "#9CA3AF", "#64748B", "#D946EF",
    "#0EA5E9", "#F43F5E", "#8B5CF6", "#059669", "#DC2626",
  ];
  const LAYER_COLORS = {};

  /** Assign colors to layers based on frequency (most frequent = first color) */
  function assignLayerColors(stats) {
    if (!stats || !stats.layerCounts) return;
    const sorted = Object.entries(stats.layerCounts).sort((a, b) => b[1] - a[1]);
    sorted.forEach(([layer, _], i) => {
      LAYER_COLORS[layer] = COLOR_PALETTE[i % COLOR_PALETTE.length];
    });
    // Ensure "Other" always gets a neutral gray
    if (!LAYER_COLORS["Other"]) {
      LAYER_COLORS["Other"] = "#6B7280";
    }
  }

  const CLASS_TYPE_COLORS = {
    class: "#3B82F6",
    abstract: "#A78BFA",
    interface: "#06B6D4",
    trait: "#F59E0B",
    enum: "#EC4899",
    unknown: "#6B7280",
  };

  const EDGE_COLORS = {
    calls: "#4B5563",
    depends_on: "#374151",
    instantiates: "#553300",
    resolves: "#1E3A5F",
    extends: "#7C3AED22",
    implements: "#06528822",
    uses_trait: "#71717A22",
    uses_type: "#0F766E44",
    references: "#52525B44",
  };

  const DEFAULT_EDITOR_URL = "phpstorm://open?file={file}&line={line}";
  const MAX_LISTED_CYCLES = 15;

  // State
  let graphData = null;
  let graph = null;
  let renderer = null;
  let fa2Layout = null;
  let fa2Running = false;
  let hoveredNode = null;
  let selectedNode = null;
  let viewMode = "namespaces"; // "namespaces", "classes", or "full"
  let namespacePath = []; // drill-down path for namespace mode
  let commonPrefix = []; // auto-detected root namespace segments to skip
  let activeFilters = {};
  let activeEdgeFilters = {};
  let activeClassTypeFilters = {};
  let hoveredNeighbors = new Set();
  let selectedNeighbors = new Set();
  let nodesById = {};
  let sizeBy = "methods";
  let highlightedCycle = null; // index in graphData.cycles
  const afterLayout = [];

  function escapeHtml(value) {
    return String(value).replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[c]);
  }

  // ─── Load data ──────────────────────────────────────────────────────
  async function loadData() {
    // Try auto-loading graph.json (works when served via HTTP)
    try {
      const resp = await fetch("graph.json");
      if (resp.ok) {
        graphData = await resp.json();
      }
    } catch (_) {
      // Expected to fail on file:// — show file picker
    }

    if (!graphData) {
      showFilePicker();
      return;
    }

    try {
      init();
    } catch (err) {
      document.getElementById("loading").innerHTML =
        `<div style="color:#f87171;font-size:14px;">Cannot display the graph: ${escapeHtml(err.message)}</div>`;
      throw err;
    }
  }

  function showFilePicker() {
    const loading = document.getElementById("loading");
    loading.innerHTML = `
      <div id="drop-zone" style="
        border: 2px dashed #30363d;
        border-radius: 16px;
        padding: 48px 64px;
        text-align: center;
        cursor: pointer;
        transition: border-color 0.2s, background 0.2s;
        max-width: 480px;
      ">
        <div style="font-size: 40px; margin-bottom: 16px;">&#128206;</div>
        <div style="font-size: 16px; font-weight: 600; margin-bottom: 8px; color: #e1e4e8;">Load graph.json</div>
        <div style="font-size: 13px; color: #8b949e; margin-bottom: 20px;">
          Drop the file here or click to browse
        </div>
        <button id="btn-browse" style="
          padding: 10px 24px;
          background: #1f6feb;
          border: none;
          border-radius: 8px;
          color: #fff;
          font-size: 13px;
          font-weight: 500;
          cursor: pointer;
          transition: background 0.15s;
        ">Choose file</button>
        <input type="file" id="file-input" accept=".json" style="display:none">
        <div style="margin-top: 20px; font-size: 11px; color: #484f58;">
          Generate it with: <code style="background:#21262d;padding:2px 6px;border-radius:4px;color:#8b949e;">php analyze.php</code>
        </div>
      </div>
    `;

    const dropZone = document.getElementById("drop-zone");
    const fileInput = document.getElementById("file-input");
    const btnBrowse = document.getElementById("btn-browse");

    // Click to browse
    btnBrowse.addEventListener("click", (e) => {
      e.stopPropagation();
      fileInput.click();
    });
    dropZone.addEventListener("click", () => fileInput.click());

    // File input change
    fileInput.addEventListener("change", (e) => {
      if (e.target.files.length > 0) {
        readJsonFile(e.target.files[0]);
      }
    });

    // Drag & drop
    dropZone.addEventListener("dragover", (e) => {
      e.preventDefault();
      dropZone.style.borderColor = "#1f6feb";
      dropZone.style.background = "rgba(31,111,235,0.05)";
    });

    dropZone.addEventListener("dragleave", () => {
      dropZone.style.borderColor = "#30363d";
      dropZone.style.background = "transparent";
    });

    dropZone.addEventListener("drop", (e) => {
      e.preventDefault();
      dropZone.style.borderColor = "#30363d";
      dropZone.style.background = "transparent";
      if (e.dataTransfer.files.length > 0) {
        readJsonFile(e.dataTransfer.files[0]);
      }
    });

    // Also support dropping anywhere on the page
    document.body.addEventListener("dragover", (e) => e.preventDefault());
    document.body.addEventListener("drop", (e) => {
      e.preventDefault();
      if (e.dataTransfer.files.length > 0) {
        readJsonFile(e.dataTransfer.files[0]);
      }
    });
  }

  function readJsonFile(file) {
    const loading = document.getElementById("loading");
    loading.innerHTML = `<div class="spinner"></div><div class="loading-text">Parsing ${file.name}...</div>`;

    const reader = new FileReader();
    reader.onload = (e) => {
      try {
        graphData = JSON.parse(e.target.result);
        if (!graphData.nodes || !graphData.edges) {
          throw new Error("Invalid graph.json: missing 'nodes' or 'edges'");
        }
        init();
      } catch (err) {
        loading.innerHTML = `<div style="color:#f87171;font-size:14px;">Error parsing JSON:<br>${err.message}</div>`;
      }
    };
    reader.onerror = () => {
      loading.innerHTML = `<div style="color:#f87171;font-size:14px;">Error reading file</div>`;
    };
    reader.readAsText(file);
  }

  // ─── Initialize ─────────────────────────────────────────────────────
  function init() {
    graphData.nodes.forEach((n) => (nodesById[n.id] = n));
    assignLayerColors(graphData.stats);
    commonPrefix = detectCommonPrefix();
    buildGraph();
    setupRenderer();
    setupSidebar();
    setupSearch();
    setupViewMode();
    setupLayoutControls();
    setupInfoPanel();
    setupSizeMode();
    setupCycles();
    setupSettings();
    updateBreadcrumb();

    document.getElementById("loading").classList.add("hidden");

    // Auto-start layout
    startLayout();
    setTimeout(() => stopLayout(), viewMode === "namespaces" ? 3000 : 5000);
  }

  // ─── Namespace helpers ─────────────────────────────────────────────
  const ROOT_NAMESPACES = [
    "app", "src", "source", "lib", "module", "modules", "domain",
    "infrastructure", "presentation", "application",
  ];

  /** Detect common root prefix to skip (e.g. "App") */
  function detectCommonPrefix() {
    const classNodes = graphData.nodes.filter((n) => n.type === "class" && n.namespace);
    if (classNodes.length === 0) return [];

    // Split all namespaces into segments
    const allSegments = classNodes.map((n) => n.namespace.split("\\"));
    const first = allSegments[0];
    const prefix = [];

    for (let i = 0; i < first.length; i++) {
      const seg = first[i];
      if (allSegments.every((s) => s[i] === seg) && ROOT_NAMESPACES.includes(seg.toLowerCase())) {
        prefix.push(seg);
      } else {
        break;
      }
    }

    return prefix;
  }

  /**
   * Get the namespace group key for a class node at the current drill-down depth.
   * Returns the next segment after commonPrefix + namespacePath, or null if not in scope.
   */
  function getNamespaceGroup(namespace) {
    if (!namespace) return null;
    const segments = namespace.split("\\");
    const prefixLen = commonPrefix.length;
    const pathLen = namespacePath.length;
    const depth = prefixLen + pathLen;

    // Check that this namespace matches the current path
    for (let i = 0; i < prefixLen; i++) {
      if (segments[i] !== commonPrefix[i]) return null;
    }
    for (let i = 0; i < pathLen; i++) {
      if (segments[prefixLen + i] !== namespacePath[i]) return null;
    }

    // Return the next segment (the group at current depth)
    if (segments.length > depth) {
      return segments[depth];
    }

    // Class is directly in the current namespace (no sub-segment)
    return "(classes)";
  }

  /** Get full namespace prefix for a group segment */
  function getGroupNamespace(segment) {
    const parts = [...commonPrefix, ...namespacePath, segment];
    return parts.join("\\");
  }

  // ─── Build Graphology graph ─────────────────────────────────────────
  function buildGraph() {
    graph = new graphology.Graph({ multi: false, type: "directed" });

    if (viewMode === "namespaces") {
      buildNamespaceGraph();
    } else {
      buildClassGraph();
    }
  }

  function buildNamespaceGraph() {
    if (commonPrefix.length === 0) {
      commonPrefix = detectCommonPrefix();
    }

    const classNodes = graphData.nodes.filter((n) => n.type === "class");

    // Group classes by their namespace segment at current depth
    const groups = new Map(); // segment → { classes: [], layers: {} }

    classNodes.forEach((node) => {
      const group = getNamespaceGroup(node.namespace || "");
      if (group === null) return;

      if (!groups.has(group)) {
        groups.set(group, { classes: [], layers: {} });
      }
      const g = groups.get(group);
      g.classes.push(node);
      g.layers[node.layer] = (g.layers[node.layer] || 0) + 1;
    });

    // Check which groups have sub-namespaces (children beyond current depth)
    const prefixLen = commonPrefix.length;
    const pathLen = namespacePath.length;
    const depth = prefixLen + pathLen;

    groups.forEach((data, segment) => {
      if (segment === "(classes)") {
        data.hasChildren = false;
        return;
      }
      // Check if any class has deeper segments
      data.hasChildren = data.classes.some((n) => {
        const segs = (n.namespace || "").split("\\");
        return segs.length > depth + 1;
      });
    });

    // Create namespace nodes
    groups.forEach((data, segment) => {
      const dominantLayer = Object.entries(data.layers).sort((a, b) => b[1] - a[1])[0];
      const color = dominantLayer ? (LAYER_COLORS[dominantLayer[0]] || LAYER_COLORS.Other) : "#6B7280";
      const count = data.classes.length;
      const label = segment === "(classes)" ? `${namespacePath[namespacePath.length - 1] || "Root"} (${count})` : `${segment} (${count})`;

      const nodeId = segment === "(classes)" ? "__direct_classes__" : getGroupNamespace(segment);

      graph.addNode(nodeId, {
        x: Math.random() * 100,
        y: Math.random() * 100,
        size: Math.max(5, Math.min(25, Math.sqrt(count) * 3)),
        color: color,
        label: label,
        nodeType: "namespace",
        layer: dominantLayer ? dominantLayer[0] : "Other",
        namespace: nodeId,
        classType: "",
        methods: [],
        methodCount: 0,
        forceLabel: true,
        // Custom namespace attrs
        isNamespaceNode: true,
        hasChildren: data.hasChildren,
        segment: segment,
        classCount: count,
        layerBreakdown: data.layers,
        containedClasses: data.classes.map((n) => n.id),
      });
    });

    // Build a class→group lookup
    const classToGroup = {};
    groups.forEach((data, segment) => {
      const nodeId = segment === "(classes)" ? "__direct_classes__" : getGroupNamespace(segment);
      data.classes.forEach((n) => {
        classToGroup[n.id] = nodeId;
      });
    });

    // Aggregate edges between groups
    const edgeCounts = {}; // "source→target" → count

    graphData.edges.forEach((edge) => {
      let source = edge.source;
      let target = edge.target;

      // Collapse to class level
      if (source.includes("::")) source = source.split("::")[0];
      if (target.includes("::")) target = target.split("::")[0];

      const sourceGroup = classToGroup[source];
      const targetGroup = classToGroup[target];

      if (!sourceGroup || !targetGroup || sourceGroup === targetGroup) return;

      const key = `${sourceGroup}→${targetGroup}`;
      edgeCounts[key] = (edgeCounts[key] || 0) + 1;
    });

    // Add aggregated edges
    Object.entries(edgeCounts).forEach(([key, count]) => {
      const [source, target] = key.split("→");
      if (!graph.hasNode(source) || !graph.hasNode(target)) return;

      try {
        graph.addEdgeWithKey(key, source, target, {
          color: "#4B556388",
          size: Math.max(0.5, Math.min(5, Math.sqrt(count) * 0.8)),
          type: "arrow",
          edgeType: "aggregated",
          edgeCount: count,
          label: String(count),
        });
      } catch (e) {
        // skip
      }
    });
  }

  function buildClassGraph() {
    const nodesById = {};
    graphData.nodes.forEach((n) => (nodesById[n.id] = n));

    // Add nodes with initial random positions
    graphData.nodes.forEach((node) => {
      if (viewMode === "classes" && node.type === "method") return;

      const isClass = node.type === "class";
      const color = LAYER_COLORS[node.layer] || LAYER_COLORS.Other;
      const size = isClass ? classNodeSize(node) : 2;

      graph.addNode(node.id, {
        x: Math.random() * 100,
        y: Math.random() * 100,
        size: size,
        color: color,
        label: node.label,
        nodeType: node.type,
        layer: node.layer,
        namespace: node.namespace || "",
        classType: node.classType || "",
        methods: node.methods || [],
        methodCount: node.methodCount || 0,
        file: node.file || "",
        line: node.line || 0,
        ownerClass: node.class || null,
        metrics: node.metrics || null,
        cycle: node.cycle ?? null,
        forceLabel: false,
      });
    });

    // Add edges
    graphData.edges.forEach((edge, i) => {
      let source = edge.source;
      let target = edge.target;

      // In classes-only mode, collapse method edges to class edges
      if (viewMode === "classes") {
        if (source.includes("::")) source = source.split("::")[0];
        if (target.includes("::")) target = target.split("::")[0];
      }

      // Skip self-loops and edges to/from non-existent nodes
      if (source === target) return;
      if (!graph.hasNode(source) || !graph.hasNode(target)) return;

      // Skip duplicate edges
      const edgeId = `${source}→${target}→${edge.type}`;
      if (graph.hasEdge(edgeId)) return;

      try {
        graph.addEdgeWithKey(edgeId, source, target, {
          color: EDGE_COLORS[edge.type] || EDGE_COLORS.calls,
          size: 0.5,
          type: "arrow",
          edgeType: edge.type,
        });
      } catch (e) {
        // Skip if edge already exists
      }
    });
  }

  function classNodeSize(node) {
    if (sizeBy === "dependents" && node.metrics) {
      return Math.max(3, Math.min(20, 3 + Math.sqrt(node.metrics.ca) * 1.2));
    }
    return Math.max(3, Math.min(15, (node.methodCount || 1) * 0.8));
  }

  // ─── Setup Sigma renderer ──────────────────────────────────────────
  function setupRenderer() {
    const container = document.getElementById("sigma-container");

    renderer = new Sigma(graph, container, {
      renderLabels: true,
      renderEdgeLabels: viewMode === "namespaces",
      defaultNodeColor: "#6B7280",
      defaultEdgeColor: "#2D333B",
      labelFont: "Inter, -apple-system, sans-serif",
      labelSize: viewMode === "namespaces" ? 14 : 12,
      labelWeight: "500",
      labelColor: { color: "#c9d1d9" },
      labelDensity: viewMode === "namespaces" ? 1 : 0.15,
      labelGridCellSize: viewMode === "namespaces" ? 50 : 100,
      labelRenderedSizeThreshold: viewMode === "namespaces" ? 0 : 6,
      edgeReducer: reduceEdge,
      nodeReducer: reduceNode,
      zIndex: true,
      // Keeps nodes clear of the floating layout buttons and breadcrumb
      stagePadding: 80,
      minCameraRatio: 0.01,
      maxCameraRatio: 10,
    });

    // Events
    renderer.on("enterNode", ({ node }) => {
      hoveredNode = node;
      hoveredNeighbors = new Set(graph.neighbors(node));
      updateTooltip(node);
      renderer.refresh({ skipIndexation: true });
    });

    renderer.on("leaveNode", () => {
      hoveredNode = null;
      hoveredNeighbors.clear();
      hideTooltip();
      renderer.refresh({ skipIndexation: true });
    });

    renderer.on("clickNode", ({ node }) => {
      // Namespace mode: drill-down on click
      if (viewMode === "namespaces") {
        const attrs = graph.getNodeAttributes(node);
        if (attrs.isNamespaceNode && attrs.hasChildren) {
          namespacePath.push(attrs.segment);
          rebuildNamespaceView();
          return;
        }
      }

      selectedNode = node;
      selectedNeighbors = new Set(graph.neighbors(node));
      showInfoPanel(node);
      renderer.refresh({ skipIndexation: true });
    });

    renderer.on("clickStage", () => {
      selectedNode = null;
      selectedNeighbors.clear();
      hideInfoPanel();
      renderer.refresh({ skipIndexation: true });
    });

    renderer.on("doubleClickNode", ({ node }) => {
      const attrs = graph.getNodeAttributes(node);
      renderer.getCamera().animate(
        { x: attrs.x, y: attrs.y, ratio: 0.15 },
        { duration: 400 }
      );
    });

    // Mouse move for tooltip positioning
    const sigmaContainer = document.getElementById("sigma-container");
    sigmaContainer.addEventListener("mousemove", (e) => {
      const tooltip = document.getElementById("tooltip");
      if (tooltip.style.display === "block") {
        tooltip.style.left = e.clientX + 15 + "px";
        tooltip.style.top = e.clientY + 15 + "px";
      }
    });
  }

  // ─── Node reducer ──────────────────────────────────────────────────
  function reduceNode(node, data) {
    const res = { ...data };

    // In namespace mode, always force labels visible
    if (viewMode === "namespaces" && data.isNamespaceNode) {
      res.forceLabel = true;
    }

    // Layer filter
    if (activeFilters[data.layer] === false) {
      res.hidden = true;
      return res;
    }

    // Class type filter
    if (data.classType && activeClassTypeFilters[data.classType] === false) {
      res.hidden = true;
      return res;
    }

    if (highlightedCycle !== null && viewMode !== "namespaces") {
      const owner = data.nodeType === "method" ? data.ownerClass : node;
      if (nodesById[owner]?.cycle !== highlightedCycle) {
        res.hidden = true;
        return res;
      }
    }

    // Selected node: show only the node + its direct connections, hide everything else
    if (selectedNode) {
      if (node === selectedNode) {
        res.highlighted = true;
        res.zIndex = 2;
        res.forceLabel = true;
      } else if (selectedNeighbors.has(node)) {
        res.zIndex = 1;
        res.forceLabel = true;
      } else {
        res.hidden = true;
      }
    }

    // Hover highlighting (only when no node is selected)
    if (hoveredNode && !selectedNode) {
      if (node === hoveredNode) {
        res.highlighted = true;
        res.zIndex = 2;
        res.forceLabel = true;
      } else if (hoveredNeighbors.has(node)) {
        res.zIndex = 1;
        res.forceLabel = true;
      } else {
        res.color = res.color + "22";
        res.label = "";
      }
    }

    return res;
  }

  // ─── Edge reducer ──────────────────────────────────────────────────
  function reduceEdge(edge, data) {
    const res = { ...data };

    // Edge type filter
    if (activeEdgeFilters[data.edgeType] === false) {
      res.hidden = true;
      return res;
    }

    const activeNode = selectedNode || hoveredNode;
    if (activeNode) {
      const source = graph.source(edge);
      const target = graph.target(edge);
      if (source === activeNode || target === activeNode) {
        res.color = LAYER_COLORS[graph.getNodeAttribute(source, "layer")] || "#58a6ff";
        res.size = 1.5;
        res.zIndex = 1;
      } else {
        res.hidden = true;
      }
    }

    return res;
  }

  // ─── Sidebar ────────────────────────────────────────────────────────
  function setupSidebar() {
    // Stats
    const statsEl = document.getElementById("stats-text");
    const s = graphData.stats;
    statsEl.textContent = `${s.totalClasses} classes · ${s.totalMethods} methods · ${s.totalEdges} edges`;

    // Layer filters
    const filtersEl = document.getElementById("layer-filters");
    const layers = Object.entries(s.layerCounts).sort((a, b) => b[1] - a[1]);

    layers.forEach(([layer, count]) => {
      activeFilters[layer] = true;
      const color = LAYER_COLORS[layer] || LAYER_COLORS.Other;
      const item = document.createElement("label");
      item.className = "filter-item";
      item.innerHTML = `
        <input type="checkbox" checked data-layer="${layer}">
        <span class="filter-dot" style="background:${color}"></span>
        <span>${layer}</span>
        <span class="filter-count">${count}</span>
      `;
      filtersEl.appendChild(item);
    });

    filtersEl.addEventListener("change", (e) => {
      if (e.target.dataset.layer) {
        activeFilters[e.target.dataset.layer] = e.target.checked;
        renderer.refresh({ skipIndexation: true });
      }
    });

    // Layer toggle all
    setupToggleAll("layer-toggle-all", filtersEl, activeFilters, "layer");

    // Edge type filters
    const edgeFiltersEl = document.getElementById("edge-type-filters");
    const edgeTypes = Object.entries(s.edgeTypeCounts || {}).sort((a, b) => b[1] - a[1]);

    edgeTypes.forEach(([type, count]) => {
      activeEdgeFilters[type] = true;
      const item = document.createElement("label");
      item.className = "filter-item";
      item.innerHTML = `
        <input type="checkbox" checked data-edge-type="${type}">
        <span class="filter-dot" style="background:${EDGE_COLORS[type] || '#6B7280'}"></span>
        <span>${type}</span>
        <span class="filter-count">${count}</span>
      `;
      edgeFiltersEl.appendChild(item);
    });

    edgeFiltersEl.addEventListener("change", (e) => {
      if (e.target.dataset.edgeType) {
        activeEdgeFilters[e.target.dataset.edgeType] = e.target.checked;
        renderer.refresh({ skipIndexation: true });
      }
    });

    // Edge toggle all
    setupToggleAll("edge-toggle-all", edgeFiltersEl, activeEdgeFilters, "edgeType");

    // Class type filters
    const classTypeFiltersEl = document.getElementById("class-type-filters");
    const classTypes = Object.entries(s.classTypeCounts || {}).sort((a, b) => b[1] - a[1]);
    const classTypeLabels = { class: "Class", abstract: "Abstract", interface: "Interface", trait: "Trait", enum: "Enum" };

    classTypes.forEach(([type, count]) => {
      activeClassTypeFilters[type] = true;
      const color = CLASS_TYPE_COLORS[type] || "#6B7280";
      const label = classTypeLabels[type] || type;
      const item = document.createElement("label");
      item.className = "filter-item";
      item.innerHTML = `
        <input type="checkbox" checked data-class-type="${type}">
        <span class="filter-dot" style="background:${color}"></span>
        <span>${label}</span>
        <span class="filter-count">${count}</span>
      `;
      classTypeFiltersEl.appendChild(item);
    });

    classTypeFiltersEl.addEventListener("change", (e) => {
      if (e.target.dataset.classType) {
        activeClassTypeFilters[e.target.dataset.classType] = e.target.checked;
        renderer.refresh({ skipIndexation: true });
      }
    });

    setupToggleAll("class-type-toggle-all", classTypeFiltersEl, activeClassTypeFilters, "classType");

    // Sidebar toggle
    const toggle = document.getElementById("sidebar-toggle");
    const sidebar = document.getElementById("sidebar");
    toggle.addEventListener("click", () => {
      sidebar.classList.toggle("collapsed");
      document.body.classList.toggle("sidebar-collapsed", sidebar.classList.contains("collapsed"));
    });
    document.getElementById("sigma-container").addEventListener("transitionend", () => {
      renderer.resize();
      renderer.refresh();
    });
  }

  /**
   * Wire up a "Select all / Deselect all" toggle button.
   */
  function setupToggleAll(buttonId, filtersEl, filtersState, dataAttr) {
    const btn = document.getElementById(buttonId);
    if (!btn) return;

    btn.addEventListener("click", () => {
      const allChecked = Object.values(filtersState).every(Boolean);
      const newState = !allChecked;

      for (const key in filtersState) {
        filtersState[key] = newState;
      }

      filtersEl.querySelectorAll('input[type="checkbox"]').forEach((cb) => {
        cb.checked = newState;
      });

      btn.textContent = newState ? "Deselect all" : "Select all";
      renderer.refresh({ skipIndexation: true });
    });
  }

  // ─── Search ─────────────────────────────────────────────────────────
  function setupSearch() {
    const input = document.getElementById("search-input");
    const resultsEl = document.getElementById("search-results");

    // Build search index from class nodes only
    const classNodes = graphData.nodes.filter((n) => n.type === "class");

    input.addEventListener("input", () => {
      const query = input.value.trim().toLowerCase();
      if (query.length < 2) {
        resultsEl.classList.remove("visible");
        resultsEl.innerHTML = "";
        return;
      }

      const matches = classNodes
        .filter((n) => n.label.toLowerCase().includes(query) || n.id.toLowerCase().includes(query))
        .slice(0, 20);

      if (matches.length === 0) {
        resultsEl.classList.remove("visible");
        return;
      }

      resultsEl.innerHTML = matches
        .map((n) => {
          const color = LAYER_COLORS[n.layer] || LAYER_COLORS.Other;
          const ns = n.namespace ? n.namespace.replace(/^App\\\\?/, "") : "";
          return `<div class="search-result" data-id="${n.id}">
            <span class="layer-dot" style="background:${color}"></span>
            <span class="class-name">${highlight(n.label, query)}</span>
            <span class="namespace">${ns}</span>
          </div>`;
        })
        .join("");

      resultsEl.classList.add("visible");
    });

    resultsEl.addEventListener("click", (e) => {
      const item = e.target.closest(".search-result");
      if (!item) return;
      const nodeId = item.dataset.id;
      focusNode(nodeId);
      resultsEl.classList.remove("visible");
      input.value = "";
    });

    // Close search on escape
    input.addEventListener("keydown", (e) => {
      if (e.key === "Escape") {
        input.value = "";
        resultsEl.classList.remove("visible");
        input.blur();
      }
      if (e.key === "Enter") {
        const first = resultsEl.querySelector(".search-result");
        if (first) {
          focusNode(first.dataset.id);
          resultsEl.classList.remove("visible");
          input.value = "";
        }
      }
    });

    // Global keyboard shortcut
    document.addEventListener("keydown", (e) => {
      if (e.key === "/" && document.activeElement !== input) {
        e.preventDefault();
        input.focus();
      }
    });
  }

  function highlight(text, query) {
    const idx = text.toLowerCase().indexOf(query.toLowerCase());
    if (idx === -1) return text;
    return (
      text.slice(0, idx) +
      `<strong style="color:#58a6ff">${text.slice(idx, idx + query.length)}</strong>` +
      text.slice(idx + query.length)
    );
  }

  function focusNode(nodeId) {
    if (!graph.hasNode(nodeId)) return;

    const ownerClass = graph.getNodeAttribute(nodeId, "ownerClass") || nodeId;
    if (highlightedCycle !== null && nodesById[ownerClass]?.cycle !== highlightedCycle) {
      setHighlightedCycle(null);
    }
    selectedNode = nodeId;
    selectedNeighbors = new Set(graph.neighbors(nodeId));
    showInfoPanel(nodeId);
    renderer.refresh({ skipIndexation: true });
    fitCamera([nodeId, ...selectedNeighbors]);
  }

  /** Frames the given nodes, using Sigma's normalized display coordinates (the camera's own space). */
  function fitCamera(nodeIds) {
    const points = nodeIds.map((id) => renderer.getNodeDisplayData(id)).filter(Boolean);
    if (points.length === 0) {
      renderer.getCamera().animatedReset({ duration: 400 });
      return;
    }

    const xs = points.map((p) => p.x);
    const ys = points.map((p) => p.y);
    const extent = Math.max(Math.max(...xs) - Math.min(...xs), Math.max(...ys) - Math.min(...ys));
    renderer.getCamera().animate(
      {
        x: (Math.min(...xs) + Math.max(...xs)) / 2,
        y: (Math.min(...ys) + Math.max(...ys)) / 2,
        ratio: Math.min(1, Math.max(0.05, extent * 1.3)),
      },
      { duration: 400 },
    );
  }

  // ─── View mode ──────────────────────────────────────────────────────
  function setupViewMode() {
    const buttons = document.querySelectorAll(".view-btn");
    buttons.forEach((btn) => {
      btn.addEventListener("click", () => {
        const mode = btn.dataset.mode;
        if (mode === viewMode) return;

        buttons.forEach((b) => b.classList.remove("active"));
        btn.classList.add("active");
        viewMode = mode;

        if (mode === "namespaces") {
          namespacePath = [];
        }

        // Rebuild graph
        if (renderer) renderer.kill();
        selectedNode = null;
        selectedNeighbors.clear();
        hideInfoPanel();
        buildGraph();
        setupRenderer();
        updateBreadcrumb();
        startLayout();
        setTimeout(() => stopLayout(), mode === "namespaces" ? 3000 : 5000);
      });
    });
  }

  // ─── Namespace navigation ─────────────────────────────────────────
  function rebuildNamespaceView() {
    if (renderer) renderer.kill();
    selectedNode = null;
    selectedNeighbors.clear();
    hoveredNode = null;
    hoveredNeighbors.clear();
    hideInfoPanel();
    buildGraph();
    setupRenderer();
    updateBreadcrumb();
    startLayout();
    setTimeout(() => stopLayout(), 3000);
    // Force label refresh after layout settles
    setTimeout(() => renderer && renderer.refresh(), 100);
  }

  function updateBreadcrumb() {
    const el = document.getElementById("breadcrumb");
    if (viewMode !== "namespaces") {
      el.style.display = "none";
      return;
    }
    el.style.display = "flex";

    const rootLabel = commonPrefix.length > 0 ? commonPrefix.join("\\") : "Root";
    let html = `<span class="breadcrumb-item" data-depth="-1">${rootLabel}</span>`;
    namespacePath.forEach((segment, i) => {
      html += `<span class="breadcrumb-sep">›</span>`;
      html += `<span class="breadcrumb-item" data-depth="${i}">${segment}</span>`;
    });
    el.innerHTML = html;

    el.querySelectorAll(".breadcrumb-item").forEach((item) => {
      item.addEventListener("click", () => {
        const depth = parseInt(item.dataset.depth);
        if (depth < 0) {
          namespacePath = [];
        } else {
          namespacePath = namespacePath.slice(0, depth + 1);
        }
        // Don't rebuild if clicking the last (current) item
        const isLast = item === el.querySelector(".breadcrumb-item:last-child");
        if (!isLast || namespacePath.length === 0) {
          rebuildNamespaceView();
        }
      });
    });
  }

  // ─── Layout ─────────────────────────────────────────────────────────
  function startLayout() {
    if (fa2Running) return;

    const FA2 = graphologyLayoutForceAtlas2;
    const settings = FA2.inferSettings(graph);
    settings.gravity = 1;
    settings.scalingRatio = viewMode === "full" ? 5 : viewMode === "namespaces" ? 20 : 10;
    settings.barnesHutOptimize = true;
    settings.barnesHutTheta = 0.5;
    settings.slowDown = 5;

    fa2Layout = FA2.assign;

    fa2Running = true;
    document.getElementById("btn-layout").classList.add("active");

    function step() {
      if (!fa2Running) return;
      FA2.assign(graph, { iterations: 5, settings });
      requestAnimationFrame(step);
    }
    requestAnimationFrame(step);
  }

  function stopLayout() {
    fa2Running = false;
    document.getElementById("btn-layout").classList.remove("active");
    afterLayout.splice(0).forEach((callback) => callback());
  }

  /** Runs once nodes stop moving, positions read earlier would be stale. */
  function whenLayoutSettles(callback) {
    if (fa2Running) {
      afterLayout.push(callback);
    } else {
      callback();
    }
  }

  function setupLayoutControls() {
    document.getElementById("btn-layout").addEventListener("click", () => {
      if (fa2Running) {
        stopLayout();
      } else {
        startLayout();
      }
    });

    document.getElementById("btn-reset-zoom").addEventListener("click", () => {
      renderer.getCamera().animatedReset({ duration: 300 });
    });
  }

  // ─── Tooltip ────────────────────────────────────────────────────────
  function updateTooltip(nodeId) {
    const tooltip = document.getElementById("tooltip");
    const attrs = graph.getNodeAttributes(nodeId);
    const color = LAYER_COLORS[attrs.layer] || LAYER_COLORS.Other;

    tooltip.querySelector(".tooltip-title").textContent = attrs.label;
    const location = attrs.file ? ` — ${attrs.file}:${attrs.line}` : "";
    tooltip.querySelector(".tooltip-namespace").textContent = (attrs.namespace || nodeId) + location;

    const layerEl = tooltip.querySelector(".tooltip-layer");

    if (attrs.isNamespaceNode) {
      // Namespace node tooltip
      layerEl.textContent = `${attrs.classCount} classes`;
      layerEl.style.background = color + "33";
      layerEl.style.color = color;

      const methodsEl = tooltip.querySelector(".tooltip-methods");
      const breakdown = attrs.layerBreakdown || {};
      const sorted = Object.entries(breakdown).sort((a, b) => b[1] - a[1]).slice(0, 8);
      if (sorted.length > 0) {
        methodsEl.innerHTML = `Layers:<ul>${sorted.map(([l, c]) => `<li>${l}: ${c}</li>`).join("")}</ul>`;
      } else {
        methodsEl.innerHTML = "";
      }

      if (attrs.hasChildren) {
        methodsEl.innerHTML += `<div style="margin-top:6px;color:#58a6ff;font-size:11px">Click to explore</div>`;
      }
    } else {
      // Class/method node tooltip
      layerEl.textContent = attrs.layer;
      layerEl.style.background = color + "33";
      layerEl.style.color = color;

      const methodsEl = tooltip.querySelector(".tooltip-methods");
      if (attrs.nodeType === "class" && attrs.methods && attrs.methods.length > 0) {
        const displayed = attrs.methods.slice(0, 10);
        const more = attrs.methods.length > 10 ? `<li style="color:#484f58">...+${attrs.methods.length - 10} more</li>` : "";
        methodsEl.innerHTML = `Methods (${attrs.methods.length}):<ul>${displayed.map((m) => `<li>${m}()</li>`).join("")}${more}</ul>`;
      } else {
        methodsEl.innerHTML = "";
      }
    }

    tooltip.style.display = "block";
  }

  function hideTooltip() {
    document.getElementById("tooltip").style.display = "none";
  }

  // ─── Info panel ─────────────────────────────────────────────────────
  function showInfoPanel(nodeId) {
    const panel = document.getElementById("info-panel");
    const attrs = graph.getNodeAttributes(nodeId);
    const color = LAYER_COLORS[attrs.layer] || LAYER_COLORS.Other;

    panel.querySelector(".panel-title").innerHTML =
      `<span style="color:${color}">${attrs.label}</span>`;

    const body = panel.querySelector(".panel-body");
    let html = "";

    if (attrs.isNamespaceNode) {
      // Namespace node info panel
      html += `
        <div style="font-size:11px;color:#8b949e;margin-bottom:8px;font-family:monospace;word-break:break-all">${attrs.namespace}</div>
        <div style="margin-bottom:12px">
          <span style="display:inline-block;padding:2px 8px;border-radius:10px;font-size:10px;font-weight:600;background:${color}33;color:${color}">${attrs.classCount} classes</span>
        </div>
      `;

      // Layer breakdown
      const breakdown = attrs.layerBreakdown || {};
      const sorted = Object.entries(breakdown).sort((a, b) => b[1] - a[1]);
      if (sorted.length > 0) {
        html += `<div class="connections-section"><h4>Layer breakdown</h4>`;
        sorted.forEach(([layer, count]) => {
          const lColor = LAYER_COLORS[layer] || LAYER_COLORS.Other;
          html += `<div class="connection-item" style="cursor:default">
            <span class="filter-dot" style="background:${lColor};width:6px;height:6px"></span>
            ${layer}
            <span class="filter-count">${count}</span>
          </div>`;
        });
        html += `</div>`;
      }

      // Contained classes (top 30)
      const contained = attrs.containedClasses || [];
      if (contained.length > 0) {
        html += `<div class="connections-section"><h4>Classes (${contained.length})</h4>`;
        contained.slice(0, 30).forEach((classId) => {
          const classNode = nodesById[classId];
          if (!classNode) return;
          const cColor = LAYER_COLORS[classNode.layer] || LAYER_COLORS.Other;
          html += `<div class="connection-item" data-id="${classId}">
            <span class="filter-dot" style="background:${cColor};width:6px;height:6px"></span>
            ${classNode.label}
            <span class="edge-type">${classNode.layer}</span>
          </div>`;
        });
        if (contained.length > 30) {
          html += `<div style="color:#484f58;font-size:11px;padding:4px 0">...+${contained.length - 30} more</div>`;
        }
        html += `</div>`;
      }

      // Dependencies
      const outEdges = graph.outEdges(nodeId);
      const inEdges = graph.inEdges(nodeId);

      if (outEdges.length > 0) {
        html += `<div class="connections-section"><h4>Depends on</h4>`;
        outEdges.forEach((e) => {
          const target = graph.target(e);
          const tAttrs = graph.getNodeAttributes(target);
          const tColor = LAYER_COLORS[tAttrs.layer] || LAYER_COLORS.Other;
          const count = graph.getEdgeAttribute(e, "edgeCount") || 1;
          html += `<div class="connection-item" data-id="${target}">
            <span class="filter-dot" style="background:${tColor};width:6px;height:6px"></span>
            ${tAttrs.label}
            <span class="edge-type">${count} deps</span>
          </div>`;
        });
        html += `</div>`;
      }

      if (inEdges.length > 0) {
        html += `<div class="connections-section"><h4>Used by</h4>`;
        inEdges.forEach((e) => {
          const source = graph.source(e);
          const sAttrs = graph.getNodeAttributes(source);
          const sColor = LAYER_COLORS[sAttrs.layer] || LAYER_COLORS.Other;
          const count = graph.getEdgeAttribute(e, "edgeCount") || 1;
          html += `<div class="connection-item" data-id="${source}">
            <span class="filter-dot" style="background:${sColor};width:6px;height:6px"></span>
            ${sAttrs.label}
            <span class="edge-type">${count} deps</span>
          </div>`;
        });
        html += `</div>`;
      }
    } else {
      // Class/method node info panel (existing logic)
      html += `
        <div style="font-size:11px;color:#8b949e;margin-bottom:8px;font-family:monospace;word-break:break-all">${nodeId}</div>
        <div style="margin-bottom:12px">
          <span style="display:inline-block;padding:2px 8px;border-radius:10px;font-size:10px;font-weight:600;text-transform:uppercase;background:${color}33;color:${color}">${attrs.layer}</span>
          ${attrs.classType ? `<span style="display:inline-block;padding:2px 8px;border-radius:10px;font-size:10px;background:#21262d;color:#8b949e;margin-left:4px">${attrs.classType}</span>` : ""}
        </div>
        ${editorLinkHtml(attrs)}
        ${metricsHtml(attrs)}
      `;

      const inEdges = graph.inEdges(nodeId);
      const outEdges = graph.outEdges(nodeId);

      if (outEdges.length > 0) {
        html += `<div class="connections-section"><h4>Depends on (${outEdges.length})</h4>`;
        const targets = {};
        outEdges.forEach((e) => {
          const target = graph.target(e);
          const edgeType = graph.getEdgeAttribute(e, "edgeType");
          if (!targets[target]) targets[target] = [];
          targets[target].push(edgeType);
        });
        Object.entries(targets)
          .slice(0, 30)
          .forEach(([target, types]) => {
            const tAttrs = graph.getNodeAttributes(target);
            const tColor = LAYER_COLORS[tAttrs.layer] || LAYER_COLORS.Other;
            const uniqueTypes = [...new Set(types)];
            html += `<div class="connection-item" data-id="${target}">
              <span class="filter-dot" style="background:${tColor};width:6px;height:6px"></span>
              ${tAttrs.label}
              ${uniqueTypes.map((t) => `<span class="edge-type">${t}</span>`).join("")}
            </div>`;
          });
        if (Object.keys(targets).length > 30) {
          html += `<div style="color:#484f58;font-size:11px;padding:4px 0">...+${Object.keys(targets).length - 30} more</div>`;
        }
        html += `</div>`;
      }

      if (inEdges.length > 0) {
        html += `<div class="connections-section"><h4>Used by (${inEdges.length})</h4>`;
        const sources = {};
        inEdges.forEach((e) => {
          const source = graph.source(e);
          const edgeType = graph.getEdgeAttribute(e, "edgeType");
          if (!sources[source]) sources[source] = [];
          sources[source].push(edgeType);
        });
        Object.entries(sources)
          .slice(0, 30)
          .forEach(([source, types]) => {
            const sAttrs = graph.getNodeAttributes(source);
            const sColor = LAYER_COLORS[sAttrs.layer] || LAYER_COLORS.Other;
            const uniqueTypes = [...new Set(types)];
            html += `<div class="connection-item" data-id="${source}">
              <span class="filter-dot" style="background:${sColor};width:6px;height:6px"></span>
              ${sAttrs.label}
              ${uniqueTypes.map((t) => `<span class="edge-type">${t}</span>`).join("")}
            </div>`;
          });
        if (Object.keys(sources).length > 30) {
          html += `<div style="color:#484f58;font-size:11px;padding:4px 0">...+${Object.keys(sources).length - 30} more</div>`;
        }
        html += `</div>`;
      }
    }

    body.innerHTML = html;
    panel.classList.add("visible");

    body.querySelector("[data-cycle]")?.addEventListener("click", (e) => {
      isolateCycle(Number(e.currentTarget.dataset.cycle));
    });

    // Click on connections to navigate
    body.querySelectorAll(".connection-item[data-id]").forEach((item) => {
      item.addEventListener("click", () => {
        const targetId = item.dataset.id;
        if (viewMode === "namespaces" && graph.hasNode(targetId)) {
          // In namespace mode, clicking a connection selects that namespace node
          selectedNode = targetId;
          selectedNeighbors = new Set(graph.neighbors(targetId));
          showInfoPanel(targetId);
          renderer.refresh({ skipIndexation: true });
        } else {
          focusNode(targetId);
        }
      });
    });
  }

  function hideInfoPanel() {
    document.getElementById("info-panel").classList.remove("visible");
  }

  function setupInfoPanel() {
    document.querySelector("#info-panel .panel-close").addEventListener("click", () => {
      selectedNode = null;
      hideInfoPanel();
      renderer.refresh({ skipIndexation: true });
    });
  }

  function metricsHtml(attrs) {
    if (!attrs.metrics) return "";

    let html = `<div class="metrics-row" title="Ca: classes depending on it · Ce: classes it depends on · Instability: Ce / (Ca + Ce)">
      <span>Ca <strong>${attrs.metrics.ca}</strong></span>
      <span>Ce <strong>${attrs.metrics.ce}</strong></span>
      <span>Instability <strong>${attrs.metrics.instability.toFixed(2)}</strong></span>
    </div>`;

    if (attrs.cycle !== null && graphData.cycles?.[attrs.cycle]) {
      const size = graphData.cycles[attrs.cycle].members.length;
      html += `<div class="cycle-badge">
        <span>In a dependency cycle of ${size} classes</span>
        <button data-cycle="${attrs.cycle}">Isolate</button>
      </div>`;
    }

    return html;
  }

  // ─── Editor links ───────────────────────────────────────────────────
  function editorUrl(file, line) {
    const projectPath = (localStorage.getItem("php-deps.projectPath") ?? graphData.root ?? "").replace(/\/$/, "");
    const template = localStorage.getItem("php-deps.editorUrl") || DEFAULT_EDITOR_URL;
    const path = projectPath ? `${projectPath}/${file}` : file;

    return template.replace("{file}", encodeURI(path)).replace("{line}", String(line || 1));
  }

  function editorLinkHtml(attrs) {
    const file = attrs.nodeType === "method" ? nodesById[attrs.ownerClass]?.file : attrs.file;
    if (!file) return "";

    return `<a class="editor-link" href="${escapeHtml(editorUrl(file, attrs.line))}">${escapeHtml(file)}:${attrs.line || 1} ↗</a>`;
  }

  function setupSettings() {
    const editorInput = document.getElementById("editor-url");
    const pathInput = document.getElementById("project-path");
    editorInput.value = localStorage.getItem("php-deps.editorUrl") || DEFAULT_EDITOR_URL;
    pathInput.value = localStorage.getItem("php-deps.projectPath") ?? graphData.root ?? "";
    pathInput.placeholder = graphData.root || "/path/to/project";

    editorInput.addEventListener("change", () => localStorage.setItem("php-deps.editorUrl", editorInput.value.trim()));
    pathInput.addEventListener("change", () => localStorage.setItem("php-deps.projectPath", pathInput.value.trim()));
  }

  // ─── Node size ──────────────────────────────────────────────────────
  function setupSizeMode() {
    const select = document.getElementById("size-by");
    select.value = sizeBy;
    select.disabled = !graphData.nodes.some((n) => n.metrics);
    select.addEventListener("change", () => {
      sizeBy = select.value;
      if (viewMode === "namespaces") return;
      graph.forEachNode((id, attrs) => {
        if (attrs.nodeType === "class") graph.setNodeAttribute(id, "size", classNodeSize(nodesById[id]));
      });
    });
  }

  // ─── Cycles ─────────────────────────────────────────────────────────
  function setupCycles() {
    const cycles = graphData.cycles || [];
    if (cycles.length === 0) return;

    document.getElementById("cycles-section").hidden = false;
    const listEl = document.getElementById("cycle-list");
    listEl.innerHTML = cycles.slice(0, MAX_LISTED_CYCLES).map((cycle, index) => {
      const path = cycle.path.map((id) => nodesById[id]?.label || id);
      return `<div class="cycle-item" data-cycle-index="${index}" title="${escapeHtml([...cycle.path, cycle.path[0]].join(" → "))}">
        <span class="cycle-size">${cycle.members.length}</span>
        <span class="cycle-path">${escapeHtml([...path, path[0]].join(" → "))}</span>
      </div>`;
    }).join("") + (cycles.length > MAX_LISTED_CYCLES ? `<div style="color:#484f58;font-size:11px;padding:4px 0">...+${cycles.length - MAX_LISTED_CYCLES} more</div>` : "");

    listEl.querySelectorAll("[data-cycle-index]").forEach((item) => {
      item.addEventListener("click", () => isolateCycle(Number(item.dataset.cycleIndex)));
    });
    document.getElementById("cycle-clear").addEventListener("click", () => isolateCycle(null));
  }

  function setHighlightedCycle(index) {
    highlightedCycle = index;
    document.getElementById("cycle-clear").hidden = index === null;
    document.querySelectorAll("#cycle-list [data-cycle-index]").forEach((item) => {
      item.classList.toggle("active", Number(item.dataset.cycleIndex) === index);
    });
  }

  function isolateCycle(index) {
    setHighlightedCycle(index);

    if (index !== null && viewMode === "namespaces") {
      document.querySelector('.view-btn[data-mode="classes"]').click();
    }

    selectedNode = null;
    selectedNeighbors.clear();
    hideInfoPanel();
    renderer.refresh({ skipIndexation: true });

    if (index === null) {
      renderer.getCamera().animatedReset({ duration: 400 });
      return;
    }
    whenLayoutSettles(() => fitCamera(graphData.cycles[index].members.filter((id) => graph.hasNode(id))));
  }

  // ─── Boot ───────────────────────────────────────────────────────────
  loadData();
})();
