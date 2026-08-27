<?php
declare(strict_types=1);

/*
 * RoundCoach PHP API
 *
 * Endpoints:
 *   api.php?action=matches&name=NAME&tag=TAG&mode=competitive&size=20
 *   api.php?action=match&matchId=MATCH_ID
 *
 * HenrikDev API key is loaded only from config.php and is never returned to the browser.
 */

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

const CACHE_DIR = __DIR__ . '/cache';
const LIST_CACHE_TTL = 3600;
const DETAIL_CACHE_TTL = 21600;
const MAP_CACHE_TTL = 86400;
const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;

final class ApiException extends RuntimeException
{
    public int $httpStatus;
    public string $apiCode;
    public int $retryAfter;
    public mixed $detail;

    public function __construct(
        string $message,
        int $httpStatus = 500,
        string $apiCode = 'INTERNAL_ERROR',
        int $retryAfter = 0,
        mixed $detail = null
    ) {
        parent::__construct($message);
        $this->httpStatus = $httpStatus;
        $this->apiCode = $apiCode;
        $this->retryAfter = max(0, $retryAfter);
        $this->detail = $detail;
    }
}

function respond(mixed $payload, int $status = 200, array $headers = []): void
{
    http_response_code($status);
    foreach ($headers as $name => $value) {
        header($name . ': ' . $value);
    }

    $json = json_encode($payload, JSON_FLAGS);
    if ($json === false) {
        http_response_code(500);
        $json = '{"error":"JSON変換エラー","code":"JSON_ERROR","message":"レスポンスを生成できませんでした。"}';
    }
    echo $json;
    exit;
}

function errorResponse(ApiException $error): void
{
    $headers = [];
    if ($error->retryAfter > 0) {
        $headers['Retry-After'] = (string)$error->retryAfter;
    }

    $title = match ($error->apiCode) {
        'API_RATE_LIMIT' => '試合データAPIが混み合っています',
        'LOCAL_RATE_LIMIT' => '検索が集中しています',
        'NO_API_KEY' => 'APIキーが設定されていません',
        'NOT_FOUND' => '試合データが見つかりません',
        'INVALID_REQUEST' => '入力内容を確認してください',
        default => '試合データを取得できませんでした',
    };

    $body = [
        'error' => $title,
        'code' => $error->apiCode,
        'message' => $error->getMessage(),
    ];
    if ($error->retryAfter > 0) {
        $body['retryAfter'] = $error->retryAfter;
    }
    if ($error->detail !== null && $error->httpStatus < 500) {
        $body['detail'] = $error->detail;
    }

    respond($body, $error->httpStatus, $headers);
}

function loadConfig(): array
{
    $path = __DIR__ . '/config.php';
    if (!is_file($path)) {
        throw new ApiException(
            'config.example.php を config.php にコピーし、HenrikDev APIキーを設定してください。',
            500,
            'NO_API_KEY'
        );
    }

    $config = require $path;
    if (!is_array($config)) {
        throw new ApiException('config.php は設定配列を返す必要があります。', 500, 'CONFIG_ERROR');
    }

    $key = trim((string)($config['henrik_api_key'] ?? ''));
    if ($key === '') {
        throw new ApiException('config.php の henrik_api_key を設定してください。', 500, 'NO_API_KEY');
    }

    $config['henrik_api_key'] = $key;

    return array_merge([
        'region' => 'ap',
        'connect_timeout' => 5,
        'request_timeout' => 15,
        'search_rate_limit' => 8,
        'detail_rate_limit' => 12,
        'upstream_rate_limit' => 24,
    ], $config);
}

function ensureCacheDirectory(): void
{
    if (!is_dir(CACHE_DIR) && !@mkdir(CACHE_DIR, 0755, true) && !is_dir(CACHE_DIR)) {
        throw new ApiException('cache フォルダを作成できませんでした。書き込み権限を確認してください。', 500, 'CACHE_ERROR');
    }
    if (!is_writable(CACHE_DIR)) {
        throw new ApiException('cache フォルダに書き込めません。パーミッションを確認してください。', 500, 'CACHE_ERROR');
    }
}

function cachePath(string $prefix, string $key): string
{
    return CACHE_DIR . '/' . $prefix . '_' . hash('sha256', $key) . '.json';
}

function readCache(string $path, int $ttl): ?array
{
    if (!is_file($path)) {
        return null;
    }
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return null;
    }
    $entry = json_decode($raw, true);
    if (!is_array($entry) || !isset($entry['savedAt'])) {
        return null;
    }
    if (time() - (int)$entry['savedAt'] > $ttl) {
        @unlink($path);
        return null;
    }
    return $entry;
}

function writeCache(string $path, array $entry): void
{
    $entry['savedAt'] = $entry['savedAt'] ?? time();
    $json = json_encode($entry, JSON_FLAGS);
    if ($json === false) {
        throw new ApiException('キャッシュデータを生成できませんでした。', 500, 'CACHE_ERROR');
    }

    $temporary = $path . '.' . bin2hex(random_bytes(5)) . '.tmp';
    if (@file_put_contents($temporary, $json, LOCK_EX) === false) {
        throw new ApiException('キャッシュを書き込めませんでした。', 500, 'CACHE_ERROR');
    }
    @chmod($temporary, 0640);
    if (!@rename($temporary, $path)) {
        @unlink($temporary);
        throw new ApiException('キャッシュを保存できませんでした。', 500, 'CACHE_ERROR');
    }
}

function maybeCleanupCache(): void
{
    if (mt_rand(1, 100) > 2) {
        return;
    }
    $now = time();
    $files = glob(CACHE_DIR . '/*');
    if ($files === false) {
        return;
    }
    foreach ($files as $path) {
        if (!is_file($path)) {
            continue;
        }
        $name = basename($path);
        $ttl = null;
        if (strpos($name, 'list_') === 0 || strpos($name, 'page_') === 0) {
            $ttl = LIST_CACHE_TTL;
        } elseif (strpos($name, 'detail_') === 0 || strpos($name, 'context_') === 0) {
            $ttl = DETAIL_CACHE_TTL;
        } elseif (strpos($name, 'maps_') === 0) {
            $ttl = MAP_CACHE_TTL;
        } elseif (strpos($name, 'rate_') === 0) {
            $ttl = 7200;
        } elseif (substr($name, -4) === '.tmp') {
            $ttl = 600;
        }
        $modified = @filemtime($path);
        if ($ttl !== null && $modified !== false && $now - $modified > $ttl) {
            @unlink($path);
        }
    }
}

function lower(string $value): string
{
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

function arrGet(mixed $value, array $path, mixed $default = null): mixed
{
    foreach ($path as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return $default;
        }
        $value = $value[$part];
    }
    return $value;
}

function firstValue(mixed ...$values): mixed
{
    foreach ($values as $value) {
        if ($value !== null && $value !== '') {
            return $value;
        }
    }
    return null;
}

function clientIp(): string
{
    $candidates = [
        $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '',
        $_SERVER['REMOTE_ADDR'] ?? '',
        $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '',
    ];
    foreach ($candidates as $candidate) {
        $first = trim(explode(',', (string)$candidate)[0]);
        if ($first !== '' && filter_var($first, FILTER_VALIDATE_IP)) {
            return $first;
        }
    }
    return 'unknown';
}

