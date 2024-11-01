<?php

/**
 * Description of BAjax
 *
 * @author nur
 */
class TaketinMpAjax
{


    public static function api_user()
    {
        //ユニークコードを取得
        $unique_code = filter_input(INPUT_POST, 'unique_code');
        //API通信
        $response = json_decode(TaketinMpUtils::get_api_user($unique_code));
        echo wp_json_encode($response);
        exit;
    }

    public static function api_user_ticket()
    {
        //ユニークコードを取得
        $unique_code = filter_input(INPUT_POST, 'unique_code');
        //API通信
        $response = json_decode(TaketinMpUtils::get_api_user_ticket($unique_code));
        echo wp_json_encode($response);
        exit;
    }

    public static function wizard_api_ticket()
    {
        //APIのURLを取得
        $endpoint = filter_input(INPUT_POST, 'endpoint');
        //APIのキーを取得
        $api_key = filter_input(INPUT_POST, 'api_key');
        $response = json_decode(TaketinMpUtils::wizard_get_api_ticket($endpoint, $api_key));
        echo wp_json_encode($response);
        exit;
    }

    public static function wizard_save_configurator()
    {
        //AJAXから送信されたデータを取得
        $params = filter_input(INPUT_POST, "params", FILTER_DEFAULT, FILTER_REQUIRE_ARRAY);
        // if (TMP_MEM_DEBUG) error_log(__CLASS__ . ":" . __FUNCTION__ . " [LINE:" . __LINE__ . "] TEST: " . print_r($params, true), 0);
        $result = TaketinMpUtils::save_configurator($params);
        echo wp_json_encode($result);
        exit;
    }

    public static function wizard_end_configurator()
    {
        //AJAXから送信されたデータを取得
        $params = filter_input(INPUT_POST, "params", FILTER_DEFAULT, FILTER_REQUIRE_ARRAY);
        update_option('tmp-finish-setup', true);
        $result['result'] = true;
        echo wp_json_encode($result);
        exit;
    }

    //所持するすべてのチケットIDを元に会員レベル判定
    public static function get_membership_level_from_tickets()
    {
        //チケットIDを取得
        $ticket_ids = filter_input(INPUT_POST, "ticket_ids", FILTER_DEFAULT, FILTER_REQUIRE_ARRAY);

        if (count($ticket_ids) == 0) {
            return wp_json_encode(array("result" => false));
        }
        $membership_level = TaketinMpUtils::get_membership_level($ticket_ids);
        echo wp_json_encode($membership_level);
        exit;
    }

    //重複ログインチェック 
    public static function duplicate_login_check()
    {

        $result['result'] = true;
        $result['mess'] = 'No problem.';
        $auth = TmpAuth::get_instance();
        if ($auth->settings['enable-duplicate-login-check']) {
            $loginToken = filter_input(INPUT_POST, 'login_token');
            $unique_code = filter_input(INPUT_POST, 'unique_code');
            if (TMP_MEM_DEBUG) error_log(__CLASS__ . ":" . __FUNCTION__ . " [LINE:" . __LINE__ . "] INFO: AJAX重複ログインチェック", 0);
            $tmp_member_data = $auth->_get_member(null, $unique_code);
            if (isset($tmp_member_data['login_token']) && $tmp_member_data['login_token']) {
                if ($loginToken != $tmp_member_data['login_token']) {
                    if (TMP_MEM_DEBUG) error_log(__CLASS__ . ":" . __FUNCTION__ . " [LINE:" . __LINE__ . "] INFO: AJAX別の端末・場所からログインTokenが上書きされているのでNG ", 0);
                    //以上（重複している）
                    $result['result'] = false;
                    $result['mess'] = "Bad! It is duplicate.";
                } else {
                    //正常（重複していない）
                    $result['result'] = true;
                    $result['mess'] = "OK! It is Not duplicate.";
                }
            }
        }
        echo wp_json_encode($result);
        wp_die();
    }
}
