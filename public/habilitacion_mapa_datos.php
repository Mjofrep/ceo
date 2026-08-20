<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/habilitacion_datos_lib.php';

$idRol = (int)($_SESSION['auth']['id_rol'] ?? 0);
if ($idRol !== 1) {
    header('Location: ' . app_url('/public/general.php'));
    exit;
}

$objectives = habDataToolObjectives();
$tables = habDataToolConfig();
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Mapa de Datos Habilitacion | <?= esc(APP_NAME) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
body {
    min-height: 100vh;
    background: radial-gradient(circle at top right, rgba(37, 99, 235, 0.10), transparent 30%), linear-gradient(180deg, #f8fbff 0%, #eef4fb 100%);
    color: #0f172a;
}
.shell {
    max-width: 1520px;
}
.hero,
.card-soft {
    background: rgba(255,255,255,0.92);
    border: 1px solid rgba(148, 163, 184, 0.18);
    border-radius: 26px;
    box-shadow: 0 16px 40px rgba(15, 23, 42, 0.08);
}
.menu-column {
    position: sticky;
    top: 1rem;
}
.objective-card {
    border: 1px solid rgba(148, 163, 184, 0.16);
    border-radius: 18px;
    background: #fff;
}
.table-link {
    width: 100%;
    text-align: left;
    border: 1px solid rgba(148, 163, 184, 0.14);
    border-radius: 14px;
    background: #f8fafc;
    padding: .85rem .95rem;
    transition: background .15s ease, border-color .15s ease;
}
.table-link:hover {
    background: #eef4ff;
    border-color: rgba(37, 99, 235, 0.24);
}
.table-link.active {
    background: #dbeafe;
    border-color: rgba(37, 99, 235, 0.35);
}
.nav-link-tabwrap {
    display: inline-flex;
    align-items: center;
    gap: .5rem;
}
.btn-close-tab {
    border: 0;
    background: transparent;
    color: #64748b;
    padding: 0;
    line-height: 1;
    font-size: 1rem;
}
.btn-close-tab:hover {
    color: #dc2626;
}
.tab-pane-card {
    min-height: 520px;
}
.table-zone {
    overflow-x: auto;
}
.grid-table th,
.grid-table td {
    vertical-align: top;
    min-width: 140px;
}
.grid-table input,
.grid-table select,
.grid-table textarea {
    font-size: .86rem;
}
.grid-table textarea {
    min-width: 220px;
}
.cell-readonly {
    color: #64748b;
    font-size: .86rem;
}
.cell-editable {
    background: #fffef2;
}
.toolbar-row {
    gap: .75rem;
}
.tiny-note {
    font-size: .78rem;
    color: #64748b;
}
.empty-state {
    border: 1px dashed rgba(148, 163, 184, 0.4);
    border-radius: 20px;
    padding: 3rem 1.5rem;
    text-align: center;
    color: #64748b;
}
.filter-row input,
.filter-row select {
    min-width: 120px;
}
</style>
</head>
<body>
<div class="container-fluid shell py-4">
    <section class="hero p-4 p-lg-5 mb-4">
        <div class="d-flex flex-column flex-lg-row justify-content-between align-items-start gap-4">
            <div>
                <span class="badge rounded-pill text-bg-primary-subtle text-primary-emphasis mb-3">
                    <i class="bi bi-diagram-3 me-1"></i> Fase 1 Habilitacion
                </span>
                <h1 class="display-6 fw-semibold mb-3">Mapa de Datos de Habilitacion</h1>
                <p class="text-secondary mb-2">Consulta y edicion controlada de tablas clave del proceso de habilitacion. Solo permite actualizar campos no clave.</p>
                <p class="small text-muted mb-0">Sesion actual: <strong><?= esc((string)($_SESSION['auth']['nombre'] ?? 'Administrador')) ?></strong></p>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a class="btn btn-outline-secondary" href="<?= esc(app_url('/public/general.php')) ?>">Volver al Panel</a>
            </div>
        </div>
    </section>

    <div class="row g-4 align-items-start">
        <div class="col-lg-4 col-xl-3">
            <div class="menu-column card-soft p-3 p-lg-4">
                <h2 class="h5 mb-3">Mapa Practico</h2>
                <div class="accordion" id="objectiveAccordion">
                    <?php foreach ($objectives as $index => $objective): ?>
                        <div class="accordion-item objective-card mb-3">
                            <h2 class="accordion-header">
                                <button class="accordion-button <?= $index === 0 ? '' : 'collapsed' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#objective<?= esc($objective['id']) ?>">
                                    <span>
                                        <span class="fw-semibold d-block"><?= esc($objective['label']) ?></span>
                                        <span class="tiny-note"><?= esc($objective['description']) ?></span>
                                    </span>
                                </button>
                            </h2>
                            <div id="objective<?= esc($objective['id']) ?>" class="accordion-collapse collapse <?= $index === 0 ? 'show' : '' ?>" data-bs-parent="#objectiveAccordion">
                                <div class="accordion-body pt-2">
                                    <div class="d-grid gap-2">
                                        <?php foreach ($objective['tables'] as $tableName): ?>
                                            <?php $cfg = $tables[$tableName] ?? null; ?>
                                            <?php if ($cfg === null) continue; ?>
                                            <button
                                                type="button"
                                                class="table-link js-open-table"
                                                data-table="<?= esc($tableName) ?>"
                                                data-label="<?= esc($cfg['label']) ?>"
                                                data-description="<?= esc($cfg['description']) ?>"
                                            >
                                                <div class="fw-semibold"><?= esc($cfg['label']) ?></div>
                                                <div class="tiny-note"><?= esc($tableName) ?></div>
                                            </button>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-8 col-xl-9">
            <div class="card-soft p-3 p-lg-4">
                <ul class="nav nav-tabs flex-nowrap overflow-auto" id="tableTabs" role="tablist"></ul>
                <div class="d-flex justify-content-end mb-3">
                    <button type="button" class="btn btn-success btn-sm" id="exportActiveExcel" disabled>
                        <i class="bi bi-file-earmark-excel me-1"></i>Exportar Excel
                    </button>
                </div>
                <div class="tab-content pt-4" id="tableTabContent">
                    <div class="empty-state" id="emptyState">
                        <div class="mb-3"><i class="bi bi-table fs-1"></i></div>
                        <h2 class="h5">Selecciona una tabla desde el mapa</h2>
                        <p class="mb-0">Se abrira una solapa con filtros por columna, paginacion y edicion por fila para campos no clave.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<template id="tabPaneTemplate">
    <div class="tab-pane fade tab-pane-card" role="tabpanel">
        <div class="d-flex flex-column flex-xl-row justify-content-between align-items-start gap-3 mb-3">
            <div>
                <h3 class="h5 mb-1 js-table-title"></h3>
                <div class="tiny-note js-table-description"></div>
            </div>
            <div class="d-flex flex-wrap toolbar-row align-items-end">
                <div>
                    <label class="form-label small mb-1">Registros por pagina</label>
                    <select class="form-select form-select-sm js-page-size">
                        <option value="20">20</option>
                        <option value="30">30</option>
                        <option value="50">50</option>
                    </select>
                </div>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-primary btn-sm js-search">Buscar</button>
                    <button type="button" class="btn btn-outline-secondary btn-sm js-reset">Limpiar</button>
                </div>
            </div>
        </div>
        <div class="tiny-note mb-3 js-table-meta"></div>
        <div class="table-zone js-table-container"></div>
    </div>
</template>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const tableConfigs = <?= json_encode(array_map(static fn($cfg) => [
    'label' => (string)$cfg['label'],
    'description' => (string)$cfg['description'],
], $tables), JSON_UNESCAPED_UNICODE) ?>;

const listUrl = 'ajax_habilitacion_datos_listar.php';
const updateUrl = 'ajax_habilitacion_datos_actualizar.php';
const exportUrl = 'habilitacion_mapa_datos_excel.php';
const openButtons = document.querySelectorAll('.js-open-table');
const tabList = document.getElementById('tableTabs');
const tabContent = document.getElementById('tableTabContent');
const emptyState = document.getElementById('emptyState');
const tabPaneTemplate = document.getElementById('tabPaneTemplate');
const exportActiveExcelButton = document.getElementById('exportActiveExcel');
const tableStates = new Map();

function getState(tableName) {
    if (!tableStates.has(tableName)) {
        tableStates.set(tableName, {
            page: 1,
            per_page: 20,
            filters: {},
            rows: [],
            columns: [],
            primary_key: [],
            editable_columns: [],
        });
    }
    return tableStates.get(tableName);
}

function activateButton(tableName) {
    openButtons.forEach((btn) => {
        btn.classList.toggle('active', btn.dataset.table === tableName);
    });
}

function refreshEmptyState() {
    const hasTabs = !!tabList.querySelector('[data-table-tab]');
    emptyState.style.display = hasTabs ? 'none' : '';
    if (!hasTabs) {
        activateButton('');
    }
    updateExportButton();
}

function getActiveTableName() {
    return tabList.querySelector('button[data-table-tab].active')?.dataset.tableTab || '';
}

function updateExportButton() {
    const activeTable = getActiveTableName();
    exportActiveExcelButton.disabled = activeTable === '';
}

function buildTabId(tableName) {
    return 'tab_' + tableName.replace(/[^a-zA-Z0-9_]/g, '_');
}

function ensureTab(tableName) {
    let tabButton = document.querySelector(`button[data-table-tab="${tableName}"]`);
    let pane = document.getElementById(buildTabId(tableName));

    if (!tabButton) {
        refreshEmptyState();

        const li = document.createElement('li');
        li.className = 'nav-item';
        li.role = 'presentation';
        li.dataset.tableNav = tableName;

        tabButton = document.createElement('button');
        tabButton.className = 'nav-link';
        tabButton.type = 'button';
        tabButton.role = 'tab';
        tabButton.dataset.bsToggle = 'tab';
        tabButton.dataset.tableTab = tableName;
        tabButton.dataset.bsTarget = '#' + buildTabId(tableName);
        tabButton.innerHTML = `<span class="nav-link-tabwrap"><span>${tableConfigs[tableName]?.label || tableName} <span class="ms-2 text-secondary">${tableName}</span></span><span class="btn-close-tab js-close-tab" role="button" aria-label="Cerrar">&times;</span></span>`;
        tabButton.addEventListener('shown.bs.tab', updateExportButton);
        li.appendChild(tabButton);
        tabList.appendChild(li);

        const fragment = tabPaneTemplate.content.cloneNode(true);
        pane = fragment.querySelector('.tab-pane');
        pane.id = buildTabId(tableName);
        pane.dataset.tablePane = tableName;
        fragment.querySelector('.js-table-title').textContent = tableConfigs[tableName]?.label || tableName;
        fragment.querySelector('.js-table-description').textContent = tableConfigs[tableName]?.description || '';
        tabContent.appendChild(fragment);

        pane = document.getElementById(buildTabId(tableName));
        bindPaneEvents(pane, tableName);
        bindTabClose(li, tabButton, pane, tableName);
    }

    bootstrap.Tab.getOrCreateInstance(tabButton).show();
    activateButton(tableName);
    return pane;
}

function bindTabClose(li, tabButton, pane, tableName) {
    const closeButton = tabButton.querySelector('.js-close-tab');
    if (!closeButton) return;

    closeButton.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();

        const wasActive = tabButton.classList.contains('active');
        const previousNav = li.previousElementSibling?.querySelector('[data-table-tab]');
        const nextNav = li.nextElementSibling?.querySelector('[data-table-tab]');

        li.remove();
        pane.remove();
        tableStates.delete(tableName);

        if (wasActive) {
            const targetNav = nextNav || previousNav;
            if (targetNav) {
                bootstrap.Tab.getOrCreateInstance(targetNav).show();
                activateButton(targetNav.dataset.tableTab || '');
            }
        }

        refreshEmptyState();
        updateExportButton();
    });
}

