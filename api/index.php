<?php

header('Content-Type: application/json; charset=utf-8');

$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/pix.php';

$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
header('Access-Control-Allow-Origin: ' . ($config['cors_origins'][0] === '*' ? '*' : $origin));
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$store = new Store(__DIR__ . '/data/db.json');

$path = $_GET['r'] ?? '';
$path = trim((string) $path, '/');
$method = $_SERVER['REQUEST_METHOD'];
$body = json_decode(file_get_contents('php://input') ?: '{}', true);
if (!is_array($body)) {
    $body = [];
}
if (isset($body['_method'])) {
    $method = strtoupper((string) $body['_method']);
} elseif (isset($_GET['_method'])) {
    $method = strtoupper((string) $_GET['_method']);
}

try {
    match (true) {
        $method === 'GET' && $path === 'guests/search' => guest_search($store),
        $method === 'POST' && $path === 'confirmations' => confirmation_store($store, $body),
        $method === 'GET' && $path === 'gifts' => gifts_index($store, $config),
        $method === 'GET' && preg_match('#^gifts/(\d+)/pix$#', $path, $m) => gift_pix($store, $config, (int) $m[1]),
        $method === 'POST' && preg_match('#^gifts/(\d+)/claim$#', $path, $m) => gift_claim($store, $body, (int) $m[1]),
        $method === 'POST' && $path === 'admin/login' => admin_login($store, $config, $body),
        $method === 'POST' && $path === 'admin/logout' => admin_logout($store),
        $method === 'GET' && $path === 'admin/dashboard' => admin_dashboard($store, $config),
        $method === 'POST' && $path === 'admin/guests' => admin_guest_store($store, $body),
        $method === 'DELETE' && preg_match('#^admin/guests/(\d+)$#', $path, $m) => admin_guest_delete($store, (int) $m[1]),
        $method === 'POST' && $path === 'admin/gifts' => admin_gift_store($store, $body),
        $method === 'PUT' && preg_match('#^admin/gifts/(\d+)$#', $path, $m) => admin_gift_update($store, $body, (int) $m[1]),
        $method === 'DELETE' && preg_match('#^admin/gifts/(\d+)$#', $path, $m) => admin_gift_delete($store, (int) $m[1]),
        default => json_error('Rota não encontrada.', 404),
    };
} catch (Throwable $e) {
    json_error($e->getMessage(), 500);
}

function json_ok($data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function json_error(string $message, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function normalize(string $text): string
{
    if (class_exists('Normalizer')) {
        $text = Normalizer::normalize($text, Normalizer::FORM_D) ?: $text;
    } else {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        if ($converted !== false) {
            $text = $converted;
        }
    }
    $text = preg_replace('/\p{Mn}/u', '', $text) ?? $text;
    return mb_strtolower(trim($text));
}

function bearer_token(): ?string
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/Bearer\s+(\S+)/i', $header, $m)) {
        return $m[1];
    }
    return null;
}

function require_admin(Store $store): void
{
    $token = bearer_token();
    if (!$token) {
        json_error('Não autorizado.', 401);
    }
    $data = $store->all();
    $entry = $data['tokens'][$token] ?? null;
    if (!$entry || strtotime($entry['expires_at']) < time()) {
        json_error('Sessão expirada. Faça login novamente.', 401);
    }
}

function guest_search(Store $store): void
{
    $term = normalize((string) ($_GET['term'] ?? ''));
    if ($term === '') {
        json_ok([]);
    }
    $data = $store->all();
    $confirmed = [];
    foreach ($data['confirmations'] as $c) {
        if (($c['status'] ?? '') !== '') {
            $confirmed[normalize($c['guest_name'])] = true;
        }
    }
    $out = [];
    foreach ($data['guests'] as $guest) {
        $n = normalize($guest['name']);
        if (str_starts_with($n, $term) || str_contains($n, $term)) {
            $out[] = [
                'id' => $guest['id'],
                'name' => $guest['name'],
                'already_confirmed' => isset($confirmed[$n]),
            ];
        }
        if (count($out) >= 15) {
            break;
        }
    }
    json_ok($out);
}