/** Returns seconds until retry, or zero when the request is allowed. */
function takeRateLimitSlot(string $scope, int $maximum, int $windowSeconds): int
{
    $maximum = max(1, $maximum);
    $windowSeconds = max(1, $windowSeconds);
    $path = cachePath('rate', $scope);
    $handle = @fopen($path, 'c+');
    if ($handle === false) {
        throw new ApiException('アクセス制限ファイルを開けませんでした。', 500, 'CACHE_ERROR');
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            throw new ApiException('アクセス状況を確認できませんでした。', 503, 'SERVER_BUSY');
        }
        rewind($handle);
        $raw = stream_get_contents($handle);
        $decoded = $raw !== false && $raw !== '' ? json_decode($raw, true) : [];
        $timestamps = is_array($decoded['timestamps'] ?? null) ? $decoded['timestamps'] : [];
        $now = time();
        $timestamps = array_values(array_filter($timestamps, static fn($stamp) => $now - (int)$stamp < $windowSeconds));

        $retryAfter = 0;
        if (count($timestamps) >= $maximum) {
            $retryAfter = max(1, $windowSeconds - ($now - (int)$timestamps[0]));
        } else {
            $timestamps[] = $now;
        }

        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, (string)json_encode(['timestamps' => $timestamps], JSON_FLAGS));
        fflush($handle);
        flock($handle, LOCK_UN);
        return $retryAfter;
    } finally {
        fclose($handle);
    }
}

function requireRateLimitSlot(string $scope, int $maximum, int $windowSeconds, string $code): void
{
    $retryAfter = takeRateLimitSlot($scope, $maximum, $windowSeconds);
    if ($retryAfter <= 0) {
        return;
    }
    $message = $code === 'LOCAL_RATE_LIMIT'
        ? "連続した検索が多いため、{$retryAfter}秒ほど待ってからもう一度お試しください。"
        : "試合データAPIへのアクセスが集中しています。{$retryAfter}秒ほど待ってからもう一度お試しください。";
    throw new ApiException($message, 429, $code, $retryAfter);
}

function requestJson(string $url, ?string $apiKey, array $config): array
{
    if (!function_exists('curl_init')) {
        throw new ApiException('PHPのcURL拡張が有効になっていません。', 500, 'CURL_REQUIRED');
    }

    $headers = ['Accept: application/json'];
    if ($apiKey !== null && $apiKey !== '') {
        $headers[] = 'Authorization: ' . $apiKey;
    }
    $responseHeaders = [];
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => max(2, (int)$config['connect_timeout']),
        CURLOPT_TIMEOUT => max(5, (int)$config['request_timeout']),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_USERAGENT => 'RoundCoach/1.0 (+PHP)',
        CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$responseHeaders): int {
            $length = strlen($line);
            $position = strpos($line, ':');
            if ($position !== false) {
                $name = lower(trim(substr($line, 0, $position)));
                $responseHeaders[$name] = trim(substr($line, $position + 1));
            }
            return $length;
        },
    ]);

    $raw = curl_exec($curl);
    $curlError = curl_error($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);

    if ($raw === false) {
        throw new ApiException('外部APIとの通信に失敗しました。時間を置いて再度お試しください。', 502, 'UPSTREAM_NETWORK_ERROR', 0, $curlError);
    }
    $json = json_decode((string)$raw, true);
    if (!is_array($json)) {
        throw new ApiException('外部APIから正しいJSONが返りませんでした。', 502, 'UPSTREAM_RESPONSE_ERROR');
    }
    if ($status < 200 || $status >= 300) {
        $retryAfter = max(0, (int)($responseHeaders['retry-after'] ?? 0));
        if ($status === 429) {
            $retryAfter = $retryAfter > 0 ? $retryAfter : 60;
            throw new ApiException(
                "試合データAPIの利用上限に達しました。{$retryAfter}秒ほど待ってからもう一度お試しください。",
                429,
                'API_RATE_LIMIT',
                $retryAfter
            );
        }
        if ($status === 404) {
            throw new ApiException('プレイヤーまたは試合が見つかりませんでした。Riot IDとタグを確認してください。', 404, 'NOT_FOUND');
        }
        $publicStatus = $status >= 400 && $status < 500 ? $status : 502;
        throw new ApiException('試合データAPI側でエラーが発生しました。時間を置いて再度お試しください。', $publicStatus, 'HENRIK_API_ERROR');
    }

    return ['data' => $json, 'headers' => $responseHeaders, 'status' => $status];
}

function upstreamJson(string $url, array $config): array
{
    requireRateLimitSlot('henrik-global', (int)$config['upstream_rate_limit'], 60, 'API_RATE_LIMIT');
    $result = requestJson($url, (string)$config['henrik_api_key'], $config);
    return $result['data'];
}

function getMatchId(array $match): ?string
{
    $value = firstValue(
        arrGet($match, ['metadata', 'matchid']),
        arrGet($match, ['metadata', 'match_id']),
        arrGet($match, ['metadata', 'matchId'])
    );
    return $value !== null ? (string)$value : null;
}

function getMatchPlayers(array $match): array
{
    $all = arrGet($match, ['players', 'all_players']);
    if (is_array($all)) {
        return $all;
    }
    return is_array($match['players'] ?? null) ? $match['players'] : [];
}

function playerName(array $player): string
{
    return (string)(firstValue($player['name'] ?? null, $player['game_name'] ?? null, $player['gameName'] ?? null) ?? '不明');
}

function playerTag(array $player): string
{
    return (string)(firstValue($player['tag'] ?? null, $player['tag_line'] ?? null, $player['tagLine'] ?? null) ?? '');
}

function playerTeam(array $player): string
{
    return lower((string)(firstValue($player['team_id'] ?? null, $player['team'] ?? null) ?? ''));
}

function findTargetPlayer(array $players, string $name, string $tag): ?array
{
    $wantedName = lower($name);
    $wantedTag = lower($tag);
    foreach ($players as $player) {
        if (!is_array($player) || lower(playerName($player)) !== $wantedName) {
            continue;
        }
        $candidateTag = lower(playerTag($player));
        if ($wantedTag === '' || $candidateTag === '' || $candidateTag === $wantedTag) {
            return $player;
        }
    }
    return null;
}

function rankInfo(array $player): array
{
    $rank = firstValue(
        arrGet($player, ['tier', 'name']),
        $player['currenttier_patched'] ?? null,
        $player['currentTierPatched'] ?? null,
        arrGet($player, ['competitiveTier', 'name']),
        arrGet($player, ['rank', 'name'])
    );
    $rankId = firstValue(
        arrGet($player, ['tier', 'id']),
        $player['currenttier'] ?? null,
        $player['currentTier'] ?? null,
        arrGet($player, ['competitiveTier', 'id']),
        arrGet($player, ['rank', 'id']),
        0
    );
    return ['rank' => $rank !== null ? (string)$rank : 'ランク不明', 'rankId' => (int)$rankId];
}

function rankNameFromId(int $id): string
{
    $names = [
        3 => 'Iron 1', 4 => 'Iron 2', 5 => 'Iron 3',
        6 => 'Bronze 1', 7 => 'Bronze 2', 8 => 'Bronze 3',
        9 => 'Silver 1', 10 => 'Silver 2', 11 => 'Silver 3',
        12 => 'Gold 1', 13 => 'Gold 2', 14 => 'Gold 3',
        15 => 'Platinum 1', 16 => 'Platinum 2', 17 => 'Platinum 3',
        18 => 'Diamond 1', 19 => 'Diamond 2', 20 => 'Diamond 3',
        21 => 'Ascendant 1', 22 => 'Ascendant 2', 23 => 'Ascendant 3',
        24 => 'Immortal 1', 25 => 'Immortal 2', 26 => 'Immortal 3', 27 => 'Radiant',
    ];
    return $names[$id] ?? 'ランク不明';
}

function averageRank(array $players): array
{
    $ids = [];
    foreach ($players as $player) {
        if (!is_array($player)) {
            continue;
        }
        $id = (int)rankInfo($player)['rankId'];
        if ($id >= 3) {
            $ids[] = $id;
        }
    }
    if ($ids === []) {
        return ['averageRank' => 'ランク不明', 'averageRankId' => 0, 'rankedPlayerCount' => 0];
    }
    $average = (int)round(array_sum($ids) / count($ids));
    return ['averageRank' => rankNameFromId($average), 'averageRankId' => $average, 'rankedPlayerCount' => count($ids)];
}