function bindPaneEvents(pane, tableName) {
    pane.querySelector('.js-search').addEventListener('click', () => {
        collectFilters(pane, tableName);
        const state = getState(tableName);
        state.page = 1;
        loadTable(tableName);
    });

    pane.querySelector('.js-reset').addEventListener('click', () => {
        const state = getState(tableName);
        state.filters = {};
        state.page = 1;
        pane.querySelectorAll('.js-filter').forEach((input) => {
            input.value = '';
        });
        loadTable(tableName);
    });

    pane.querySelector('.js-page-size').addEventListener('change', (event) => {
        const state = getState(tableName);
        state.per_page = parseInt(event.target.value, 10) || 20;
        state.page = 1;
        loadTable(tableName);
    });
}

function collectFilters(pane, tableName) {
    const state = getState(tableName);
    const filters = {};
    pane.querySelectorAll('.js-filter').forEach((input) => {
        const column = input.dataset.column;
        const value = input.value;
        if (column && value !== '') {
            filters[column] = value;
        }
    });
    state.filters = filters;
}

async function loadTable(tableName) {
    const pane = document.getElementById(buildTabId(tableName));
    if (!pane) return;

    const state = getState(tableName);
    const container = pane.querySelector('.js-table-container');
    const meta = pane.querySelector('.js-table-meta');
    const pageSizeSelect = pane.querySelector('.js-page-size');
    pageSizeSelect.value = String(state.per_page);

    container.innerHTML = '<div class="py-5 text-center text-secondary">Cargando datos...</div>';

    const payload = {
        table: tableName,
        page: state.page,
        per_page: state.per_page,
        filters: state.filters,
    };

    const response = await fetch(listUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
    });

    const result = await response.json();
    if (!result.ok) {
        container.innerHTML = `<div class="alert alert-danger mb-0">${result.error || 'No fue posible cargar la tabla.'}</div>`;
        return;
    }

    state.rows = result.rows || [];
    state.columns = result.columns || [];
    state.primary_key = result.primary_key || [];
    state.editable_columns = result.editable_columns || [];

    meta.textContent = `Total registros: ${result.total} | Pagina ${result.page} de ${result.pages}`;
    renderTable(pane, tableName, result);
}

