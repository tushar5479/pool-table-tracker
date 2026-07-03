(function () {
  const app = document.querySelector("[data-ptt-app]");
  if (!app || !window.PoolTableTracker) return;

  const money = new Intl.NumberFormat("en-US", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const state = {
    tables: [],
    history: [],
    hourlyRate: Number(PoolTableTracker.hourlyRate || 5),
    serverNowTs: Math.floor(Date.now() / 1000),
    receivedAtMs: Date.now(),
    tick: null,
    dialogMode: "start",
  };

  const els = {
    grid: app.querySelector("[data-ptt-table-grid]"),
    activeCount: app.querySelector("[data-ptt-active-count]"),
    liveTotal: app.querySelector("[data-ptt-live-total]"),
    historyQuery: app.querySelector("[data-ptt-history-query]"),
    historySearch: app.querySelector("[data-ptt-history-search]"),
    historyList: app.querySelector("[data-ptt-history-list]"),
    rate: app.querySelector("[data-ptt-rate]"),
    saveRate: app.querySelector("[data-ptt-save-rate]"),
    addTable: app.querySelector("[data-ptt-add-table]"),
    settingsTables: app.querySelector("[data-ptt-settings-tables]"),
    dialog: app.querySelector("[data-ptt-dialog]"),
    dialogTitle: app.querySelector("[data-ptt-dialog-title]"),
    form: app.querySelector("[data-ptt-guest-form]"),
    tableId: app.querySelector("[data-ptt-dialog-table-id]"),
    guestName: app.querySelector("[data-ptt-guest-name]"),
    cancel: app.querySelector("[data-ptt-cancel]"),
    swapDialog: app.querySelector("[data-ptt-swap-dialog]"),
    swapForm: app.querySelector("[data-ptt-swap-form]"),
    swapSource: app.querySelector("[data-ptt-swap-source]"),
    swapTarget: app.querySelector("[data-ptt-swap-target]"),
    swapCancel: app.querySelector("[data-ptt-swap-cancel]"),
    playerTransferDialog: app.querySelector("[data-ptt-player-transfer-dialog]"),
    playerTransferForm: app.querySelector("[data-ptt-player-transfer-form]"),
    playerTransferGuest: app.querySelector("[data-ptt-player-transfer-guest]"),
    playerTransferTarget: app.querySelector("[data-ptt-player-transfer-target]"),
    playerTransferCancel: app.querySelector("[data-ptt-player-transfer-cancel]"),
    fullscreen: app.querySelector("[data-ptt-fullscreen]"),
  };

  function request(action, data = {}) {
    const form = new FormData();
    form.append("action", action);
    form.append("nonce", PoolTableTracker.nonce);
    Object.entries(data).forEach(([key, value]) => form.append(key, value));

    return fetch(PoolTableTracker.ajaxUrl, {
      method: "POST",
      credentials: "same-origin",
      body: form,
    })
      .then((response) => response.json())
      .then((payload) => {
        if (!payload.success) {
          throw new Error(payload.data && payload.data.message ? payload.data.message : "Request failed.");
        }
        return payload.data;
      });
  }

  function parseMysqlDate(value) {
    return new Date(String(value).replace(" ", "T"));
  }

  function liveServerNowTs() {
    return state.serverNowTs + Math.floor((Date.now() - state.receivedAtMs) / 1000);
  }

  function elapsedSeconds(guest) {
    const start = Number(guest.startedAtTs || 0);
    const end = guest.endedAtTs ? Number(guest.endedAtTs) : liveServerNowTs();
    if (!start) return 0;
    return Math.max(0, end - start);
  }

  function chargeFor(guest) {
    if (guest.isExcluded) return 0;
    return (elapsedSeconds(guest) / 3600) * state.hourlyRate;
  }

  function formatDuration(totalSeconds) {
    const hours = Math.floor(totalSeconds / 3600);
    const minutes = Math.floor((totalSeconds % 3600) / 60);
    const seconds = totalSeconds % 60;
    return [hours, minutes, seconds].map((part) => String(part).padStart(2, "0")).join(":");
  }

  function formatTime(value) {
    if (!value) return "now";
    return parseMysqlDate(value).toLocaleString([], {
      month: "short",
      day: "numeric",
      hour: "numeric",
      minute: "2-digit",
    });
  }

  function formatAuditTime(value) {
    if (!value) return "";
    const date = parseMysqlDate(value);
    const parts = new Intl.DateTimeFormat("en-GB", { day: "numeric", month: "short", year: "numeric" })
      .formatToParts(date)
      .reduce((carry, part) => {
        carry[part.type] = part.value;
        return carry;
      }, {});
    const day = `${parts.day} ${parts.month} ${parts.year}`;
    const time = date.toLocaleTimeString([], {
      hour: "numeric",
      minute: "2-digit",
    });
    return `${day} at ${time}`;
  }

  function setState(data) {
    state.tables = data.tables || [];
    state.history = data.history || state.history || [];
    state.hourlyRate = Number(data.hourlyRate || state.hourlyRate);
    state.serverNowTs = Number(data.serverNowTs || state.serverNowTs);
    state.receivedAtMs = Date.now();
    if (els.rate) els.rate.value = state.hourlyRate.toFixed(2);
    render();
  }

  function tableTotal(table) {
    return table.activeGuests.reduce((sum, guest) => sum + chargeFor(guest), 0);
  }

  function guestCounts(table) {
    const guests = table.activeGuests || [];
    return {
      active: guests.filter((guest) => guest.status === "active").length,
      stopped: guests.filter((guest) => guest.status === "stopped").length,
      total: guests.length,
    };
  }

  function render() {
    renderDashboard();
    renderHistory();
    renderSettings();
    renderStats();
  }

  function isFullscreen() {
    return document.fullscreenElement === app || app.classList.contains("is-fullscreen");
  }

  function syncFullscreenState() {
    app.classList.toggle("is-fullscreen", document.fullscreenElement === app);
    updateFullscreenButton();
  }

  function updateFullscreenButton() {
    if (!els.fullscreen) return;
    const active = isFullscreen();
    els.fullscreen.setAttribute("aria-label", active ? "Exit fullscreen" : "Enter fullscreen");
    els.fullscreen.setAttribute("title", active ? "Exit fullscreen" : "Fullscreen");
    els.fullscreen.innerHTML = active
      ? '<i class="fas fa-compress" aria-hidden="true"></i>'
      : '<i class="fas fa-expand" aria-hidden="true"></i>';
  }

  function toggleFullscreen() {
    if (document.fullscreenElement) {
      document.exitFullscreen();
      return;
    }

    if (app.requestFullscreen) {
      app.requestFullscreen().catch(syncFullscreenState);
      return;
    }

    app.classList.toggle("is-fullscreen");
    updateFullscreenButton();
  }

  function enableDefaultFullscreenStyle() {
    if (!PoolTableTracker.autoFullscreen) return;
    app.classList.add("is-fullscreen");
  }

  function renderStats() {
    const activeTables = state.tables.filter((table) => table.isOpen || table.activeGuests.length > 0).length;
    const total = state.tables.reduce((sum, table) => sum + tableTotal(table), 0);
    els.activeCount.textContent = activeTables;
    els.liveTotal.textContent = money.format(total);
  }

  function renderDashboard() {
    els.grid.innerHTML = state.tables
      .map((table) => {
        const excluded = Boolean(table.isExcluded);
        const active = !excluded && (table.isOpen || table.activeGuests.length > 0);
        const counts = guestCounts(table);
        let status = "Available";
        if (excluded) {
          status = "Excluded";
        } else if (counts.active && counts.stopped) {
          status = `${counts.active} active, ${counts.stopped} stopped`;
        } else if (counts.active) {
          status = `${counts.active} active ${counts.active === 1 ? "guest" : "guests"}`;
        } else if (counts.stopped) {
          status = `${counts.stopped} stopped ${counts.stopped === 1 ? "guest" : "guests"}`;
        } else if (table.isOpen) {
          status = "Open table";
        }
        const guests = table.activeGuests.map(renderGuest).join("");
        return `
          <article class="ptt-table ${active ? "is-active" : ""} ${excluded ? "is-excluded" : ""}">
            <div class="ptt-table-head">
              <div>
                <h2><i class="fas fa-border-all" aria-hidden="true"></i> ${escapeHtml(table.label)}</h2>
                <p>${status}</p>
              </div>
              <strong>${active ? "$" + money.format(tableTotal(table)) : excluded ? "Excluded" : "-"}</strong>
            </div>
            <div class="ptt-guests">${guests}</div>
            <div class="ptt-actions">
              ${
                excluded
                  ? `<button type="button" class="ptt-secondary ptt-full" disabled><i class="fas fa-ban" aria-hidden="true"></i> Excluded</button>`
                  : active
                  ? `<button type="button" class="ptt-secondary" data-add-guest="${table.id}"><i class="fas fa-user-plus" aria-hidden="true"></i>Add</button>
                     <button type="button" class="ptt-secondary" data-swap-table="${table.id}"><i class="fas fa-exchange-alt" aria-hidden="true"></i> Transfer</button>
                     <button type="button" class="ptt-primary" data-close-table="${table.id}">Close Table</button>`
                  : `<button type="button" class="ptt-primary ptt-full" data-start-table="${table.id}"><i class="fas fa-plus" aria-hidden="true"></i> Start Table</button>`
              }
            </div>
          </article>
        `;
      })
      .join("");
  }

  function renderGuest(guest) {
    const elapsed = elapsedSeconds(guest);
    const stopped = guest.status === "stopped";
    const excluded = Boolean(guest.isExcluded);
    return `
      <div class="ptt-guest ${stopped ? "is-stopped" : ""} ${excluded ? "is-excluded" : ""}">
        <div>
          <strong><i class="fas fa-user" aria-hidden="true"></i> ${escapeHtml(guest.guestName)}${excluded ? ' <span class="ptt-mini-badge">Excluded</span>' : ""}</strong>
          <span><i class="fas fa-clock" aria-hidden="true"></i> ${formatTime(guest.startedAt)} - ${stopped ? formatTime(guest.endedAt) : "now"}</span>
          <span><i class="fas fa-stopwatch" aria-hidden="true"></i> ${formatDuration(elapsed)}</span>
        </div>
        <b>$${money.format(chargeFor(guest))}</b>
        <button type="button" class="ptt-exclude-toggle" data-toggle-exclude="${guest.id}">
          <i class="fas ${excluded ? "fa-user-check" : "fa-user-slash"}" aria-hidden="true"></i> ${excluded ? "Include" : "Exclude"}
        </button>
        <button type="button" class="ptt-secondary ptt-player-transfer" data-transfer-player="${guest.id}">
          <i class="fas fa-exchange-alt" aria-hidden="true"></i> Transfer
        </button>
        ${
          stopped
            ? '<span class="ptt-ended-badge"><i class="fas fa-check-circle" aria-hidden="true"></i> Stopped</span>'
            : `<button type="button" data-end-guest="${guest.id}"><i class="fas fa-stop-circle" aria-hidden="true"></i> End</button>`
        }
      </div>
    `;
  }

  function renderHistory() {
    if (!state.history.length) {
      els.historyList.innerHTML = '<div class="ptt-empty">No completed sessions found.</div>';
      return;
    }

    els.historyList.innerHTML = state.history
      .map((guest) => {
        if (guest.type === "audit") return renderAuditHistory(guest);
        return `
          <div class="ptt-history-row">
            <div>
              <strong><i class="fas fa-user" aria-hidden="true"></i> ${escapeHtml(guest.guestName)}</strong>
              <span><i class="fas fa-border-all" aria-hidden="true"></i> Table ${guest.tableNumber}</span>
            </div>
            <div>
              <span><i class="fas fa-play-circle" aria-hidden="true"></i> ${formatTime(guest.startedAt)}</span>
              <span><i class="fas fa-stop-circle" aria-hidden="true"></i> ${formatTime(guest.endedAt)}</span>
            </div>
            <div><i class="fas fa-stopwatch" aria-hidden="true"></i> ${formatDuration(Number(guest.elapsed || 0))}</div>
            <b><i class="fas fa-dollar-sign" aria-hidden="true"></i> ${money.format(Number(guest.chargedAmount || 0))}</b>
          </div>
        `;
      })
      .join("");
  }

  function renderAuditHistory(item) {
    const tableLabel = item.tableLabel || `Table ${item.tableNumber}`;
    const sourceLabel = item.sourceTableLabel || `Table ${item.sourceTableNumber}`;
    const destinationLabel = item.destinationTableLabel || `Table ${item.destinationTableNumber}`;
    const userName = item.userName || "Admin";
    const createdAt = formatAuditTime(item.createdAt);
    const reason = item.reason ? ` (Reason: ${escapeHtml(item.reason)})` : "";
    const isPlayerTransfer = item.action === "player_transferred";
    const isTableTransfer = item.action === "table_transferred";
    const title = isPlayerTransfer ? "Player Transferred" : isTableTransfer ? "Table Transferred" : "Table Excluded";
    const icon = isPlayerTransfer || isTableTransfer ? "fa-exchange-alt" : "fa-clipboard-list";
    const summary = isPlayerTransfer
      ? `${escapeHtml(userName)} transferred ${escapeHtml(item.guestName || "Player")} from ${escapeHtml(sourceLabel)} to ${escapeHtml(destinationLabel)} on ${escapeHtml(createdAt)}.`
      : isTableTransfer
        ? `${escapeHtml(userName)} transferred ${escapeHtml(sourceLabel)} session to ${escapeHtml(destinationLabel)} on ${escapeHtml(createdAt)}.`
        : `${escapeHtml(userName)} excluded ${escapeHtml(tableLabel)} on ${escapeHtml(createdAt)}${reason}.`;
    return `
      <div class="ptt-history-row ptt-audit-row">
        <div>
          <strong><i class="fas ${icon}" aria-hidden="true"></i> ${title}</strong>
          <span>${summary}</span>
        </div>
        <div>
          <span><i class="fas fa-border-all" aria-hidden="true"></i> ${isPlayerTransfer || isTableTransfer ? `${escapeHtml(sourceLabel)} -> ${escapeHtml(destinationLabel)}` : escapeHtml(tableLabel)}</span>
          <span><i class="fas fa-user-shield" aria-hidden="true"></i> ${escapeHtml(userName)}</span>
        </div>
        <div><i class="fas fa-clock" aria-hidden="true"></i> ${escapeHtml(createdAt)}</div>
        <b><i class="fas ${isPlayerTransfer || isTableTransfer ? "fa-exchange-alt" : "fa-ban"}" aria-hidden="true"></i> ${isPlayerTransfer || isTableTransfer ? "Transfer" : "Excluded"}</b>
        <div class="ptt-audit-reason">${
          isPlayerTransfer
            ? `<span>Player: ${escapeHtml(item.guestName || "Player")}</span>`
            : isTableTransfer
              ? '<span>Entire table session</span>'
              : item.reason ? `<span>Reason: ${escapeHtml(item.reason)}</span>` : '<span>No reason given</span>'
        }</div>
      </div>
    `;
  }

  function renderSettings() {
    if (!els.settingsTables) return;

    els.settingsTables.innerHTML = state.tables
      .map((table) => {
        const counts = guestCounts(table);
        const excluded = Boolean(table.isExcluded);
        const isOpen = table.isOpen || counts.total > 0;
        const disabled = isOpen ? "disabled" : "";
        const status = excluded
          ? "Excluded"
          : counts.active > 0
          ? `${counts.active} active ${counts.active === 1 ? "guest" : "guests"}`
          : counts.stopped > 0
            ? `${counts.stopped} stopped ${counts.stopped === 1 ? "guest" : "guests"}`
          : table.isOpen
            ? "Open table"
            : "Available";

        return `
          <div class="ptt-settings-table-row">
            <div>
              <strong><i class="fas fa-border-all" aria-hidden="true"></i> ${escapeHtml(table.label)}</strong>
              <span>${status}</span>
            </div>
            <div class="ptt-settings-actions">
              ${
                excluded
                  ? `<button type="button" class="ptt-secondary" data-restore-table="${table.id}"><i class="fas fa-undo" aria-hidden="true"></i> Restore</button>`
                  : `<button type="button" class="ptt-warning" data-exclude-table="${table.id}" ${disabled}><i class="fas fa-ban" aria-hidden="true"></i> Exclude</button>`
              }
              <button type="button" class="ptt-danger" data-remove-table="${table.id}" ${disabled}>
                <i class="fas fa-trash" aria-hidden="true"></i> Remove
              </button>
            </div>
          </div>
        `;
      })
      .join("");
  }

  function openDialog(tableId, mode) {
    state.dialogMode = mode;
    els.tableId.value = tableId;
    els.guestName.value = "";
    els.dialogTitle.textContent = mode === "start" ? "Start Table" : "Add Guest";
    els.dialog.setAttribute("aria-hidden", "false");
    els.guestName.focus();
  }

  function closeDialog() {
    els.dialog.setAttribute("aria-hidden", "true");
  }

  function tableStatusText(table) {
    const counts = guestCounts(table);
    if (counts.active && counts.stopped) return `${counts.active} active, ${counts.stopped} stopped`;
    if (counts.active) return `${counts.active} active`;
    if (counts.stopped) return `${counts.stopped} stopped`;
    if (table.isOpen) return "open";
    return "available";
  }

  function openSwapDialog(tableId) {
    if (!els.swapDialog) return;
    const sourceId = Number(tableId);
    els.swapSource.value = sourceId;
    els.swapTarget.innerHTML = state.tables
      .filter((table) => Number(table.id) !== sourceId && !table.isExcluded)
      .map((table) => {
        return `<option value="${table.id}">${escapeHtml(table.label)} - ${escapeHtml(tableStatusText(table))}</option>`;
      })
      .join("");
    els.swapDialog.setAttribute("aria-hidden", "false");
    els.swapTarget.focus();
  }

  function closeSwapDialog() {
    if (!els.swapDialog) return;
    els.swapDialog.setAttribute("aria-hidden", "true");
  }

  function findGuest(guestId) {
    const id = Number(guestId);
    for (const table of state.tables) {
      const guest = (table.activeGuests || []).find((item) => Number(item.id) === id);
      if (guest) return guest;
    }
    return null;
  }

  function openPlayerTransferDialog(guestId) {
    if (!els.playerTransferDialog) return;
    const guest = findGuest(guestId);
    if (!guest) return;
    els.playerTransferGuest.value = guestId;
    els.playerTransferTarget.innerHTML = state.tables
      .filter((table) => Number(table.id) !== Number(guest.tableId) && !table.isExcluded)
      .map((table) => {
        return `<option value="${table.id}">${escapeHtml(table.label)} - ${escapeHtml(tableStatusText(table))}</option>`;
      })
      .join("");
    els.playerTransferDialog.setAttribute("aria-hidden", "false");
    els.playerTransferTarget.focus();
  }

  function closePlayerTransferDialog() {
    if (!els.playerTransferDialog) return;
    els.playerTransferDialog.setAttribute("aria-hidden", "true");
  }

  function escapeHtml(value) {
    return String(value)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");
  }

  app.addEventListener("click", (event) => {
    const fullscreen = event.target.closest("[data-ptt-fullscreen]");
    if (fullscreen) {
      toggleFullscreen();
      return;
    }

    const tab = event.target.closest("[data-ptt-tab]");
    if (tab) {
      app.querySelectorAll("[data-ptt-tab]").forEach((button) => button.classList.remove("is-active"));
      app.querySelectorAll("[data-ptt-view]").forEach((view) => view.classList.remove("is-active"));
      tab.classList.add("is-active");
      app.querySelector(`[data-ptt-view="${tab.dataset.pttTab}"]`).classList.add("is-active");
      return;
    }

    const start = event.target.closest("[data-start-table]");
    if (start) openDialog(start.dataset.startTable, "start");

    const add = event.target.closest("[data-add-guest]");
    if (add) openDialog(add.dataset.addGuest, "add");

    const swap = event.target.closest("[data-swap-table]");
    if (swap) openSwapDialog(swap.dataset.swapTable);

    const transferPlayer = event.target.closest("[data-transfer-player]");
    if (transferPlayer) openPlayerTransferDialog(transferPlayer.dataset.transferPlayer);

    const end = event.target.closest("[data-end-guest]");
    if (end && confirm("End this guest timer?")) {
      request("ptt_end_guest", { guest_id: end.dataset.endGuest }).then(setState).catch(alert);
    }

    const exclude = event.target.closest("[data-toggle-exclude]");
    if (exclude) {
      request("ptt_toggle_guest_exclusion", { guest_id: exclude.dataset.toggleExclude }).then(setState).catch(alert);
    }

    const closeTable = event.target.closest("[data-close-table]");
    if (closeTable && confirm("Close this table and end all active guests?")) {
      request("ptt_checkout_table", { table_id: closeTable.dataset.closeTable }).then(setState).catch(alert);
    }

    const removeTable = event.target.closest("[data-remove-table]");
    if (removeTable && !removeTable.disabled && confirm("Remove this table?")) {
      request("ptt_remove_table", { table_id: removeTable.dataset.removeTable }).then(setState).catch(alert);
    }

    const excludeTable = event.target.closest("[data-exclude-table]");
    if (excludeTable && !excludeTable.disabled) {
      const reason = prompt("Reason for excluding this table? (Optional)", "");
      if (reason !== null) {
        request("ptt_exclude_table", {
          table_id: excludeTable.dataset.excludeTable,
          reason: reason.trim(),
        }).then(setState).catch(alert);
      }
    }

    const restoreTable = event.target.closest("[data-restore-table]");
    if (restoreTable && confirm("Restore this table?")) {
      request("ptt_restore_table", { table_id: restoreTable.dataset.restoreTable }).then(setState).catch(alert);
    }
  });

  els.form.addEventListener("submit", (event) => {
    event.preventDefault();
    const action = state.dialogMode === "start" ? "ptt_start_table" : "ptt_add_guest";
    request(action, {
      table_id: els.tableId.value,
      guest_name: els.guestName.value.trim(),
    })
      .then((data) => {
        closeDialog();
        setState(data);
      })
      .catch(alert);
  });

  els.cancel.addEventListener("click", closeDialog);
  els.dialog.addEventListener("click", (event) => {
    if (event.target === els.dialog) closeDialog();
  });

  if (els.swapForm) {
    els.swapForm.addEventListener("submit", (event) => {
      event.preventDefault();
      request("ptt_transfer_table", {
        source_table_id: els.swapSource.value,
        target_table_id: els.swapTarget.value,
      })
        .then((data) => {
          closeSwapDialog();
          setState(data);
        })
        .catch(alert);
    });
  }

  if (els.swapCancel) els.swapCancel.addEventListener("click", closeSwapDialog);
  if (els.swapDialog) {
    els.swapDialog.addEventListener("click", (event) => {
      if (event.target === els.swapDialog) closeSwapDialog();
    });
  }

  if (els.playerTransferForm) {
    els.playerTransferForm.addEventListener("submit", (event) => {
      event.preventDefault();
      request("ptt_transfer_player", {
        guest_id: els.playerTransferGuest.value,
        target_table_id: els.playerTransferTarget.value,
      })
        .then((data) => {
          closePlayerTransferDialog();
          setState(data);
        })
        .catch(alert);
    });
  }

  if (els.playerTransferCancel) els.playerTransferCancel.addEventListener("click", closePlayerTransferDialog);
  if (els.playerTransferDialog) {
    els.playerTransferDialog.addEventListener("click", (event) => {
      if (event.target === els.playerTransferDialog) closePlayerTransferDialog();
    });
  }

  els.historySearch.addEventListener("click", () => {
    request("ptt_search_history", { query: els.historyQuery.value.trim() })
      .then((data) => {
        state.history = data.history || [];
        renderHistory();
      })
      .catch(alert);
  });

  els.historyQuery.addEventListener("keydown", (event) => {
    if (event.key === "Enter") els.historySearch.click();
  });

  if (els.saveRate) {
    els.saveRate.addEventListener("click", () => {
      request("ptt_update_rate", { rate: els.rate.value }).then(setState).catch(alert);
    });
  }

  if (els.addTable) {
    els.addTable.addEventListener("click", () => {
      request("ptt_add_table").then(setState).catch(alert);
    });
  }

  document.addEventListener("fullscreenchange", () => {
    syncFullscreenState();
  });

  enableDefaultFullscreenStyle();

  request("ptt_get_state").then(setState).catch((error) => {
    els.grid.innerHTML = `<div class="ptt-empty">${escapeHtml(error.message)}</div>`;
  });

  updateFullscreenButton();
  state.tick = setInterval(renderStats, 1000);
  setInterval(renderDashboard, 1000);
})();