function teamObject(array $match, string $teamId): ?array
{
    $teams = $match['teams'] ?? null;
    if (!is_array($teams)) {
        return null;
    }
    if (isset($teams[$teamId]) && is_array($teams[$teamId])) {
        return $teams[$teamId];
    }
    foreach ($teams as $team) {
        if (is_array($team) && lower((string)(firstValue($team['team_id'] ?? null, $team['team'] ?? null) ?? '')) === $teamId) {
            return $team;
        }
    }
    return null;
}

function teamScore(array $match, string $teamId): int
{
    $team = teamObject($match, $teamId);
    if ($team === null) {
        return 0;
    }
    return (int)(firstValue(
        arrGet($team, ['rounds', 'won']),
        $team['rounds_won'] ?? null,
        $team['roundsWon'] ?? null,
        $team['score'] ?? null,
        0
    ) ?? 0);
}

function teamWon(array $match, string $teamId): bool
{
    $team = teamObject($match, $teamId);
    if ($team === null) {
        return false;
    }
    foreach (['won', 'has_won', 'hasWon'] as $key) {
        if (array_key_exists($key, $team) && is_bool($team[$key])) {
            return $team[$key];
        }
    }
    $other = $teamId === 'red' ? 'blue' : 'red';
    return teamScore($match, $teamId) > teamScore($match, $other);
}

function roundsPlayed(array $match): int
{
    $metadataRounds = (int)(arrGet($match, ['metadata', 'rounds_played'], 0));
    if ($metadataRounds > 0) {
        return $metadataRounds;
    }
    if (is_array($match['rounds'] ?? null) && count($match['rounds']) > 0) {
        return count($match['rounds']);
    }
    return max(1, teamScore($match, 'red') + teamScore($match, 'blue'));
}

function listStats(array $player, int $rounds): array
{
    $stats = is_array($player['stats'] ?? null) ? $player['stats'] : [];
    $kills = (int)($stats['kills'] ?? 0);
    $deaths = (int)($stats['deaths'] ?? 0);
    $headshots = (int)($stats['headshots'] ?? 0);
    $bodyshots = (int)($stats['bodyshots'] ?? 0);
    $legshots = (int)($stats['legshots'] ?? 0);
    $shots = $headshots + $bodyshots + $legshots;
    return [
        'kills' => $kills,
        'deaths' => $deaths,
        'assists' => (int)($stats['assists'] ?? 0),
        'kd' => round($kills / max($deaths, 1), 2),
        'acs' => (int)round((float)($stats['score'] ?? 0) / max($rounds, 1)),
        'hs' => round($shots > 0 ? $headshots / $shots * 100 : 0, 1),
    ];
}

function matchMapName(array $match): string
{
    $map = arrGet($match, ['metadata', 'map']);
    if (is_array($map)) {
        return (string)(firstValue($map['name'] ?? null, $map['displayName'] ?? null) ?? '不明');
    }
    return $map !== null && $map !== '' ? (string)$map : '不明';
}

function matchMode(array $match): string
{
    return (string)(firstValue(
        arrGet($match, ['metadata', 'queue', 'name']),
        arrGet($match, ['metadata', 'queue', 'id']),
        arrGet($match, ['metadata', 'mode'])
    ) ?? '不明');
}

function normalizeListItem(array $match, string $name, string $tag): ?array
{
    $matchId = getMatchId($match);
    if ($matchId === null || $matchId === '') {
        return null;
    }
    $players = getMatchPlayers($match);
    $target = findTargetPlayer($players, $name, $tag);
    if ($target === null) {
        return null;
    }
    $team = playerTeam($target);
    $enemy = $team === 'red' ? 'blue' : ($team === 'blue' ? 'red' : '');
    $agent = firstValue(
        $target['character'] ?? null,
        arrGet($target, ['agent', 'name']),
        arrGet($target, ['agent', 'displayName'])
    );
    $agentIcon = firstValue(
        arrGet($target, ['assets', 'agent', 'small']),
        arrGet($target, ['agent', 'displayIcon']),
        arrGet($target, ['agent', 'display_icon'])
    );
    $agentId = arrGet($target, ['agent', 'id']);
    if (($agentIcon === null || $agentIcon === '') && $agentId) {
        $agentIcon = 'https://media.valorant-api.com/agents/' . rawurlencode((string)$agentId) . '/displayicon.png';
    }

    $myScore = teamScore($match, $team);
    $enemyScore = $enemy !== '' ? teamScore($match, $enemy) : 0;
    return array_merge([
        'matchId' => $matchId,
        'map' => matchMapName($match),
        'mode' => matchMode($match),
        'startedAt' => (string)(firstValue(
            arrGet($match, ['metadata', 'started_at']),
            arrGet($match, ['metadata', 'game_start_patched']),
            arrGet($match, ['metadata', 'game_start'])
        ) ?? ''),
        'agent' => (string)($agent ?? '不明'),
        'agentIcon' => (string)($agentIcon ?? ''),
    ], rankInfo($target), averageRank($players), listStats($target, roundsPlayed($match)), [
        'score' => $myScore . ' - ' . $enemyScore,
        'myTeamScore' => $myScore,
        'enemyTeamScore' => $enemyScore,
        'win' => teamWon($match, $team),
        'playerTeam' => $team,
    ]);
}

function extractMatches(array $json): array
{
    if (is_array($json['data'] ?? null)) {
        if (is_array($json['data']['matches'] ?? null)) {
            return $json['data']['matches'];
        }
        return $json['data'];
    }
    if (is_array($json['matches'] ?? null)) {
        return $json['matches'];
    }
    return [];
}

function saveMatchContext(string $matchId, string $name, string $tag): void
{
    $path = cachePath('context', $matchId);
    writeCache($path, ['targetName' => $name, 'targetTag' => $tag]);
}

function fetchListPage(array $config, string $name, string $tag, string $mode, int $start): array
{
    $key = lower($name) . '|' . lower($tag) . '|' . $mode . '|' . $start . '|10';
    $path = cachePath('page', $key);
    $cached = readCache($path, LIST_CACHE_TTL);
    if ($cached !== null && is_array($cached['items'] ?? null)) {
        return [
            'items' => $cached['items'],
            'sourceCount' => (int)($cached['sourceCount'] ?? count($cached['items'])),
            'savedAt' => (int)$cached['savedAt'],
        ];
    }

    $region = rawurlencode((string)$config['region']);
    $url = 'https://api.henrikdev.xyz/valorant/v4/matches/' . $region . '/pc/'
        . rawurlencode($name) . '/' . rawurlencode($tag)
        . '?size=10&start=' . $start . '&mode=' . rawurlencode($mode);
    $matches = extractMatches(upstreamJson($url, $config));
    $items = [];
    foreach ($matches as $match) {
        if (!is_array($match)) {
            continue;
        }
        $item = normalizeListItem($match, $name, $tag);
        if ($item !== null) {
            $items[] = $item;
        }
    }
    $savedAt = time();
    writeCache($path, [
        'items' => $items,
        'sourceCount' => count($matches),
        'savedAt' => $savedAt,
    ]);
    return ['items' => $items, 'sourceCount' => count($matches), 'savedAt' => $savedAt];
}

