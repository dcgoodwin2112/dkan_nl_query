<?php

namespace Drupal\dkan_nl_query\Controller;

use Drupal\dkan_nl_query\Service\NlQueryService;
use Drupal\dkan_nl_query\Service\SchemaContextBuilder;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * SSE streaming endpoint for natural language queries.
 */
class NlQueryController {

  public function __construct(
    protected NlQueryService $nlQueryService,
    protected SchemaContextBuilder $schemaContextBuilder,
  ) {}

  /**
   * Handle a natural language query via SSE.
   */
  public function query(?string $dataset_id, Request $request): StreamedResponse|JsonResponse {
    $question = $request->request->get('question', '');
    if (empty(trim($question))) {
      return new JsonResponse(['error' => 'Question is required.'], 400);
    }

    // Decode conversation history if provided.
    $historyJson = $request->request->get('history', '[]');
    $history = json_decode($historyJson, TRUE) ?? [];

    // Optional model override from frontend selector.
    $model = $request->request->get('model') ?: NULL;

    // Normalize empty dataset_id to null for cross-dataset mode.
    $datasetId = !empty($dataset_id) ? $dataset_id : NULL;

    return new StreamedResponse(function () use ($datasetId, $question, $history, $model) {
      // Allow long-running agentic loops (multiple Claude API calls).
      set_time_limit(120);

      // Prevent buffering.
      while (ob_get_level()) {
        ob_end_clean();
      }

      $emit = function (string $type, mixed $data) {
        echo "event: {$type}\n";
        echo "data: " . json_encode($data) . "\n\n";
        flush();
      };

      try {
        $this->nlQueryService->query($datasetId, $question, $emit, $history, $model);
      }
      catch (\Throwable $e) {
        $emit('error', ['message' => 'Server error: ' . $e->getMessage()]);
      }

      echo "event: done\ndata: {}\n\n";
      flush();
    }, 200, [
      'Content-Type' => 'text/event-stream',
      'Cache-Control' => 'no-cache',
      'Connection' => 'keep-alive',
      'X-Accel-Buffering' => 'no',
    ]);
  }

  /**
   * Return list of datasets for the selector dropdown.
   */
  public function listDatasets(): JsonResponse {
    $catalog = $this->schemaContextBuilder->buildCatalogContext();
    return new JsonResponse($catalog['datasets'] ?? []);
  }

}
