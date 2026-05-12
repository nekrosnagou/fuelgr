<?php
/**
 * FuelGR REST API
 * Built with Slim Framework 4
 * 
 * Endpoints:
 * GET    /api/stations/{fuelType?}        - #1 Stations + prices for selected fuel
 * GET    /api/stations/{fuelType}/stats   - #2 Count, max, min, avg price
 * GET    /api/stations/{id}/pricelist     - #3 Full pricelist of a station
 * POST   /api/auth/login                 - #4 User login (JWT)
 * POST   /api/orders                     - #5 Place order (consumer)
 * GET    /api/orders/station/{id}        - #6 Get station orders (owner)
 * PUT    /api/pricelist/{stationId}/{fuelSubTypeId} - #7 Update fuel price (owner)
 * PUT    /api/orders/{id}/execute        - #8 Execute order (owner)
 * DELETE /api/orders/{id}               - #9 Delete order
 */

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

require __DIR__ . '/../vendor/autoload.php';

// ─── DB Connection ──────────────────────────────────────────────────────────
function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $host = getenv('DB_HOST') ?: 'localhost';
        $db   = getenv('DB_NAME') ?: 'fuelgr';
        $user = getenv('DB_USER') ?: 'root';
        $pass = getenv('DB_PASS') ?: '';
        $pdo  = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}

// ─── JWT Helpers ─────────────────────────────────────────────────────────────
define('JWT_SECRET', getenv('JWT_SECRET') ?: 'fuelgr_secret_key_change_in_prod');

function generateToken(array $payload): string {
    $payload['iat'] = time();
    $payload['exp'] = time() + 86400; // 24h
    return JWT::encode($payload, JWT_SECRET, 'HS256');
}

function verifyToken(Request $request): ?array {
    $header = $request->getHeaderLine('Authorization');
    if (!preg_match('/Bearer\s+(.*)$/i', $header, $matches)) return null;
    try {
        $decoded = JWT::decode($matches[1], new Key(JWT_SECRET, 'HS256'));
        return (array)$decoded;
    } catch (\Exception $e) {
        return null;
    }
}

function requireAuth(Request $request, Response $response): ?array {
    $user = verifyToken($request);
    if (!$user) {
        $response->getBody()->write(json_encode(['error' => 'Unauthorized']));
        return null;
    }
    return $user;
}

// ─── App Setup ───────────────────────────────────────────────────────────────
$app = AppFactory::create();
$app->setBasePath('/fuelgr/api');
$app->addErrorMiddleware(true, true, true);

// CORS Middleware
$app->add(function (Request $request, $handler): Response {
    $response = $handler->handle($request);
    return $response
        ->withHeader('Access-Control-Allow-Origin', '*')
        ->withHeader('Access-Control-Allow-Headers', 'Authorization, Content-Type, Accept')
        ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
});

$app->options('/{routes:.+}', function (Request $request, Response $response) {
    return $response;
});

// ─── Helper ──────────────────────────────────────────────────────────────────
function jsonResponse(Response $response, $data, int $status = 200): Response {
    $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
    return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
}

function xmlResponse(Response $response, array $stations): Response {
    $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><stations/>');
    foreach ($stations as $s) {
        $node = $xml->addChild('station');
        foreach ($s as $k => $v) {
            $node->addChild($k, htmlspecialchars((string)$v));
        }
    }
    $response->getBody()->write($xml->asXML());
    return $response->withHeader('Content-Type', 'application/xml')->withStatus(200);
}