function handleMatches(array $config): void
{
    $name = trim((string)($_GET['name'] ?? ''));
    $tag = ltrim(trim((string)($_GET['tag'] ?? '')), '#');
    $mode = lower(trim((string)($_GET['mode'] ?? 'competitive')));
    $requested = (int)($_GET['size'] ?? 20);
    $limit = in_array($requested, [20, 40, 60], true) ? $requested : 20;

    if ($name === '' || $tag === '' || strlen($name) > 96 || strlen($tag) > 96) {
        throw new ApiException('プレイヤー名とタグを正しく入力してください。', 400, 'INVALID_REQUEST');
    }
    if (!preg_match('/^[a-z0-9 _-]{1,32}$/', $mode)) {
        throw new ApiException('指定されたモードは利用できません。', 400, 'INVALID_REQUEST');
    }

    $cacheKey = lower($name) . '|' . lower($tag) . '|' . $mode . '|' . $limit;
    $listPath = cachePath('list', $cacheKey);
    $cached = readCache($listPath, LIST_CACHE_TTL);
    if ($cached !== null && is_array($cached['data'] ?? null)) {
        foreach ($cached['data'] as $item) {
            if (is_array($item) && !empty($item['matchId'])) {
                saveMatchContext((string)$item['matchId'], $name, $tag);
            }
        }
        respond($cached['data'], 200, ['X-App-Cache' => 'HIT']);
    }

    requireRateLimitSlot('search|' . clientIp(), (int)$config['search_rate_limit'], 60, 'LOCAL_RATE_LIMIT');

    $items = [];
    $seen = [];
    $oldestSource = time();
    for ($start = 0; $start < $limit; $start += 10) {
        $pageEntry = fetchListPage($config, $name, $tag, $mode, $start);
        $page = $pageEntry['items'];
        $oldestSource = min($oldestSource, (int)$pageEntry['savedAt']);
        foreach ($page as $item) {
            if (!is_array($item) || empty($item['matchId'])) {
                continue;
            }
            if (isset($seen[$item['matchId']])) {
                continue;
            }
            $seen[$item['matchId']] = true;
            $items[] = $item;
            saveMatchContext((string)$item['matchId'], $name, $tag);
            if (count($items) >= $limit) {
                break 2;
            }
        }
        if ((int)$pageEntry['sourceCount'] < 10) {
            break;
        }
    }

    writeCache($listPath, ['data' => $items, 'savedAt' => $oldestSource]);
    respond($items, 200, ['X-App-Cache' => 'MISS']);
}

function clampNumber(float $value, float $minimum, float $maximum): float
{
    return min($maximum, max($minimum, $value));
}

function playerSideForRound(string $team, int $roundNumber): string
{
    if ($roundNumber <= 12) {
        return $team === 'red' ? 'attack' : 'defense';
    }
    if ($roundNumber <= 24) {
        return $team === 'red' ? 'defense' : 'attack';
    }
    $redAttacks = (($roundNumber - 25) % 2) === 0;
    if ($team === 'red') {
        return $redAttacks ? 'attack' : 'defense';
    }
    return $redAttacks ? 'defense' : 'attack';
}

function normalizeDetailPlayer(array $player, string $targetPuuid, int $rounds): array
{
    $stats = is_array($player['stats'] ?? null) ? $player['stats'] : [];
    $kills = (int)($stats['kills'] ?? 0);
    $deaths = (int)($stats['deaths'] ?? 0);
    $headshots = (int)($stats['headshots'] ?? 0);
    $bodyshots = (int)($stats['bodyshots'] ?? 0);
    $legshots = (int)($stats['legshots'] ?? 0);
    $shots = $headshots + $bodyshots + $legshots;
    $agentId = (string)(arrGet($player, ['agent', 'id'], '') ?? '');
    $puuid = (string)($player['puuid'] ?? '');

    return array_merge([
        'name' => (string)($player['name'] ?? '不明'),
        'tag' => (string)($player['tag'] ?? ''),
        'puuid' => $puuid,
        'team' => lower((string)(firstValue($player['team_id'] ?? null, $player['team'] ?? null) ?? '')),
        'agent' => (string)(arrGet($player, ['agent', 'name'], '不明') ?? '不明'),
        'agentIcon' => $agentId !== '' ? 'https://media.valorant-api.com/agents/' . rawurlencode($agentId) . '/displayicon.png' : '',
        'kills' => $kills,
        'deaths' => $deaths,
        'assists' => (int)($stats['assists'] ?? 0),
        'kd' => round($kills / max($deaths, 1), 2),
        'acs' => (int)round((float)($stats['score'] ?? 0) / max($rounds, 1)),
        'hs' => round($shots > 0 ? $headshots / $shots * 100 : 0, 1),
        'damageDealt' => (int)(arrGet($stats, ['damage', 'dealt'], 0) ?? 0),
        'damageReceived' => (int)(arrGet($stats, ['damage', 'received'], 0) ?? 0),
        'isMe' => $targetPuuid !== '' && $puuid === $targetPuuid,
    ], rankInfo($player));
}

function roundAnalysis(array $round): array
{
    $kills = (int)($round['kills'] ?? 0);
    $deaths = (int)($round['deaths'] ?? 0);
    $damage = (int)($round['damage'] ?? 0);
    $difference = (int)($round['loadoutDifference'] ?? 0);
    $deathTime = (int)($round['deathTimeMs'] ?? 0);
    $won = (bool)($round['won'] ?? false);
    $firstKill = (bool)($round['firstKill'] ?? false);
    $firstDeath = (bool)($round['firstDeath'] ?? false);

    $score = 50 + ($won ? 8 : -4);
    $score += $kills >= 3 ? 28 : ($kills === 2 ? 18 : ($kills === 1 ? 8 : 0));
    $score += $damage >= 250 ? 12 : ($damage >= 150 ? 7 : ($damage >= 80 ? 3 : 0));
    $score += $firstKill ? 12 : 0;
    $score -= $firstDeath ? 12 : 0;
    $score += !empty($round['plant']) ? 6 : 0;
    $score += !empty($round['defuse']) ? 8 : 0;
    $score += $deaths === 0 && ($kills > 0 || $damage >= 80) ? 3 : 0;
    if (!$won && $difference >= 3000) {
        $score -= 10;
    }
    if ($won && $difference <= -3000) {
        $score += 10;
    }
    if ($deaths > 0 && $damage < 40) {
        $score -= 8;
    }
    if ($deathTime > 0 && $deathTime < 20000) {
        $score -= 10;
    } elseif ($deathTime >= 20000 && $deathTime < 35000) {
        $score -= 5;
    }
    $score = (int)round(clampNumber((float)$score, 0, 100));
    $grade = $score >= 85 ? 'S' : ($score >= 72 ? 'A' : ($score >= 58 ? 'B' : ($score < 38 ? 'D' : 'C')));

    $strengths = [];
    $improvements = [];
    $evidence = [];
    if ($firstKill) {
        $strengths[] = '最初のキルを取り、味方が人数有利で動ける状況を作りました。';
        $evidence[] = 'ファーストキル';
    }
    if ($kills >= 2) {
        $strengths[] = "{$kills}キルを取り、このラウンドで大きく貢献しました。";
        $evidence[] = "{$kills}キル";
    } elseif ($kills === 1 && $won) {
        $strengths[] = '1キルを取り、ラウンド勝利に貢献しました。';
    }
    if ($damage >= 200) {
        $strengths[] = "{$damage}ダメージを与え、複数の相手に影響を与えました。";
        $evidence[] = "{$damage}ダメージ";
    }
    if ($won && $difference <= -3000) {
        $amount = number_format(abs($difference));
        $strengths[] = "チーム装備が{$amount}クレジット不利な状況で勝利しました。";
        $evidence[] = '装備不利で勝利';
    }
    if (!empty($round['plant'])) {
        $strengths[] = 'スパイク設置を担当し、勝利条件の進行に貢献しました。';
    }
    if (!empty($round['defuse'])) {
        $strengths[] = 'スパイク解除を成功させました。';
    }

    if ($firstDeath) {
        $improvements[] = '最初に倒されています。交戦時の味方との位置関係と逃げ道を確認しましょう。';
        $evidence[] = 'ファーストデス';
    }
    if ($deathTime > 0 && $deathTime < 20000) {
        $seconds = (int)round($deathTime / 1000);
        $improvements[] = "ラウンド開始から約{$seconds}秒で倒されています。序盤の立ち位置とピークのタイミングを見直しましょう。";
        $evidence[] = "約{$seconds}秒でデス";
    }
    if ($deaths > 0 && $damage < 40) {
        $improvements[] = "{$damage}ダメージのまま倒されています。遮蔽物を使える角度だったか確認しましょう。";
        $evidence[] = '低ダメージでデス';
    }
    if (!$won && $difference >= 3000) {
        $amount = number_format($difference);
        $improvements[] = "チーム装備が{$amount}クレジット有利でしたが敗北しました。人数有利の維持や単独交戦を確認しましょう。";
        $evidence[] = '装備有利で敗北';
    }
    if (!$won && $kills === 0 && $damage < 80 && !$firstDeath) {
        $improvements[] = 'キルにつながる影響が小さめでした。交戦への参加タイミングを確認しましょう。';
    }
    if ($strengths === []) {
        $strengths[] = $won
            ? '大きく突出した個人指標はありませんが、ラウンド勝利につながりました。'
            : '数値上の好材料は少なめです。位置関係から次の改善点を探せます。';
    }
    if ($improvements === []) {
        $improvements[] = $won
            ? '大きな問題は見つかりません。良かった立ち位置と交戦タイミングを再現しましょう。'
            : '数値だけでは原因を断定できません。キルマップで人数状況と交戦位置を確認しましょう。';
    }

    $priority = $firstDeath
        ? '序盤の交戦位置を確認'
        : (!$won && $difference >= 3000
            ? '装備有利を失った流れを確認'
            : ($deaths > 0 && $damage < 40
                ? '最初の撃ち合いを確認'
                : ($won && $kills >= 2 ? '好プレーの再現条件を確認' : 'マップ上で交戦の流れを確認')));
    $summary = $score >= 85
        ? 'このラウンドは非常に高い貢献ができています。'
        : ($score >= 72
            ? '良い内容のラウンドです。成功した動きを再現できるように確認しましょう。'
            : ($score >= 58
                ? '良い点と改善点の両方があるラウンドです。'
                : ($score >= 38
                    ? '改善の余地があるラウンドです。数値とキル位置を合わせて振り返りましょう。'
                    : '優先して見直したいラウンドです。序盤の動きから確認しましょう。')));

    return [
        'score' => $score,
        'grade' => $grade,
        'summary' => $summary,
        'priority' => $priority,
        'strengths' => array_slice($strengths, 0, 3),
        'improvements' => array_slice($improvements, 0, 3),
        'evidence' => array_slice($evidence, 0, 4),
        'note' => '評価はキル、デス、ダメージ、装備差、ラウンド結果、発生時刻をもとに算出しています。視界や連携はマップ表示と合わせて確認してください。',
    ];
}