function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function renderFilterInput(column) {
    const mode = column.filter_mode || 'text';
    const currentValue = column.filter_value ?? '';
    if (mode === 'enum' && Array.isArray(column.enum_values) && column.enum_values.length > 0) {
        const options = ['<option value="">Todos</option>']
            .concat(column.enum_values.map((value) => `<option value="${escapeHtml(value)}" ${String(currentValue) === String(value) ? 'selected' : ''}>${escapeHtml(value)}</option>`))
            .join('');
        return `<select class="form-select form-select-sm js-filter" data-column="${escapeHtml(column.name)}">${options}</select>`;
    }

    const type = mode === 'number' ? 'number' : (mode === 'date' ? 'text' : 'text');
    const placeholder = mode === 'date' ? 'YYYY-MM-DD' : 'Filtrar';
    return `<input type="${type}" class="form-control form-control-sm js-filter" data-column="${escapeHtml(column.name)}" value="${escapeHtml(currentValue)}" placeholder="${placeholder}">`;
}

function renderEditor(column, value, rowIndex) {
    const safeValue = value ?? '';
    const columnName = escapeHtml(column.name);
    const fieldName = `field_${rowIndex}_${columnName}`;
    const mode = column.filter_mode || 'text';
    if (mode === 'enum' && Array.isArray(column.enum_values) && column.enum_values.length > 0) {
        const options = ['<option value="">(vacío)</option>']
            .concat(column.enum_values.map((item) => `<option value="${escapeHtml(item)}" ${String(safeValue) === String(item) ? 'selected' : ''}>${escapeHtml(item)}</option>`))
            .join('');
        return `<select class="form-select form-select-sm js-edit-input" data-column="${columnName}" name="${fieldName}">${options}</select>`;
    }
    if (String(column.type || '').toLowerCase().includes('text')) {
        return `<textarea class="form-control form-control-sm js-edit-input" data-column="${columnName}" name="${fieldName}" rows="2">${escapeHtml(safeValue)}</textarea>`;
    }
    return `<input type="text" class="form-control form-control-sm js-edit-input" data-column="${columnName}" name="${fieldName}" value="${escapeHtml(safeValue)}">`;
}

