<?php

namespace ShopAgg\AI_Deployer\Domain;

final class WordPressGateway {

    public function discover(array $input): array {
        $namespace = trim((string) ($input['namespace'] ?? ''), '/');
        $routes = [];
        foreach (rest_get_server()->get_routes() as $route => $endpoints) {
            if (!$this->isAllowedRoute($route) || ($namespace !== '' && !str_starts_with(ltrim($route, '/'), $namespace . '/'))) {
                continue;
            }
            $methods = [];
            $arguments = [];
            foreach ($endpoints as $endpoint) {
                $methods = array_values(array_unique(array_merge($methods, array_keys(array_filter((array) ($endpoint['methods'] ?? []))))));
                foreach ((array) ($endpoint['args'] ?? []) as $name => $schema) {
                    $arguments[$name] = [
                        'type' => $schema['type'] ?? null,
                        'required' => !empty($schema['required']),
                        'description' => $schema['description'] ?? '',
                    ];
                }
            }
            $routes[] = ['route' => $route, 'methods' => $methods, 'args' => $arguments];
        }
        return ['success' => true, 'routes' => $routes, 'count' => count($routes)];
    }

    public function read(array $input): array {
        return $this->request('GET', $input);
    }

    public function write(array $input): array {
        $method = strtoupper((string) ($input['method'] ?? 'POST'));
        if (!in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            return $this->error('invalid_method', 'Write supports POST, PUT, and PATCH only.');
        }
        return $this->request($method, $input);
    }

    public function delete(array $input): array {
        return $this->request('DELETE', $input);
    }

    private function request(string $method, array $input): array {
        $route = '/' . ltrim((string) ($input['route'] ?? ''), '/');
        if (!$this->isAllowedRoute($route)) {
            return $this->error('route_not_allowed', 'A valid WordPress REST route is required.');
        }
        $request = new \WP_REST_Request($method, $route);
        $parameters = is_array($input['parameters'] ?? null) ? $input['parameters'] : [];
        if ($method === 'GET' || $method === 'DELETE') {
            $request->set_query_params($parameters);
        } else {
            $request->set_header('Content-Type', 'application/json');
            $request->set_body_params($parameters);
        }
        $response = rest_do_request($request);
        return [
            'success' => $response->get_status() >= 200 && $response->get_status() < 300,
            'status' => $response->get_status(),
            'route' => $route,
            'method' => $method,
            'data' => $response->get_data(),
            'headers' => $response->get_headers(),
        ];
    }

    private function isAllowedRoute(string $route): bool {
        $route = '/' . ltrim($route, '/');
        return $route !== '/' && !str_contains($route, "\0") && !str_contains($route, '..');
    }

    private function error(string $code, string $message): array {
        return ['success' => false, 'code' => $code, 'error' => $message];
    }
}
