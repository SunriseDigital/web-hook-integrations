PivotalTracker to ChatWork
==========================

ToChatwork.php
--------------

PivotalTracker のアクティビティを逐一チャットワークにメッセージとして送信するスクリプトです。

### 使い方

`Config.php` を `Config.php.example` を参考に作成し、log ディレクトリのパーミッションを書き込み可能にして準備完了。  
log ディレクトリが Web から閲覧可能になっているのが気になる場合は適当に書き込み先を変更するなどしてください。

`ToChatWork.php?room_id=[your-room-id]` のような形で呼び出すとそこに投稿します。

ToChatWorkOnReleased.php
------------------------

PivotalTracker 上で accept された release story や、accepted な他の stroy を release に変更した場合に ChatWork 側にタスクを追加するスクリプトです。

### 使い方

ほぼ ToChatwork.php と同じですが、渡すべきパラメータは少し変わっています。

`ToChatWorkOnReleased.php?room_id=[your-room-id]&task_user_ids=[aid,aid,aid...]`

room_id に指定した部屋の task_user_ids に指定したユーザーにタスクを追加します。
