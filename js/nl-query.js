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
    var currentAssistantBubble = null;

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
        currentTableContainer = null;
        currentAssistantBubble = null;
        thread.innerHTML = '';
        errorEl.hidden = true;
        threadHeader.hidden = true;
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

      var tableContainer = document.createElement('div');
      tableContainer.className = 'nl-query-table-container';
      tableContainer.hidden = true;
      assistantBubble.appendChild(tableContainer);

      thread.appendChild(assistantBubble);

      // Set current streaming targets.
      rawAnswer = '';
      currentAnswerEl = answerEl;
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
      var body = new URLSearchParams({
        question: question,
        history: JSON.stringify(history),
        model: selectedModel,
      });

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
        case 'status':
          statusEl.hidden = false;
          statusText.textContent = data.message || '';
          if (rawAnswer.trim()) {
            rawAnswer += '\n\n';
          }
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

    function renderTable(data, container) {
      var results = data.results || [];
      if (results.length === 0) return;

      var columns = Object.keys(results[0]);
      container.hidden = false;

      var sortCol = null;
      var sortAsc = true;

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

        // Meta row with count and CSV export.
        var meta = '<div class="nl-query-table-footer">';
        meta += '<span class="nl-query-table-meta">';
        meta += results.length + ' row' + (results.length !== 1 ? 's' : '');
        if (data.total_rows != null && data.total_rows > results.length) {
          meta += ' of ' + data.total_rows + ' total';
        }
        meta += '</span>';
        meta += '<button type="button" class="nl-query-csv-btn">Download CSV</button>';
        meta += '</div>';
        html += meta;

        container.innerHTML = html;

        // Sort handlers.
        container.querySelectorAll('th').forEach(function (th) {
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

        // CSV export handler.
        var csvBtn = container.querySelector('.nl-query-csv-btn');
        if (csvBtn) {
          csvBtn.addEventListener('click', function () {
            downloadCsv(columns, rows);
          });
        }
      }

      buildTable(results);
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

    function escapeHtml(str) {
      var div = document.createElement('div');
      div.textContent = str;
      return div.innerHTML;
    }
  }

})(Drupal, drupalSettings);
