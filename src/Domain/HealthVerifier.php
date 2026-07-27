<?php

namespace ShopAgg\AI_Deployer\Domain;

final class HealthVerifier {

    public function verify(array $paths = []): array {
        $paths = array_values(array_unique(array_merge(['/'], $paths)));
        $paths = array_slice($paths, 0, 10);
        $results = [];
        $ok = true;

        foreach ($paths as $path) {
            $path = '/' . ltrim((string) $path, '/');
            if (str_contains($path, "\0") || str_starts_with($path, '//')) {
                $results[] = ['path' => $path, 'ok' => false, 'error' => 'Invalid health-check path.'];
                $ok = false;
                continue;
            }
            $result = $this->probe(home_url($path));
            $result['path'] = $path;
            $results[] = $result;
            $ok = $ok && !empty($result['ok']);
        }

        return [
            'ok' => $ok,
            'checked_at' => gmdate('c'),
            'probes' => $results,
        ];
    }

    private function probe(string $url): array {
        $started = microtime(true);
        $response = wp_remote_get(add_query_arg('shopagg_health', (string) microtime(true), $url), [
            'timeout' => 15,
            'sslverify' => true,
            'redirection' => 3,
            'headers' => ['Cache-Control' => 'no-cache'],
        ]);
        if (is_wp_error($response)) {
            return [
                'ok' => false,
                'status_code' => 0,
                'error' => $response->get_error_message(),
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            ];
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        $body = strtolower((string) wp_remote_retrieve_body($response));
        $marker = null;
        foreach ([
            'fatal error',
            'parse error',
            'uncaught error',
            'there has been a critical error',
            'error establishing a database connection',
        ] as $candidate) {
            if (str_contains($body, $candidate)) {
                $marker = $candidate;
                break;
            }
        }
        return [
            'ok' => $status >= 200 && $status < 400 && $marker === null,
            'status_code' => $status,
            'fatal_marker' => $marker,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
        ];
    }
}
