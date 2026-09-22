<?php

/*
|--------------------------------------------------------------------------
| /api/catalogue — the catalogue as a section of the app (CAT-2, Owner 2026-09-22)
|--------------------------------------------------------------------------
| "Remember the catalogue section on builder editor? We need that exposed outside too on both Basic and Advanced.
|  It should be named according to the industry it is being used so it is dynamic."
| Required from routes/api.php inside the authenticated group. The per-website catalogue endpoints stay where they
| are (/api/builder/websites/{id}/catalogue…); this file adds the workspace-level summary the section and the
| sidebar need: every website's catalogue kinds with their industry vocabulary and counts, and the label the
| sidebar shows (one vocabulary → that word; several → "Catalogue"; none → the section is hidden).
*/

Route::get('/catalogue/summary', function (\Illuminate\Http\Request $r) {
    $wsId = (int) $r->attributes->get('workspace_id');
    return response()->json(\App\Engines\Builder\Support\CatalogueSummary::forWorkspace($wsId));
});
