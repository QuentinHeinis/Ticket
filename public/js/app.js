'use strict';

const STATUS_LABELS = {
    new: 'Nouveau',
    assigned: 'Assigné',
    in_progress: 'En cours',
    resolved: 'Résolu',
    closed: 'Clôturé',
};

const PRIORITY_LABELS = {
    low: 'Basse',
    normal: 'Normale',
    high: 'Haute',
    urgent: 'Urgente',
};

const STATUS_TRANSITIONS = {
    new: ['assigned'],
    assigned: ['in_progress'],
    in_progress: ['resolved'],
    resolved: ['closed', 'in_progress'],
    closed: [],
};

let ticketsCursor = null;
let overduePage = 1;

function createElement(tagName, className, text) {
    const element = document.createElement(tagName);

    if (className) {
        element.className = className;
    }

    if (text !== undefined && text !== null) {
        element.textContent = String(text);
    }

    return element;
}

function appendCell(row, value) {
    row.appendChild(createElement('td', null, value));

    return row;
}

function setText(id, value) {
    const element = document.getElementById(id);

    if (element) {
        element.textContent = String(value);
    }
}

function clearError(element) {
    if (element) {
        element.textContent = '';
    }
}

function errorMessages(data, fallback) {
    const messages = [];

    if (data && typeof data === 'object') {
        if (typeof data.error === 'string' && data.error !== '') {
            messages.push(data.error);
        }

        if (typeof data.detail === 'string' && data.detail !== '') {
            messages.push(data.detail);
        }

        if (Array.isArray(data.violations)) {
            for (const violation of data.violations) {
                if (!violation || typeof violation !== 'object') {
                    continue;
                }

                const title = typeof violation.title === 'string'
                    ? violation.title
                    : (typeof violation.message === 'string' ? violation.message : '');
                const path = typeof violation.propertyPath === 'string' ? violation.propertyPath : '';

                if (title !== '') {
                    messages.push(path !== '' ? `${path} : ${title}` : title);
                }
            }
        }

        if (messages.length === 0 && typeof data.title === 'string' && data.title !== '') {
            messages.push(data.title);
        }
    }

    const uniqueMessages = [...new Set(messages)];

    return uniqueMessages.length > 0 ? uniqueMessages.join(' ') : fallback;
}

function showError(element, data, fallback) {
    if (element) {
        element.textContent = errorMessages(data, fallback);
    }
}

function formatDate(value) {
    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return value === undefined || value === null ? '' : String(value);
    }

    return date.toLocaleString('fr-FR');
}

async function apiFetch(path, options = {}, redirectOn401 = true) {
    const headers = new Headers(options.headers || {});
    headers.set('Accept', 'application/json');

    if (options.body !== undefined && options.body !== null && !headers.has('Content-Type')) {
        headers.set('Content-Type', 'application/json');
    }

    const response = await fetch(path, {
        ...options,
        headers,
        credentials: 'same-origin',
    });

    let data = null;
    const contentType = response.headers.get('content-type') || '';

    if (response.status !== 204 && contentType.includes('json')) {
        try {
            data = await response.json();
        } catch (error) {
            data = null;
        }
    } else if (response.status !== 204) {
        const text = await response.text().catch(() => '');
        data = text !== '' ? { error: text } : null;
    }

    if (response.status === 401 && redirectOn401) {
        window.location.assign('/login');
        throw new Error('Authentification requise.');
    }

    return { response, data };
}

function ticketFiltersElement() {
    return document.getElementById('tickets-error');
}

function buildTicketQuery() {
    const params = new URLSearchParams();
    const status = document.getElementById('filter-status');
    const priority = document.getElementById('filter-priority');
    const assignee = document.getElementById('filter-assignee');
    const createdFrom = document.getElementById('filter-created-from');
    const createdTo = document.getElementById('filter-created-to');

    if (status && status.value !== '') {
        params.set('status', status.value);
    }

    if (priority && priority.value !== '') {
        params.set('priority', priority.value);
    }

    if (assignee && assignee.value.trim() !== '') {
        params.set('assigneeId', assignee.value.trim());
    }

    if (createdFrom && createdFrom.value !== '') {
        const from = new Date(createdFrom.value);

        if (!Number.isNaN(from.getTime())) {
            params.set('createdFrom', from.toISOString());
        }
    }

    if (createdTo && createdTo.value !== '') {
        const to = new Date(createdTo.value);

        if (!Number.isNaN(to.getTime())) {
            params.set('createdTo', to.toISOString());
        }
    }

    return params;
}