function flowAnalysis(array $rounds): array
{
    $bestWin = $worstLoss = $currentWin = $currentLoss = $bestEnd = $worstEnd = 0;
    foreach ($rounds as $round) {
        if (!empty($round['won'])) {
            ++$currentWin;
            $currentLoss = 0;
            if ($currentWin > $bestWin) {
                $bestWin = $currentWin;
                $bestEnd = (int)$round['number'];
            }
        } else {
            ++$currentLoss;
            $currentWin = 0;
            if ($currentLoss > $worstLoss) {
                $worstLoss = $currentLoss;
                $worstEnd = (int)$round['number'];
            }
        }
    }
    $first = array_values(array_filter($rounds, static fn($r) => (int)$r['number'] <= 12));
    $second = array_values(array_filter($rounds, static fn($r) => (int)$r['number'] >= 13 && (int)$r['number'] <= 24));
    $wins = static fn(array $list): int => count(array_filter($list, static fn($r) => !empty($r['won'])));
    return [
        'sequence' => array_map(static fn($r) => !empty($r['won']) ? 'W' : 'L', $rounds),
        'bestWinStreak' => $bestWin,
        'bestWinStart' => $bestWin > 0 ? $bestEnd - $bestWin + 1 : 0,
        'bestWinEnd' => $bestEnd,
        'worstLoseStreak' => $worstLoss,
        'worstLoseStart' => $worstLoss > 0 ? $worstEnd - $worstLoss + 1 : 0,
        'worstLoseEnd' => $worstEnd,
        'firstHalf' => ['wins' => $wins($first), 'losses' => count($first) - $wins($first)],
        'secondHalf' => ['wins' => $wins($second), 'losses' => count($second) - $wins($second)],
    ];
}

function matchAnalysis(array $me, array $teammates, array $sideStats, array $weapons, int $firstKills, int $firstDeaths): array
{
    $teamAcs = $teammates !== []
        ? array_sum(array_map(static fn($p) => (float)$p['acs'], $teammates)) / count($teammates)
        : (float)$me['acs'];
    $teamDamage = $teammates !== []
        ? array_sum(array_map(static fn($p) => (float)$p['damageDealt'], $teammates)) / count($teammates)
        : (float)$me['damageDealt'];

    $score = 50.0;
    $score += clampNumber(((float)$me['kd'] - 1) * 28, -22, 24);
    $score += clampNumber(((float)$me['acs'] - 200) * 0.11, -14, 16);
    $score += clampNumber(((float)$me['hs'] - 20) * 0.35, -7, 8);
    $score += clampNumber(((float)$me['damageDealt'] - $teamDamage) / 120, -8, 8);
    $score += $firstKills * 1.5 - $firstDeaths * 1.5;
    $score = (int)round(clampNumber($score, 0, 100));
    $grade = $score >= 85 ? 'S' : ($score >= 72 ? 'A' : ($score >= 58 ? 'B' : ($score < 38 ? 'D' : 'C')));
    $strengths = [];
    $improvements = [];
    $notices = [];

    if ((float)$me['kd'] >= 1.2) {
        $strengths[] = 'KD ' . number_format((float)$me['kd'], 2) . 'で、キル数がデス数を十分に上回っています。';
    } elseif ((float)$me['kd'] < 0.85) {
        $improvements[] = 'KDは' . number_format((float)$me['kd'], 2) . 'でした。無理な再ピークを減らし、味方と同時に交戦することが改善候補です。';
    }
    if ((float)$me['acs'] >= $teamAcs + 20) {
        $strengths[] = 'ACS ' . $me['acs'] . 'で、味方平均' . (int)round($teamAcs) . 'を上回りました。';
    } elseif ((float)$me['acs'] < $teamAcs - 25) {
        $improvements[] = 'ACS ' . $me['acs'] . 'は味方平均' . (int)round($teamAcs) . 'を下回りました。低ダメージで倒されたラウンドを確認しましょう。';
    }
    if ((float)$me['hs'] >= 28) {
        $strengths[] = 'HS率' . number_format((float)$me['hs'], 1) . '%で、ヘッドラインの精度が高い試合でした。';
    } elseif ((float)$me['hs'] < 15) {
        $improvements[] = 'HS率は' . number_format((float)$me['hs'], 1) . '%でした。近中距離では初弾を頭の高さに合わせる練習が有効です。';
    }

    $attack = $sideStats['attack'];
    $defense = $sideStats['defense'];
    $attackRate = $attack['total'] > 0 ? $attack['wins'] / $attack['total'] * 100 : 0;
    $defenseRate = $defense['total'] > 0 ? $defense['wins'] / $defense['total'] * 100 : 0;
    if (abs($attackRate - $defenseRate) >= 20) {
        $weaker = $attackRate < $defenseRate ? '攻撃' : '防衛';
        $rate = (int)round(min($attackRate, $defenseRate));
        $improvements[] = "{$weaker}側のラウンド勝率が{$rate}%と低めでした。{$weaker}側の敗戦ラウンドを優先して見直しましょう。";
    }
    if ($firstKills > $firstDeaths) {
        $strengths[] = "ファーストキル{$firstKills}回、ファーストデス{$firstDeaths}回で、序盤の人数有利に貢献しました。";
    } elseif ($firstDeaths > $firstKills) {
        $improvements[] = "ファーストデス{$firstDeaths}回がファーストキル{$firstKills}回を上回りました。序盤の単独交戦を減らす余地があります。";
    }
    if (isset($weapons[0])) {
        $notices[] = '最多キル武器は' . $weapons[0]['name'] . 'で' . $weapons[0]['kills'] . 'キルでした。';
    }
    if ($strengths === []) {
        $strengths[] = '大きく突出した指標はありませんが、ラウンド別データから改善箇所を絞れます。';
    }
    if ($improvements === []) {
        $improvements[] = '主要指標は安定しています。次は攻守別や連敗区間を見て、再現性を高めましょう。';
    }
    $summary = $score >= 72
        ? '個人成績は良好です。強かった側や武器を再現しつつ、苦戦したラウンドを絞って見直しましょう。'
        : ($score >= 50
            ? '一部に良い指標があります。攻守差と序盤の人数状況を確認すると、次の改善点が見つかります。'
            : '苦戦した試合です。攻守別・ラウンド別に原因を分け、具体的な練習課題に変えましょう。');
    return [
        'score' => $score,
        'stars' => round(1 + $score / 25, 1),
        'grade' => $grade,
        'summary' => $summary,
        'strengths' => $strengths,
        'improvements' => $improvements,
        'notices' => $notices,
        'teamAcs' => (int)round($teamAcs),
    ];
}

