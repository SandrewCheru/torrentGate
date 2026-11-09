'use strict';

(() => {
    const API = '/index.php?action=';
    const MAX_FILE_BYTES = 5 * 1024 * 1024;

    const elements = {
        connection: document.getElementById('connection-status'),
        dropZone: document.getElementById('drop-zone'),
        browse: document.getElementById('browse-button'),
        fileInput: document.getElementById('file-input'),
        progress: document.getElementById('upload-progress'),
        progressText: document.getElementById('upload-progress-text'),
        globalError: document.getElementById('global-error'),
        list: document.getElementById('torrent-list'),
        empty: document.getElementById('empty-state'),
        queueDescription: document.getElementById('queue-description'),
        selectAll: document.getElementById('select-all-button'),
        deselectAll: document.getElementById('deselect-all-button'),
        clear: document.getElementById('clear-button'),
        uploaded: document.getElementById('summary-uploaded'),
        valid: document.getElementById('summary-valid'),
        invalid: document.getElementById('summary-invalid'),
        duplicates: document.getElementById('summary-duplicates'),
        selectedCount: document.getElementById('summary-selected-count'),
        selectedSize: document.getElementById('summary-selected-size'),
        uniqueSize: document.getElementById('summary-unique-size'),
        destinationForm: document.getElementById('destination-form'),
        destinationInput: document.getElementById('destination-input'),
        destinationRoot: document.getElementById('destination-root'),
        destinationPreview: document.getElementById('destination-preview'),
        destinationSave: document.getElementById('destination-save'),
        destinationStatus: document.getElementById('destination-status')
    };

    let csrfToken = null;
    let uploads = [];
    let summary = null;
    let busy = false;
    let destination = {
        root: '',
        relative_path: '',
        absolute_path: ''
    };

    function formatBytes(value) {
        if (!Number.isFinite(value) || value < 0) {
            return '—';
        }

        if (value === 0) {
            return '0 B';
        }

        const units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
        let amount = value;
        let unit = 0;

        while (amount >= 1024 && unit < units.length - 1) {
            amount /= 1024;
            unit++;
        }

        const digits = unit === 0 ? 0 : 2;
        return `${amount.toFixed(digits)} ${units[unit]}`;
    }

    function element(tag, className, text) {
        const node = document.createElement(tag);

        if (className) {
            node.className = className;
        }

        if (text !== undefined && text !== null) {
            node.textContent = String(text);
        }

        return node;
    }

    function setBusy(value) {
        busy = value;

        for (const button of [
            elements.browse,
            elements.selectAll,
            elements.deselectAll,
            elements.clear
        ]) {
            button.disabled = value;
        }

        elements.fileInput.disabled = value;
        elements.dropZone.setAttribute('aria-busy', value ? 'true' : 'false');
    }

    function showError(message) {
        elements.globalError.textContent = message;
        elements.globalError.hidden = false;
    }

    function clearError() {
        elements.globalError.textContent = '';
        elements.globalError.hidden = true;
    }

    async function request(action, options = {}) {
        const response = await fetch(API + encodeURIComponent(action), {
            credentials: 'same-origin',
            cache: 'no-store',
            ...options
        });

        let body;

        try {
            body = await response.json();
        } catch {
            throw new Error(`The server returned an unreadable response (${response.status}).`);
        }

        if (!response.ok || body.ok !== true) {
            throw new Error(
                typeof body.error === 'string'
                    ? body.error
                    : `The request failed (${response.status}).`
            );
        }

        return body;
    }

    async function refreshState() {
        const body = await request('state');

        csrfToken = body.csrf_token;
        uploads = Array.isArray(body.uploads) ? body.uploads : [];
        summary = body.summary;

        if (body.destination && typeof body.destination === 'object') {
            destination = body.destination;
            elements.destinationRoot.textContent = destination.root;
            elements.destinationInput.value = destination.relative_path;
            updateDestinationPreview();
        }

        render();
        elements.connection.textContent = 'Connected';
        elements.connection.classList.remove('error');
    }

    function updateDestinationPreview() {
        const relativePath = elements.destinationInput.value;
        const root = destination.root;

        elements.destinationPreview.textContent = relativePath === ''
            ? root
            : `${root}/${relativePath}`;
    }

    function setDestinationStatus(message, type = '') {
        elements.destinationStatus.textContent = message;
        elements.destinationStatus.className =
            `destination-status${type ? ` ${type}` : ''}`;
    }

    async function saveDestination(event) {
        event.preventDefault();

        if (busy) {
            return;
        }

        const relativePath = elements.destinationInput.value;

        elements.destinationSave.disabled = true;
        setDestinationStatus('Saving…');
        clearError();

        try {
            const body = await request('destination', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-TorrentGate-CSRF': csrfToken
                },
                body: JSON.stringify({
                    relative_path: relativePath
                })
            });

            destination = body.destination;
            elements.destinationRoot.textContent = destination.root;
            elements.destinationInput.value = destination.relative_path;
            updateDestinationPreview();

            setDestinationStatus('Destination saved.', 'success');
        } catch (error) {
            setDestinationStatus(error.message, 'error');
        } finally {
            elements.destinationSave.disabled = false;
        }
    }

    function renderSummary() {
        if (!summary) {
            return;
        }

        elements.uploaded.textContent = summary.uploaded;
        elements.valid.textContent = summary.valid;
        elements.invalid.textContent = summary.invalid;
        elements.duplicates.textContent = summary.duplicate;
        elements.selectedCount.textContent = summary.selected_count;
        elements.selectedSize.textContent = formatBytes(summary.selected_size);
        elements.uniqueSize.textContent = formatBytes(summary.unique_valid_size);

        const count = summary.uploaded;
        elements.queueDescription.textContent = count === 0
            ? 'No files have been added.'
            : `${count} upload${count === 1 ? '' : 's'} in this session · ` +
              `${formatBytes(summary.uploaded_bytes)} received`;
    }

    function makeBadge(label, type = '') {
        return element('span', `badge${type ? ` badge-${type}` : ''}`, label);
    }

    function renderFileDetails(metadata) {
        const details = element('div', 'file-details');
        const heading = element('h3', '', `Files (${metadata.file_count})`);
        details.append(heading);

        const list = element('ul', 'file-list');

        for (const file of metadata.files || []) {
            const item = element('li');
            item.append(document.createTextNode(file.path || '(unnamed file)'));

            const size = element('span', 'file-size', ` · ${formatBytes(file.size)}`);
            item.append(size);
            list.append(item);
        }

        details.append(list);

        const hashes = element('div', 'hash-list');
        hashes.append(element('div', '', `Format: ${metadata.protocol || 'unknown'}`));

        if (metadata.info_hash_v1) {
            const row = element('div');
            row.append(document.createTextNode('SHA-1 info hash: '));
            row.append(element('code', '', metadata.info_hash_v1));
            hashes.append(row);
        }

        if (metadata.info_hash_v2) {
            const row = element('div');
            row.append(document.createTextNode('SHA-256 info hash: '));
            row.append(element('code', '', metadata.info_hash_v2));
            hashes.append(row);
        }

        details.append(hashes);
        return details;
    }

    function renderUpload(upload) {
        const card = element('article', 'torrent-card');
        const main = element('div', 'torrent-main');

        const checkbox = document.createElement('input');
        checkbox.type = 'checkbox';
        checkbox.className = 'torrent-select';
        checkbox.checked = upload.selected === true;
        checkbox.disabled = busy ||
            upload.status !== 'valid' ||
            upload.duplicate === true;
        checkbox.setAttribute('aria-label', `Select ${upload.original_name}`);

        checkbox.addEventListener('change', async () => {
            await changeSelection(upload.id, checkbox.checked);
        });

        main.append(checkbox);

        const information = element('div');
        information.append(element('h3', 'torrent-title', upload.original_name));

        if (upload.status === 'valid' && upload.metadata) {
            const metadata = upload.metadata;
            const subtitle = element('div', 'torrent-subtitle');

            subtitle.append(element('span', '', formatBytes(metadata.total_size)));
            subtitle.append(element('span', '', `${metadata.file_count} file${metadata.file_count === 1 ? '' : 's'}`));
            subtitle.append(element('span', '', (metadata.protocol || 'unknown').toUpperCase()));

            information.append(subtitle);
        } else {
            information.append(
                element('div', 'torrent-subtitle', `Uploaded size: ${formatBytes(upload.input_size)}`)
            );
        }

        main.append(information);

        const badges = element('div', 'badges');

        if (upload.status === 'invalid') {
            badges.append(makeBadge('Invalid', 'danger'));
        } else if (upload.duplicate) {
            badges.append(makeBadge('Duplicate', 'warning'));
        } else {
            badges.append(makeBadge('Valid', 'success'));
        }

        if (upload.status === 'valid' && upload.selected && !upload.duplicate) {
            badges.append(makeBadge('Selected'));
        }

        main.append(badges);
        card.append(main);

        const actions = element('div', 'torrent-actions');

        if (upload.status === 'valid' && upload.metadata) {
            const detailsButton = element('button', 'text-button', 'Show file list and hashes');
            detailsButton.type = 'button';
            detailsButton.setAttribute('aria-expanded', 'false');

            let details = null;

            detailsButton.addEventListener('click', () => {
                if (details === null) {
                    details = renderFileDetails(upload.metadata);
                    card.append(details);
                } else {
                    details.hidden = !details.hidden;
                }

                const expanded = !details.hidden;
                detailsButton.textContent = expanded
                    ? 'Hide file list and hashes'
                    : 'Show file list and hashes';
                detailsButton.setAttribute('aria-expanded', expanded ? 'true' : 'false');
            });

            actions.append(detailsButton);
            card.append(actions);
        } else {
            card.append(actions);
        }

        const removeButton = element('button', 'text-button', 'Remove');
        removeButton.type = 'button';
        removeButton.disabled = busy;
        removeButton.addEventListener('click', () => removeUpload(upload.id));
        actions.append(removeButton);

        if (upload.status === 'invalid' && upload.error) {
            card.append(element('p', 'torrent-error', upload.error));
        } else if (upload.duplicate) {
            card.append(
                element(
                    'p',
                    'torrent-error',
                    'This torrent duplicates an earlier upload in the current session and cannot be selected.'
                )
            );
        }

        return card;
    }

    function render() {
        renderSummary();

        elements.list.replaceChildren();
        elements.empty.hidden = uploads.length !== 0;

        for (const upload of uploads) {
            elements.list.append(renderUpload(upload));
        }

        const selectable = uploads.some(
            upload => upload.status === 'valid' && !upload.duplicate
        );

        elements.selectAll.disabled = busy || !selectable;
        elements.deselectAll.disabled = busy || !selectable;
        elements.clear.disabled = busy || uploads.length === 0;
    }

    async function changeSelection(id, selected) {
        if (busy) {
            return;
        }

        setBusy(true);
        clearError();

        try {
            await request('select', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-TorrentGate-CSRF': csrfToken
                },
                body: JSON.stringify({ id, selected })
            });

            await refreshState();
        } catch (error) {
            showError(error.message);
            await refreshState().catch(() => {});
        } finally {
            setBusy(false);
            render();
        }
    }

    async function removeUpload(id) {
        if (busy) {
            return;
        }

        setBusy(true);
        clearError();

        try {
            await request('remove', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-TorrentGate-CSRF': csrfToken
                },
                body: JSON.stringify({ id })
            });

            await refreshState();
        } catch (error) {
            showError(error.message);
        } finally {
            setBusy(false);
            render();
        }
    }

    async function setAllSelected(selected) {
        if (busy) {
            return;
        }

        const targets = uploads.filter(
            upload => upload.status === 'valid' &&
                !upload.duplicate &&
                upload.selected !== selected
        );

        if (targets.length === 0) {
            return;
        }

        setBusy(true);
        clearError();

        try {
            for (const upload of targets) {
                await request('select', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-TorrentGate-CSRF': csrfToken
                    },
                    body: JSON.stringify({
                        id: upload.id,
                        selected
                    })
                });
            }

            await refreshState();
        } catch (error) {
            showError(error.message);
            await refreshState().catch(() => {});
        } finally {
            setBusy(false);
            render();
        }
    }

    async function clearQueue() {
        if (busy || uploads.length === 0) {
            return;
        }

        if (!window.confirm('Remove all torrents from this upload session?')) {
            return;
        }

        setBusy(true);
        clearError();

        try {
            await request('clear', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-TorrentGate-CSRF': csrfToken
                },
                body: '{}'
            });

            await refreshState();
        } catch (error) {
            showError(error.message);
        } finally {
            setBusy(false);
            render();
        }
    }

    async function uploadFiles(fileList) {
        if (busy || !fileList || fileList.length === 0) {
            return;
        }

        const files = Array.from(fileList);
        const form = new FormData();
        const rejected = [];

        for (const file of files) {
            if (!file.name.toLowerCase().endsWith('.torrent')) {
                rejected.push(`${file.name}: filename must end in .torrent`);
                continue;
            }

            if (file.size > MAX_FILE_BYTES) {
                rejected.push(`${file.name}: exceeds the 5 MiB file limit`);
                continue;
            }

            form.append('files[]', file, file.name);
        }

        if (rejected.length > 0) {
            showError(rejected.join('\n'));
        } else {
            clearError();
        }

        if (!form.has('files[]')) {
            return;
        }

        setBusy(true);
        elements.progress.hidden = false;
        elements.progressText.textContent = `Uploading ${form.getAll('files[]').length} file(s)…`;

        try {
            await request('upload', {
                method: 'POST',
                headers: {
                    'X-TorrentGate-CSRF': csrfToken
                },
                body: form
            });

            await refreshState();
        } catch (error) {
            showError(error.message);
        } finally {
            elements.progress.hidden = true;
            elements.fileInput.value = '';
            setBusy(false);
            render();
        }
    }

    elements.browse.addEventListener('click', () => {
        elements.fileInput.click();
    });

    elements.fileInput.addEventListener('change', () => {
        uploadFiles(elements.fileInput.files);
    });

    elements.dropZone.addEventListener('click', event => {
        if (event.target === elements.dropZone ||
            event.target.closest('.drop-zone-content')) {
            elements.fileInput.click();
        }
    });

    elements.dropZone.addEventListener('keydown', event => {
        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            elements.fileInput.click();
        }
    });

    for (const eventName of ['dragenter', 'dragover']) {
        elements.dropZone.addEventListener(eventName, event => {
            event.preventDefault();
            elements.dropZone.classList.add('drag-over');
        });
    }

    for (const eventName of ['dragleave', 'drop']) {
        elements.dropZone.addEventListener(eventName, event => {
            event.preventDefault();
            elements.dropZone.classList.remove('drag-over');
        });
    }

    elements.dropZone.addEventListener('drop', event => {
        uploadFiles(event.dataTransfer.files);
    });

    elements.selectAll.addEventListener('click', () => setAllSelected(true));
    elements.deselectAll.addEventListener('click', () => setAllSelected(false));
    elements.clear.addEventListener('click', clearQueue);

    elements.destinationInput.addEventListener(
        'input',
        updateDestinationPreview
    );
    elements.destinationForm.addEventListener('submit', saveDestination);

    refreshState().catch(error => {
        elements.connection.textContent = 'Connection error';
        elements.connection.classList.add('error');
        showError(error.message);
    });
})();
