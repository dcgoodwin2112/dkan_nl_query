# Feature Backlog

Research based on ThoughtSpot Sage, Databricks Genie, Snowflake Cortex Analyst, Vanna.ai, WrenAI, Chat2DB, Amazon Q, Google Looker, and other NL data query tools.

## Current Strengths

Streaming SSE responses, multi-turn agentic loop with tool use, Vega-Lite charting, interactive sortable tables, CSV export, API call transparency, conversation persistence with sidebar, cross-dataset discovery mode, configurable admin form. Debug panel with tool call visibility is a differentiator.

## Tier 1: High Impact, Moderate Effort

### 1. Suggested Follow-Up Questions
After each answer, show 2-3 clickable follow-up suggestions (e.g., "Break this down by region", "Show as a chart", "What are the outliers?").

- **Seen in**: ThoughtSpot, Databricks Genie, Amazon Q, Google Looker
- **Why**: Lowers the barrier for non-technical users who don't know what to ask next. Helps discover data incrementally.
- **Implementation**: LLM generates 2-3 suggestions with each response. Emit as new SSE event type, render as clickable chips below the answer.

### 2. Query Interpretation / Transparency Bar
Show users how their question was interpreted — e.g., "Filtering smokers > 20%, sorted by smokers descending, showing state and smokers columns."

- **Seen in**: ThoughtSpot (search verification), Amazon Q (query interpretation), Tableau Ask Data
- **Why**: Builds trust. Users catch misinterpretations before acting on results. Important for open data portals where data literacy varies.
- **Implementation**: Already partially there via debug panel. Surface a user-facing summary line above the table, derived from tool call input.

### 3. Chart Type Refinement Controls
After a chart renders, offer buttons to switch chart type (bar/line/pie/scatter) or change axes without re-querying.

- **Seen in**: WrenAI, Google Looker, QuickSight
- **Why**: Currently users must type "show that as a bar chart." Direct controls are faster and more discoverable.
- **Implementation**: Store underlying data with chart spec. Render chart type buttons that regenerate Vega-Lite spec client-side with same data but different mark type.

### 4. Type-Ahead / Autocomplete Suggestions
As user types, suggest column names, dataset names, or common question patterns.

- **Seen in**: Amazon Q, ThoughtSpot, Tableau Ask Data
- **Why**: Helps users form valid questions and discover available data fields. Reduces "what can I ask?" uncertainty.
- **Implementation**: Fetch schema context on widget load (already cached by SchemaContextBuilder). Match typed words against column names/descriptions and dataset titles.

## Tier 2: Medium Impact, Lower Effort

### 5. Natural Language Summary of Results
After table/chart results, include a brief narrative (e.g., "Kentucky has the highest smoking rate at 28.2%. 22 states exceed 20%.").

- **Seen in**: ThoughtSpot Sage, Tableau Pulse, Snowflake Cortex Analyst
- **Why**: Not all users can quickly interpret tables or charts. A narrative bridges the gap.
- **Implementation**: Prompt engineering — instruct LLM to always provide a brief insight summary when returning data results.

### 6. Export Options Beyond CSV
Add JSON export and copy-table-as-markdown for pasting into documents.

- **Seen in**: Chat2DB, WrenAI (spreadsheet export), SQLChat
- **Why**: CSV for spreadsheets, JSON for developers, markdown for reports/docs.
- **Implementation**: Add buttons alongside "Download CSV" — data already available in JS as array of objects.

### 7. Shareable Query Links
Generate a permalink for a specific query + results that can be shared with colleagues.

- **Seen in**: Mode Analytics, ThoughtSpot
- **Why**: Open data portals are collaborative. "Look at this finding" is a common workflow.
- **Implementation**: Encode query params in URL hash or generate short link backed by conversation entity.

### 8. Feedback Thumbs Up/Down
Per-response feedback buttons to flag good/bad answers.

- **Seen in**: ThoughtSpot Sage Coach, Mode AI Assist, Databricks Genie
- **Why**: Identifies where the LLM struggles. Feeds into prompt refinement. Signals to users the system is improving.
- **Implementation**: Add feedback buttons to assistant bubble footer. Store in message entity. Admin view to review flagged responses.

## Tier 3: Ambitious / Future Roadmap

### 9. File Upload for Ad-Hoc Analysis
Let users upload a CSV/Excel file and query it alongside existing datasets.

- **Seen in**: Databricks Genie, DataLine
- **Why**: Users often want to compare their own data against published open data.
- **Complexity**: High — temporary datastore import, cleanup, security considerations.

### 10. Benchmark / Accuracy Testing
Admin tool to define expected Q&A pairs and measure answer accuracy over time.

- **Seen in**: Databricks Genie (Genie Benchmarks)
- **Why**: Validates that prompt changes, model upgrades, or schema changes don't degrade quality.
- **Complexity**: Medium — could be a Drush command that runs test queries and compares results.

### 11. Dashboard / Pinned Insights
Let users pin individual query results to a personal dashboard.

- **Seen in**: WrenAI, Chat2DB
- **Why**: Turns one-off queries into reusable monitoring views.
- **Complexity**: High — new entity type, scheduled refresh, layout management.

### 12. Multi-Language Support
Accept questions in languages other than English.

- **Seen in**: Most commercial tools via LLM capability
- **Why**: Government open data portals serve multilingual populations.
- **Complexity**: Low for LLM layer (already capable), medium for UI localization.

## Not Pursuing

- **Local/private model support**: API key approach fits institutional deployment.
- **SQL display**: REST API equivalent is more useful for DKAN users.
- **Code interpreter / Python execution**: Overkill for open data portal context. Datastore query expressions/aggregations cover needed analysis.

## Recommended Next Steps

1. **Suggested follow-up questions** — highest UX impact for lowest effort
2. **Query interpretation bar** — builds trust, leverages existing data
3. **Feedback thumbs up/down** — low effort, high value for prompt iteration