function importantRounds(array $rounds): array
{
    $items = [];
    foreach ($rounds as $round) {
        $difference = (int)($round['loadoutDifference'] ?? 0);
        $reasons = [];
        $importance = 0;
        $type = 'notice';
        if (empty($round['won']) && $difference >= 3000) {
            $reasons[] = '味方が' . number_format($difference) . 'クレジット装備有利な状態で敗北しました。';
            $importance += 100;
            $type = 'warning';
        }
        if (!empty($round['won']) && $difference <= -3000) {
            $reasons[] = number_format(abs($difference)) . 'クレジット装備不利な状態で勝利しました。';
            $importance += 90;
            $type = 'highlight';
        }
        if (!empty($round['firstDeath']) && empty($round['won'])) {
            $reasons[] = 'ファーストデスが発生し、そのままラウンドを落としました。';
            $importance += 75;
            $type = 'warning';
        }
        if (!empty($round['firstKill']) && !empty($round['won'])) {
            $reasons[] = 'ファーストキルを取り、人数有利を勝利につなげました。';
            $importance += 60;
            if ($type !== 'highlight') {
                $type = 'good';
            }
        }
        if ((int)$round['kills'] >= 2) {
            $reasons[] = (int)$round['kills'] . 'キルでラウンドに大きく貢献しました。';
            $importance += (int)$round['kills'] * 15;
        }
        if ((int)$round['damage'] >= 200) {
            $reasons[] = (int)$round['damage'] . 'ダメージを与えました。';
            $importance += (int)round((int)$round['damage'] / 20);
        }
        if ($reasons !== []) {
            $items[] = [
                'number' => (int)$round['number'],
                'won' => (bool)$round['won'],
                'side' => (string)$round['side'],
                'endType' => (string)$round['endType'],
                'kills' => (int)$round['kills'],
                'deaths' => (int)$round['deaths'],
                'damage' => (int)$round['damage'],
                'allyLoadoutValue' => (int)$round['allyLoadoutValue'],
                'enemyLoadoutValue' => (int)$round['enemyLoadoutValue'],
                'loadoutDifference' => $difference,
                'type' => $type,
                'importanceScore' => $importance,
                'reasons' => $reasons,
            ];
        }
    }
    usort($items, static fn($a, $b) => $b['importanceScore'] <=> $a['importanceScore']);
    return array_slice($items, 0, 5);
}

function bestRound(array $rounds): ?array
{
    if ($rounds === []) {
        return null;
    }
    $items = [];
    foreach ($rounds as $round) {
        $difference = (int)($round['loadoutDifference'] ?? 0);
        $score = (int)$round['kills'] * 30 + (float)$round['damage'] * 0.12;
        $score += !empty($round['firstKill']) ? 20 : 0;
        $score += !empty($round['won']) ? 20 : 0;
        $score += !empty($round['won']) && $difference <= -3000 ? 25 : 0;
        $score -= !empty($round['firstDeath']) ? 15 : 0;
        $round['performanceScore'] = (int)round($score);
        $items[] = $round;
    }
    usort($items, static fn($a, $b) => $b['performanceScore'] <=> $a['performanceScore']);
    return $items[0];
}

function nextMatchActions(array $rounds, array $sideStats, int $firstKills, int $firstDeaths): array
{
    $actions = [];
    $attack = $sideStats['attack'];
    $defense = $sideStats['defense'];
    $attackRate = $attack['total'] > 0 ? $attack['wins'] / $attack['total'] * 100 : 0;
    $defenseRate = $defense['total'] > 0 ? $defense['wins'] / $defense['total'] * 100 : 0;
    if ($attack['total'] > 0 && $defense['total'] > 0 && abs($attackRate - $defenseRate) >= 15) {
        $side = $attackRate < $defenseRate ? '攻撃側' : '防衛側';
        $rate = (int)round(min($attackRate, $defenseRate));
        $actions[] = "{$side}の勝率が{$rate}%でした。苦戦した側の敗戦ラウンドを優先して振り返りましょう。";
    }
    if ($firstDeaths > $firstKills) {
        $actions[] = "ファーストデス{$firstDeaths}回に対してファーストキルは{$firstKills}回でした。序盤の単独ピークを減らしましょう。";
    }
    $lostWithAdvantage = array_values(array_filter($rounds, static fn($r) => empty($r['won']) && (int)$r['loadoutDifference'] >= 3000));
    if ($lostWithAdvantage !== []) {
        $numbers = implode('、', array_map(static fn($r) => 'R' . $r['number'], $lostWithAdvantage));
        $actions[] = "{$numbers}では装備有利な状態で敗北しました。序盤の人数損失や味方との距離を確認しましょう。";
    }
    $lowDamage = array_values(array_filter($rounds, static fn($r) => empty($r['won']) && (int)$r['damage'] < 50 && (int)$r['deaths'] > 0));
    if (count($lowDamage) >= 3) {
        $actions[] = count($lowDamage) . 'ラウンドで50未満のダメージのまま倒されています。最初の交戦を見直しましょう。';
    }
    if ($actions === []) {
        $actions[] = '大きな弱点は見つかりませんでした。良かったラウンドの立ち位置と味方との合わせ方を再現しましょう。';
    }
    return array_slice($actions, 0, 3);
}

function killMapEvents(array $kills, string $targetPuuid, array $players): array
{
    $playerMap = [];
    foreach ($players as $player) {
        if (!is_array($player)) {
            continue;
        }
        $puuid = (string)($player['puuid'] ?? '');
        $agentId = (string)(arrGet($player, ['agent', 'id'], '') ?? '');
        $playerMap[$puuid] = [
            'agent' => (string)(arrGet($player, ['agent', 'name'], '') ?? ''),
            'agentIcon' => $agentId !== '' ? 'https://media.valorant-api.com/agents/' . rawurlencode($agentId) . '/displayicon.png' : '',
        ];
    }

    $events = [];
    foreach ($kills as $index => $kill) {
        if (!is_array($kill)) {
            continue;
        }
        $killerPuuid = (string)(arrGet($kill, ['killer', 'puuid'], '') ?? '');
        $victimPuuid = (string)(arrGet($kill, ['victim', 'puuid'], '') ?? '');
        $killerLocation = null;
        $locations = is_array($kill['player_locations'] ?? null) ? $kill['player_locations'] : [];
        foreach ($locations as $location) {
            if (is_array($location) && (string)(arrGet($location, ['player', 'puuid'], '') ?? '') === $killerPuuid) {
                $killerLocation = is_array($location['location'] ?? null) ? $location['location'] : null;
                break;
            }
        }
        $victimLocation = is_array($kill['location'] ?? null) ? $kill['location'] : null;
        if ($killerLocation === null || $victimLocation === null) {
            continue;
        }
        $killer = array_merge([
            'puuid' => $killerPuuid,
            'name' => (string)(arrGet($kill, ['killer', 'name'], '不明') ?? '不明'),
            'tag' => (string)(arrGet($kill, ['killer', 'tag'], '') ?? ''),
            'team' => lower((string)(arrGet($kill, ['killer', 'team'], '') ?? '')),
            'isMe' => $killerPuuid === $targetPuuid,
        ], $playerMap[$killerPuuid] ?? []);
        $victim = array_merge([
            'puuid' => $victimPuuid,
            'name' => (string)(arrGet($kill, ['victim', 'name'], '不明') ?? '不明'),
            'tag' => (string)(arrGet($kill, ['victim', 'tag'], '') ?? ''),
            'team' => lower((string)(arrGet($kill, ['victim', 'team'], '') ?? '')),
            'isMe' => $victimPuuid === $targetPuuid,
        ], $playerMap[$victimPuuid] ?? []);
        $events[] = [
            'id' => (int)($kill['round'] ?? 0) . '-' . $index,
            'round' => (int)($kill['round'] ?? 0) + 1,
            'timeMs' => (int)($kill['time_in_round_in_ms'] ?? 0),
            'weapon' => (string)(arrGet($kill, ['weapon', 'name'], '不明') ?? '不明'),
            'killer' => $killer,
            'victim' => $victim,
            'killerLocation' => ['x' => (float)($killerLocation['x'] ?? 0), 'y' => (float)($killerLocation['y'] ?? 0)],
            'victimLocation' => ['x' => (float)($victimLocation['x'] ?? 0), 'y' => (float)($victimLocation['y'] ?? 0)],
        ];
    }
    return $events;
}

