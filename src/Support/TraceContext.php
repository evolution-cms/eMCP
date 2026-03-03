<?php

declare(strict_types=1);

namespace EvolutionCMS\eMCP\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class TraceContext
{
    public static function resolve(Request $request): string
    {
        $cached = trim((string)$request->attributes->get('emcp.trace_id', ''));
        if ($cached !== '') {
            return $cached;
        }

        $header = (string)config('cms.settings.eMCP.trace.header', 'X-Trace-Id');
        $incoming = trim((string)$request->headers->get($header, ''));

        if ($incoming !== '') {
            $request->attributes->set('emcp.trace_id', $incoming);
            return $incoming;
        }

        $shouldGenerate = (bool)config('cms.settings.eMCP.trace.generate_if_missing', true);

        if (!$shouldGenerate) {
            return '';
        }

        $generated = (string)Str::uuid();
        $request->attributes->set('emcp.trace_id', $generated);

        return $generated;
    }
}
