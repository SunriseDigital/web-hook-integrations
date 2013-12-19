<?php
require_once(dirname(__FILE__)."/Config.php");
  
function main(){
  // PivotalTracker API v5 WebHook のデータは生 POST データとして JSON が渡される
  $raw = file_get_contents("php://input");

  //後で見れるように最新のものだけは控えておく
  //file_put_contents が成功するように事前にサーバ側を準備しておくこと
  file_put_contents("log/from_pivotal", $raw);
  $json = json_decode($raw);
  
  // 投稿対象の部屋のIDを取得
  if (!isset($_GET['room_id'])){
    new Exception("呼び出し時に room_id が指定されていない");
  }
  $room_id = $_GET['room_id'];

  // タイトルの組み立て
  $title = sprintf(
    "[PivotalTracker] %s at %s",
    $json->project->name,
    date("Y-m-d H:i:s", $json->occurred_at / 1000)
  );

  // 本文の組み立て

  // 付与するエモーティコン
  $emoticon = "";
  switch ($json->highlight) {
  case "accepted":
    $emoticon = "(cracker) ";
    break;
  case "started":
    $emoticon = "(y) ";
    break;
  }
  // このアクティビティに関連するストーリーの列挙（ひとつとは限らない）
  $stories = array();
  foreach ($json->primary_resources as $rsc) {
    if ($rsc->kind != "story") {
      new Exception("primary_resources の kind が story じゃなくて " . $rsc->kind);
    }
    $stories[] = sprintf("[info]%s\n%s\n[/info]", $rsc->name, $rsc->url);
  }
  $message = sprintf(
    "[info][title]%s[/title]%s%s%s[/info]",
    $title,
    $emoticon,
    $json->message . ".",
    implode("\n", $stories)
  );
  
  postChatWork(CHATWORK_API_TOKEN, $room_id, $message);
}

// チャットワークにメッセージを投稿する
function postChatWork($api_token, $room_id, $message) {
  $url = sprintf('https://api.chatwork.com/v1/rooms/%s/messages', $room_id);
  $data = array(
      'body' => $message,
  );
  $headers = array(
    'X-ChatWorkToken: ' . $api_token,
    'Content-Type: application/x-www-form-urlencoded',
  );
  $options = array('http' => array(
      'method' => 'POST',
      'content' => http_build_query($data),
      'header' => implode("\r\n", $headers),
  ));
  return file_get_contents($url, false, stream_context_create($options));
}

// エラー発生時のログ処理

function log_error($num, $str, $file, $line, $context = null) {
    log_exception(new ErrorException($str, 0, $num, $file, $line));
}

function log_exception(Exception $e) {
  $message = sprintf(
    "Type: %s\nMessage: %s\nFile: %s\nLine: %s\n",
    get_class($e),
    $e->getMessage(),
    $e->getFile(),
    $e->getLine()
  );
  file_put_contents("log/pivotal_tracker_hook_exceptions.log", $message, FILE_APPEND);
  exit();
}

register_shutdown_function("check_for_fatal");
set_error_handler("log_error");
set_exception_handler("log_exception");
ini_set("display_errors", "off");
error_reporting(E_ALL);
main();


