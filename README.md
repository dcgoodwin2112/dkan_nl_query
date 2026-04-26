# DKAN Natural Language Query

Drupal module that adds a chat-style natural language interface for querying DKAN datasets. Users type questions in plain English, an LLM translates them into structured datastore queries via `dkan_query_tools` services, and results stream back as interactive tables, Vega-Lite charts, and AI-generated summaries. Supports Anthropic Claude and OpenAI GPT models.

## Requirements

- Drupal 10.2+ or 11
- `dkan_query_tools` module enabled
- At least one LLM SDK installed:
  - Anthropic: `anthropic-ai/sdk` (for Claude models)
  - OpenAI: `openai-php/client` (for GPT models)
- At least one API key configured

## Installation

1. Install the `dkan_query_tools` module first — it provides the catalog/datastore/search tool classes that this module's `ToolExecutor` and `SchemaContextBuilder` depend on. See [dkan_query_tools README](../dkan_query_tools/README.md) for full instructions; in short:

   ```bash
   # Add to composer.json then:
   composer update dcgoodwin2112/dkan_query_tools
   drush en dkan_query_tools
   ```

2. Install at least one LLM SDK and enable this module:

   ```bash
   composer require anthropic-ai/sdk      # for Claude models
   composer require openai-php/client     # for GPT models (optional)

   drush en dkan_nl_query
   ```

3. Configure API keys at `/admin/config/dkan/nl-query`.

Drupal will auto-enable `dkan_query_tools` as a dependency if it isn't already enabled, but the Composer step in (1) must happen first so the package is on disk.

## Configuration

Admin form at `/admin/config/dkan/nl-query`, organized into three sections:

### API Keys

| Setting | Description | Default |
|---|---|---|
| `anthropic_api_key` | Anthropic API key | — |
| `openai_api_key` | OpenAI API key | — |

### Model & Generation

| Setting | Description | Default |
|---|---|---|
| `model` | Default model ID | `claude-haiku-4-5` |
| `max_tokens` | Max tokens per LLM response (256–8192) | `4096` |
| `max_iterations` | Max agentic loop iterations per query (1–20) | `10` |

### Widget Display

| Setting | Description | Default |
|---|---|---|
| `show_model_selector` | Allow users to choose a model in the widget | `true` |
| `show_examples` | Display example question buttons | `true` |
| `show_debug_panel` | Show collapsible tool calls debug panel | `false` |
| `save_chat_history` | Save conversations for authenticated users | `true` |

## Usage

### Placing the Block

Add the **DKAN Natural Language Query** block to any page via Drupal's block layout (`/admin/structure/block`).

**Block configuration:**
- **Dataset UUID**: Pre-scope the block to a single dataset. Leave empty to show a dataset selector dropdown that lets users choose or query across all datasets.

### Query Modes

**Single-dataset mode** (dataset UUID configured or selected from dropdown):
- Schema context is pre-built — the LLM knows all columns, types, sample values, and stats
- Only query tools available (fast, focused)

**Cross-dataset mode** (no dataset selected, "All datasets" option):
- LLM gets a catalog of all datasets with descriptions, keywords, and themes
- Discovery tools available: search datasets, search columns, list distributions
- LLM finds the right dataset, gets its schema, then queries

### Chat Interface

- Type a question and press Enter (Shift+Enter for newline)
- Previous Q&A pairs stay visible in the thread as chat bubbles
- Each response shows the model used and has a copy button
- **Charts**: Vega-Lite visualizations rendered inline when the LLM determines a chart would help
- **Data tables**: Collapsed by default with a summary bar showing row count, "Show table" toggle, and CSV export
- Dataset selector locks after first query; click "New conversation" to reset
- Switch models between turns using the model selector

### Chat History

Authenticated users get persistent chat history stored as Drupal entities:

- **Sidebar**: Always-visible panel showing saved conversations with search, dataset labels, and pinned conversations on top
- **Full state recall**: Loading a conversation restores text, charts, tables, and debug panel entries
- **Pin/unpin**: Star icon on each conversation to pin important chats to the top
- **Delete**: Trash icon with confirmation prompt
- **Auto-save**: Conversations are saved automatically after each exchange

Requires the `manage own nl query conversations` permission.

### Debug Panel

When enabled (`show_debug_panel`), a collapsible "Tool calls" panel appears below the chat showing:

- Tool name, agentic loop step number, and execution duration
- Full input arguments as formatted JSON
- For `query_datastore` calls: the equivalent DKAN REST API request (copy-pasteable)

### Example Questions

- "Show me the first 5 rows"
- "Which states have smoking rates above 20%?"
- "What's the average tax rate?"
- "Sort by population descending"
- "What datasets do you have about health?" (cross-dataset mode)

## API Endpoints

### Query Endpoints

| Method | Path | Parameters | Response |
|---|---|---|---|
| POST | `/api/nl-query/{dataset_id}` | `question` (required), `history` (JSON), `model`, `conversation_id` | SSE stream |
| POST | `/api/nl-query` | `question` (required), `history` (JSON), `model`, `conversation_id` | SSE stream (cross-dataset) |
| GET | `/api/nl-query-datasets` | — | JSON array of datasets |

