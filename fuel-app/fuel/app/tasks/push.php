<?php

//ドキュメントルートの指定で書かないといけないやつ
namespace Fuel\Tasks;

class Push
{
    public static function run () {
        /**
         * curl_multiでHTTP複数リクエストを並列実行するテンプレ
         *
         */

        //タイムアウト時間を決めておく
        $TIMEOUT = 100; //10秒

        /*
        * 1) 準備
        *  - curl_multiハンドラを用意
        *  - 各リクエストに対応するcurlハンドラを用意
        *    リクエスト分だけ必要
        *    * レスポンスが必要な場合はRETURNTRANSFERオプションをtrueにしておくこと。
        *  - 全てcurl_multiハンドラに追加
        */
        $mh = curl_multi_init();

        // 動的にURLリストを生成
        $call_ulr = "http://apache-api-srv/sleep.php?wait=";
        #$call_ulr = "http://nodejs-api-srv/?wait=";
        $urls = array();
        for ($i = 1; $i <= 1000; $i++) {
            $urls[] =  $call_ulr . $i;
        }
        foreach ($urls as $u) {
            $ch = curl_init();
            curl_setopt_array($ch, array(
                CURLOPT_URL            => $u,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $TIMEOUT,
                CURLOPT_CONNECTTIMEOUT => $TIMEOUT,
            ));
            curl_multi_add_handle($mh, $ch);
        }


        /*
        * 2) リクエストを開始する
        *  - curl_multiでは即座に制御が戻る（レスポンスが返ってくるのを待たない）
        *  - いきなり失敗するケースを考えてエラー処理を書いておく
        *  - do～whileはlibcurl<7.20で必要
        */
        do {
            $stat = curl_multi_exec($mh, $running); //multiリクエストスタート
        } while ($stat === CURLM_CALL_MULTI_PERFORM);
        if ( ! $running || $stat !== CURLM_OK) {
            throw new RuntimeException('リクエストが開始出来なかった。マルチリクエスト内のどれか、URLの設定がおかしいのでは？');
        }

        /*
        * 3) レスポンスをcurl_multi_selectで待つ
        *  - 何かイベントがあったらループが進む
        *    selectはイベントが起きるまでCPUをほとんど消費せずsleep状態になる
        *  - どれか一つレスポンスが返ってきたらselectがsleepを中断して何か数字を返す。
        *
        */
        do switch (curl_multi_select($mh, $TIMEOUT)) { //イベントが発生するまでブロック
            // 最悪$TIMEOUT秒待ち続ける。
            // あえて早めにtimeoutさせると、レスポンスを待った状態のまま別の処理を挟めるようになります。
            // もう一度curl_multi_selectを繰り返すと、またイベントがあるまでブロックして待ちます。

            case -1: //selectに失敗。ありうるらしい。 https://bugs.php.net/bug.php?id=61141
                usleep(10); //ちょっと待ってからretry。ここも別の処理を挟んでもよい。
                do {
                    $stat = curl_multi_exec($mh, $running);
                } while ($stat === CURLM_CALL_MULTI_PERFORM);
                continue 2;

            case 0:  //タイムアウト -> 必要に応じてエラー処理に入るべきかも。
                continue 2; //ここではcontinueでリトライします。

            default: //どれかが成功 or 失敗した
                do {
                    $stat = curl_multi_exec($mh, $running); //ステータスを更新
                } while ($stat === CURLM_CALL_MULTI_PERFORM);

                do if ($raised = curl_multi_info_read($mh, $remains)) {
                    //変化のあったcurlハンドラを取得する
                    $info = curl_getinfo($raised['handle']);
                    $error = curl_error($raised['handle']);
                    //echo curl_error($ch);
                    echo "$info[url]: $info[http_code]\n";
                    $response = curl_multi_getcontent($raised['handle']);
                    if ($error) {
                        echo "CURL ERROR: $error\n";
                    }
                    if ($response === false) {
                        //エラー。404などが返ってきている
                        echo 'ERROR!!!', PHP_EOL;
                    } else {
                        //正常にレスポンス取得
                        echo $response, PHP_EOL;
                    }
                    curl_multi_remove_handle($mh, $raised['handle']);
                    curl_close($raised['handle']);
                } while ($remains);
                //select前に全ての処理が終わっていたりすると
                //複数の結果が入っていることがあるのでループが必要

        } while ($running);
        echo 'finished', PHP_EOL;
        curl_multi_close($mh);
    }
}
