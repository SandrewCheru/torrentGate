<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark light">
    <title>TorrentGate</title>
    <link rel="stylesheet" href="/assets/app.css">
    <script src="/assets/app.js" defer></script>
</head>
<body>
    <header class="site-header">
        <div class="container header-inner">
            <div>
                <h1>TorrentGate</h1>
                <p class="tagline">Prepare and submit torrents to Transmission</p>
            </div>
            <span class="connection-status" id="connection-status">
                Connecting…
            </span>
        </div>
    </header>

    <main class="container">
        <section class="panel upload-panel" aria-labelledby="upload-heading">
            <div class="section-heading">
                <div>
                    <h2 id="upload-heading">Add torrent files</h2>
                    <p>Select one or more .torrent files to inspect.</p>
                </div>
            </div>

            <div class="drop-zone" id="drop-zone" tabindex="0">
                <div class="drop-zone-content">
                    <div class="drop-icon" aria-hidden="true">⇧</div>
                    <p class="drop-title">Drop torrent files here</p>
                    <p class="muted">or</p>
                    <button type="button" class="button button-primary" id="browse-button">
                        Choose files
                    </button>
                    <p class="help-text">
                        .torrent files only · 5 MiB per file · 25 MiB per session
                    </p>
                </div>
                <input
                    type="file"
                    id="file-input"
                    accept=".torrent,application/x-bittorrent"
                    multiple
                    hidden
                >
            </div>

            <div class="upload-progress" id="upload-progress" hidden>
                <span id="upload-progress-text">Uploading…</span>
            </div>

            <div class="notice notice-error" id="global-error" role="alert" hidden></div>
        </section>

        <section class="summary-grid" aria-label="Upload summary">
            <article class="summary-card">
                <span class="summary-label">Uploaded</span>
                <strong id="summary-uploaded">0</strong>
            </article>
            <article class="summary-card">
                <span class="summary-label">Valid</span>
                <strong id="summary-valid">0</strong>
            </article>
            <article class="summary-card">
                <span class="summary-label">Invalid</span>
                <strong id="summary-invalid">0</strong>
            </article>
            <article class="summary-card">
                <span class="summary-label">Duplicates</span>
                <strong id="summary-duplicates">0</strong>
            </article>
        </section>

        <section class="panel uploads-panel" aria-labelledby="uploads-heading">
            <div class="section-heading uploads-heading">
                <div>
                    <h2 id="uploads-heading">Torrent queue</h2>
                    <p id="queue-description">No files have been added.</p>
                </div>
                <div class="toolbar">
                    <button type="button" class="button button-secondary" id="select-all-button">
                        Select all
                    </button>
                    <button type="button" class="button button-secondary" id="deselect-all-button">
                        Deselect all
                    </button>
                    <button type="button" class="button button-danger" id="clear-button">
                        Clear queue
                    </button>
                </div>
            </div>

            <div id="empty-state" class="empty-state">
                Your torrent queue is empty.
            </div>

            <div id="torrent-list" class="torrent-list"></div>
        </section>

        <section class="panel destination-panel" aria-labelledby="destination-heading">
            <div class="section-heading">
                <div>
                    <h2 id="destination-heading">Download destination</h2>
                    <p>Choose where Transmission should store the selected torrents.</p>
                </div>
            </div>

            <div class="destination-root">
                <span class="summary-label">Fixed destination root</span>
                <code id="destination-root">Loading…</code>
            </div>

            <form id="destination-form" class="destination-form">
                <label for="destination-input">Optional subdirectory</label>
                <div class="destination-input-row">
                    <input
                        type="text"
                        id="destination-input"
                        name="relative_path"
                        maxlength="1024"
                        autocomplete="off"
                        spellcheck="false"
                        placeholder="e.g. Music/Live Concerts"
                        aria-describedby="destination-help destination-preview"
                    >
                    <button type="submit" class="button button-primary" id="destination-save">
                        Save destination
                    </button>
                </div>
                <p class="help-text" id="destination-help">
                    Leave empty to use the fixed root. Use forward slashes to separate folders.
                    Absolute paths and parent-directory references are not allowed.
                </p>
                <div class="destination-preview">
                    <span class="summary-label">Resulting destination</span>
                    <code id="destination-preview">Loading…</code>
                </div>
                <p class="destination-status" id="destination-status" role="status" aria-live="polite"></p>
            </form>
        </section>

        <section class="panel selection-panel" aria-labelledby="selection-heading">
            <div class="section-heading">
                <div>
                    <h2 id="selection-heading">Selection</h2>
                    <p>Unique valid torrents selected for submission.</p>
                </div>
            </div>

            <div class="selection-summary">
                <div>
                    <span class="summary-label">Selected torrents</span>
                    <strong id="summary-selected-count">0</strong>
                </div>
                <div>
                    <span class="summary-label">Selected download size</span>
                    <strong id="summary-selected-size">0 B</strong>
                </div>
                <div>
                    <span class="summary-label">Total unique valid size</span>
                    <strong id="summary-unique-size">0 B</strong>
                </div>
            </div>


        </section>
    </main>

    <footer class="site-footer">
        <div class="container">
            TorrentGate · Torrent metadata is inspected locally by this application.
            Transmission handles all downloads.
        </div>
    </footer>
</body>
</html>
