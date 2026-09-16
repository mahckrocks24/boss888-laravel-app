<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public Arthur — /api/public/builder/arthur/chat
|--------------------------------------------------------------------------
| The marketing hero lets a visitor describe their business to Arthur BEFORE they have an account, because asking
| for the account first is asking for trust that has not been earned yet. Added 2026-09-08 on the Owner's flow:
| talk to Arthur, capture the summary, sign up, then press build.
|
| This route is chat() and NOTHING else. It never builds, never writes a website, and never spends credits —
| chat() uses its workspace id only for logging and for a build-data cache key, so anonymous callers pass 0 and
| the summary is returned to the browser rather than read back from that cache.
|
| The real exposure is the LLM call, so it is throttled per IP, the conversation is capped at eight exchanges, and
| the message length is capped. The build stays where it was: behind authentication, on the account the visitor
| creates, paid for by that workspace's own trial credits.
*/
Route::prefix('public/builder/arthur')->middleware('throttle:15,1')->group(function () {

    Route::post('/chat', function (\Illuminate\Http\Request $r) {
        $msg = trim((string) $r->input('message', ''));
        if ($msg === '') {
            return response()->json(['type' => 'error', 'message' => 'Tell Arthur a little about the business first.'], 422);
        }
        if (mb_strlen($msg) > 1200) {
            $msg = mb_substr($msg, 0, 1200);
        }

        $history = $r->input('history', []);
        if (! is_array($history)) {
            $history = [];
        }
        // Eight exchanges is more than Arthur has ever needed to get to a summary, and it stops a scripted client
        // holding an open-ended conversation at our expense.
        if (count($history) > 16) {
            return response()->json([
                'type'    => 'error',
                'message' => 'That is a longer conversation than this page can hold. Create your account and carry on in the builder.',
            ], 429);
        }
        $history = array_slice($history, -16);

        try {
            $arthur = new \App\Engines\Builder\Services\ArthurService();
            $result = $arthur->chat(0, $msg, $history);
            // The authenticated route decorates a confirmable reply with curated palettes; mirror that exactly,
            // or the hero's theme step would be empty where the app's is full.
            if (is_array($result) && !empty($result['build_data']['business_name'])) {
                $result['themes'] = $arthur->themesFor((array) $result['build_data'], 4);
                $result['themes_all'] = array_map(
                    fn ($t) => array_diff_key($t, ['moods' => 1, 'industries' => 1]),
                    \App\Engines\Builder\Support\ColorTheme::all()
                );
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[Arthur:public] chat failed', ['error' => $e->getMessage()]);
            return response()->json(['type' => 'error', 'message' => 'Arthur could not answer just then. Please try again.'], 503);
        }

        // Only what the page needs: Arthur's own words, whether he has enough, and the summary to carry across
        // the signup boundary. chat() calls its text field 'reply'; 'message' is kept alongside it so the page has one name.
        return response()->json([
            'type'             => $result['type'] ?? 'question',
            'message'          => $result['reply'] ?? '',
            'reply'            => $result['reply'] ?? '',
            'ready_to_confirm' => (bool) ($result['ready_to_confirm'] ?? false),
            'build_data'       => $result['build_data'] ?? null,
            'history'          => $result['history'] ?? null,
            // Step 3 of the finishing-touches panel is the theme picker; it renders these.
            'themes'           => $result['themes'] ?? null,
            'themes_all'       => $result['themes_all'] ?? null,
        ]);
    })->name('public.arthur.chat');

});
