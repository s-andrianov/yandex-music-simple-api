<?php

class yClient
{
    private $base_url = "https://api.music.yandex.net";
    private $token;
    public $user;
    private $sign_key = "p93jhgh689SBReK6ghtw62";
    private $cookie_file;
    private $user_agent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    public function __construct($token)
    {
        $this->token = $token;
        $this->cookie_file = sys_get_temp_dir() . '/yandex_music_cookies_' . md5($token) . '.txt';
        $this->user = $this->getAccountInfo();
    }

    private function request($url, $options = [])
    {
        $default_options = [
            'method' => 'GET',
            'headers' => [],
            'data' => null,
            'timeout' => 10,
        ];

        $options = array_merge($default_options, $options);

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $options['timeout'],
            CURLOPT_COOKIEFILE => $this->cookie_file,
            CURLOPT_COOKIEJAR => $this->cookie_file,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT => $this->user_agent,
        ]);

        $headers = [
            'X-Yandex-Music-Client: YandexMusicAndroid/24023621',
            'Authorization: OAuth ' . $this->token,
            'Accept-Language: ru',
            'Accept: application/json',
        ];

        if (!empty($options['headers'])) {
            $headers = array_merge($headers, $options['headers']);
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if ($options['method'] === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($options['data']) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $options['data']);
            }
        }

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            throw new Exception('CURL Error');
        }

        if ($http_code !== 200) {
            throw new Exception('HTTP Error: ' . $http_code);
        }

        return $response;
    }

    public function getAccountInfo()
    {
        $response = $this->request("{$this->base_url}/account/status");
        $data = json_decode($response, true);

        if (!isset($data['result']['account'])) {
            throw new Exception('Invalid API response');
        }

        $account = $data['result']['account'];
        $subscription = $data['result']['subscription'] ?? [];

        return [
            'id' => $account['uid'],
            'login' => $account['login'],
            'name' => $account['displayName'] ?? null,
            'email' => $data['result']['defaultEmail'] ?? null,
            'region' => $account['regionCode'] ?? null,
            'has_plus' => $data['result']['plus']['hasPlus'] ?? null,
            'subscription_active' => !empty($subscription['autoRenewable']),
        ];
    }

    public function convert_track_id($track_id)
    {
        if (is_string($track_id)) {
            $parts = explode(':', $track_id);
            $track_id = (int) $parts[0];
        }
        return $track_id;
    }

    public function get_sign($track_id)
    {
        $track_id = $this->convert_track_id($track_id);
        $timestamp = time();
        $message = $track_id . $timestamp;

        $sign = base64_encode(
            hash_hmac('sha256', $message, $this->sign_key, true)
        );

        return [
            'timestamp' => $timestamp,
            'sign' => $sign
        ];
    }

    public function getLiked($type = 'tracks')
    {
        $types = ['tracks', 'albums', 'artists'];
        if (!in_array($type, $types)) {
            throw new Exception('Invalid type: ' . $type);
        }

        $response = $this->request(
            "{$this->base_url}/users/{$this->user['id']}/likes/{$type}?if-modified-since-revision=0"
        );

        $data = json_decode($response, true);

        if (isset($data['result']['library']['tracks'])) {
            return $data['result']['library']['tracks'];
        }

        return $data['result'] ?? $data;
    }

    public function getLyrics($track_id, $format = "LRC")
    {
        $sign = $this->get_sign($track_id);
        $params = http_build_query([
            'sign' => $sign['sign'],
            'timeStamp' => $sign['timestamp'],
            'format' => $format,
        ]);

        $url = "{$this->base_url}/tracks/{$track_id}/lyrics?{$params}";
        $response = $this->request($url);

        return json_decode($response, true)['result'];
    }

    public function getArtistInfo($artist_id)
    {
        $response = $this->request("{$this->base_url}/artists/{$artist_id}/brief-info");

        $data = json_decode($response, true);
        $artist = $data['result']['artist'];

        return $artist;
    }

    public function getTrackInfo($track_id)
    {
        $response = $this->request("{$this->base_url}/tracks/{$track_id}/full-info");
        $data = json_decode($response, true);
        $track = $data['result']['track'];

        return $this->formatTrackInfo($track);
    }

    private function formatTrackInfo($track)
    {
        $track_id = $track['id'];
        $downloadInfoResponse = $this->request("{$this->base_url}/tracks/{$track_id}/download-info");
        $downloadInfoData = json_decode($downloadInfoResponse, true)['result'];

        $audioUrl = null;

        if (!empty($downloadInfoData) && is_array($downloadInfoData)) {
            $downloadInfo = $downloadInfoData[0];

            if (isset($downloadInfo['downloadInfoUrl'])) {
                try {
                    $audioUrl = $this->getDirectDownloadLink($downloadInfo['downloadInfoUrl']);
                } catch (Exception $e) {
                    error_log("Error getting direct link: " . $e->getMessage());
                }
            }
        }

        $artists = array_map(function ($artist) {
            return [
                'id' => $artist['id'],
                'name' => $artist['name'],
                'cover' => $artist['cover']['uri'] ?? null
            ];
        }, $track['artists']);

        if (isset($track['lyricsInfo'])) {
            if ($track['lyricsInfo']['hasAvailableSyncLyrics'] == true) {
                $lyricsUrl = $this->getLyrics($track_id)['downloadUrl'];
            } else if ($track['lyricsInfo']['hasAvailableTextLyrics'] == true) {
                $lyricsUrl = $this->getLyrics($track_id, 'TEXT')['downloadUrl'];
            }
        }

        return [
            'id' => $track['id'],
            'title' => $track['title'],
            'duration' => $track['durationMs'],
            'artists' => [
                'text' => implode(', ', array_column($artists, 'name')),
                'list' => $artists
            ],
            'album' => [
                'id' => $track['albums'][0]['id'],
                'title' => $track['albums'][0]['title']
            ],
            'year' => $track['albums'][0]['year'],
            'cover' => $track['ogImage'] ?? $track['coverUri'] ?? null,
            'info' => [
                'colors' => $track['derivedColors'] ?? null,
                'fade' => $track['fade'] ?? null,
                'lyricsUrl' => $lyricsUrl ?? null,
                'audioUrl' => $audioUrl,
                'downloadInfo' => $downloadInfoData
            ]
        ];
    }

    private function getDirectDownloadLink($downloadInfoUrl)
    {
        // Получаем XML с информацией о загрузке
        $xmlResponse = $this->request($downloadInfoUrl);

        // Парсим XML
        $xml = simplexml_load_string($xmlResponse);
        if (!$xml) {
            throw new Exception("Failed to parse XML response");
        }

        $host = (string)$xml->host;
        $path = (string)$xml->path;
        $ts = (string)$xml->ts;
        $s = (string)$xml->s;

        if (!$host || !$path || !$ts || !$s) {
            throw new Exception("Missing required XML fields");
        }

        $sign_salt = 'XGRlBW9FXlekgbPrRHuSiA';
        $sign = md5($sign_salt . substr($path, 1) . $s);

        $directLink = "https://{$host}/get-mp3/{$sign}/{$ts}{$path}";

        return $directLink;
    }

    public function search($query, $params)
    {
        $params = http_build_query([
            'text' => $query,
            'nocorrect' => $params['nocorrect'] ?? false,
            'type' => $params['type'] ?? 'all',
            'page' => $params['page'] ?? 0,
            'playlist-in-best' => $params['playlist_in_best'] ?? true,
        ]);
        $response = $this->request("{$this->base_url}/search?{$params}");
        $result = json_decode($response, true)['result'];

        return $result;
    }

    public function searchSuggest($part)
    {
        $params = http_build_query([
            'part' => $part,
        ]);
        $response = $this->request("{$this->base_url}/search/suggest?{$params}");

        return json_decode($response, true)['result'];
    }
}
