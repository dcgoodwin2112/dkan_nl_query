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
    var examplesContainer = widget.querySelector('.nl-query-examples');
    var originalExamplesHtml = examplesContainer ? examplesContainer.innerHTML : '';

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
    var debugLastIteration = 0;
    var debugToolCount = 0;
    var debugTotalMs = 0;
    var debugMaxIteration = 0;
    var lastApiEquivalent = null;
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
    var showApiCallButton = settings.showApiCallButton !== false;
    var showSqlButton = settings.showSqlButton !== false;
    var showSqlInDebug = settings.showSqlInDebug !== false;
    var lastSqlEquivalent = null;

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
        debugLastIteration = 0; debugToolCount = 0; debugTotalMs = 0; debugMaxIteration = 0; lastApiEquivalent = null; lastSqlEquivalent = null;
        if (debugDetails) debugDetails.open = false;
        input.placeholder = defaultPlaceholder;
        if (examplesContainer && originalExamplesHtml) {
          examplesContainer.innerHTML = originalExamplesHtml;
          // Re-bind example button click handlers.
          examplesContainer.querySelectorAll('.nl-query-example').forEach(function (btn) {
            btn.addEventListener('click', function () {
              input.value = btn.dataset.question;
              input.focus();
            });
          });
        }
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
        case 'suggestions':
          if (data.items && data.items.length) {
            renderSuggestions(data.items);
          }
          scrollToBottom();
          break;
        case 'done':
          renderDebugFooter();
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

    function renderSuggestions(items) {
      if (!examplesContainer) return;
      examplesContainer.innerHTML = '';
      var label = document.createElement('span');
      label.className = 'nl-query-suggestions-label';
      label.textContent = 'Try next:';
      examplesContainer.appendChild(label);
      items.forEach(function (text) {
        var chip = document.createElement('button');
        chip.type = 'button';
        chip.className = 'nl-query-suggestion-chip';
        chip.textContent = text;
        chip.addEventListener('click', function () {
          runQuery(text);
        });
        examplesContainer.appendChild(chip);
      });
    }

    function scrollToBottom() {
      thread.scrollTop = thread.scrollHeight;
    }

    function renderToolCall(data) {
      if (!debugLog) return;

      // Iteration separator when the step changes.
      if (data.iteration && data.iteration !== debugLastIteration) {
        if (debugLastIteration > 0) {
          var sep = document.createElement('div');
          sep.className = 'nl-query-debug-separator';
          sep.textContent = 'Step ' + data.iteration + ' \u2014 Analyzing results';
          debugLog.appendChild(sep);
        }
        debugLastIteration = data.iteration;
      }

      // Track accumulated stats.
      debugToolCount++;
      debugTotalMs += data.duration_ms || 0;
      if (data.iteration > debugMaxIteration) {
        debugMaxIteration = data.iteration;
      }

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

        // For query tools, show equivalent API call and SQL.
        if (data.name === 'query_datastore' || data.name === 'query_datastore_join') {
          var apiCall = buildApiEquivalent(data.name, data.input, data.resolved_resource_id);
          lastApiEquivalent = apiCall;
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

          var sqlCall = buildSqlEquivalent(data.name, data.input, data.resolved_resource_id);
          lastSqlEquivalent = sqlCall;
          if (showSqlInDebug && sqlCall) {
            var sqlEl = document.createElement('div');
            sqlEl.className = 'nl-query-debug-sql';
            var sqlLabel = document.createElement('span');
            sqlLabel.className = 'nl-query-debug-sql-label';
            sqlLabel.textContent = 'SQL equivalent:';
            sqlEl.appendChild(sqlLabel);
            var sqlPre = document.createElement('pre');
            sqlPre.className = 'nl-query-debug-args';
            sqlPre.textContent = sqlCall;
            sqlEl.appendChild(sqlPre);
            entry.appendChild(sqlEl);
          }
        }
      }

      // Result summary line.
      if (data.result_summary) {
        var summaryText = formatResultSummary(data.name, data.result_summary);
        if (summaryText) {
          var resultEl = document.createElement('div');
          resultEl.className = 'nl-query-debug-result';
          if (data.result_summary.error) {
            resultEl.className += ' nl-query-debug-result-error';
          }
          resultEl.textContent = summaryText;
          entry.appendChild(resultEl);
        }
      }

      debugLog.appendChild(entry);
    }

    function formatResultSummary(name, summary) {
      if (summary.error) {
        return '\u2192 Error: ' + summary.error;
      }
      switch (name) {
        case 'query_datastore':
        case 'query_datastore_join':
          return '\u2192 ' + (summary.result_count || 0).toLocaleString()
            + ' of ' + (summary.total_rows || 0).toLocaleString() + ' rows'
            + ' (limit ' + (summary.limit || 100) + ', offset ' + (summary.offset || 0) + ')';
        case 'get_datastore_schema':
          var cols = summary.columns || [];
          var colList = cols.length > 5 ? cols.slice(0, 5).join(', ') + ', \u2026' : cols.join(', ');
          return '\u2192 ' + (summary.column_count || 0) + ' columns' + (colList ? ': ' + colList : '');
        case 'get_datastore_stats':
          return '\u2192 ' + (summary.total_rows || 0).toLocaleString() + ' rows, ' + (summary.column_count || 0) + ' columns';
        case 'get_import_status':
          return '\u2192 ' + (summary.status || 'unknown')
            + ' (' + (summary.num_of_rows || 0).toLocaleString() + ' rows, ' + (summary.num_of_columns || 0) + ' columns)';
        case 'search_columns':
          return '\u2192 ' + (summary.total_matches || 0) + ' match' + ((summary.total_matches || 0) !== 1 ? 'es' : '')
            + ' across ' + (summary.resources_searched || 0) + ' resources';
        case 'search_datasets':
          return '\u2192 ' + (summary.result_count || 0) + ' of ' + (summary.total || 0) + ' results';
        case 'list_datasets':
          return '\u2192 ' + (summary.result_count || 0) + ' of ' + (summary.total || 0) + ' datasets';
        case 'list_distributions':
          return '\u2192 ' + (summary.count || 0) + ' distribution' + ((summary.count || 0) !== 1 ? 's' : '');
        case 'find_dataset_resources':
          return '\u2192 ' + (summary.title || 'Unknown') + ' (' + (summary.distribution_count || 0) + ' distribution' + ((summary.distribution_count || 0) !== 1 ? 's' : '') + ')';
        case 'create_chart':
          return '\u2192 chart rendered';
        default:
          return '';
      }
    }

    function renderDebugFooter() {
      if (!debugLog || debugToolCount === 0) return;
      // Remove any existing footer.
      var existing = debugLog.querySelector('.nl-query-debug-footer');
      if (existing) existing.remove();

      var footer = document.createElement('div');
      footer.className = 'nl-query-debug-footer';
      var footerParts = [];
      footerParts.push(debugToolCount + ' tool call' + (debugToolCount !== 1 ? 's' : ''));
      footerParts.push(debugMaxIteration + ' step' + (debugMaxIteration !== 1 ? 's' : ''));
      footerParts.push(debugTotalMs.toLocaleString() + 'ms total');
      footer.textContent = footerParts.join(' \u00b7 ');
      debugLog.appendChild(footer);
    }

    function buildApiEquivalent(toolName, input, resolvedResourceId) {
      var resourceId = resolvedResourceId || input.resource_id || '';
      var isJoin = toolName === 'query_datastore_join' && input.join_resource_id;
      var body = {};

      // Resources array — only needed for join queries (multi-resource endpoint).
      if (isJoin) {
        body.resources = [
          { id: resourceId, alias: 't' },
          { id: input.join_resource_id, alias: 'j' }
        ];
      }

      // Properties: plain strings for single-resource, qualified objects for joins.
      var properties = [];
      if (input.columns) {
        input.columns.split(',').forEach(function (c) {
          c = c.trim();
          if (isJoin && c.indexOf('.') !== -1) {
            var parts = c.split('.');
            properties.push({ resource: parts[0], property: parts[1] });
          } else {
            properties.push(c);
          }
        });
      }

      // Groupings.
      if (input.groupings) {
        body.groupings = input.groupings.split(',').map(function (c) {
          c = c.trim();
          if (isJoin && c.indexOf('.') !== -1) {
            var parts = c.split('.');
            return { resource: parts[0], property: parts[1] };
          }
          return c;
        });
      }

      // Expressions are appended to properties.
      if (input.expressions) {
        try {
          JSON.parse(input.expressions).forEach(function (expr) {
            properties.push(expr);
          });
        } catch (e) {}
      }

      if (properties.length) {
        body.properties = properties;
      }

      // Conditions.
      if (input.conditions) {
        try {
          body.conditions = JSON.parse(input.conditions);
        } catch (e) {
          body.conditions = input.conditions;
        }
      }

      // Sorts: qualified for joins, simple for single-resource.
      if (input.sort_field) {
        var sort = { order: input.sort_direction || 'asc' };
        if (isJoin && input.sort_field.indexOf('.') !== -1) {
          var sortParts = input.sort_field.split('.');
          sort.resource = sortParts[0];
          sort.property = sortParts[1];
        } else {
          sort.property = input.sort_field;
        }
        body.sorts = [sort];
      }

      // Joins: DKAN nested condition structure.
      if (isJoin && input.join_on) {
        var joinOn = input.join_on.trim();
        if (joinOn.charAt(0) === '{') {
          // JSON format.
          try {
            var parsed = JSON.parse(joinOn);
            var left = parseQualifiedField(parsed.left || '', 't');
            var right = parseQualifiedField(parsed.right || '', 'j');
            body.joins = [{ resource: right.resource, condition: { resource: left.resource, property: left.property, value: right } }];
          } catch (e) {
            body.joins = [{ raw: joinOn }];
          }
        } else if (joinOn.indexOf('=') !== -1) {
          // Simple "col1=col2" format.
          var eqParts = joinOn.split('=');
          var leftField = parseQualifiedField(eqParts[0].trim(), 't');
          var rightField = parseQualifiedField(eqParts[1].trim(), 'j');
          body.joins = [{ resource: rightField.resource, condition: { resource: leftField.resource, property: leftField.property, value: rightField } }];
        }
      }

      body.limit = input.limit || 100;
      if (input.offset) body.offset = input.offset;
      body.count = true;
      body.results = true;
      body.keys = true;

      // Endpoint: joins use the multi-resource endpoint (no resource in path).
      var endpoint = isJoin
        ? 'POST /api/1/datastore/query'
        : 'POST /api/1/datastore/query/' + resourceId;

      return endpoint + '\n' + JSON.stringify(body, null, 2);
    }

    function buildSqlEquivalent(toolName, input, resolvedResourceId) {
      var resourceId = resolvedResourceId || input.resource_id || 'resource';
      var isJoin = toolName === 'query_datastore_join' && input.join_resource_id;
      var parts = [];

      // SELECT clause.
      var selectCols = [];
      if (input.columns) {
        input.columns.split(',').forEach(function (c) {
          selectCols.push(c.trim());
        });
      }
      if (input.expressions) {
        try {
          JSON.parse(input.expressions).forEach(function (expr) {
            var fn = (expr.operator || 'value').toUpperCase();
            var col = expr.operands || expr.property || '*';
            if (Array.isArray(col)) col = col.join(', ');
            var alias = expr.alias || '';
            var exprStr = fn + '(' + col + ')';
            if (alias) exprStr += ' AS ' + alias;
            selectCols.push(exprStr);
          });
        } catch (e) {}
      }
      parts.push('SELECT ' + (selectCols.length ? selectCols.join(', ') : '*'));

      // FROM clause.
      var tableName = 'datastore_' + resourceId.replace(/-/g, '_');
      if (isJoin) {
        parts.push('FROM ' + tableName + ' AS t');
      } else {
        parts.push('FROM ' + tableName);
      }

      // JOIN clause.
      if (isJoin && input.join_on) {
        var joinTable = 'datastore_' + input.join_resource_id.replace(/-/g, '_');
        var joinOn = input.join_on.trim();
        var onClause = '';
        if (joinOn.charAt(0) === '{') {
          try {
            var parsed = JSON.parse(joinOn);
            onClause = (parsed.left || 't.id') + ' = ' + (parsed.right || 'j.id');
          } catch (e) {
            onClause = joinOn;
          }
        } else if (joinOn.indexOf('=') !== -1) {
          var eqParts = joinOn.split('=');
          var left = eqParts[0].trim();
          var right = eqParts[1].trim();
          if (left.indexOf('.') === -1) left = 't.' + left;
          if (right.indexOf('.') === -1) right = 'j.' + right;
          onClause = left + ' = ' + right;
        } else {
          onClause = joinOn;
        }
        parts.push('JOIN ' + joinTable + ' AS j ON ' + onClause);
      }

      // WHERE clause.
      if (input.conditions) {
        try {
          var conditions = JSON.parse(input.conditions);
          if (Array.isArray(conditions) && conditions.length) {
            var whereClauses = conditions.map(function (cond) {
              var col = cond.property || cond.column || '?';
              if (isJoin && cond.resource) col = cond.resource + '.' + col;
              var op = (cond.operator || '=').toUpperCase();
              var val = cond.value;
              if (typeof val === 'string') val = "'" + val.replace(/'/g, "''") + "'";
              if (op === 'LIKE' || op === 'NOT LIKE') {
                return col + ' ' + op + ' ' + val;
              }
              if (op === 'IN' || op === 'NOT IN') {
                var vals = Array.isArray(cond.value) ? cond.value : [cond.value];
                var formatted = vals.map(function (v) {
                  return typeof v === 'string' ? "'" + v.replace(/'/g, "''") + "'" : v;
                });
                return col + ' ' + op + ' (' + formatted.join(', ') + ')';
              }
              if (op === 'BETWEEN') {
                return col + ' BETWEEN ' + cond.value;
              }
              return col + ' ' + op + ' ' + val;
            });
            parts.push('WHERE ' + whereClauses.join('\n  AND '));
          }
        } catch (e) {}
      }

      // GROUP BY clause.
      if (input.groupings) {
        parts.push('GROUP BY ' + input.groupings);
      }

      // ORDER BY clause.
      if (input.sort_field) {
        var dir = (input.sort_direction || 'asc').toUpperCase();
        parts.push('ORDER BY ' + input.sort_field + ' ' + dir);
      }

      // LIMIT / OFFSET.
      var limit = input.limit || 100;
      parts.push('LIMIT ' + limit);
      if (input.offset) {
        parts.push('OFFSET ' + input.offset);
      }

      return parts.join('\n');
    }

    function parseQualifiedField(field, defaultResource) {
      field = field.trim();
      if (field.indexOf('.') !== -1) {
        var parts = field.split('.');
        return { resource: parts[0], property: parts[1] };
      }
      return { resource: defaultResource, property: field };
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

      // "Show API call" button — between table toggle and CSV.
      var capturedApiEquivalent = showApiCallButton ? lastApiEquivalent : null;
      if (capturedApiEquivalent) {
        var apiBtn = document.createElement('button');
        apiBtn.type = 'button';
        apiBtn.className = 'nl-query-api-btn';
        apiBtn.textContent = 'Show API call';
        actions.appendChild(apiBtn);
      }

      // "Show SQL" button — after API button.
      var capturedSqlEquivalent = showSqlButton ? lastSqlEquivalent : null;
      if (capturedSqlEquivalent) {
        var sqlBtn = document.createElement('button');
        sqlBtn.type = 'button';
        sqlBtn.className = 'nl-query-sql-btn';
        sqlBtn.textContent = 'Show SQL';
        actions.appendChild(sqlBtn);
      }

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

      // API call collapsible panel.
      if (capturedApiEquivalent) {
        var apiWrapper = document.createElement('div');
        apiWrapper.className = 'nl-query-api-wrapper';
        apiWrapper.hidden = true;

        var apiPre = document.createElement('pre');
        apiPre.className = 'nl-query-api-code';
        apiPre.textContent = capturedApiEquivalent;
        apiWrapper.appendChild(apiPre);

        var copyApiBtn = document.createElement('button');
        copyApiBtn.type = 'button';
        copyApiBtn.className = 'nl-query-api-copy';
        copyApiBtn.textContent = 'Copy';
        copyApiBtn.addEventListener('click', function () {
          navigator.clipboard.writeText(capturedApiEquivalent).then(function () {
            copyApiBtn.textContent = 'Copied!';
            setTimeout(function () { copyApiBtn.textContent = 'Copy'; }, 1500);
          });
        });
        apiWrapper.appendChild(copyApiBtn);

        container.appendChild(apiWrapper);

        apiBtn.addEventListener('click', function () {
          var isHidden = apiWrapper.hidden;
          apiWrapper.hidden = !isHidden;
          apiBtn.textContent = isHidden ? 'Hide API call' : 'Show API call';
          scrollToBottom();
        });
      }

      // SQL collapsible panel.
      if (capturedSqlEquivalent) {
        var sqlWrapper = document.createElement('div');
        sqlWrapper.className = 'nl-query-sql-wrapper';
        sqlWrapper.hidden = true;

        var sqlPre = document.createElement('pre');
        sqlPre.className = 'nl-query-sql-code';
        sqlPre.textContent = capturedSqlEquivalent;
        sqlWrapper.appendChild(sqlPre);

        var copySqlBtn = document.createElement('button');
        copySqlBtn.type = 'button';
        copySqlBtn.className = 'nl-query-sql-copy';
        copySqlBtn.textContent = 'Copy';
        copySqlBtn.addEventListener('click', function () {
          navigator.clipboard.writeText(capturedSqlEquivalent).then(function () {
            copySqlBtn.textContent = 'Copied!';
            setTimeout(function () { copySqlBtn.textContent = 'Copy'; }, 1500);
          });
        });
        sqlWrapper.appendChild(copySqlBtn);

        container.appendChild(sqlWrapper);

        sqlBtn.addEventListener('click', function () {
          var isHidden = sqlWrapper.hidden;
          sqlWrapper.hidden = !isHidden;
          sqlBtn.textContent = isHidden ? 'Hide SQL' : 'Show SQL';
          scrollToBottom();
        });
      }

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
          debugLastIteration = 0; debugToolCount = 0; debugTotalMs = 0; debugMaxIteration = 0; lastApiEquivalent = null; lastSqlEquivalent = null;
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
                renderDebugFooter();
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
