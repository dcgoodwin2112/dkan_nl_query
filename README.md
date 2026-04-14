# DKAN Natural Language Query

Drupal module that adds a chat-style natural language interface for querying DKAN datasets. Users type questions in plain English, an LLM translates them into structured datastore queries via dkan_mcp tool services, and results stream back as interactive tables with AI-generated summaries. Supports Anthropic Claude and OpenAI GPT models.

## Requirements

- Drupal 10.2+ or 11
- `dkan_mcp` module enabled
- At least one LLM SDK installed:
  - Anthropic: `anthropic-ai/sdk` (for Claude models)
  - OpenAI: `openai-php/client` (for GPT models)
- At least one API key configured

## Installation

```bash
# Install LLM SDKs (one or both)
composer require anthropic-ai/sdk
composer require openai-php/client

# Enable the module
drush en dkan_nl_query

# Configure API keys
# Visit /admin/config/dkan/nl-query
```

## Configuration

Admin form at `/admin/config/dkan/nl-query`:

| Setting | Description | Default |
|---|---|---|
| `provider` | Default LLM provider (Anthropic or OpenAI) | `anthropic` |
| `anthropic_api_key` | Anthropic API key (max 256 chars) | — |
| `openai_api_key` | OpenAI API key (max 256 chars) | — |
| `model` | Default model ID | `claude-sonnet-4-20250514` |
| `max_tokens` | Max tokens per LLM response (256–8192) | `4096` |

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
- Data tables are interactive: click column headers to sort, use "Download CSV" to export
- Dataset selector locks after first query; click "New conversation" to reset
- Switch models between turns using the model selector

### Example Questions

- "Show me the first 5 rows"
- "Which states have smoking rates above 20%?"
- "What's the average tax rate?"
- "Sort by population descending"
- "What datasets do you have about health?" (cross-dataset mode)

## API Endpoints

| Method | Path | Parameters | Response |
|---|---|---|---|
| POST | `/api/nl-query/{dataset_id}` | `question` (required), `history` (JSON), `model` | SSE stream |
| POST | `/api/nl-query` | `question` (required), `history` (JSON), `model` | SSE stream (cross-dataset) |
| GET | `/api/nl-query-datasets` | — | JSON array of datasets |

All endpoints require `access content` permission. The settings form requires `administer site configuration`.

**SSE event types:**

| Event | Data | Description |
|---|---|---|
| `status` | `{message}` | Progress indicator ("Thinking...", "Querying data: query_datastore...") |
| `token` | `{text}` | Streaming text token from the LLM |
| `data` | `{results, total_rows, ...}` | Query results for interactive table rendering |
| `error` | `{message}` | Error message |
| `done` | `{}` | Stream complete |

## Architecture

```
NlQueryController (SSE endpoint)
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
            └─ SearchTools::searchDatasets()
```

### Agentic Loop

The `NlQueryService` runs an iterative loop (max 5 rounds):

1. Build system prompt with schema context (cached 1 hour)
2. Send question + conversation history to LLM with tool definitions
3. Stream text tokens to client as SSE events
4. If LLM returns `tool_use`: execute the tool via `ToolExecutor`, send results back to LLM, continue
5. If LLM returns `end_turn`: done

Each LLM call is streamed — text appears token-by-token in the browser while the LLM generates.

## dkan_mcp Integration

This module consumes dkan_mcp tool classes as **Drupal services via dependency injection** — it does not use the MCP protocol (no JSON-RPC, no HTTP/stdio transport). The same PHP methods that power the MCP tools are called directly, avoiding serialization overhead.

### Services Consumed

| dkan_mcp Service | Used By | Methods Called |
|---|---|---|
| `dkan_mcp.tools.metastore` | SchemaContextBuilder, ToolExecutor | `getDataset()`, `listDistributions()`, `listDatasets()` |
| `dkan_mcp.tools.datastore` | SchemaContextBuilder, ToolExecutor | `queryDatastore()`, `queryDatastoreJoin()`, `getDatastoreSchema()`, `getDatastoreStats()`, `getImportStatus()`, `searchColumns()` |
| `dkan_mcp.tools.search` | ToolExecutor | `searchDatasets()` |

### Tool Mapping

dkan_mcp methods are exposed to the LLM as callable tools. The LLM sees tool definitions with names, descriptions, and JSON Schema input parameters. When it decides to call a tool, `ToolExecutor` routes the call to the correct dkan_mcp method.

**Query mode tools** (single-dataset, 6 tools):

| LLM Tool Name | dkan_mcp Method | Purpose |
|---|---|---|
| `query_datastore` | `DatastoreTools::queryDatastore()` | Filter, sort, paginate, aggregate data |
| `query_datastore_join` | `DatastoreTools::queryDatastoreJoin()` | Join two resources on a shared column |
| `get_datastore_schema` | `DatastoreTools::getDatastoreSchema()` | Discover columns and types |
| `get_datastore_stats` | `DatastoreTools::getDatastoreStats()` | Column statistics (distinct, null, min/max) |
| `search_columns` | `DatastoreTools::searchColumns()` | Find columns by name across all resources |
| `get_import_status` | `DatastoreTools::getImportStatus()` | Check if a resource is queryable |

**Discovery mode tools** (cross-dataset, adds 3 more):

| LLM Tool Name | dkan_mcp Method | Purpose |
|---|---|---|
| `search_datasets` | `SearchTools::searchDatasets()` | Find datasets by keyword |
| `list_datasets` | `MetastoreTools::listDatasets()` | Browse all datasets |
| `list_distributions` | `MetastoreTools::listDistributions()` | Get resource_ids for a dataset |

### Schema Context

`SchemaContextBuilder` uses dkan_mcp services to build rich context for the LLM's system prompt:

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
| `claude-sonnet-4-20250514` | Anthropic | Claude Sonnet 4 |
| `claude-haiku-4-5-20251001` | Anthropic | Claude Haiku 4.5 |
| `gpt-4o` | OpenAI | GPT-4o |
| `gpt-4o-mini` | OpenAI | GPT-4o Mini |

### Provider Selection

`LlmProviderFactory` infers the provider from the model ID prefix:
- `claude-` → `AnthropicProvider`
- `gpt-`, `o1-`, `o3-`, `o4-` → `OpenAiProvider`
- Unknown prefix → falls back to configured default provider

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

**Key features:**
- **Chat thread** — scrollable message history with user (blue) and assistant (grey) bubbles
- **SSE streaming** — text appears token-by-token as the LLM generates
- **Markdown rendering** — responses parsed via marked.js (raw HTML stripped for XSS safety)
- **Interactive data tables** — sortable columns, row count, CSV export button
- **Model selector** — grouped dropdown (Anthropic / OpenAI) with optgroup labels
- **Dataset selector** — dropdown populated from `/api/nl-query-datasets`, locks after first query
- **Conversation context** — follow-up questions include last 10 history turns
- **Copy button** — copies AI answer text to clipboard
- **Textarea input** — auto-growing, Enter to submit, Shift+Enter for newline
- **New conversation** — clears thread, history, unlocks dataset selector
