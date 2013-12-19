PivotalTracker to ChatWork
==========================

PivotalTracker のアクティビティを逐一チャットワークにメッセージとして送信するスクリプトです。

使い方
------

`Config.php` を `Config.php.example` を参考に作成し、log ディレクトリのパーミッションを書き込み可能にして準備完了。  
log ディレクトリが Web から閲覧可能になっているのが気になる場合は適当に書き込み先を変更するなどしてください。

`ToChatWork.php?room_id=[your-room-id]` のような形で呼び出すとそこに投稿します。
