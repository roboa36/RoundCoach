<?php
declare(strict_types=1);

/*
 * このファイルを config.php という名前でコピーし、APIキーを設定してください。
 * config.php は公開リポジトリへコミットしないでください。
 */
return [
    'henrik_api_key' => '', // ここにHenrikDevのAPIキーを入力
    'region' => 'ap',

    // 共有サーバーでPHPプロセスを長時間占有しないためのタイムアウト（秒）
    'connect_timeout' => 5,
    'request_timeout' => 15,

    // 1分間の上限。通常は変更不要です。
    'search_rate_limit' => 8,   // 同一IPからの一覧検索（キャッシュミス時）
    'detail_rate_limit' => 12,  // 同一IPからの詳細取得（キャッシュミス時）
    'upstream_rate_limit' => 24 // サイト全体からHenrikDev APIへのリクエスト
];
