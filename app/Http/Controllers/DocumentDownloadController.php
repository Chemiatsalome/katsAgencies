<?php

namespace App\Http\Controllers;

use App\Models\Document;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

/**
 * Every Document category can carry PII (tenant ID scans, signed leases,
 * payout/evidence files) and used to be served straight off the public disk
 * — a permanent, unauthenticated bearer-token URL. This route is the only
 * way a document is served now: it re-checks DocumentPolicy::view() against
 * the current session on every request, not just once at upload/list time.
 */
class DocumentDownloadController extends Controller
{
    public function show(Document $document)
    {
        Gate::authorize('view', $document);

        return Storage::disk('local')->response($document->path, $document->original_filename);
    }
}