All query endpoints require `access content` permission.

### History Endpoints

| Method | Path | Permission | Response |
|---|---|---|---|
| GET | `/api/nl-query/conversations` | `manage own nl query conversations` | JSON array of user's conversations |
| GET | `/api/nl-query/conversations/{id}` | `manage own nl query conversations` | JSON conversation with all messages |
| DELETE | `/api/nl-query/conversations/{id}` | `manage own nl query conversations` | `{status: "deleted"}` |
| PATCH | `/api/nl-query/conversations/{id}/pin` | `manage own nl query conversations` | `{pinned: true/false}` |

### SSE Event Types

| Event | Data | Description |
|---|---|---|
| `status` | `{message}` | Progress indicator ("Thinking...", "Querying data: query_datastore...") |
| `token` | `{text}` | Streaming text token from the LLM |
| `data` | `{results, total_rows}` | Query results for table rendering |
| `chart` | `{spec}` | Vega-Lite v5 spec for inline chart |
| `tool_call` | `{name, input, duration_ms, iteration, is_error}` | Debug info for tool calls panel |
| `conversation` | `{id, title}` | Conversation entity ID after save |
| `error` | `{message}` | Error message |
| `done` | `{}` | Stream complete |

## Architecture

```
NlQueryController (SSE endpoint)
  ├─ Saves NlQueryConversation + NlQueryMessage entities
  └─ NlQueryService (agentic loop)
       ├─ SchemaContextBuilder (schema + system prompt)
       │    ├─ MetastoreTools::getDataset()
       │    ├─ MetastoreTools::listDistributions()
       │    ├─ DatastoreTools::getImportStatus()
       │    ├─ DatastoreTools::getDatastoreSchema()
       │    ├─ DatastoreTools::getDatastoreStats()
       │    └─ DatastoreTools::queryDatastore() (sample values)
       │
       ├─ LlmProviderFactory → AnthropicProvider | OpenAiProvider
       │    └─ LlmProviderInterface::stream() → LLM API (streaming)
       │
       └─ ToolExecutor (when LLM returns tool_use)
            ├─ DatastoreTools::queryDatastore()
            ├─ DatastoreTools::queryDatastoreJoin()
            ├─ DatastoreTools::getDatastoreSchema()
            ├─ DatastoreTools::getDatastoreStats()
            ├─ DatastoreTools::getImportStatus()
            ├─ DatastoreTools::searchColumns()
            ├─ MetastoreTools::listDatasets()
            ├─ MetastoreTools::listDistributions()
            ├─ SearchTools::searchDatasets()
            └─ create_chart → emits Vega-Lite spec to frontend

NlQueryHistoryController (REST API)
  └─ CRUD for NlQueryConversation + NlQueryMessage entities
```

### Entities

| Entity Type | Table | Purpose |
|---|---|---|
| `nl_query_conversation` | `nl_query_conversations` | Conversation metadata: title, user, dataset, pinned status |
| `nl_query_message` | `nl_query_messages` | Individual messages with role, content, chart spec, table data, tool calls |

### Agentic Loop

The `NlQueryService` runs an iterative loop (max configurable, default 10 rounds):

1. Build system prompt with schema context (cached 1 hour)
2. Send question + conversation history to LLM with tool definitions
3. Stream text tokens to client as SSE events
4. If LLM returns `tool_use`: execute the tool via `ToolExecutor`, send results back to LLM, continue
5. If LLM returns `end_turn`: done
6. Return collected answer, chart spec, table data, and tool calls for persistence

Each LLM call is streamed — text appears token-by-token in the browser while the LLM generates.

## dkan_query_tools Integration

This module consumes `dkan_query_tools` tool classes as **Drupal services via dependency injection** — the same shared library that powers `dkan_mcp` and `dkan_drupal_ai_query`. It does not use the MCP protocol (no JSON-RPC, no HTTP/stdio transport).

### Services Consumed

| Service | Used By | Methods Called |
|---|---|---|
| `dkan_query_tools.metastore` | SchemaContextBuilder, ToolExecutor | `getDataset()`, `listDistributions()`, `listDatasets()` |
| `dkan_query_tools.datastore` | SchemaContextBuilder, ToolExecutor | `queryDatastore()`, `queryDatastoreJoin()`, `getDatastoreSchema()`, `getDatastoreStats()`, `getImportStatus()`, `searchColumns()` |
| `dkan_query_tools.search` | ToolExecutor | `searchDatasets()` |

### Tool Mapping

Tool methods are exposed to the LLM as callable tools. The LLM sees tool definitions with names, descriptions, and JSON Schema input parameters. When it decides to call a tool, `ToolExecutor` routes the call to the correct method on the corresponding `dkan_query_tools` service.

**Query mode tools** (single-dataset, 7 tools):