function confirmation_store(Store $store, array $body): void
{
    $name = trim((string) ($body['guest_name'] ?? ''));
    $status = (string) ($body['status'] ?? '');
    if ($name === '' || !in_array($status, ['confirmed', 'declined'], true)) {
        json_error('Dados inválidos.');
    }

    $resolvedName = $name;
    $store->update(function (array $data) use (&$resolvedName, $status) {
        $found = false;
        foreach ($data['guests'] as $g) {
            if (normalize($g['name']) === normalize($resolvedName)) {
                $found = true;
                $resolvedName = $g['name'];
                break;
            }
        }
        if (!$found) {
            throw new RuntimeException('Convidado não encontrado na lista.');
        }

        $updated = false;
        foreach ($data['confirmations'] as &$c) {
            if (normalize($c['guest_name']) === normalize($resolvedName)) {
                $c['status'] = $status;
                $c['confirmed_at'] = date('c');
                $updated = true;
                break;
            }
        }
        unset($c);

        if (!$updated) {
            $data['confirmations'][] = [
                'id' => $data['meta']['next_confirmation_id']++,
                'guest_name' => $resolvedName,
                'status' => $status,
                'confirmed_at' => date('c'),
            ];
        }
        return $data;
    });

    json_ok(['ok' => true, 'guest_name' => $resolvedName, 'status' => $status]);
}

function gifts_index(Store $store, array $config): void
{
    $data = $store->all();
    $gifts = array_values(array_filter($data['gifts'], fn ($g) => !empty($g['is_active'])));
    $out = array_map(function ($g) use ($config) {
        $claimed = (int) ($g['claimed_count'] ?? 0);
        $max = max(1, (int) ($g['max_people'] ?? 1));
        return [
            'id' => $g['id'],
            'name' => $g['name'],
            'price' => (float) $g['price'],
            'image' => $g['image'],
            'details' => $g['details'] ?? '',
            'delivery_mode' => $g['delivery_mode'] ?? 'pix',
            'max_people' => $max,
            'claimed_count' => $claimed,
            'is_full' => $claimed >= $max,
            'pix_key' => ($g['delivery_mode'] ?? 'pix') === 'pix' ? $config['pix']['key'] : null,
        ];
    }, $gifts);
    json_ok($out);
}

function gift_pix(Store $store, array $config, int $id): void
{
    $data = $store->all();
    $gift = null;
    foreach ($data['gifts'] as $g) {
        if ((int) $g['id'] === $id) {
            $gift = $g;
            break;
        }
    }
    if (!$gift) {
        json_error('Presente não encontrado.', 404);
    }
    if (($gift['delivery_mode'] ?? '') !== 'pix') {
        json_error('Este presente está configurado para entrega na mão.');
    }

    $amount = (float) $gift['price'];
    $payload = pix_payload(
        $config['pix']['key'],
        $config['pix']['name'],
        $config['pix']['city'],
        $amount,
        'AM' . $gift['id']
    );

    json_ok([
        'gift_id' => $gift['id'],
        'gift_name' => $gift['name'],
        'amount' => $amount,
        'amount_label' => 'R$ ' . number_format($amount, 2, ',', '.'),
        'pix_key' => $config['pix']['key'],
        'payload' => $payload,
    ]);
}

function gift_claim(Store $store, array $body, int $id): void
{
    $guestName = trim((string) ($body['guest_name'] ?? 'Anônimo'));
    $store->update(function (array $data) use ($id, $guestName) {
        $found = false;
        foreach ($data['gifts'] as &$g) {
            if ((int) $g['id'] !== $id) {
                continue;
            }
            $found = true;
            $max = max(1, (int) ($g['max_people'] ?? 1));
            $claimed = (int) ($g['claimed_count'] ?? 0);
            if ($claimed >= $max) {
                throw new RuntimeException('Este presente já foi escolhido.');
            }
            $g['claimed_count'] = $claimed + 1;
            $data['gift_claims'][] = [
                'id' => $data['meta']['next_claim_id']++,
                'gift_id' => $id,
                'gift_name' => $g['name'],
                'guest_name' => $guestName,
                'delivery_mode' => $g['delivery_mode'] ?? 'pix',
                'amount' => (float) $g['price'],
                'created_at' => date('c'),
            ];
            break;
        }
        unset($g);
        if (!$found) {
            throw new RuntimeException('Presente não encontrado.');
        }
        return $data;
    });
    json_ok(['ok' => true]);
}