function fetchMapInfo(string $mapName, array $config): ?array
{
    $path = cachePath('maps', 'en-US');
    $cached = readCache($path, MAP_CACHE_TTL);
    $maps = is_array($cached['maps'] ?? null) ? $cached['maps'] : null;
    if ($maps === null) {
        try {
            $result = requestJson('https://valorant-api.com/v1/maps?language=en-US', null, $config);
            $maps = is_array($result['data']['data'] ?? null) ? $result['data']['data'] : [];
            writeCache($path, ['maps' => $maps]);
        } catch (Throwable) {
            return null;
        }
    }
    $wanted = lower(trim($mapName));
    foreach ($maps as $map) {
        if (is_array($map) && lower(trim((string)($map['displayName'] ?? ''))) === $wanted) {
            return $map;
        }
    }
    return null;
}

function normalizeMatchDetail(array $match, string $targetName, string $targetTag, ?array $mapInfo): array
{
    $players = is_array($match['players'] ?? null) ? $match['players'] : [];
    $target = findTargetPlayer($players, $targetName, $targetTag);
    if ($target === null) {
        throw new ApiException('対象プレイヤーが試合データ内に見つかりませんでした。', 404, 'NOT_FOUND');
    }
    $targetPuuid = (string)($target['puuid'] ?? '');
    $targetTeam = playerTeam($target);
    $rawRounds = is_array($match['rounds'] ?? null) ? $match['rounds'] : [];
    $kills = is_array($match['kills'] ?? null) ? $match['kills'] : [];
    $roundCount = max(1, count($rawRounds));
    $normalizedPlayers = [];
    foreach ($players as $player) {
        if (is_array($player)) {
            $normalizedPlayers[] = normalizeDetailPlayer($player, $targetPuuid, $roundCount);
        }
    }
    $me = null;
    $ally = [];
    $enemy = [];
    foreach ($normalizedPlayers as $player) {
        if ($player['isMe']) {
            $me = $player;
        }
        if ($player['team'] === $targetTeam) {
            $ally[] = $player;
        } else {
            $enemy[] = $player;
        }
    }
    if ($me === null) {
        throw new ApiException('対象プレイヤーの統計を作成できませんでした。', 404, 'NOT_FOUND');
    }

    $teamByPuuid = [];
    foreach ($players as $player) {
        if (is_array($player)) {
            $teamByPuuid[(string)($player['puuid'] ?? '')] = playerTeam($player);
        }
    }

    $roundDetails = [];
    foreach ($rawRounds as $index => $round) {
        if (!is_array($round)) {
            continue;
        }
        $number = $index + 1;
        $winningTeam = lower((string)($round['winning_team'] ?? ''));
        $roundStats = is_array($round['stats'] ?? null) ? $round['stats'] : [];
        $playerStat = null;
        $allyLoadout = 0;
        $enemyLoadout = 0;
        foreach ($roundStats as $stat) {
            if (!is_array($stat)) {
                continue;
            }
            $puuid = (string)(arrGet($stat, ['player', 'puuid'], '') ?? '');
            if ($puuid === $targetPuuid) {
                $playerStat = $stat;
            }
            $statTeam = lower((string)(firstValue(
                arrGet($stat, ['player', 'team_id']),
                arrGet($stat, ['player', 'team']),
                $teamByPuuid[$puuid] ?? null
            ) ?? ''));
            $value = (int)(firstValue(arrGet($stat, ['economy', 'loadout_value']), arrGet($stat, ['economy', 'loadoutValue']), 0) ?? 0);
            if ($statTeam === $targetTeam) {
                $allyLoadout += $value;
            } elseif ($statTeam !== '') {
                $enemyLoadout += $value;
            }
        }

        $roundKills = array_values(array_filter($kills, static fn($kill) => is_array($kill) && (int)($kill['round'] ?? -1) === $index));
        usort($roundKills, static fn($a, $b) => (int)($a['time_in_round_in_ms'] ?? 0) <=> (int)($b['time_in_round_in_ms'] ?? 0));
        $myKills = array_values(array_filter($roundKills, static fn($kill) => (string)(arrGet($kill, ['killer', 'puuid'], '') ?? '') === $targetPuuid));
        $myDeath = null;
        foreach ($roundKills as $kill) {
            if ((string)(arrGet($kill, ['victim', 'puuid'], '') ?? '') === $targetPuuid) {
                $myDeath = $kill;
                break;
            }
        }
        $firstEvent = $roundKills[0] ?? null;
        $economy = is_array($playerStat['economy'] ?? null) ? $playerStat['economy'] : [];
        $damage = 0;
        $damageEvents = is_array($playerStat['damage_events'] ?? null) ? $playerStat['damage_events'] : [];
        foreach ($damageEvents as $event) {
            $damage += is_array($event) ? (int)($event['damage'] ?? 0) : 0;
        }
        $difference = $allyLoadout - $enemyLoadout;
        $detail = [
            'number' => $number,
            'winningTeam' => $winningTeam,
            'won' => $winningTeam === $targetTeam,
            'side' => playerSideForRound($targetTeam, $number),
            'endType' => (string)($round['result'] ?? ''),
            'kills' => count($myKills),
            'deaths' => $myDeath !== null ? 1 : 0,
            'damage' => $damage,
            'weapon' => (string)(firstValue(arrGet($economy, ['weapon', 'name']), arrGet($myKills[0] ?? [], ['weapon', 'name'])) ?? 'なし'),
            'armor' => (string)(arrGet($economy, ['armor', 'name'], 'なし') ?? 'なし'),
            'loadoutValue' => (int)(firstValue($economy['loadout_value'] ?? null, $economy['loadoutValue'] ?? null, 0) ?? 0),
            'remainingCredits' => (int)($economy['remaining'] ?? 0),
            'allyLoadoutValue' => $allyLoadout,
            'enemyLoadoutValue' => $enemyLoadout,
            'loadoutDifference' => $difference,
            'loadoutAdvantage' => $difference >= 3000 ? 'advantage' : ($difference <= -3000 ? 'disadvantage' : 'even'),
            'firstKill' => $firstEvent !== null && (string)(arrGet($firstEvent, ['killer', 'puuid'], '') ?? '') === $targetPuuid,
            'firstDeath' => $firstEvent !== null && (string)(arrGet($firstEvent, ['victim', 'puuid'], '') ?? '') === $targetPuuid,
            'firstEventTimeMs' => (int)($firstEvent['time_in_round_in_ms'] ?? 0),
            'deathTimeMs' => (int)($myDeath['time_in_round_in_ms'] ?? 0),
            'plant' => (string)(arrGet($round, ['plant', 'player', 'puuid'], '') ?? '') === $targetPuuid,
            'defuse' => (string)(arrGet($round, ['defuse', 'player', 'puuid'], '') ?? '') === $targetPuuid,
        ];
        $detail['roundAnalysis'] = roundAnalysis($detail);
        $roundDetails[] = $detail;
    }

    $sideStats = [
        'attack' => ['wins' => 0, 'losses' => 0, 'total' => 0, 'kills' => 0, 'deaths' => 0, 'damage' => 0],
        'defense' => ['wins' => 0, 'losses' => 0, 'total' => 0, 'kills' => 0, 'deaths' => 0, 'damage' => 0],
    ];
    foreach ($roundDetails as $round) {
        $side = $round['side'];
        ++$sideStats[$side]['total'];
        ++$sideStats[$side][$round['won'] ? 'wins' : 'losses'];
        $sideStats[$side]['kills'] += $round['kills'];
        $sideStats[$side]['deaths'] += $round['deaths'];
        $sideStats[$side]['damage'] += $round['damage'];
    }

    $weaponCounts = [];
    foreach ($kills as $kill) {
        if (is_array($kill) && (string)(arrGet($kill, ['killer', 'puuid'], '') ?? '') === $targetPuuid) {
            $weapon = (string)(arrGet($kill, ['weapon', 'name'], '不明') ?? '不明');
            $weaponCounts[$weapon] = ($weaponCounts[$weapon] ?? 0) + 1;
        }
    }
    arsort($weaponCounts);
    $weapons = [];
    foreach ($weaponCounts as $weapon => $count) {
        $weapons[] = ['name' => $weapon, 'kills' => $count];
    }
    $firstKills = count(array_filter($roundDetails, static fn($round) => !empty($round['firstKill'])));
    $firstDeaths = count(array_filter($roundDetails, static fn($round) => !empty($round['firstDeath'])));

    $teams = is_array($match['teams'] ?? null) ? $match['teams'] : [];
    $myTeam = null;
    $enemyTeam = null;
    foreach ($teams as $team) {
        if (!is_array($team)) {
            continue;
        }
        if (lower((string)($team['team_id'] ?? '')) === $targetTeam) {
            $myTeam = $team;
        } else {
            $enemyTeam = $team;
        }
    }
    $myScore = (int)(arrGet($myTeam ?? [], ['rounds', 'won'], 0) ?? 0);
    $enemyScore = (int)(arrGet($enemyTeam ?? [], ['rounds', 'won'], 0) ?? 0);
    $mapName = (string)(arrGet($match, ['metadata', 'map', 'name'], '不明') ?? '不明');
    $mapData = $mapInfo !== null ? [
        'name' => (string)(firstValue($mapInfo['displayName'] ?? null, $mapName) ?? '不明'),
        'image' => (string)($mapInfo['displayIcon'] ?? ''),
        'xMultiplier' => (float)($mapInfo['xMultiplier'] ?? 0),
        'yMultiplier' => (float)($mapInfo['yMultiplier'] ?? 0),
        'xScalarToAdd' => (float)($mapInfo['xScalarToAdd'] ?? 0),
        'yScalarToAdd' => (float)($mapInfo['yScalarToAdd'] ?? 0),
    ] : null;

    return [
        'matchId' => (string)(arrGet($match, ['metadata', 'match_id'], '') ?? ''),
        'map' => $mapName,
        'mode' => (string)(firstValue(arrGet($match, ['metadata', 'queue', 'name']), arrGet($match, ['metadata', 'queue', 'id'])) ?? '不明'),
        'startedAt' => (string)(arrGet($match, ['metadata', 'started_at'], '') ?? ''),
        'score' => $myScore . ' - ' . $enemyScore,
        'myTeamScore' => $myScore,
        'enemyTeamScore' => $enemyScore,
        'win' => (bool)($myTeam['won'] ?? ($myScore > $enemyScore)),
        'playerTeam' => $targetTeam,
        'player' => $me,
        'teams' => ['ally' => $ally, 'enemy' => $enemy],
        'rounds' => $roundDetails,
        'sideStats' => $sideStats,
        'weapons' => $weapons,
        'firstKills' => $firstKills,
        'firstDeaths' => $firstDeaths,
        'flow' => flowAnalysis($roundDetails),
        'analysis' => matchAnalysis($me, $ally, $sideStats, $weapons, $firstKills, $firstDeaths),
        'importantRounds' => importantRounds($roundDetails),
        'bestRound' => bestRound($roundDetails),
        'nextMatchActions' => nextMatchActions($roundDetails, $sideStats, $firstKills, $firstDeaths),
        'mapData' => $mapData,
        'killMapEvents' => killMapEvents($kills, $targetPuuid, $players),
    ];
}