| LLM Tool Name | Method | Purpose |
|---|---|---|
| `query_datastore` | `DatastoreTools::queryDatastore()` | Filter, sort, paginate, aggregate data |
| `query_datastore_join` | `DatastoreTools::queryDatastoreJoin()` | Join two resources on a shared column |
| `get_datastore_schema` | `DatastoreTools::getDatastoreSchema()` | Discover columns and types |
| `get_datastore_stats` | `DatastoreTools::getDatastoreStats()` | Column statistics (distinct, null, min/max) |
| `search_columns` | `DatastoreTools::searchColumns()` | Find columns by name across all resources |
| `create_chart` | (frontend) | Render Vega-Lite visualization from query results |

**Discovery mode tools** (cross-dataset, adds 4 more):

| LLM Tool Name | Method | Purpose |
|---|---|---|
| `search_datasets` | `SearchTools::searchDatasets()` | Find datasets by keyword |
| `list_datasets` | `MetastoreTools::listDatasets()` | Browse all datasets |
| `list_distributions` | `MetastoreTools::listDistributions()` | Get resource_ids for a dataset |
| `find_dataset_resources` | (internal) | Find dataset by title and return resource_ids |

### Schema Context

`SchemaContextBuilder` uses `dkan_query_tools` services to build rich context for the LLM's system prompt:

1. **Dataset metadata** — title, description, keywords, themes (from `MetastoreTools::getDataset()`)
2. **Import filtering** — only includes resources with status `done` (from `DatastoreTools::getImportStatus()`)
3. **Column inventory** — names, types, descriptions (from `DatastoreTools::getDatastoreSchema()`)
4. **Column statistics** — distinct count, null count, min/max (from `DatastoreTools::getDatastoreStats()`)
5. **Sample values** — 3 example values per column (from `DatastoreTools::queryDatastore()` with `limit: 5`)
6. **Catalog** — all datasets with keywords/themes for cross-dataset discovery (from `MetastoreTools::listDatasets()`)

Context is cached for 1 hour per dataset (`dkan_nl_query:context:{dataset_id}`) and per catalog (`dkan_nl_query:catalog`).

## LLM Providers

| Model ID | Provider | Label |
|---|---|---|
| `claude-opus-4-6` | Anthropic | Claude Opus 4.6 |
| `claude-sonnet-4-6` | Anthropic | Claude Sonnet 4.6 |
| `claude-haiku-4-5` | Anthropic | Claude Haiku 4.5 |
| `gpt-5.4` | OpenAI | GPT-5.4 |
| `gpt-5.4-mini-2026-03-17` | OpenAI | GPT-5.4 Mini |
| `gpt-5.4-nano-2026-03-17` | OpenAI | GPT-5.4 Nano |

### Provider Selection

`LlmProviderFactory` infers the provider from the model ID prefix:
- `claude-` → `AnthropicProvider`
- `gpt-`, `o1-`, `o3-`, `o4-` → `OpenAiProvider`
- Unknown prefix → defaults to Anthropic

### Provider Interface

Both providers implement `LlmProviderInterface::stream()`, which handles client creation, API calls, streaming event parsing, tool format conversion, and stop reason normalization. Tool definitions are maintained in Anthropic format (canonical) — `OpenAiProvider` converts to OpenAI's `function` wrapper format internally.

### Adding a New Provider

1. Create `src/Llm/NewProvider.php` implementing `LlmProviderInterface`
2. Add model prefix mapping in `LlmProviderFactory::MODEL_PROVIDERS`
3. Add `createNewProvider()` method and match case in `LlmProviderFactory::createForModel()`
4. Add models to `NlQueryBlock::MODELS` and `NlQuerySettingsForm` options
5. Add API key field to settings form and config schema

## Frontend

The widget is a Drupal block rendering `nl-query-widget.html.twig` with attached JS/CSS.

**Layout:** Sidebar (280px, always visible for authenticated users) + main chat area. Widget expands to 1180px when sidebar is active, 900px without.

**Key features:**
- **Chat thread** — scrollable message history with user (blue) and assistant (grey) bubbles
- **SSE streaming** — text appears token-by-token as the LLM generates
- **Markdown rendering** — responses parsed via marked.js (raw HTML stripped for XSS safety)
- **Vega-Lite charts** — inline visualizations with export actions, auto-sized to bubble width
- **Interactive data tables** — collapsed by default with row count, sortable columns, CSV export
- **Chat history sidebar** — searchable conversation list with dataset labels, pin/delete, active indicator
- **Debug panel** — collapsible tool call log with args and API equivalents (admin-configurable)
- **Model selector** — grouped dropdown (Anthropic / OpenAI) with optgroup labels
- **Dataset selector** — dropdown populated from `/api/nl-query-datasets`, locks after first query
- **Conversation context** — follow-up questions include last 10 history turns
- **Copy button** — copies AI answer text to clipboard
- **Textarea input** — auto-growing, Enter to submit, Shift+Enter for newline
- **New conversation** — clears thread, history, unlocks dataset selector

**External libraries** (loaded via CDN):
- marked.js v5 — Markdown parsing
- Vega v5, Vega-Lite v5, Vega-Embed v6 — Chart rendering
