<?php

namespace App\Http\Middleware;

use App\Models\ActivityLog;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class AuditMutation
{
    /**
     * @throws \Throwable
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)
            || $request->is('api/reports/export')) {
            return $next($request);
        }

        try {
            $response = $next($request);
        } catch (\Throwable $exception) {
            $this->record($request, $this->statusForException($exception));

            throw $exception;
        }

        $this->record($request, $response->getStatusCode());

        return $response;
    }

    private function record(Request $request, int $statusCode): void
    {
        try {
            [$subjectType, $subjectId] = $this->subject($request);
            ActivityLog::query()->create([
                'user_id' => $request->user()?->getAuthIdentifier(),
                'action' => $this->action($request),
                'method' => $request->method(),
                'path' => Str::limit('/'.$request->path(), 512, ''),
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'payload' => $this->sanitizedPayload($request->all()),
                'status_code' => min(599, max(100, $statusCode)),
                'ip_address' => $request->ip(),
                'user_agent' => Str::limit(
                    preg_replace('/[\x00-\x1F\x7F]/u', '', (string) $request->userAgent()) ?? '',
                    512,
                    ''
                ) ?: null,
            ]);
        } catch (\Throwable $exception) {
            // Auditing must not change the outcome of the administrator's
            // request. Database/logging failures still reach the error log.
            report($exception);
        }
    }

    /** @return array{0: ?string, 1: ?int} */
    private function subject(Request $request): array
    {
        foreach ($request->route()?->parameters() ?? [] as $parameter) {
            if ($parameter instanceof Model) {
                return [$parameter::class, (int) $parameter->getKey()];
            }
        }

        return [null, null];
    }

    private function action(Request $request): string
    {
        $uses = (string) ($request->route()?->getActionName() ?? 'unknown');
        if (! str_contains($uses, '@')) {
            return Str::limit($uses, 160, '');
        }

        [$controller, $method] = explode('@', $uses, 2);

        return Str::limit(class_basename($controller).'.'.$method, 160, '');
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function sanitizedPayload(array $payload): array
    {
        $sanitized = [];
        foreach ($payload as $key => $value) {
            if (preg_match('/password|token|secret|authorization|api[_-]?key|photo|file/i', (string) $key)) {
                $sanitized[$key] = '[redacted]';

                continue;
            }

            if (is_array($value)) {
                $sanitized[$key] = $this->sanitizedPayload($value);
            } elseif (is_string($value) || is_numeric($value) || is_bool($value) || $value === null) {
                $sanitized[$key] = $value;
            } else {
                $sanitized[$key] = '[redacted]';
            }
        }

        return $sanitized;
    }

    private function statusForException(\Throwable $exception): int
    {
        return match (true) {
            $exception instanceof HttpExceptionInterface => $exception->getStatusCode(),
            $exception instanceof HttpResponseException => $exception->getResponse()->getStatusCode(),
            $exception instanceof ValidationException => $exception->status,
            $exception instanceof AuthenticationException => 401,
            $exception instanceof AuthorizationException => 403,
            default => 500,
        };
    }
}