function handleMatch(array $config): void
{
    $matchId = trim((string)($_GET['matchId'] ?? ''));
    if ($matchId === '' || strlen($matchId) > 160 || !preg_match('/^[A-Za-z0-9:_-]+$/', $matchId)) {
        throw new ApiException('試合IDが正しくありません。', 400, 'INVALID_REQUEST');
    }
    $context = readCache(cachePath('context', $matchId), DETAIL_CACHE_TTL);
    $name = trim((string)($_GET['name'] ?? ($context['targetName'] ?? '')));
    $tag = ltrim(trim((string)($_GET['tag'] ?? ($context['targetTag'] ?? ''))), '#');
    if ($name === '' || $tag === '') {
        throw new ApiException('先にトップページで試合一覧を検索してから、試合を選択してください。', 404, 'NOT_FOUND');
    }
    if (strlen($name) > 96 || strlen($tag) > 96) {
        throw new ApiException('プレイヤー名またはタグが長すぎます。', 400, 'INVALID_REQUEST');
    }
    $detailPath = cachePath('detail', $matchId . '|' . lower($name) . '|' . lower($tag));
    $cached = readCache($detailPath, DETAIL_CACHE_TTL);
    if ($cached !== null && is_array($cached['data'] ?? null)) {
        respond($cached['data'], 200, ['X-App-Cache' => 'HIT']);
    }

    requireRateLimitSlot('detail|' . clientIp(), (int)$config['detail_rate_limit'], 60, 'LOCAL_RATE_LIMIT');
    $region = rawurlencode((string)$config['region']);
    $url = 'https://api.henrikdev.xyz/valorant/v4/match/' . $region . '/' . rawurlencode($matchId);
    $json = upstreamJson($url, $config);
    $match = is_array($json['data'] ?? null) ? $json['data'] : $json;
    $mapName = (string)(arrGet($match, ['metadata', 'map', 'name'], '') ?? '');
    $detail = normalizeMatchDetail($match, $name, $tag, fetchMapInfo($mapName, $config));
    writeCache($detailPath, ['data' => $detail]);
    respond($detail, 200, ['X-App-Cache' => 'MISS']);
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        throw new ApiException('GETリクエストを使用してください。', 405, 'INVALID_REQUEST');
    }
    ensureCacheDirectory();
    maybeCleanupCache();
    $config = loadConfig();
    $action = lower(trim((string)($_GET['action'] ?? '')));
    if ($action === 'matches') {
        handleMatches($config);
    } elseif ($action === 'match') {
        handleMatch($config);
    } else {
        throw new ApiException('action には matches または match を指定してください。', 400, 'INVALID_REQUEST');
    }
} catch (ApiException $error) {
    errorResponse($error);
} catch (Throwable $error) {
    error_log('RoundCoach API error: ' . $error->getMessage());
    errorResponse(new ApiException('サーバー内部でエラーが発生しました。時間を置いて再度お試しください。', 500, 'INTERNAL_ERROR'));
}