function renderTicketRow(ticket) {
    const row = document.createElement('tr');

    appendCell(row, ticket.id);
    appendCell(row, ticket.title);
    appendCell(row, PRIORITY_LABELS[ticket.priority] || ticket.priority);
    appendCell(row, STATUS_LABELS[ticket.status] || ticket.status);
    appendCell(
        row,
        ticket.assignee ? `${ticket.assignee.name} (#${ticket.assignee.id})` : '—',
    );
    appendCell(row, formatDate(ticket.createdAt));

    const actions = createElement('td', 'row-actions');
    const statusSelect = document.createElement('select');
    const placeholder = document.createElement('option');
    placeholder.value = '';
    placeholder.textContent = 'Changer le statut…';
    statusSelect.appendChild(placeholder);

    for (const nextStatus of STATUS_TRANSITIONS[ticket.status] || []) {
        const option = document.createElement('option');
        option.value = nextStatus;
        option.textContent = STATUS_LABELS[nextStatus] || nextStatus;
        statusSelect.appendChild(option);
    }

    statusSelect.disabled = statusSelect.options.length <= 1;
    statusSelect.addEventListener('change', async () => {
        const targetStatus = statusSelect.value;

        if (targetStatus === '') {
            return;
        }

        statusSelect.disabled = true;
        clearError(ticketFiltersElement());

        const { response, data } = await apiFetch(
            `/api/tickets/${encodeURIComponent(ticket.id)}/status`,
            {
                method: 'PATCH',
                body: JSON.stringify({ status: targetStatus }),
            },
        );

        if (!response.ok) {
            showError(ticketFiltersElement(), data, `Erreur ${response.status}.`);
            statusSelect.value = '';
            statusSelect.disabled = false;

            return;
        }

        await loadTickets({ reset: true });
    });

    const assigneeInput = document.createElement('input');
    assigneeInput.type = 'number';
    assigneeInput.min = '1';
    assigneeInput.step = '1';
    assigneeInput.placeholder = 'Id utilisateur';
    assigneeInput.inputMode = 'numeric';

    const reassignButton = createElement('button', null, 'Réattribuer');
    reassignButton.type = 'button';
    reassignButton.addEventListener('click', async () => {
        const assigneeId = Number.parseInt(assigneeInput.value, 10);

        if (!Number.isInteger(assigneeId) || assigneeId < 1) {
            showError(ticketFiltersElement(), null, 'Identifiant utilisateur invalide.');

            return;
        }

        reassignButton.disabled = true;
        clearError(ticketFiltersElement());

        const { response, data } = await apiFetch(
            `/api/tickets/${encodeURIComponent(ticket.id)}/assignee`,
            {
                method: 'PATCH',
                body: JSON.stringify({ assigneeId }),
            },
        );

        if (!response.ok) {
            showError(ticketFiltersElement(), data, `Erreur ${response.status}.`);
            reassignButton.disabled = false;

            return;
        }

        await loadTickets({ reset: true });
    });

    actions.appendChild(statusSelect);
    actions.appendChild(assigneeInput);
    actions.appendChild(reassignButton);
    row.appendChild(actions);

    return row;
}

async function loadTickets({ reset = false } = {}) {
    const tbody = document.getElementById('tickets-body');

    if (!tbody) {
        return;
    }

    const errorElement = ticketFiltersElement();
    const nextButton = document.getElementById('tickets-next');
    clearError(errorElement);

    if (reset) {
        ticketsCursor = null;
        tbody.replaceChildren();
    }

    const params = buildTicketQuery();

    if (ticketsCursor !== null) {
        params.set('cursor', ticketsCursor);
    }

    const { response, data } = await apiFetch(`/api/tickets?${params.toString()}`);

    if (!response.ok) {
        showError(errorElement, data, `Erreur ${response.status}.`);

        return;
    }

    const items = data && Array.isArray(data.items) ? data.items : [];

    if (items.length > 0) {
        for (const emptyRow of tbody.querySelectorAll('.empty-row')) {
            emptyRow.remove();
        }
    }

    for (const ticket of items) {
        tbody.appendChild(renderTicketRow(ticket));
    }

    if (tbody.children.length === 0) {
        const emptyRow = document.createElement('tr');
        emptyRow.className = 'empty-row';
        const emptyCell = createElement('td', null, 'Aucun ticket.');
        emptyCell.colSpan = 7;
        emptyRow.appendChild(emptyCell);
        tbody.appendChild(emptyRow);
    }

    ticketsCursor = data && data.nextCursor ? data.nextCursor : null;

    if (nextButton) {
        nextButton.disabled = ticketsCursor === null;
    }
}