// ─── #1 GET /stations/{fuelType?} ────────────────────────────────────────────
// Returns station data + price for selected fuel type (default: fuelTypeID=1 = Αμόλυβδη 95)
// Add ?format=xml to get XML response
$app->get('/stations[/{fuelType}]', function (Request $request, Response $response, array $args) {
    $fuelType = (int)($args['fuelType'] ?? 1);
    $format   = $request->getQueryParams()['format'] ?? 'json';

    $pdo = getDB();
    
    $sql = "
        SELECT 
            g.gasStationID, g.gasStationLat AS lat, g.gasStationLong AS lng,
            g.fuelCompNormalName AS brand, g.gasStationOwner AS owner,
            g.gasStationAddress AS address, g.phone1 AS phone,
            g.municipalityNormalName AS municipality, g.countyName AS county,
            g.username,
            p.fuelNormalName, p.fuelPrice AS price, p.fuelSubTypeID, p.dateUpdated
        FROM gasstations g
        LEFT JOIN (
            SELECT gasStationID, fuelTypeID, fuelSubTypeID, fuelNormalName, fuelPrice, dateUpdated
            FROM pricedata p1
            WHERE fuelTypeID = :fuelType
              AND fuelPrice = (
                SELECT MIN(fuelPrice) FROM pricedata p2
                WHERE p2.gasStationID = p1.gasStationID AND p2.fuelTypeID = :fuelType2
              )
            GROUP BY gasStationID
        ) p ON g.gasStationID = p.gasStationID
        ORDER BY p.fuelPrice ASC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':fuelType' => $fuelType, ':fuelType2' => $fuelType]);
    $stations = $stmt->fetchAll();

    if ($format === 'xml') {
        return xmlResponse($response, $stations);
    }
    return jsonResponse($response, $stations);
});

// ─── #2 GET /stations/{fuelType}/stats ───────────────────────────────────────
$app->get('/stations/{fuelType}/stats', function (Request $request, Response $response, array $args) {
    $fuelType = (int)$args['fuelType'];
    $pdo = getDB();

    // Count stations that have this fuel type; stats on min price per station
    $sql = "
        SELECT 
            COUNT(DISTINCT gasStationID) AS stationCount,
            MAX(minPrice) AS maxPrice,
            MIN(minPrice) AS minPrice,
            ROUND(AVG(minPrice), 3) AS avgPrice
        FROM (
            SELECT gasStationID, MIN(fuelPrice) AS minPrice
            FROM pricedata
            WHERE fuelTypeID = :fuelType
            GROUP BY gasStationID
        ) sub
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':fuelType' => $fuelType]);
    $stats = $stmt->fetch();

    return jsonResponse($response, $stats);
});

// ─── #3 GET /stations/{id}/pricelist ─────────────────────────────────────────
$app->get('/stations/{id}/pricelist', function (Request $request, Response $response, array $args) {
    $id  = (int)$args['id'];
    $pdo = getDB();

    $stmt = $pdo->prepare("SELECT * FROM pricedata WHERE gasStationID = :id ORDER BY fuelTypeID, isPremium");
    $stmt->execute([':id' => $id]);
    $prices = $stmt->fetchAll();

    return jsonResponse($response, $prices);
});

// ─── #4 POST /auth/login ─────────────────────────────────────────────────────
$app->post('/auth/login', function (Request $request, Response $response) {
    $body     = json_decode($request->getBody()->getContents(), true);
    $username = trim($body['username'] ?? '');
    $password = trim($body['password'] ?? '');

    if (!$username || !$password) {
        return jsonResponse($response, ['error' => 'Missing credentials'], 400);
    }

    $pdo  = getDB();
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = :u");
    $stmt->execute([':u' => $username]);
    $user = $stmt->fetch();

    if (!$user) {
        return jsonResponse($response, ['error' => 'Invalid credentials'], 401);
    }


    $valid = password_verify($password, $user['password'])
          || $password === $user['password'];

    if (!$valid) {
        return jsonResponse($response, ['error' => 'Invalid credentials'], 401);
    }

// Use role from users table as the authority
$role = $user['role']; // 'owner' or 'consumer' from DB

// Only look up stationID if they are an owner
$stationID = null;
if ($role === 'owner') {
    $stmtOwner = $pdo->prepare("SELECT gasStationID FROM gasstations WHERE username = :u LIMIT 1");
    $stmtOwner->execute([':u' => $username]);
    $station   = $stmtOwner->fetch();
    $stationID = $station ? $station['gasStationID'] : null;
}

    $token = generateToken([
        'username'  => $username,
        'role'      => $role,
        'stationID' => $stationID,
    ]);

    return jsonResponse($response, [
        'token'     => $token,
        'username'  => $username,
        'role'      => $role,
        'stationID' => $stationID,
    ]);
});