function admin_login(Store $store, array $config, array $body): void
{
    $email = trim((string) ($body['email'] ?? ''));
    $password = (string) ($body['password'] ?? '');
    if ($email !== $config['admin_email'] || !password_verify($password, $config['admin_password_hash'])) {
        json_error('Credenciais inválidas.', 401);
    }
    $token = bin2hex(random_bytes(32));
    $expires = date('c', time() + ((int) $config['token_ttl_hours'] * 3600));
    $store->update(function (array $data) use ($token, $expires) {
        foreach ($data['tokens'] as $k => $t) {
            if (strtotime($t['expires_at']) < time()) {
                unset($data['tokens'][$k]);
            }
        }
        $data['tokens'][$token] = ['expires_at' => $expires];
        return $data;
    });
    json_ok(['token' => $token, 'expires_at' => $expires]);
}

function admin_logout(Store $store): void
{
    $token = bearer_token();
    if ($token) {
        $store->update(function (array $data) use ($token) {
            unset($data['tokens'][$token]);
            return $data;
        });
    }
    json_ok(['ok' => true]);
}

function admin_dashboard(Store $store, array $config): void
{
    require_admin($store);
    $data = $store->all();

    $statusMap = [];
    foreach ($data['confirmations'] as $c) {
        $statusMap[normalize($c['guest_name'])] = $c;
    }

    $weddingGuests = array_map(function ($g) use ($statusMap) {
        $c = $statusMap[normalize($g['name'])] ?? null;
        return [
            'id' => $g['id'],
            'name' => $g['name'],
            'status' => $c['status'] ?? null,
            'confirmed_at' => isset($c['confirmed_at']) ? date('d/m/Y H:i', strtotime($c['confirmed_at'])) : null,
        ];
    }, $data['guests']);

    $confirmations = array_map(function ($c) {
        return [
            'id' => $c['id'],
            'guest_name' => $c['guest_name'],
            'status' => $c['status'],
            'confirmed_at' => isset($c['confirmed_at']) ? date('d/m/Y H:i', strtotime($c['confirmed_at'])) : null,
        ];
    }, array_reverse($data['confirmations']));

    $gifts = array_map(function ($g) use ($config) {
        $claimed = (int) ($g['claimed_count'] ?? 0);
        $max = max(1, (int) ($g['max_people'] ?? 1));
        return [
            'id' => $g['id'],
            'name' => $g['name'],
            'price' => (float) $g['price'],
            'image' => $g['image'],
            'details' => $g['details'] ?? '',
            'delivery_mode' => $g['delivery_mode'] ?? 'pix',
            'max_people' => $max,
            'claimed_count' => $claimed,
            'is_full' => $claimed >= $max,
            'is_active' => (bool) ($g['is_active'] ?? true),
            'pix_key' => $config['pix']['key'],
        ];
    }, $data['gifts']);

    $claims = array_map(function ($c) {
        return [
            'id' => $c['id'],
            'gift_name' => $c['gift_name'],
            'guest_name' => $c['guest_name'],
            'delivery_mode' => $c['delivery_mode'],
            'amount' => (float) $c['amount'],
            'created_at' => date('d/m/Y H:i', strtotime($c['created_at'])),
        ];
    }, array_reverse($data['gift_claims']));

    $confirmed = count(array_filter($data['confirmations'], fn ($c) => $c['status'] === 'confirmed'));
    $declined = count(array_filter($data['confirmations'], fn ($c) => $c['status'] === 'declined'));

    json_ok([
        'stats' => [
            'total_guests' => count($data['guests']),
            'confirmed' => $confirmed,
            'declined' => $declined,
            'pending' => count($data['guests']) - $confirmed - $declined,
            'gifts' => count($data['gifts']),
            'gift_claims' => count($data['gift_claims']),
        ],
        'wedding_guests' => $weddingGuests,
        'confirmations' => $confirmations,
        'gifts' => $gifts,
        'gift_claims' => $claims,
        'pix' => $config['pix'],
    ]);
}