function renderOverdueRow(ticket) {
    const row = document.createElement('tr');

    appendCell(row, ticket.id);
    appendCell(row, ticket.title);
    appendCell(row, PRIORITY_LABELS[ticket.priority] || ticket.priority);
    appendCell(row, STATUS_LABELS[ticket.status] || ticket.status);
    appendCell(
        row,
        ticket.assignee ? `${ticket.assignee.name} (#${ticket.assignee.id})` : '—',
    );
    appendCell(row, formatDate(ticket.createdAt));

    return row;
}

async function loadOverdue(page) {
    const tbody = document.getElementById('overdue-body');

    if (!tbody) {
        return;
    }

    const errorElement = document.getElementById('overdue-error');
    const pageInput = document.getElementById('overdue-page');
    const limitInput = document.getElementById('overdue-limit');
    const previousButton = document.getElementById('overdue-prev');
    const nextButton = document.getElementById('overdue-next');
    const totalElement = document.getElementById('overdue-total');
    clearError(errorElement);

    let requestedPage = Number.parseInt(String(page), 10);

    if (!Number.isInteger(requestedPage) || requestedPage < 1) {
        requestedPage = Number.parseInt(pageInput ? pageInput.value : '1', 10);
    }

    if (!Number.isInteger(requestedPage) || requestedPage < 1) {
        requestedPage = 1;
    }

    let limit = Number.parseInt(limitInput ? limitInput.value : '50', 10);

    if (!Number.isInteger(limit) || limit < 1) {
        limit = 50;
    }

    limit = Math.min(limit, 100);

    if (pageInput) {
        pageInput.value = String(requestedPage);
    }

    if (limitInput) {
        limitInput.value = String(limit);
    }

    const params = new URLSearchParams({
        page: String(requestedPage),
        limit: String(limit),
    });

    const { response, data } = await apiFetch(`/api/tickets/overdue?${params.toString()}`);

    if (!response.ok) {
        showError(errorElement, data, `Erreur ${response.status}.`);

        return;
    }

    const items = data && Array.isArray(data.items) ? data.items : [];
    const total = data && Number.isFinite(Number(data.total)) ? Number(data.total) : 0;

    overduePage = data && Number.isFinite(Number(data.page)) ? Number(data.page) : requestedPage;

    if (pageInput) {
        pageInput.value = String(overduePage);
    }

    tbody.replaceChildren();

    for (const ticket of items) {
        tbody.appendChild(renderOverdueRow(ticket));
    }

    if (tbody.children.length === 0) {
        const emptyRow = document.createElement('tr');
        const emptyCell = createElement('td', null, 'Aucun ticket en retard.');
        emptyCell.colSpan = 6;
        emptyRow.appendChild(emptyCell);
        tbody.appendChild(emptyRow);
    }

    if (totalElement) {
        totalElement.textContent = `Total : ${total}`;
    }

    if (previousButton) {
        previousButton.disabled = overduePage <= 1;
    }

    if (nextButton) {
        nextButton.disabled = overduePage * limit >= total;
    }
}

async function loadDashboard() {
    const errorElement = document.getElementById('dashboard-error');
    const tbody = document.getElementById('dashboard-users');
    clearError(errorElement);

    const { response, data } = await apiFetch('/api/dashboard');

    if (!response.ok) {
        showError(errorElement, data, `Erreur ${response.status}.`);

        return;
    }

    const tickets = data && data.tickets && typeof data.tickets === 'object' ? data.tickets : {};

    setText('dashboard-total', tickets.total ?? 0);
    setText('dashboard-unassigned', tickets.unassigned ?? 0);
    setText('dashboard-overdue', tickets.overdue ?? 0);

    if (!tbody) {
        return;
    }

    const users = data && Array.isArray(data.users) ? data.users : [];
    tbody.replaceChildren();

    for (const user of users) {
        const row = document.createElement('tr');
        appendCell(row, user.name);
        appendCell(row, user.activeTickets);
        appendCell(row, user.currentLoad);
        appendCell(row, user.capacity);
        tbody.appendChild(row);
    }

    if (tbody.children.length === 0) {
        const emptyRow = document.createElement('tr');
        const emptyCell = createElement('td', null, 'Aucun utilisateur.');
        emptyCell.colSpan = 4;
        emptyRow.appendChild(emptyCell);
        tbody.appendChild(emptyRow);
    }
}

function initTabs() {
    const buttons = [...document.querySelectorAll('[data-tab]')];
    const panels = [...document.querySelectorAll('[data-tab-panel]')];

    const activate = (name) => {
        for (const button of buttons) {
            button.classList.toggle('active', button.dataset.tab === name);
        }

        for (const panel of panels) {
            panel.hidden = panel.dataset.tabPanel !== name;
        }

        if (name === 'overdue') {
            void loadOverdue(1);
        } else if (name === 'dashboard') {
            void loadDashboard();
        }
    };

    for (const button of buttons) {
        button.addEventListener('click', () => activate(button.dataset.tab));
    }

    return activate;
}

