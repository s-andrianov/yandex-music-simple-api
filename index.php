<?php
header('Content-Type: application/json; charset=utf-8');

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type');

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}

require_once 'src/client.php';

class YMusicAPI
{
    private $ym;

    public function __construct($token)
    {
        if (!$token) {
            throw new Exception('Token is required');
        }
        $this->ym = new yClient($token);
    }

    public function response($data)
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    public function handleRequest()
    {
        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $path = trim($path, '/');

        $pathParts = explode('/', $path);
        $endpoint = $pathParts[0] ?? '';
        $param1 = $pathParts[1] ?? '';
        $param2 = $pathParts[2] ?? '';

        try {
            switch ($endpoint) {
                case 'account':
                    return json_encode($this->ym->user, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

                case 'liked':
                    $type = $_GET['type'] ?? 'tracks';
                    $data = $this->ym->getLiked($type);
                    return $this->response($data);

                case 'track':
                    if (!$param1) {
                        throw new Exception('Track ID is required');
                    }

                    if ($param2 === 'lyrics') {
                        $format = $_GET['format'] ?? 'LRC';
                        $data = $this->ym->getLyrics($param1, $format);
                        return $this->response($data);
                    } else {
                        $data = $this->ym->getTrackInfo($param1);
                        return $this->response($data);
                    }

                case 'artist':
                    if (!$param1) {
                        throw new Exception('Artist ID is required');
                    }

                    $data = $this->ym->getArtistInfo($param1);
                    return $this->response($data);

                case 'search':
                    $query = $_GET['q'] ?? null;
                    if (!$query) {
                        throw new Exception('Query parameter "q" is required');
                    }

                    if ($param1 === 'suggest') {
                        $data = $this->ym->searchSuggest($query);
                        return $this->response($data);
                    } else {
                        $searchParams = [
                            'nocorrect' => isset($_GET['nocorrect']) ? filter_var($_GET['nocorrect'], FILTER_VALIDATE_BOOLEAN) : false,
                            'type' => $_GET['type'] ?? 'all',
                            'page' => isset($_GET['page']) ? (int)$_GET['page'] : 0,
                            'playlist_in_best' => isset($_GET['playlist_in_best']) ? filter_var($_GET['playlist_in_best'], FILTER_VALIDATE_BOOLEAN) : true,
                        ];

                        $data = $this->ym->search($query, $searchParams);
                        return $this->response($data);
                    }

                default:
                    http_response_code(404);
                    return json_encode(['error' => 'Endpoint not found']);
            }
        } catch (Exception $e) {
            http_response_code(400);
            return json_encode(['error' => $e->getMessage()]);
        }
    }
}

function getToken()
{
    $headers = getallheaders();
    if (isset($headers['Authorization'])) {
        $authHeader = $headers['Authorization'];
        if (preg_match('/Bearer\s+(.+)/i', $authHeader, $matches)) {
            return $matches[1];
        }
    }

    return $_GET['token'] ?? '';
}

try {
    $token = getToken();
    $api = new YMusicAPI($token);
    echo $api->handleRequest();
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}
