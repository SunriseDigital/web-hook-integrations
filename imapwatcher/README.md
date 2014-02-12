Mail to ChatWork
==========================

メールをチャットワークにメッセージとして送信するプログラムです。  
IMAP4 でログインして、存在するメール全てをチャットワークに送信し、サーバからメールを削除します。  
ログインした時点でメールがない場合は IDLE コマンドで待機状態に入ります。

このプログラムは Go 言語で書かれています。

使い方
------

Go言語の開発環境の準備と GOPATH の設定が正しく行われている状態で以下の流れで起動できます。

```bash
go get -u github.com/SunriseDigital/web-hook-integrations/imapwatcher
imapwatcher -api=チャットワークAPIトークン -room=チャットワーク部屋ID -imap=IMAPサーバ -user=IMAPユーザー -pass=IMAPパスワード
```

起動すると無限ループで imap サーバを監視し続けます。  
その他にもいくつかオプションがあり、パラメータを省略して呼び出すと参照できます。

