<?php

namespace App\Http\Middleware;

use App\Models\ActivityLog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuditMutations
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->user() && ! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true) && $response->getStatusCode() < 400) {
            ActivityLog::create([
                'user_id' => $request->user()->id,
                'action' => strtolower($request->method()).':'.($request->route()?->getName() ?: $request->route()?->uri() ?: 'unknown'),
                'subject_type' => $request->route()?->parameterNames()[0] ?? null,
                'subject_id' => $this->subjectId($request),
                'metadata' => ['status' => $response->getStatusCode()],
                'ip' => $request->ip(),
                'created_at' => now(),
            ]);
        }

        return $response;
    }

    private function subjectId(Request $request): ?int
    {
        foreach ($request->route()?->parameters() ?? [] as $parameter) {
            if (is_object($parameter) && isset($parameter->id)) {
                return (int) $parameter->id;
            }
            if (is_numeric($parameter)) {
                return (int) $parameter;
            }
        }

        return null;
    }
}