function admin_guest_store(Store $store, array $body): void
{
    require_admin($store);
    $name = trim((string) ($body['name'] ?? ''));
    if ($name === '') {
        json_error('Informe o nome.');
    }
    $created = null;
    $store->update(function (array $data) use ($name, &$created) {
        foreach ($data['guests'] as $g) {
            if (normalize($g['name']) === normalize($name)) {
                throw new RuntimeException('Convidado já existe.');
            }
        }
        $created = [
            'id' => $data['meta']['next_guest_id']++,
            'name' => $name,
            'created_at' => date('c'),
        ];
        $data['guests'][] = $created;
        return $data;
    });
    json_ok($created, 201);
}

function admin_guest_delete(Store $store, int $id): void
{
    require_admin($store);
    $store->update(function (array $data) use ($id) {
        $data['guests'] = array_values(array_filter($data['guests'], fn ($g) => (int) $g['id'] !== $id));
        return $data;
    });
    json_ok(['ok' => true]);
}

function admin_gift_store(Store $store, array $body): void
{
    require_admin($store);
    $name = trim((string) ($body['name'] ?? ''));
    $price = (float) ($body['price'] ?? 0);
    $mode = ($body['delivery_mode'] ?? 'pix') === 'mao' ? 'mao' : 'pix';
    if ($name === '' || $price <= 0) {
        json_error('Nome e valor são obrigatórios.');
    }
    $created = null;
    $store->update(function (array $data) use ($body, $name, $price, $mode, &$created) {
        $created = [
            'id' => $data['meta']['next_gift_id']++,
            'name' => $name,
            'price' => $price,
            'image' => trim((string) ($body['image'] ?? '')),
            'details' => trim((string) ($body['details'] ?? '')),
            'delivery_mode' => $mode,
            'max_people' => max(1, (int) ($body['max_people'] ?? 1)),
            'claimed_count' => 0,
            'is_active' => true,
            'created_at' => date('c'),
        ];
        $data['gifts'][] = $created;
        return $data;
    });
    json_ok($created, 201);
}

function admin_gift_update(Store $store, array $body, int $id): void
{
    require_admin($store);
    $updated = null;
    $store->update(function (array $data) use ($body, $id, &$updated) {
        foreach ($data['gifts'] as &$g) {
            if ((int) $g['id'] !== $id) {
                continue;
            }
            if (isset($body['name'])) {
                $g['name'] = trim((string) $body['name']);
            }
            if (isset($body['price'])) {
                $g['price'] = (float) $body['price'];
            }
            if (isset($body['image'])) {
                $g['image'] = trim((string) $body['image']);
            }
            if (isset($body['details'])) {
                $g['details'] = trim((string) $body['details']);
            }
            if (isset($body['delivery_mode'])) {
                $g['delivery_mode'] = $body['delivery_mode'] === 'mao' ? 'mao' : 'pix';
            }
            if (isset($body['max_people'])) {
                $g['max_people'] = max(1, (int) $body['max_people']);
            }
            if (isset($body['is_active'])) {
                $g['is_active'] = (bool) $body['is_active'];
            }
            $updated = $g;
            break;
        }
        unset($g);
        if (!$updated) {
            throw new RuntimeException('Presente não encontrado.');
        }
        return $data;
    });
    json_ok($updated);
}

function admin_gift_delete(Store $store, int $id): void
{
    require_admin($store);
    $store->update(function (array $data) use ($id) {
        $data['gifts'] = array_values(array_filter($data['gifts'], fn ($g) => (int) $g['id'] !== $id));
        return $data;
    });
    json_ok(['ok' => true]);
}
