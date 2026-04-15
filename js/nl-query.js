(function (Drupal, drupalSettings) {
  'use strict';

  // Strip raw HTML from marked output to prevent XSS.
  if (typeof marked !== 'undefined') {
    marked.use({
      renderer: {
        html: function () { return ''; }
      }
    });
  }

  Drupal.behaviors.dkanNlQuery = {
    attach: function (context) {
      var widgets = context.querySelectorAll('.nl-query-widget');
      widgets.forEach(function (widget) {
        if (widget.dataset.nlQueryInitialized) return;
        widget.dataset.nlQueryInitialized = 'true';
        initWidget(widget);
      });
    }
  };

  function initWidget(widget) {
    var form = widget.querySelector('.nl-query-form');
    var input = widget.querySelector('.nl-query-input');
    var submitBtn = widget.querySelector('.nl-query-submit');
    var thread = widget.querySelector('.nl-query-thread');
    var statusEl = widget.querySelector('.nl-query-status');
    var statusText = widget.querySelector('.nl-query-status-text');
    var errorEl = widget.querySelector('.nl-query-error');
    var examples = widget.querySelectorAll('.nl-query-example');

    var settings = drupalSettings.dkanNlQuery || {};
    var configuredDatasetId = widget.dataset.datasetId || settings.datasetId || '';
    var baseEndpoint = settings.endpoint || '/api/nl-query';
    var datasetSelector = widget.querySelector('.nl-query-dataset-selector');
    var datasetSelect = widget.querySelector('.nl-query-dataset-select');
    var threadHeader = widget.querySelector('.nl-query-thread-header');
    var newConvoBtn = widget.querySelector('.nl-query-new-conversation');
    var modelSelect = widget.querySelector('.nl-query-model-select');

    var debugDetails = widget.querySelector('.nl-query-debug');
    var debugLog = widget.querySelector('.nl-query-debug-log');
    var sidebar = widget.querySelector('.nl-query-sidebar');
    var sidebarList = widget.querySelector('.nl-query-sidebar-list');
    var sidebarSearchInput = widget.querySelector('.nl-query-sidebar-search-input');
    var sidebarFooter = widget.querySelector('.nl-query-sidebar-footer');

    // Apply widget display settings.
    if (settings.showModelSelector === false) {
      var modelSelectorEl = widget.querySelector('.nl-query-model-selector');
      if (modelSelectorEl) modelSelectorEl.hidden = true;
    }
    if (settings.showExamples === false) {
      var examplesEl = widget.querySelector('.nl-query-examples');
      if (examplesEl) examplesEl.hidden = true;
    }
    if (settings.showDebugPanel === false && debugDetails) {
      debugDetails.hidden = true;
    }

    // Show sidebar for authenticated users with history enabled.
    var historyEnabled = settings.userAuthenticated && settings.saveChatHistory !== false;
    var datasetMap = {};
    var cachedConversations = [];
    if (historyEnabled && sidebar) {
      sidebar.hidden = false;
      widget.classList.add('nl-query-widget--with-sidebar');
      loadSidebarConversations();
    }

    var defaultPlaceholder = 'Ask a question about this data...';
    var followUpPlaceholder = 'Ask a follow-up...';

    // Populate model selector from settings, grouped by provider.
    var models = settings.models || [];
    var defaultModel = settings.defaultModel || '';
    if (modelSelect && models.length) {
      var groups = {};
      models.forEach(function (m) {
        var group = m.provider || 'Other';
        if (!groups[group]) {
          groups[group] = document.createElement('optgroup');
          groups[group].label = group;
          modelSelect.appendChild(groups[group]);
        }
        var opt = document.createElement('option');
        opt.value = m.id;
        opt.textContent = m.label;
        if (m.id === defaultModel) {
          opt.selected = true;
        }
        groups[group].appendChild(opt);
      });
    }

    // Conversation history for follow-up context.
    var history = [];

    // Current streaming state.
    var rawAnswer = '';
    var currentAnswerEl = null;
    var currentTableContainer = null;
    var currentChartContainer = null;
    var currentAssistantBubble = null;
    var currentConversationId = null;

    // If no dataset pre-configured, fetch dataset list for selector.
    if (!configuredDatasetId && datasetSelector) {
      datasetSelector.hidden = false;
      fetch('/api/nl-query-datasets')
        .then(function (r) { return r.json(); })
        .then(function (datasets) {
          datasets.forEach(function (ds) {
            var opt = document.createElement('option');
            opt.value = ds.identifier;
            opt.textContent = ds.title;
            datasetSelect.appendChild(opt);
          });
        });
    }

    // Example buttons fill the input.
    examples.forEach(function (btn) {
      btn.addEventListener('click', function () {
        input.value = btn.dataset.question;
        input.focus();
      });
    });

    // New conversation resets everything.
    if (newConvoBtn) {
      newConvoBtn.addEventListener('click', function () {
        history = [];
        rawAnswer = '';
        currentAnswerEl = null;
        currentChartContainer = null;
        currentTableContainer = null;
        currentAssistantBubble = null;
        currentConversationId = null;
        thread.innerHTML = '';
        errorEl.hidden = true;
        threadHeader.hidden = true;
        if (newConvoBtn) newConvoBtn.hidden = true;
        updateSidebarActiveState();
        if (debugLog) debugLog.innerHTML = '';
        if (debugDetails) debugDetails.open = false;
        input.placeholder = defaultPlaceholder;
        if (datasetSelect) {
          datasetSelect.disabled = false;
        }
        input.focus();
      });
    }

    // Textarea: Enter submits, Shift+Enter inserts newline.
    input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        form.requestSubmit();
      }
    });

    // Auto-grow textarea.
    input.addEventListener('input', function () {
      input.style.height = 'auto';
      input.style.height = Math.min(input.scrollHeight, 120) + 'px';
    });

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var question = input.value.trim();
      if (!question) return;
      input.value = '';
      input.style.height = 'auto';
      runQuery(question);
    });

    function getModelLabel() {
      if (!modelSelect) return '';
      var opt = modelSelect.options[modelSelect.selectedIndex];
      return opt ? opt.textContent : '';
    }

    function runQuery(question) {
      // Lock dataset selector after first query.
      if (datasetSelect && !datasetSelect.disabled) {
        datasetSelect.disabled = true;
      }
      if (threadHeader) {
        threadHeader.hidden = false;
      }
      if (newConvoBtn) {
        newConvoBtn.hidden = false;
      }

      // Append user message bubble.
      var userBubble = document.createElement('div');
      userBubble.className = 'nl-query-message nl-query-message-user';
      userBubble.textContent = question;
      thread.appendChild(userBubble);

      // Create assistant message area.
      var assistantBubble = document.createElement('div');
      assistantBubble.className = 'nl-query-message nl-query-message-assistant';

      var answerEl = document.createElement('div');
      answerEl.className = 'nl-query-answer';
      assistantBubble.appendChild(answerEl);

      var chartContainer = document.createElement('div');
      chartContainer.className = 'nl-query-chart-container';
      chartContainer.hidden = true;
      assistantBubble.appendChild(chartContainer);

      var tableContainer = document.createElement('div');
      tableContainer.className = 'nl-query-table-container';
      tableContainer.hidden = true;
      assistantBubble.appendChild(tableContainer);

      thread.appendChild(assistantBubble);

      // Set current streaming targets.
      rawAnswer = '';
      currentAnswerEl = answerEl;
      currentChartContainer = chartContainer;
      currentTableContainer = tableContainer;
      currentAssistantBubble = assistantBubble;

      // UI state.
      submitBtn.disabled = true;
      errorEl.hidden = true;
      errorEl.textContent = '';
      statusEl.hidden = false;
      statusText.textContent = 'Thinking...';
      input.placeholder = followUpPlaceholder;

      scrollToBottom();

      // Build request.
      var activeDatasetId = configuredDatasetId || (datasetSelect ? datasetSelect.value : '');
      var url = activeDatasetId
        ? baseEndpoint + '/' + encodeURIComponent(activeDatasetId)
        : baseEndpoint;
      var selectedModel = modelSelect ? modelSelect.value : '';
      var bodyParams = {
        question: question,
        history: JSON.stringify(history),
        model: selectedModel,
      };
      if (currentConversationId) {
        bodyParams.conversation_id = currentConversationId;
      }
      var body = new URLSearchParams(bodyParams);

      fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString()
      }).then(function (response) {
        if (!response.ok) {
          throw new Error('Request failed: ' + response.status);
        }

        var reader = response.body.getReader();
        var decoder = new TextDecoder();
        var buffer = '';

        function parseBuffer() {
          var lines = buffer.split('\n');
          buffer = lines.pop() || '';

          var eventType = '';
          for (var i = 0; i < lines.length; i++) {
            var line = lines[i];
            if (line.startsWith('event: ')) {
              eventType = line.substring(7).trim();
            } else if (line.startsWith('data: ')) {
              var dataStr = line.substring(6);
              try {
                var data = JSON.parse(dataStr);
                handleEvent(eventType, data);
              } catch (e) {
                // Ignore malformed JSON.
              }
            }
          }
        }

        function processChunk() {
          return reader.read().then(function (result) {
            if (result.done) {
              if (buffer.trim()) {
                buffer += '\n';
                parseBuffer();
              }
              return;
            }

            buffer += decoder.decode(result.value, { stream: true });
            parseBuffer();

            return processChunk();
          });
        }

        return processChunk();
      }).catch(function (err) {
        errorEl.textContent = err.message;
        errorEl.hidden = false;
      }).finally(function () {
        onStreamEnd(question);
      });
    }

    function handleEvent(type, data) {
      switch (type) {
        case 'token':
          statusEl.hidden = true;
          rawAnswer += data.text || '';
          if (currentAnswerEl) {
            currentAnswerEl.textContent = rawAnswer;
          }
          scrollToBottom();
          break;
        case 'data':
          if (currentTableContainer) {
            renderTable(data, currentTableContainer);
          }
          scrollToBottom();
          break;
        case 'chart':
          if (currentChartContainer && data.spec && typeof vegaEmbed !== 'undefined') {
            currentChartContainer.hidden = false;
            // Widen the bubble to give the chart more room.
            if (currentAssistantBubble) {
              currentAssistantBubble.classList.add('nl-query-message-has-chart');
            }
            var spec = data.spec;
            // Recalculate after class change widens the bubble.
            var bubbleWidth = currentAssistantBubble ? currentAssistantBubble.offsetWidth - 28 : 600;
            if (!spec.width || spec.width === 'container') {
              spec.width = bubbleWidth || 600;
            }
            if (spec.width > bubbleWidth && bubbleWidth > 0) {
              spec.width = bubbleWidth;
            }
            if (!spec.height) {
              spec.height = 400;
            }
            spec.autosize = {type: 'pad'};
            if (!spec.padding) {
              spec.padding = {left: 50, bottom: 40, right: 20, top: 10};
            }
            vegaEmbed(currentChartContainer, spec, {
              actions: {export: true, source: false, compiled: false, editor: false},
              renderer: 'svg',
            }).catch(function (err) {
              console.warn('Chart render error:', err);
              currentChartContainer.hidden = true;
            });
          }
          scrollToBottom();
          break;
        case 'status':
          statusEl.hidden = false;
          statusText.textContent = data.message || '';
          if (rawAnswer.trim()) {
            rawAnswer += '\n\n';
          }
          break;
        case 'conversation':
          if (data.id) {
            currentConversationId = data.id;
            // Refresh sidebar to show the new/updated conversation.
            if (historyEnabled) {
              loadSidebarConversations();
            }
          }
          break;
        case 'tool_call':
          renderToolCall(data);
          break;
        case 'error':
          errorEl.textContent = data.message || 'An error occurred.';
          errorEl.hidden = false;
          break;
        case 'done':
          break;
      }
    }

    function onStreamEnd(question) {
      submitBtn.disabled = false;
      statusEl.hidden = true;
      input.focus();

      // Parse markdown in the completed answer (HTML stripped by marked config).
      if (rawAnswer.trim() && currentAnswerEl && typeof marked !== 'undefined') {
        currentAnswerEl.innerHTML = marked.parse(rawAnswer);
      }

      // Add copy button and model label to the assistant bubble.
      if (currentAssistantBubble && rawAnswer.trim()) {
        var footer = document.createElement('div');
        footer.className = 'nl-query-bubble-footer';

        // Model label.
        var label = getModelLabel();
        if (label) {
          var modelLabel = document.createElement('span');
          modelLabel.className = 'nl-query-model-label-bubble';
          modelLabel.textContent = 'via ' + label;
          footer.appendChild(modelLabel);
        }

        // Copy button.
        var copyBtn = document.createElement('button');
        copyBtn.className = 'nl-query-copy-btn';
        copyBtn.textContent = 'Copy';
        copyBtn.type = 'button';
        var answerText = rawAnswer;
        copyBtn.addEventListener('click', function () {
          navigator.clipboard.writeText(answerText).then(function () {
            copyBtn.textContent = 'Copied!';
            setTimeout(function () { copyBtn.textContent = 'Copy'; }, 1500);
          });
        });
        footer.appendChild(copyBtn);

        currentAssistantBubble.appendChild(footer);
      }

      // Add to history for follow-up context.
      if (rawAnswer.trim()) {
        history.push({ role: 'user', content: question });
        history.push({ role: 'assistant', content: rawAnswer });

        while (history.length > 10) {
          history.shift();
        }
      }

      scrollToBottom();
    }

    function scrollToBottom() {
      thread.scrollTop = thread.scrollHeight;
    }

    function renderToolCall(data) {
      if (!debugLog) return;

      var entry = document.createElement('div');
      entry.className = 'nl-query-debug-entry' + (data.is_error ? ' nl-query-debug-error' : '');

      // Header: tool name, iteration badge, duration.
      var header = document.createElement('div');
      header.className = 'nl-query-debug-header';

      var nameSpan = document.createElement('span');
      nameSpan.className = 'nl-query-debug-name';
      nameSpan.textContent = data.name;
      header.appendChild(nameSpan);

      var meta = document.createElement('span');
      meta.className = 'nl-query-debug-meta';
      var parts = [];
      if (data.iteration) {
        parts.push('step ' + data.iteration);
      }
      if (data.duration_ms != null) {
        parts.push(data.duration_ms + 'ms');
      }
      meta.textContent = parts.join(' · ');
      header.appendChild(meta);

      entry.appendChild(header);

      // Input args as formatted JSON.
      if (data.input && Object.keys(data.input).length) {
        var pre = document.createElement('pre');
        pre.className = 'nl-query-debug-args';
        pre.textContent = JSON.stringify(data.input, null, 2);
        entry.appendChild(pre);

        // For query tools, show equivalent API call.
        if (data.name === 'query_datastore' || data.name === 'query_datastore_join') {
          var apiCall = buildApiEquivalent(data.name, data.input);
          if (apiCall) {
            var apiEl = document.createElement('div');
            apiEl.className = 'nl-query-debug-api';
            var apiLabel = document.createElement('span');
            apiLabel.className = 'nl-query-debug-api-label';
            apiLabel.textContent = 'API equivalent:';
            apiEl.appendChild(apiLabel);
            var apiPre = document.createElement('pre');
            apiPre.className = 'nl-query-debug-args';
            apiPre.textContent = apiCall;
            apiEl.appendChild(apiPre);
            entry.appendChild(apiEl);
          }
        }
      }

      debugLog.appendChild(entry);
    }

    function buildApiEquivalent(toolName, input) {
      var resourceId = input.resource_id || '';
      var body = {};
      if (input.columns) {
        body.properties = input.columns.split(',').map(function (c) {
          return { property: c.trim() };
        });
      }
      if (input.conditions) {
        try {
          body.conditions = JSON.parse(input.conditions);
        } catch (e) {
          body.conditions = input.conditions;
        }
      }
      if (input.sort_field) {
        body.sorts = [{ property: input.sort_field, order: input.sort_direction || 'asc' }];
      }
      if (input.limit) body.limit = input.limit;
      if (input.offset) body.offset = input.offset;
      if (input.expressions) {
        try {
          body.properties = body.properties || [];
          JSON.parse(input.expressions).forEach(function (expr) {
            body.properties.push(expr);
          });
        } catch (e) {}
      }
      if (input.groupings) {
        body.groupings = input.groupings.split(',').map(function (c) {
          return { property: c.trim() };
        });
      }
      if (toolName === 'query_datastore_join' && input.join_resource_id) {
        body.joins = [{
          resource_id: input.join_resource_id,
          on: input.join_on,
        }];
      }
      return 'POST /api/1/datastore/query/' + resourceId + '\n' + JSON.stringify(body, null, 2);
    }

    function renderTable(data, container) {
      var results = data.results || [];
      if (results.length === 0) return;

      var columns = Object.keys(results[0]);
      container.hidden = false;

      var sortCol = null;
      var sortAsc = true;

      // Build summary bar (always visible).
      var summaryBar = document.createElement('div');
      summaryBar.className = 'nl-query-table-summary';

      var countText = results.length + ' row' + (results.length !== 1 ? 's' : '');
      if (data.total_rows != null && data.total_rows > results.length) {
        countText += ' of ' + data.total_rows + ' total';
      }
      var metaSpan = document.createElement('span');
      metaSpan.className = 'nl-query-table-meta';
      metaSpan.textContent = countText;
      summaryBar.appendChild(metaSpan);

      var actions = document.createElement('span');
      actions.className = 'nl-query-table-actions';

      var toggleBtn = document.createElement('button');
      toggleBtn.type = 'button';
      toggleBtn.className = 'nl-query-table-toggle';
      toggleBtn.textContent = 'Show table';
      actions.appendChild(toggleBtn);

      var csvBtn = document.createElement('button');
      csvBtn.type = 'button';
      csvBtn.className = 'nl-query-csv-btn';
      csvBtn.textContent = 'Download CSV';
      csvBtn.addEventListener('click', function () {
        downloadCsv(columns, results);
      });
      actions.appendChild(csvBtn);

      summaryBar.appendChild(actions);
      container.appendChild(summaryBar);

      // Build table wrapper (collapsed by default).
      var tableWrapper = document.createElement('div');
      tableWrapper.className = 'nl-query-table-wrapper';
      tableWrapper.hidden = true;
      container.appendChild(tableWrapper);

      toggleBtn.addEventListener('click', function () {
        var isHidden = tableWrapper.hidden;
        tableWrapper.hidden = !isHidden;
        toggleBtn.textContent = isHidden ? 'Hide table' : 'Show table';
        if (isHidden && !tableWrapper.hasChildNodes()) {
          buildTable(results);
        }
        scrollToBottom();
      });

      function buildTable(rows) {
        var html = '<table class="nl-query-table"><thead><tr>';
        columns.forEach(function (col) {
          var indicator = '';
          if (col === sortCol) {
            indicator = '<span class="sort-indicator">' + (sortAsc ? '\u25B2' : '\u25BC') + '</span>';
          }
          html += '<th data-col="' + escapeHtml(col) + '">' + escapeHtml(col) + indicator + '</th>';
        });
        html += '</tr></thead><tbody>';

        rows.forEach(function (row) {
          html += '<tr>';
          columns.forEach(function (col) {
            html += '<td>' + escapeHtml(String(row[col] ?? '')) + '</td>';
          });
          html += '</tr>';
        });

        html += '</tbody></table>';

        tableWrapper.innerHTML = html;

        // Sort handlers.
        tableWrapper.querySelectorAll('th').forEach(function (th) {
          th.addEventListener('click', function () {
            var col = th.dataset.col;
            if (sortCol === col) {
              sortAsc = !sortAsc;
            } else {
              sortCol = col;
              sortAsc = true;
            }
            var sorted = rows.slice().sort(function (a, b) {
              var va = a[col] ?? '';
              var vb = b[col] ?? '';
              var na = Number(va);
              var nb = Number(vb);
              if (!isNaN(na) && !isNaN(nb)) {
                return sortAsc ? na - nb : nb - na;
              }
              return sortAsc ? String(va).localeCompare(String(vb)) : String(vb).localeCompare(String(va));
            });
            buildTable(sorted);
          });
        });
      }
    }

    function downloadCsv(columns, rows) {
      var lines = [];
      lines.push(columns.map(csvEscape).join(','));
      rows.forEach(function (row) {
        lines.push(columns.map(function (col) {
          return csvEscape(String(row[col] ?? ''));
        }).join(','));
      });
      var csv = lines.join('\n');
      var blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
      var url = URL.createObjectURL(blob);
      var a = document.createElement('a');
      a.href = url;
      a.download = 'query-results.csv';
      a.click();
      URL.revokeObjectURL(url);
    }

    function csvEscape(str) {
      if (str.indexOf(',') !== -1 || str.indexOf('"') !== -1 || str.indexOf('\n') !== -1) {
        return '"' + str.replace(/"/g, '""') + '"';
      }
      return str;
    }

    // --- Sidebar / History ---

    // Build dataset ID → title map for sidebar labels.
    function fetchDatasetMap() {
      fetch('/api/nl-query-datasets')
        .then(function (r) { return r.json(); })
        .then(function (datasets) {
          datasets.forEach(function (ds) {
            datasetMap[ds.identifier] = ds.title;
          });
          // Re-render sidebar entries with dataset labels.
          renderSidebarList(cachedConversations);
        });
    }

    function loadSidebarConversations() {
      if (!sidebarList) return;
      sidebarList.innerHTML = '<div class="nl-query-sidebar-loading">Loading...</div>';
      fetch('/api/nl-query/conversations')
        .then(function (r) { return r.json(); })
        .then(function (conversations) {
          cachedConversations = conversations;
          renderSidebarList(conversations);
          // Fetch dataset titles for labels (non-blocking).
          if (!Object.keys(datasetMap).length) {
            fetchDatasetMap();
          }
        })
        .catch(function () {
          sidebarList.innerHTML = '<div class="nl-query-sidebar-empty">Failed to load history.</div>';
        });
    }

    function renderSidebarList(conversations, filter) {
      if (!sidebarList) return;
      var filtered = conversations;
      if (filter) {
        var lower = filter.toLowerCase();
        filtered = conversations.filter(function (c) {
          return c.title.toLowerCase().indexOf(lower) !== -1;
        });
      }
      sidebarList.innerHTML = '';
      if (!filtered.length) {
        sidebarList.innerHTML = '<div class="nl-query-sidebar-empty">' +
          (filter ? 'No matching conversations.' : 'No saved conversations.') + '</div>';
      } else {
        filtered.forEach(function (conv) {
          sidebarList.appendChild(buildSidebarEntry(conv));
        });
      }
      // Update count.
      if (sidebarFooter) {
        sidebarFooter.textContent = conversations.length + ' conversation' +
          (conversations.length !== 1 ? 's' : '');
      }
    }

    // Search filtering.
    if (sidebarSearchInput) {
      sidebarSearchInput.addEventListener('input', function () {
        renderSidebarList(cachedConversations, sidebarSearchInput.value.trim());
      });
    }

    function updateSidebarActiveState() {
      if (!sidebarList) return;
      sidebarList.querySelectorAll('.nl-query-sidebar-entry').forEach(function (el) {
        var isActive = currentConversationId && el.dataset.id == currentConversationId;
        el.classList.toggle('nl-query-sidebar-entry--active', isActive);
      });
    }

    function buildSidebarEntry(conv) {
      var entry = document.createElement('div');
      var classes = 'nl-query-sidebar-entry';
      if (conv.pinned) classes += ' nl-query-sidebar-entry--pinned';
      if (currentConversationId && conv.id == currentConversationId) classes += ' nl-query-sidebar-entry--active';
      entry.className = classes;
      entry.dataset.id = conv.id;

      var title = document.createElement('div');
      title.className = 'nl-query-sidebar-title';
      title.textContent = conv.title;
      entry.appendChild(title);

      // Dataset label.
      if (conv.dataset_id) {
        var dsLabel = document.createElement('div');
        dsLabel.className = 'nl-query-sidebar-dataset';
        dsLabel.textContent = datasetMap[conv.dataset_id] || conv.dataset_id;
        entry.appendChild(dsLabel);
      }

      var meta = document.createElement('div');
      meta.className = 'nl-query-sidebar-meta';

      var date = new Date(conv.changed * 1000);
      var dateStr = date.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
      var dateSpan = document.createElement('span');
      dateSpan.textContent = dateStr;
      meta.appendChild(dateSpan);

      var actions = document.createElement('span');
      actions.className = 'nl-query-sidebar-actions';

      var pinBtn = document.createElement('button');
      pinBtn.type = 'button';
      pinBtn.className = 'nl-query-sidebar-pin';
      pinBtn.textContent = conv.pinned ? '\u2605' : '\u2606';
      pinBtn.title = conv.pinned ? 'Unpin' : 'Pin';
      pinBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        fetch('/api/nl-query/conversations/' + conv.id + '/pin', { method: 'PATCH' })
          .then(function (r) { return r.json(); })
          .then(function (result) {
            conv.pinned = result.pinned;
            pinBtn.textContent = result.pinned ? '\u2605' : '\u2606';
            pinBtn.title = result.pinned ? 'Unpin' : 'Pin';
            entry.classList.toggle('nl-query-sidebar-entry--pinned', result.pinned);
          });
      });
      actions.appendChild(pinBtn);

      var delBtn = document.createElement('button');
      delBtn.type = 'button';
      delBtn.className = 'nl-query-sidebar-delete';
      delBtn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>';
      delBtn.title = 'Delete';
      delBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        if (!confirm('Delete this conversation?')) return;
        fetch('/api/nl-query/conversations/' + conv.id, { method: 'DELETE' })
          .then(function () {
            cachedConversations = cachedConversations.filter(function (c) { return c.id !== conv.id; });
            entry.remove();
            if (currentConversationId === conv.id) {
              currentConversationId = null;
            }
            if (sidebarFooter) {
              sidebarFooter.textContent = cachedConversations.length + ' conversation' +
                (cachedConversations.length !== 1 ? 's' : '');
            }
          });
      });
      actions.appendChild(delBtn);

      meta.appendChild(actions);
      entry.appendChild(meta);

      // Click entry to load conversation.
      entry.addEventListener('click', function () {
        loadConversation(conv.id);
      });

      return entry;
    }

    function loadConversation(id) {
      fetch('/api/nl-query/conversations/' + id)
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (data.error) return;

          // Reset state.
          history = [];
          rawAnswer = '';
          thread.innerHTML = '';
          errorEl.hidden = true;
          if (debugLog) debugLog.innerHTML = '';
          currentConversationId = id;

          if (threadHeader) threadHeader.hidden = false;
          if (newConvoBtn) newConvoBtn.hidden = false;
          input.placeholder = followUpPlaceholder;
          if (datasetSelect) datasetSelect.disabled = true;

          updateSidebarActiveState();

          // Rebuild thread from saved messages.
          data.messages.forEach(function (msg) {
            if (msg.role === 'user') {
              var userBubble = document.createElement('div');
              userBubble.className = 'nl-query-message nl-query-message-user';
              userBubble.textContent = msg.content;
              thread.appendChild(userBubble);
              history.push({ role: 'user', content: msg.content });
            }
            else if (msg.role === 'assistant') {
              var bubble = document.createElement('div');
              bubble.className = 'nl-query-message nl-query-message-assistant';

              var answerEl = document.createElement('div');
              answerEl.className = 'nl-query-answer';
              if (msg.content && typeof marked !== 'undefined') {
                answerEl.innerHTML = marked.parse(msg.content);
              } else {
                answerEl.textContent = msg.content || '';
              }
              bubble.appendChild(answerEl);

              // Restore chart.
              if (msg.chart_spec && typeof vegaEmbed !== 'undefined') {
                bubble.classList.add('nl-query-message-has-chart');
                var chartEl = document.createElement('div');
                chartEl.className = 'nl-query-chart-container';
                bubble.appendChild(chartEl);
                var spec = msg.chart_spec;
                var bubbleWidth = 870;
                if (!spec.width || spec.width === 'container') {
                  spec.width = bubbleWidth;
                }
                if (!spec.height) spec.height = 400;
                spec.autosize = {type: 'pad'};
                if (!spec.padding) {
                  spec.padding = {left: 50, bottom: 40, right: 20, top: 10};
                }
                vegaEmbed(chartEl, spec, {
                  actions: {export: true, source: false, compiled: false, editor: false},
                  renderer: 'svg',
                }).catch(function () {
                  chartEl.hidden = true;
                });
              }

              // Restore table.
              if (msg.table_data) {
                var tableEl = document.createElement('div');
                tableEl.className = 'nl-query-table-container';
                bubble.appendChild(tableEl);
                renderTable(msg.table_data, tableEl);
              }

              // Restore tool calls to debug panel.
              if (msg.tool_calls && debugLog) {
                msg.tool_calls.forEach(function (tc) {
                  renderToolCall(tc);
                });
              }

              thread.appendChild(bubble);
              history.push({ role: 'assistant', content: msg.content || '' });
            }
          });

          // Trim history.
          while (history.length > 10) {
            history.shift();
          }

          scrollToBottom();
        });
    }

    function escapeHtml(str) {
      var div = document.createElement('div');
      div.textContent = str;
      return div.innerHTML;
    }
  }

})(Drupal, drupalSettings);