function buildKeyPayload(row, primaryKey) {
    const key = {};
    primaryKey.forEach((column) => {
        key[column] = row[column] ?? null;
    });
    return key;
}

function renderTable(pane, tableName, result) {
    const state = getState(tableName);
    const container = pane.querySelector('.js-table-container');
    const columns = result.columns || [];
    const rows = result.rows || [];

    const header = columns.map((column) => `<th>${escapeHtml(column.label)}<div class="tiny-note">${escapeHtml(column.type)}</div></th>`).join('');
    const filterRow = columns.map((column) => `<th>${renderFilterInput(column)}</th>`).join('');

    let body = '';
    if (rows.length === 0) {
        body = `<tr><td colspan="${columns.length + 1}" class="text-center text-secondary py-4">Sin registros para los filtros seleccionados.</td></tr>`;
    } else {
        body = rows.map((row, rowIndex) => {
            const keyPayload = buildKeyPayload(row, state.primary_key);
            const keyJson = escapeHtml(JSON.stringify(keyPayload));
            const cells = columns.map((column) => {
                const value = row[column.name] ?? '';
                const editable = state.editable_columns.includes(column.name);
                return `
                    <td data-column-cell="${escapeHtml(column.name)}" class="${editable ? 'cell-editable' : ''}">
                        <div class="js-cell-readonly ${editable ? '' : 'cell-readonly'}">${escapeHtml(value)}</div>
                        ${editable ? `<div class="js-cell-editor d-none">${renderEditor(column, value, rowIndex)}</div>` : ''}
                    </td>`;
            }).join('');

            return `
                <tr data-row-index="${rowIndex}" data-key='${keyJson}'>
                    ${cells}
                    <td>
                        <div class="d-flex gap-2">
                            <button type="button" class="btn btn-outline-primary btn-sm js-edit-row">Editar</button>
                            <button type="button" class="btn btn-success btn-sm d-none js-save-row">Guardar</button>
                            <button type="button" class="btn btn-outline-secondary btn-sm d-none js-cancel-row">Cancelar</button>
                        </div>
                    </td>
                </tr>`;
        }).join('');
    }

    const pagination = `
        <div class="d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2">
            <div class="tiny-note">Mostrando ${rows.length} registro(s) en esta pagina.</div>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-outline-secondary btn-sm js-prev-page" ${result.page <= 1 ? 'disabled' : ''}>Anterior</button>
                <button type="button" class="btn btn-outline-secondary btn-sm js-next-page" ${result.page >= result.pages ? 'disabled' : ''}>Siguiente</button>
            </div>
        </div>`;

    container.innerHTML = `
        <div class="table-responsive">
            <table class="table table-sm table-bordered align-middle grid-table">
                <thead class="table-light">
                    <tr>${header}<th>Acciones</th></tr>
                    <tr class="filter-row">${filterRow}<th class="tiny-note">Filtros por columna</th></tr>
                </thead>
                <tbody>${body}</tbody>
            </table>
        </div>
        ${pagination}`;

    container.querySelectorAll('.js-edit-row').forEach((button) => {
        button.addEventListener('click', () => toggleEditRow(button.closest('tr'), true));
    });
    container.querySelectorAll('.js-cancel-row').forEach((button) => {
        button.addEventListener('click', () => toggleEditRow(button.closest('tr'), false, true));
    });
    container.querySelectorAll('.js-save-row').forEach((button) => {
        button.addEventListener('click', () => saveRow(tableName, button.closest('tr')));
    });
    container.querySelector('.js-prev-page')?.addEventListener('click', () => {
        state.page = Math.max(1, state.page - 1);
        loadTable(tableName);
    });
    container.querySelector('.js-next-page')?.addEventListener('click', () => {
        state.page = Math.min(result.pages, state.page + 1);
        loadTable(tableName);
    });
}