// ─── #5 POST /orders ─────────────────────────────────────────────────────────
$app->post('/orders', function (Request $request, Response $response) {
    $user = verifyToken($request);
    if (!$user) return jsonResponse($response, ['error' => 'Unauthorized'], 401);
    if ($user['role'] !== 'consumer') return jsonResponse($response, ['error' => 'Only consumers can place orders'], 403);

    $body        = json_decode($request->getBody()->getContents(), true);
    $stationID   = (int)($body['gasStationID'] ?? 0);
    $fuelTypeID  = (int)($body['fuelTypeID'] ?? 0);
    $fuelSubType = (int)($body['fuelSubTypeID'] ?? 0);
    $quantity    = (float)($body['quantity'] ?? 0);

    if (!$stationID || !$fuelTypeID || $quantity <= 0) {
        return jsonResponse($response, ['error' => 'Invalid order data'], 400);
    }

    $pdo = getDB();
    // Fetch current price
    $stmt = $pdo->prepare("SELECT fuelPrice, fuelName FROM pricedata WHERE gasStationID=:s AND fuelTypeID=:ft AND fuelSubTypeID=:fst");
    $stmt->execute([':s' => $stationID, ':ft' => $fuelTypeID, ':fst' => $fuelSubType]);
    $priceRow = $stmt->fetch();

    if (!$priceRow) {
        return jsonResponse($response, ['error' => 'Fuel not found at this station'], 404);
    }

    $price     = (float)$priceRow['fuelPrice'];
    $totalCost = round($price * $quantity, 2);

    $ins = $pdo->prepare("
        INSERT INTO orders (username, gasStationID, fuelTypeID, fuelSubTypeID, fuelName, quantity, pricePerLt, totalCost)
        VALUES (:u, :s, :ft, :fst, :fn, :q, :p, :tc)
    ");
    $ins->execute([
        ':u'   => $user['username'],
        ':s'   => $stationID,
        ':ft'  => $fuelTypeID,
        ':fst' => $fuelSubType,
        ':fn'  => $priceRow['fuelName'],
        ':q'   => $quantity,
        ':p'   => $price,
        ':tc'  => $totalCost,
    ]);
    $orderID = $pdo->lastInsertId();

    return jsonResponse($response, [
        'orderID'    => $orderID,
        'fuelName'   => $priceRow['fuelName'],
        'quantity'   => $quantity,
        'pricePerLt' => $price,
        'totalCost'  => $totalCost,
        'message'    => 'Order placed successfully',
    ], 201);
});

// ─── #6 GET /orders/station/{id} ─────────────────────────────────────────────
$app->get('/orders/station/{id}', function (Request $request, Response $response, array $args) {
    $user = verifyToken($request);
    if (!$user) return jsonResponse($response, ['error' => 'Unauthorized'], 401);
    if ($user['role'] !== 'owner') return jsonResponse($response, ['error' => 'Only owners can view station orders'], 403);

    $stationID = (int)$args['id'];
    // Verify ownership
    if ($user['stationID'] != $stationID) {
        return jsonResponse($response, ['error' => 'Access denied: not your station'], 403);
    }

    $pdo  = getDB();
    $stmt = $pdo->prepare("SELECT * FROM orders WHERE gasStationID = :s ORDER BY orderDate DESC");
    $stmt->execute([':s' => $stationID]);
    $orders = $stmt->fetchAll();

    return jsonResponse($response, $orders);
});

// ─── #7 PUT /pricelist/{stationId}/{fuelSubTypeId} ────────────────────────────
$app->put('/pricelist/{stationId}/{fuelSubTypeId}', function (Request $request, Response $response, array $args) {
    $user = verifyToken($request);
    if (!$user) return jsonResponse($response, ['error' => 'Unauthorized'], 401);
    if ($user['role'] !== 'owner') return jsonResponse($response, ['error' => 'Only owners can update prices'], 403);

    $stationID   = (int)$args['stationId'];
    $fuelSubType = (int)$args['fuelSubTypeId'];

    if ($user['stationID'] != $stationID) {
        return jsonResponse($response, ['error' => 'Access denied: not your station'], 403);
    }

    $body     = json_decode($request->getBody()->getContents(), true);
    $newPrice = (float)($body['fuelPrice'] ?? 0);

    if ($newPrice <= 0) {
        return jsonResponse($response, ['error' => 'Invalid price'], 400);
    }

    $pdo  = getDB();
    $stmt = $pdo->prepare("
        UPDATE pricedata 
        SET fuelPrice = :price, dateUpdated = NOW()
        WHERE gasStationID = :s AND fuelSubTypeID = :fst
    ");
    $stmt->execute([':price' => $newPrice, ':s' => $stationID, ':fst' => $fuelSubType]);

    if ($stmt->rowCount() === 0) {
        return jsonResponse($response, ['error' => 'Price entry not found'], 404);
    }

    return jsonResponse($response, ['message' => 'Price updated', 'newPrice' => $newPrice]);
});

// ─── #8 PUT /orders/{id}/execute ─────────────────────────────────────────────
$app->put('/orders/{id}/execute', function (Request $request, Response $response, array $args) {
    $user = verifyToken($request);
    if (!$user) return jsonResponse($response, ['error' => 'Unauthorized'], 401);
    if ($user['role'] !== 'owner') return jsonResponse($response, ['error' => 'Only owners can execute orders'], 403);

    $orderID = (int)$args['id'];
    $pdo     = getDB();

    // Verify order belongs to owner's station
    $stmt = $pdo->prepare("SELECT * FROM orders WHERE orderID = :id");
    $stmt->execute([':id' => $orderID]);
    $order = $stmt->fetch();

    if (!$order) return jsonResponse($response, ['error' => 'Order not found'], 404);
    if ($order['gasStationID'] != $user['stationID']) return jsonResponse($response, ['error' => 'Access denied'], 403);
    if ($order['isExecuted']) return jsonResponse($response, ['error' => 'Order already executed'], 400);

    $upd = $pdo->prepare("UPDATE orders SET isExecuted=1, executedDate=NOW() WHERE orderID=:id");
    $upd->execute([':id' => $orderID]);

    return jsonResponse($response, ['message' => 'Order executed', 'orderID' => $orderID]);
});

// ─── #9 DELETE /orders/{id} ──────────────────────────────────────────────────
$app->delete('/orders/{id}', function (Request $request, Response $response, array $args) {
    $user = verifyToken($request);
    if (!$user) return jsonResponse($response, ['error' => 'Unauthorized'], 401);

    $orderID = (int)$args['id'];
    $pdo     = getDB();

    $stmt = $pdo->prepare("SELECT * FROM orders WHERE orderID = :id");
    $stmt->execute([':id' => $orderID]);
    $order = $stmt->fetch();

    if (!$order) return jsonResponse($response, ['error' => 'Order not found'], 404);

    // Owner can delete any order from their station; consumer can only delete their own unexecuted orders
    if ($user['role'] === 'owner' && $order['gasStationID'] != $user['stationID']) {
        return jsonResponse($response, ['error' => 'Access denied'], 403);
    }
    if ($user['role'] === 'consumer') {
        if ($order['username'] !== $user['username']) return jsonResponse($response, ['error' => 'Access denied'], 403);
        if ($order['isExecuted']) return jsonResponse($response, ['error' => 'Cannot delete an executed order'], 400);
    }

    $pdo->prepare("DELETE FROM orders WHERE orderID = :id")->execute([':id' => $orderID]);

    return jsonResponse($response, ['message' => 'Order deleted', 'orderID' => $orderID]);
});

$app->run();