async function initHome() {
    const userElement = document.getElementById('current-user');

    if (!userElement) {
        return;
    }

    try {
        const { response, data } = await apiFetch('/api/me');

        if (!response.ok) {
            return;
        }

        userElement.textContent = data && data.name ? String(data.name) : '—';
    } catch (error) {
        return;
    }

    const logoutButton = document.getElementById('logout-button');

    if (logoutButton) {
        logoutButton.addEventListener('click', async () => {
            logoutButton.disabled = true;

            try {
                await apiFetch('/api/logout', { method: 'POST' }, false);
            } catch (error) {
                // La redirection vers la page de connexion est faite dans tous les cas.
            }

            window.location.assign('/login');
        });
    }

    const activateTab = initTabs();

    const filtersForm = document.getElementById('ticket-filters');
    const resetButton = document.getElementById('tickets-reset');
    const nextButton = document.getElementById('tickets-next');

    if (filtersForm) {
        filtersForm.addEventListener('submit', (event) => {
            event.preventDefault();
            void loadTickets({ reset: true });
        });
    }

    if (resetButton && filtersForm) {
        resetButton.addEventListener('click', () => {
            filtersForm.reset();
            void loadTickets({ reset: true });
        });
    }

    if (nextButton) {
        nextButton.addEventListener('click', () => {
            void loadTickets();
        });
    }

    const createForm = document.getElementById('ticket-create-form');

    if (createForm) {
        createForm.addEventListener('submit', async (event) => {
            event.preventDefault();

            const errorElement = document.getElementById('ticket-create-error');
            const infoElement = document.getElementById('ticket-create-info');
            const submitButton = createForm.querySelector('button[type="submit"]');
            clearError(errorElement);

            if (infoElement) {
                infoElement.textContent = '';
            }

            if (submitButton) {
                submitButton.disabled = true;
            }

            const payload = {
                title: String(createForm.elements.namedItem('title').value),
                description: String(createForm.elements.namedItem('description').value),
                priority: String(createForm.elements.namedItem('priority').value),
            };

            const { response, data } = await apiFetch('/api/tickets', {
                method: 'POST',
                body: JSON.stringify(payload),
            });

            if (submitButton) {
                submitButton.disabled = false;
            }

            if (!response.ok) {
                showError(errorElement, data, `Erreur ${response.status}.`);

                return;
            }

            createForm.reset();

            if (infoElement) {
                infoElement.textContent = 'Ticket créé.';
            }

            await loadTickets({ reset: true });
        });
    }

    const overdueForm = document.getElementById('overdue-form');
    const overduePrevious = document.getElementById('overdue-prev');
    const overdueNext = document.getElementById('overdue-next');

    if (overdueForm) {
        overdueForm.addEventListener('submit', (event) => {
            event.preventDefault();
            const pageInput = document.getElementById('overdue-page');
            void loadOverdue(Number.parseInt(pageInput ? pageInput.value : '1', 10));
        });
    }

    if (overduePrevious) {
        overduePrevious.addEventListener('click', () => {
            void loadOverdue(Math.max(1, overduePage - 1));
        });
    }

    if (overdueNext) {
        overdueNext.addEventListener('click', () => {
            void loadOverdue(overduePage + 1);
        });
    }

    activateTab('tickets');
    await loadTickets({ reset: true });
}

function initLogin() {
    const form = document.getElementById('login-form');

    if (!form) {
        return;
    }

    form.addEventListener('submit', async (event) => {
        event.preventDefault();

        const errorElement = document.getElementById('login-error');
        const submitButton = form.querySelector('button[type="submit"]');
        clearError(errorElement);

        if (submitButton) {
            submitButton.disabled = true;
        }

        const payload = {
            email: String(form.elements.namedItem('email').value),
            password: String(form.elements.namedItem('password').value),
        };

        const { response, data } = await apiFetch(
            '/api/login',
            {
                method: 'POST',
                body: JSON.stringify(payload),
            },
            false,
        );

        if (submitButton) {
            submitButton.disabled = false;
        }

        if (response.ok) {
            window.location.assign('/');

            return;
        }

        showError(errorElement, data, 'Échec de la connexion.');
    });
}

function init() {
    if (document.getElementById('login-form')) {
        initLogin();

        return;
    }

    if (document.getElementById('current-user')) {
        void initHome();
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}
