(function () {
  const app = document.querySelector("[data-darts-app]");
  if (!app || !window.DartsBoardManager) return;

  const money = new Intl.NumberFormat("en-US", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const state = {
    boards: [],
    history: [],
    report: {},
    hourlyRate: Number(DartsBoardManager.hourlyRate || 2),
    serverNowTs: Math.floor(Date.now() / 1000),
    receivedAtMs: Date.now(),
  };

  const els = {
    grid: app.querySelector("[data-dbm-board-grid]"),
    runningCount: app.querySelector("[data-dbm-running-count]"),
    liveTotal: app.querySelector("[data-dbm-live-total]"),
    report: app.querySelector("[data-dbm-report]"),
    historyQuery: app.querySelector("[data-dbm-history-query]"),
    historySearch: app.querySelector("[data-dbm-history-search]"),
    historyList: app.querySelector("[data-dbm-history-list]"),
    dialog: app.querySelector("[data-dbm-start-dialog]"),
    form: app.querySelector("[data-dbm-start-form]"),
    boardId: app.querySelector("[data-dbm-board-id]"),
    playerName: app.querySelector("[data-dbm-player-name]"),
    cancel: app.querySelector("[data-dbm-cancel]"),
    transferDialog: app.querySelector("[data-dbm-transfer-dialog]"),
    transferForm: app.querySelector("[data-dbm-transfer-form]"),
    transferSource: app.querySelector("[data-dbm-transfer-source]"),
    transferTarget: app.querySelector("[data-dbm-transfer-target]"),
    transferCancel: app.querySelector("[data-dbm-transfer-cancel]"),
    guestDialog: app.querySelector("[data-dbm-guest-dialog]"),
    guestForm: app.querySelector("[data-dbm-guest-form]"),
    guestBoard: app.querySelector("[data-dbm-guest-board]"),
    guestName: app.querySelector("[data-dbm-guest-name]"),
    guestCancel: app.querySelector("[data-dbm-guest-cancel]"),
    guestTransferDialog: app.querySelector("[data-dbm-guest-transfer-dialog]"),
    guestTransferForm: app.querySelector("[data-dbm-guest-transfer-form]"),
    guestTransferPlayer: app.querySelector("[data-dbm-guest-transfer-player]"),
    guestTransferTarget: app.querySelector("[data-dbm-guest-transfer-target]"),
    guestTransferCancel: app.querySelector("[data-dbm-guest-transfer-cancel]"),
    fullscreen: app.querySelector("[data-dbm-fullscreen]"),
  };

  function request(action, data = {}) {
    const form = new FormData();
    form.append("action", action);
    form.append("nonce", DartsBoardManager.nonce);
    Object.entries(data).forEach(([key, value]) => form.append(key, value));

    return fetch(DartsBoardManager.ajaxUrl, {
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

  function liveServerNowTs() {
    return state.serverNowTs + Math.floor((Date.now() - state.receivedAtMs) / 1000);
  }

  function elapsedSeconds(session) {
    if (!session) return 0;
    const start = Number(session.startedAtTs || 0);
    const end = session.endedAtTs ? Number(session.endedAtTs) : liveServerNowTs();
    if (!start) return 0;
    return Math.max(0, end - start);
  }

  function chargeFor(session) {
    if (!session) return 0;
    if (Array.isArray(session.players) && session.players.length) {
      return session.players.reduce(
        (sum, player) => sum + (elapsedSeconds(player) / 3600) * state.hourlyRate,
        0
      );
    }
    return (elapsedSeconds(session) / 3600) * state.hourlyRate * Number(session.playerCount || 1);
  }

  function formatDuration(totalSeconds) {
    const hours = Math.floor(totalSeconds / 3600);
    const minutes = Math.floor((totalSeconds % 3600) / 60);
    const seconds = totalSeconds % 60;
    return [hours, minutes, seconds].map((part) => String(part).padStart(2, "0")).join(":");
  }

  function parseMysqlDate(value) {
    return new Date(String(value).replace(" ", "T"));
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

  function setState(data) {
    state.boards = data.boards || [];
    state.history = data.history || [];
    state.report = data.report || {};
    state.hourlyRate = Number(data.hourlyRate || state.hourlyRate);
    state.serverNowTs = Number(data.serverNowTs || state.serverNowTs);
    state.receivedAtMs = Date.now();
    render();
  }

  function render() {
    renderBoards();
    renderStats();
    renderReport();
    renderHistory();
  }

  function statusLabel(status) {
    return String(status || "available").replace(/^\w/, (letter) => letter.toUpperCase());
  }

  function boardTotal(board) {
    return board.activeSession ? chargeFor(board.activeSession) : 0;
  }

  function renderStats() {
    const running = state.boards.filter((board) => board.status === "running").length;
    const total = state.boards.reduce((sum, board) => sum + boardTotal(board), 0);
    els.runningCount.textContent = running;
    els.liveTotal.textContent = money.format(total);
  }

  function renderBoards() {
    els.grid.innerHTML = state.boards
      .map((board) => {
        const session = board.activeSession;
        const running = board.status === "running" && session;
        return `
          <article class="ptt-table dbm-board is-${escapeHtml(board.status)}">
            <div class="ptt-table-head">
              <div>
                <h2><i class="fas fa-bullseye" aria-hidden="true"></i> ${escapeHtml(board.label)}</h2>
                <p>${escapeHtml(statusLabel(board.status))}</p>
              </div>
              <strong>${running ? "$" + money.format(boardTotal(board)) : "-"}</strong>
            </div>
            ${running ? renderRunningSession(session) : ""}
            <div class="ptt-actions dbm-actions">
              ${renderBoardActions(board)}
            </div>
          </article>
        `;
      })
      .join("");
  }

  function renderRunningSession(session) {
    const players = Array.isArray(session.players) ? session.players : [];
    return `
      <div class="dbm-session">
        <div class="dbm-session-summary">
          <span><i class="fas fa-users" aria-hidden="true"></i> ${session.playerCount} ${session.playerCount === 1 ? "guest" : "guests"}</span>
          <span><i class="fas fa-clock" aria-hidden="true"></i> ${formatTime(session.startedAt)} - now</span>
        </div>
        <div class="dbm-player-list">
          ${players.map(renderPlayer).join("")}
        </div>
      </div>
    `;
  }

  function renderPlayer(player) {
    return `
      <div class="dbm-player">
        <div class="dbm-player-info">
          <strong><i class="fas fa-user" aria-hidden="true"></i> ${escapeHtml(player.guestName)}</strong>
          <span><i class="fas fa-stopwatch" aria-hidden="true"></i> ${formatDuration(elapsedSeconds(player))}</span>
        </div>
        <b>$${money.format((elapsedSeconds(player) / 3600) * state.hourlyRate)}</b>
        <button type="button" class="ptt-secondary dbm-player-transfer" data-transfer-guest="${player.id}" data-source-board="${player.boardId}" title="Transfer guest">
          <i class="fas fa-user-friends" aria-hidden="true"></i><span>Transfer</span>
        </button>
      </div>
    `;
  }

  function renderBoardActions(board) {
    if (board.status === "running") {
      return `
        <button type="button" class="ptt-secondary" data-add-guest="${board.id}"><i class="fas fa-user-plus" aria-hidden="true"></i> Add Guest</button>
        <button type="button" class="ptt-secondary" data-transfer-board="${board.id}"><i class="fas fa-exchange-alt" aria-hidden="true"></i> Transfer</button>
        <button type="button" class="ptt-primary" data-end-board="${board.id}"><i class="fas fa-cash-register" aria-hidden="true"></i> End & Collect</button>
      `;
    }
    if (board.status === "available") {
      return `
        <button type="button" class="ptt-primary" data-start-board="${board.id}"><i class="fas fa-play" aria-hidden="true"></i> Start</button>
        <button type="button" class="ptt-warning" data-exclude-board="${board.id}"><i class="fas fa-ban" aria-hidden="true"></i> Exclude</button>
      `;
    }
    return `<button type="button" class="ptt-secondary ptt-full" data-restore-board="${board.id}"><i class="fas fa-check-circle" aria-hidden="true"></i> Restore</button>`;
  }

  function renderReport() {
    const report = state.report || {};
    els.report.innerHTML = `
      <div><strong>${Number(report.sessions || 0)}</strong><span>sessions</span></div>
      <div><strong>${Number(report.players || 0)}</strong><span>players</span></div>
      <div><strong>${formatDuration(Number(report.seconds || 0))}</strong><span>total time</span></div>
      <div><strong>$${money.format(Number(report.revenue || 0))}</strong><span>collected</span></div>
    `;
  }

  function renderHistory() {
    if (!state.history.length) {
      els.historyList.innerHTML = '<div class="ptt-empty">No darts sessions or activity found.</div>';
      return;
    }

    els.historyList.innerHTML = state.history
      .map((item) => (item.type === "audit" ? renderAuditHistory(item) : renderSessionHistory(item)))
      .join("");
  }

  function renderSessionHistory(session) {
    return `
      <div class="ptt-history-row dbm-history-row">
        <div>
          <strong><i class="fas fa-bullseye" aria-hidden="true"></i> ${escapeHtml(session.boardLabel)}</strong>
          <span><i class="fas fa-users" aria-hidden="true"></i> ${session.playerCount} ${session.playerCount === 1 ? "player" : "players"}</span>
        </div>
        <div>
          <span><i class="fas fa-play-circle" aria-hidden="true"></i> ${formatTime(session.startedAt)}</span>
          <span><i class="fas fa-stop-circle" aria-hidden="true"></i> ${formatTime(session.endedAt)}</span>
        </div>
        <div><i class="fas fa-stopwatch" aria-hidden="true"></i> ${formatDuration(Number(session.elapsed || 0))}</div>
        <b><i class="fas fa-dollar-sign" aria-hidden="true"></i> ${money.format(Number(session.chargedAmount || 0))}</b>
      </div>
    `;
  }

  function renderAuditHistory(item) {
    const source = item.sourceTableLabel || item.tableLabel || "Board";
    const destination = item.destinationTableLabel || "";
    const isBoardTransfer = item.action === "dart_board_transferred";
    const isGuestTransfer = item.action === "dart_player_transferred";
    const isTransfer = isBoardTransfer || isGuestTransfer;
    const actionLabel = isGuestTransfer ? "Guest Transferred" : isBoardTransfer ? "Board Transferred" : "Board Excluded";
    const icon = isGuestTransfer ? "fa-user-friends" : isBoardTransfer ? "fa-exchange-alt" : "fa-ban";
    const description = isGuestTransfer
      ? `${escapeHtml(item.userName || "Admin")} transferred ${escapeHtml(item.guestName || "Guest")} from ${escapeHtml(source)} to ${escapeHtml(destination)} on ${formatTime(item.createdAt)}.`
      : isBoardTransfer
        ? `${escapeHtml(item.userName || "Admin")} transferred ${escapeHtml(source)} session to ${escapeHtml(destination)} on ${formatTime(item.createdAt)}.`
        : `${escapeHtml(item.userName || "Admin")} excluded ${escapeHtml(item.tableLabel || source)} on ${formatTime(item.createdAt)}.`;

    return `
      <div class="ptt-history-row ptt-audit-row dbm-audit-row">
        <div>
          <strong><i class="fas ${icon}" aria-hidden="true"></i> ${actionLabel}</strong>
          <span>${description}</span>
        </div>
        <div>
          <span><i class="fas fa-bullseye" aria-hidden="true"></i> ${isTransfer ? `${escapeHtml(source)} -> ${escapeHtml(destination)}` : escapeHtml(item.tableLabel || source)}</span>
          <span><i class="fas fa-user-shield" aria-hidden="true"></i> ${escapeHtml(item.userName || "Unknown user")}</span>
        </div>
        <div><i class="fas fa-clock" aria-hidden="true"></i> ${formatTime(item.createdAt)}</div>
        <b>${isGuestTransfer ? escapeHtml(item.guestName || "Guest") : isTransfer ? "Transfer" : "Exclude"}</b>
        ${item.reason ? `<div class="ptt-audit-reason"><i class="fas fa-comment-alt" aria-hidden="true"></i> Reason: ${escapeHtml(item.reason)}</div>` : ""}
      </div>
    `;
  }

  function openDialog(boardId) {
    els.boardId.value = boardId;
    els.playerName.value = "";
    els.dialog.setAttribute("aria-hidden", "false");
    els.playerName.focus();
  }

  function closeDialog() {
    els.dialog.setAttribute("aria-hidden", "true");
  }

  function openTransferDialog(boardId) {
    const destinations = state.boards.filter((board) => board.status === "available" && String(board.id) !== String(boardId));
    if (!destinations.length) {
      alert("No available destination board found.");
      return;
    }

    els.transferSource.value = boardId;
    els.transferTarget.innerHTML = destinations
      .map((board) => `<option value="${board.id}">${escapeHtml(board.label)}</option>`)
      .join("");
    els.transferDialog.setAttribute("aria-hidden", "false");
    els.transferTarget.focus();
  }

  function closeTransferDialog() {
    els.transferDialog.setAttribute("aria-hidden", "true");
  }

  function openGuestDialog(boardId) {
    els.guestBoard.value = boardId;
    els.guestName.value = "";
    els.guestDialog.setAttribute("aria-hidden", "false");
    els.guestName.focus();
  }

  function closeGuestDialog() {
    els.guestDialog.setAttribute("aria-hidden", "true");
  }

  function openGuestTransferDialog(playerId, sourceBoardId) {
    const destinations = state.boards.filter(
      (board) => board.status === "running" && String(board.id) !== String(sourceBoardId)
    );
    if (!destinations.length) {
      alert("Start another board before transferring a guest.");
      return;
    }

    els.guestTransferPlayer.value = playerId;
    els.guestTransferTarget.innerHTML = destinations
      .map((board) => `<option value="${board.id}">${escapeHtml(board.label)}</option>`)
      .join("");
    els.guestTransferDialog.setAttribute("aria-hidden", "false");
    els.guestTransferTarget.focus();
  }

  function closeGuestTransferDialog() {
    els.guestTransferDialog.setAttribute("aria-hidden", "true");
  }

  function isFullscreen() {
    return document.fullscreenElement === app || app.classList.contains("is-fullscreen");
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
      app.requestFullscreen();
      return;
    }
    app.classList.toggle("is-fullscreen");
    updateFullscreenButton();
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
    const fullscreen = event.target.closest("[data-dbm-fullscreen]");
    if (fullscreen) {
      toggleFullscreen();
      return;
    }

    const tab = event.target.closest("[data-dbm-tab]");
    if (tab) {
      app.querySelectorAll("[data-dbm-tab]").forEach((button) => button.classList.remove("is-active"));
      app.querySelectorAll("[data-dbm-view]").forEach((view) => view.classList.remove("is-active"));
      tab.classList.add("is-active");
      app.querySelector(`[data-dbm-view="${tab.dataset.dbmTab}"]`).classList.add("is-active");
      return;
    }

    const start = event.target.closest("[data-start-board]");
    if (start) openDialog(start.dataset.startBoard);

    const end = event.target.closest("[data-end-board]");
    if (end && confirm("End this darts session and collect payment?")) {
      request("dbm_end_session", { board_id: end.dataset.endBoard }).then(setState).catch(alert);
    }

    const transfer = event.target.closest("[data-transfer-board]");
    if (transfer) {
      openTransferDialog(transfer.dataset.transferBoard);
      return;
    }

    const addGuest = event.target.closest("[data-add-guest]");
    if (addGuest) {
      openGuestDialog(addGuest.dataset.addGuest);
      return;
    }

    const transferGuest = event.target.closest("[data-transfer-guest]");
    if (transferGuest) {
      openGuestTransferDialog(transferGuest.dataset.transferGuest, transferGuest.dataset.sourceBoard);
      return;
    }

    const exclude = event.target.closest("[data-exclude-board]");
    if (exclude) {
      const reason = window.prompt("Reason for excluding this board? (Optional)", "") || "";
      request("dbm_exclude_board", {
        board_id: exclude.dataset.excludeBoard,
        reason,
      }).then(setState).catch(alert);
      return;
    }

    const restore = event.target.closest("[data-restore-board]");
    if (restore && confirm("Restore this board to available?")) {
      request("dbm_restore_board", { board_id: restore.dataset.restoreBoard }).then(setState).catch(alert);
    }
  });

  els.form.addEventListener("submit", (event) => {
    event.preventDefault();
    request("dbm_start_session", {
      board_id: els.boardId.value,
      player_name: els.playerName.value.trim(),
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

  els.transferForm.addEventListener("submit", (event) => {
    event.preventDefault();
    request("dbm_transfer_board", {
      source_board_id: els.transferSource.value,
      target_board_id: els.transferTarget.value,
    })
      .then((data) => {
        closeTransferDialog();
        setState(data);
      })
      .catch(alert);
  });

  els.transferCancel.addEventListener("click", closeTransferDialog);
  els.transferDialog.addEventListener("click", (event) => {
    if (event.target === els.transferDialog) closeTransferDialog();
  });

  els.guestForm.addEventListener("submit", (event) => {
    event.preventDefault();
    request("dbm_add_guest", {
      board_id: els.guestBoard.value,
      guest_name: els.guestName.value.trim(),
    })
      .then((data) => {
        closeGuestDialog();
        setState(data);
      })
      .catch(alert);
  });

  els.guestCancel.addEventListener("click", closeGuestDialog);
  els.guestDialog.addEventListener("click", (event) => {
    if (event.target === els.guestDialog) closeGuestDialog();
  });

  els.guestTransferForm.addEventListener("submit", (event) => {
    event.preventDefault();
    request("dbm_transfer_guest", {
      player_id: els.guestTransferPlayer.value,
      target_board_id: els.guestTransferTarget.value,
    })
      .then((data) => {
        closeGuestTransferDialog();
        setState(data);
      })
      .catch(alert);
  });

  els.guestTransferCancel.addEventListener("click", closeGuestTransferDialog);
  els.guestTransferDialog.addEventListener("click", (event) => {
    if (event.target === els.guestTransferDialog) closeGuestTransferDialog();
  });

  els.historySearch.addEventListener("click", () => {
    request("dbm_search_history", { query: els.historyQuery.value.trim() })
      .then((data) => {
        state.history = data.history || [];
        state.report = data.report || {};
        renderReport();
        renderHistory();
      })
      .catch(alert);
  });

  els.historyQuery.addEventListener("keydown", (event) => {
    if (event.key === "Enter") els.historySearch.click();
  });

  document.addEventListener("fullscreenchange", () => {
    app.classList.toggle("is-fullscreen", document.fullscreenElement === app);
    updateFullscreenButton();
  });

  if (DartsBoardManager.autoFullscreen) app.classList.add("is-fullscreen");
  request("dbm_get_state").then(setState).catch((error) => {
    els.grid.innerHTML = `<div class="ptt-empty">${escapeHtml(error.message)}</div>`;
  });
  updateFullscreenButton();
  setInterval(() => {
    renderStats();
    renderBoards();
  }, 1000);
})();
