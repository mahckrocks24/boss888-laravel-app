<?php

namespace App\Engines\Write\Http\Controllers;

use App\Http\Controllers\Api\BaseEngineController;
use App\Engines\Write\Services\WriteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WriteController extends BaseEngineController
{
    public function __construct(private WriteService $write) {}
    protected function engineSlug(): string { return 'write'; }

    // READS
    public function listArticles(Request $r): JsonResponse { return $this->readJson($this->write->listArticles($this->wsId($r), $r->all())); }
    public function getArticle(Request $r, int $id): JsonResponse { return $this->readJson($this->write->getArticle($this->wsId($r), $id)); }
    public function getVersions(Request $r, int $id): JsonResponse { return $this->readJson(['versions' => $this->write->getVersions($id)]); }
    public function dashboard(Request $r): JsonResponse { return $this->readJson($this->write->getDashboard($this->wsId($r))); }

    // WRITES — through pipeline
    public function createArticle(Request $r): JsonResponse { $r->validate(['title' => 'required|string']); return $this->executeAction($r, 'create_article', $r->all(), 'manual', 201); }
    public function updateArticle(Request $r, int $id): JsonResponse { return $this->readJson($this->write->updateArticle($id, $r->all())); }
    public function deleteArticle(Request $r, int $id): JsonResponse { $this->write->deleteArticle($id); return $this->readJson(['success' => true]); }
    public function restoreVersion(Request $r, int $id, int $versionId): JsonResponse { return $this->readJson($this->write->restoreVersion($id, $versionId)); }
    public function writeArticle(Request $r): JsonResponse { return $this->executeAction($r, 'write_article', $r->all()); }
    public function improveDraft(Request $r): JsonResponse { return $this->executeAction($r, 'improve_draft', $r->all()); }
    public function generateOutline(Request $r): JsonResponse { return $this->executeAction($r, 'generate_outline', $r->all()); }
    public function generateHeadlines(Request $r): JsonResponse { return $this->executeAction($r, 'generate_headlines', $r->all()); }
    public function generateMeta(Request $r): JsonResponse { return $this->executeAction($r, 'generate_meta', $r->all()); }
}
