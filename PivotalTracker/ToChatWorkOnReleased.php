<?php
require_once(dirname(__FILE__)."/Config.php");

// accept されていない release が accept されたり、
// accept 済みで release ではなかったものが release に変更された時を検出して $found コールバックを呼ぶ。
function enumReleasedStories($json, $found) {
  switch ($json->kind) {
  case "story_create_activity":
    foreach ($json->changes as $change) {
      if (!isset($change->story_type)) {
        continue;
      }
      if ($change->story_type != "release") {
        continue;
      }
      if (!isset($change->new_values->current_state)) {
        continue;
      }
      if ($change->new_values->current_state != "accepted") {
        continue;
      }
      $found($change->id);
    }
    break;
  case "story_update_activity":
    foreach ($json->changes as $change) {
      if (!isset($change->story_type)) {
        continue;
      }
      if ($change->story_type != "release") {
        continue;
      }
      // ストーリータイプが release 以外から release に変更された場合
      if (
        isset($change->new_values->story_type) &&
        $change->new_values->story_type == "release"
      ) {
        // もし state が変更されている場合はそれが accepted じゃないとダメ
        if (
          isset($change->new_values->current_state) &&
          $change->new_values->current_state != "accepted"
        ) {
          continue;
        }
        $found($change->id);
      }
      // release が accepted された場合
      if (
        isset($change->new_values->current_state) &&
        $change->new_values->current_state == "accepted"
      ) {
        $found($change->id);
      }
    }
    break;
  }
}

function main(){
  // PivotalTracker API v5 WebHook のデータは生 POST データとして JSON が渡される
  $raw = file_get_contents("php://input");

  //後で見れるように最新のものだけは控えておく
  //file_put_contents が成功するように事前にサーバ側を準備しておくこと
  file_put_contents("log/from_pivotal_on_released", $raw);
  $json = json_decode($raw);

  // 投稿対象の部屋のIDを取得
  if (!array_key_exists('room_id', $_GET)){
    throw new Exception("呼び出し時に room_id が指定されていない");
  }
  $room_id = $_GET['room_id'];

  // タスク割り当て対象のユーザーIDを取得
  if (!array_key_exists('task_user_ids', $_GET)){
    throw new Exception("呼び出し時に task_user_ids が指定されていない");
  }
  $task_user_ids = explode(',', $_GET['task_user_ids']);

  // 完了した release story があるか調べる
  $founds = array();
  try {
    enumReleasedStories($json, function($id) use (&$founds) {
      $founds[] = $id;
    });
  } catch (Exception $e) {
    file_put_contents("log/on_release_ret.log", date("Y-m-d H:i:s - ") . "exception\n", FILE_APPEND);
    throw $e;
  }

  if (count($founds) == 0) {
    //file_put_contents("log/on_release_ret.log", date("Y-m-d H:i:s - "). "not found\n", FILE_APPEND);
    return;
  }

  // 完了した全ての story に関して ChatWork 上にタスクを追加する
  foreach($founds as $id) {
    try {
      $raw_story_json = getStory(PIVOTALTRACKER_API_TOKEN, $json->project->id, $id);
      $story_json = json_decode($raw_story_json);

      // ラベルの収集
      $labels = array();
      if (isset($story_json->labels) && is_array($story_json->labels)) {
        foreach($story_json->labels as $label) {
          $labels[] = $label->name;
        }
      }

      // タスクの組み立て
      $body = sprintf(
        "[info][title]リリース完了[/title]%s（%s）%s%s\n\n%s[/info]",
        $story_json->name,
        $json->project->name,
        isset($story_json->description) ? "\n\n".$story_json->description : "",
        count($labels) ? "\n\nラベル: ".implode(', ', $labels) : "",
        $story_json->url
      );
  
      // まだロックファイルが存在せず、作成に成功した場合だけ投稿する
      $fp = @fopen(sprintf("/tmp/PT_ToChatworkOnReleased_%d", $id), "x");
      if ($fp) {
        addTaskChatWork(CHATWORK_API_TOKEN, $room_id, $task_user_ids, $body);
        //file_put_contents("log/on_release_ret.log", date("Y-m-d H:i:s - ") . var_export($room_id, true) . var_export($task_user_ids, true) . var_export($body, true), FILE_APPEND);
        fclose($fp);
      }
    } catch (Exception $e) {
      $message = "完了されたストーリーの処理中にエラーが発生しました。\n";
      $message .= "以下のストーリーに関する完了レポートを手動で行う必要があります。\n";
      $message .= "[info]https://www.pivotaltracker.com/story/show/${id}[/info]\n";
      postChatWork(CHATWORK_API_TOKEN, $room_id, "[info]${message}[/info]");
      log_exception($e, false);
    }
  }
}

// PivotalTracker の story の詳細を取得する
function getStory($api_token, $project_id, $story_id) {
  $url = sprintf("https://www.pivotaltracker.com/services/v5/projects/%d/stories/%d", $project_id, $story_id);
  $headers = array(
    'X-TrackerToken: ' . $api_token,
    'Content-Type: application/x-www-form-urlencoded',
  );
  $options = array('http' => array(
      'method' => 'GET',
      'header' => implode("\r\n", $headers),
  ));
  return file_get_contents($url, false, stream_context_create($options));
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

// チャットワークにタスクを追加する
function addTaskChatWork($api_token, $room_id, $to_ids, $body, $limit = null) {
  $url = sprintf('https://api.chatwork.com/v1/rooms/%s/tasks', $room_id);
  if (!is_array($to_ids)) {
    $to_ids = array($to_ids);
  }
  $data = array(
      'to_ids' => implode(',', $to_ids),
      'body' => $body,
  );
  if ($limit) {
    $data['limit'] = $limit;
  }
  $headers = array(
    'X-ChatWorkToken: ' . $api_token,
    'Content-Type: application/x-www-form-urlencoded',
  );
  $options = array('http' => array(
      'method' => 'POST',
      'content' => http_build_query($data),
      'header' => implode("\r\n", $headers),
  ));
  //file_put_contents("log/on_release_ret.log", date("Y-m-d H:i:s - ") . var_export($options['http']['content'], true) . var_export($options['http']['header'], true), FILE_APPEND);
  return file_get_contents($url, false, stream_context_create($options));
}

// エラー発生時のログ処理

function log_error($num, $str, $file, $line, $context = null) {
    log_exception(new ErrorException($str, 0, $num, $file, $line));
}

function log_exception(Exception $e, $exit = true) {
  $message = sprintf(
    "Type: %s\nMessage: %s\nFile: %s\nLine: %s\nDate: %s\n",
    get_class($e),
    $e->getMessage(),
    $e->getFile(),
    $e->getLine(),
    date("Y-m-d H:i:s")
  );
  file_put_contents("log/pivotal_tracker_hook_exceptions_on_released.log", $message, FILE_APPEND);
  if ($exit) {
    exit();
  }
}

set_error_handler("log_error");
set_exception_handler("log_exception");
ini_set("display_errors", "off");
error_reporting(E_ALL);
main();