function toggleEditRow(row, editing, reset = false) {
    if (!row) return;
    row.querySelectorAll('.js-cell-readonly').forEach((node) => node.classList.toggle('d-none', editing));
    row.querySelectorAll('.js-cell-editor').forEach((node) => node.classList.toggle('d-none', !editing));
    row.querySelector('.js-edit-row')?.classList.toggle('d-none', editing);
    row.querySelector('.js-save-row')?.classList.toggle('d-none', !editing);
    row.querySelector('.js-cancel-row')?.classList.toggle('d-none', !editing);

    if (reset) {
        row.querySelectorAll('.js-edit-input').forEach((input) => {
            const readonly = input.closest('td')?.querySelector('.js-cell-readonly');
            const value = readonly ? readonly.textContent : '';
            input.value = value ?? '';
        });
    }
}

async function saveRow(tableName, row) {
    const key = JSON.parse(row.dataset.key || '{}');
    const values = {};
    row.querySelectorAll('.js-edit-input').forEach((input) => {
        values[input.dataset.column] = input.value;
    });

    const response = await fetch(updateUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ table: tableName, key, values }),
    });

    const result = await response.json();
    if (!result.ok) {
        alert(result.error || 'No fue posible guardar el registro.');
        return;
    }

    row.querySelectorAll('.js-edit-input').forEach((input) => {
        const column = input.dataset.column;
        const td = row.querySelector(`[data-column-cell="${CSS.escape(column)}"]`);
        const readonly = td?.querySelector('.js-cell-readonly');
        if (readonly) {
            readonly.textContent = input.value;
        }
    });

    toggleEditRow(row, false);
}

openButtons.forEach((button) => {
    button.addEventListener('click', () => {
        const tableName = button.dataset.table;
        ensureTab(tableName);
        loadTable(tableName);
    });
});

exportActiveExcelButton.addEventListener('click', () => {
    const tableName = getActiveTableName();
    if (!tableName) {
        return;
    }

    const state = getState(tableName);
    const params = new URLSearchParams({
        table: tableName,
        filters: JSON.stringify(state.filters || {}),
    });

    window.location.href = `${exportUrl}?${params.toString()}`;
});

refreshEmptyState();
updateExportButton();
</script>
</body>
</html>
