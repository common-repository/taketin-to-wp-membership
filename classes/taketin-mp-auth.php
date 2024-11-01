<?php

class TmpAuth
{

    public $protected;
    public $permitted;
    private $isLoggedIn;
    private $lastStatusMsg;
    private static $_this;
    private $_error_msg;

    private function __construct()
    {
        $this->isLoggedIn = false;
        $this->protected = TmpProtection::get_instance();
    }

    private function init()
    {
        $valid = $this->_validate();
        if (TMP_MEM_DEBUG) error_log(__CLASS__ . ":" . __FUNCTION__ . " [LINE:" . __LINE__ . "] INFO: " . ($valid ? "valid" : "invalid"), 0);
    }

    public static function get_instance()
    {
        if (empty(self::$_this)) {
            if (TMP_MEM_DEBUG) error_log(__CLASS__ . ":" . __FUNCTION__ . " [LINE:" . __LINE__ . "] INFO: get_instance start.", 0);
            self::$_this = new TmpAuth();
            self::$_this->init();
        }
        return self::$_this;
    }

    /*ログインセッション確認*/
    private function _validate()
    {

        if (!isset($_COOKIE[TMP_MEM_COOKIE_KEY]) || empty($_COOKIE[TMP_MEM_COOKIE_KEY])) {
            //未ログイン
            $this->isLoggedIn = false;
        } else {
            //ログインCookie有り
            $this->isLoggedIn = true;
            error_log(__CLASS__ . ":" . __FUNCTION__ . " [LINE:" . __LINE__ . "] INFO:" . $_COOKIE[TMP_MEM_COOKIE_KEY]['unique_code'], 0);
        }
        if (empty($this->settings)) {
            $this->settings = (array) get_option('tmp-settings');
        }
        // トップページかつ「制限設定のないページ」は表示する設定の場合
        if ((is_home() || is_front_page()) && !empty($this->settings['show-contents-if-no-config'])) {
            return true;
        }
        return $this->isLoggedIn;
    }

    /**
     * 認証メソッド ログイン処理
     * @param string $mail
     * @param string $pass
     */
    private function authenticate($mail = null, $pass = null)
    {
        //Check nonce
        if (!isset($_POST['_wpnonce_login_tmp_end']) || !wp_verify_nonce($_POST['_wpnonce_login_tmp_end'], 'login_tmp_end')) {
            //Nonce check failed.
            //wp_die('Nonce認証エラー 不正なアクセスです。');
            if (TMP_MEM_DEBUG) error_log(__CLASS__ . ":" . __FUNCTION__ . " [LINE:" . __LINE__ . "] API: Nonce認証エラー フォームの期限切れ等", 0);
            $this->_error_msg = '再度ログインをお試しください。';
            $this->isLoggedIn = false;
            return false;
        }

        $tmp_password = empty($pass) ? filter_input(INPUT_POST, 'tmp_password') : $pass;
        $tmp_mail = empty($mail) ? filter_input(INPUT_POST, 'tmp_mail') : $mail;
        $auto_login = filter_input(INPUT_POST, 'auto_login', FILTER_SANITIZE_STRING);

        if (!empty($tmp_mail) && !empty($tmp_password)) {

            //API認証
            $unique_code = $this->_api_login($tmp_mail, $tmp_password);
            if (!empty($unique_code['code']) && substr($unique_code['code'], 0, 1) == '5') {
                // 通信エラー
                if (TMP_MEM_DEBUG) error_log(__CLASS__ . ":" . __FUNCTION__ . " [LINE:" . __LINE__ . "] API: 通信失敗", 0);
                $this->_error_msg = '通信エラーが発生しました。この状態が続くようであれば運営元までご連絡ください。';
                //NGならログイン画面へリダイレクト
                $this->isLoggedIn = false;
                return false;
            }
            if (!$unique_code) {
                //認証失敗
                if (TMP_MEM_DEBUG) error_log(__CLASS__ . ":" . __FUNCTION__ . " [LINE:" . __LINE__ . "] API: 認証失敗", 0);
                $this->_error_msg = 'メールアドレスかパスワードに誤りがあります';
                //NGならログイン画面へリダイレクト
                $this->isLoggedIn = false;
                return false;
            }

            //DBから会員レコードを探す
            $tmp_member_data = $this->_get_member($tmp_mail, $unique_code);
            if (!$tmp_member_data) {
                //会員レコードがなければ作成
                $tmp_member_data = $this->create_tmp_member($tmp_mail, $tmp_password, $unique_code);
            } else {
                //会員レコードがあればAPIから所持チケットを取得し会員レベルに変動がないかチェックし対象カラムの情報更新
                if (TMP_MEM_DEBUG) error_log(__CLASS__ . ":" . __FUNCTION__ . " [LINE:" . __LINE__ . "] INFO: 既存ユーザー", 0);
                $tmp_member_data = $this->update_tmp_member($tmp_member_data);
            }
            if (!$tmp_member_data) return false;
            //ログイン状態
            $this->isLoggedIn = true;
            //ログインセッションを保存
            TaketinMpUtils::set_cookie_tmp_member($tmp_member_data, $auto_login);

            return true;
        }
        $this->_error_msg = 'メールアドレスとパスワードを入力してください';
        return false;   // initから呼ばれた場合
    }

    /**
     * 会員情報を新規作成
     **/
    function create_tmp_member($mail, $password, $unique_code)
    {

        //CMSからユーザー情報を取得する
        if (TMP_MEM_DEBUG) error_log(__CLASS__ . ":" . __FUNCTION__ . " [LINE:" . __LINE__ . "] INFO: API:ユーザー情報取得", 0);
        $cms_user_data = $this->_api_get_user_data($mail, $unique_code);
        if (!$cms_user_data) return false; //エラーのため終了

        //CMSからユーザーの所持するチケット情報を取得する
        if (TMP_MEM_DEBUG) error_log(__CLASS__ . ":" . __FUNCTION__ . " [LINE:" . __LINE__ . "] INFO: API: 所持チケット取得", 0);
        $tInfo = $this->_api_get_user_ticket_info($unique_code);
        $ticket_ids = $tInfo['ids'];
        $tickets = $tInfo['tickets'];
        if (!$ticket_ids) return false; //エラーのため終了

        //DBからチケットIDに該当する会員レベルを取得する
        if (TMP_MEM_DEBUG) error_log(__CLASS__ . ":" . __FUNCTION__ . " [LINE:" . __LINE__ . "] INFO: DB:会員レベル判定", 0);
        $memberships_level_id = $this->_get_membership_level_id($ticket_ids);
        if (!$memberships_level_id) return false; //エラーのため終了

        //登録情報を作成
        $insert_date = wp_date("Y-m-d H:i:s");
        $ip = isset($_SERVER["REMOTE_ADDR"]) ? $_SERVER["REMOTE_ADDR"] : '127.0.0.1';
        $token = TaketinMpUtils::get_token_strings(30);
        $insert_data = array(
            'name_sei' => $cms_user_data["name_sei"],
            'name_mei' => $cms_user_data["name_mei"],
            'email' => $mail,
            'unique_code' => $unique_code,
            'ticket_list_serialized' => serialize($tickets),
            'memberships_id' => $memberships_level_id,
            'progress' => 0,
            'memberships_check_date' => $insert_date,
            'last_login' => $insert_date,
            'created' => $insert_date,
            'last_login_ip' => $ip,
            'login_token' => $token,
            'display_name' => $cms_user_data["username"],
            //	        'code' => $cms_user_data["code"],
        );

        //登録処理
        if (TMP_MEM_DEBUG) error_log(__CLASS__ . ":" . __FUNCTION__ . " [LINE:" . __LINE__ . "] INFO: DB:会員データ新規登録", 0);
        $result = TaketinMpMember::get_instance()->create_member_authenticate($insert_data, $mailValidate = false);

        if ($result['result'] != true) {
            $this->_error_msg = isset($result['error_msg']) ? $result['error_msg'] : "";
            return false;
        }

        //登録したデータを取得する
        $member_id = $result['member_id'];
        $tmp_member_data = $this->_get_member_by_id($member_id);

        $tmp_member_data["tickets"] = serialize($tInfo['tickets']);

        $tmp_member_data['af_code'] = $cms_user_data["af_code"];

        return $tmp_member_data;
    }

    /**
     * 会員レベルに変更がないかチェックし、会員情報を更新
     **/
    function update_tmp_member($tmp_member, $is_last_login_update = true, $flg_update_token = true)
    {
        $update_member = array();

        //CMSからユーザー情報を取得する
        if (TMP_MEM_DEBUG) error_log(__CLASS__ . ":" . __FUNCTION__ . " [LINE:" . __LINE__ . "] INFO: API:ユーザー情報取得", 0);
        $cms_user_data = $this->_api_get_user_data($mail = null, $tmp_member['unique_code']);
        $tmp_member['display_name'] = sanitize_text_field($cms_user_data['username']);
        $tmp_member['name'] = sanitize_text_field($cms_user_data['name_sei'] . " " . $cms_user_data['name_mei']);
        $tmp_member['email'] = $cms_user_data["email"];
        //		$tmp_member['code'] = $cms_user_data["code"];

        //CMSからユーザーの所持するチケット情報を取得する
        if (TMP_MEM_DEBUG) error_log(__CLASS__ . ":" . __FUNCTION__ . " [LINE:" . __LINE__ . "] INFO: API: 所持チケット取得 -> 差分チェック", 0);
        $tInfo = $this->_api_get_user_ticket_info($tmp_member['unique_code']);
        $ticket_ids = ($tInfo['ids']) ? $tInfo['ids'] : null;
        $tickets = ($tInfo['tickets']) ? $tInfo['tickets'] : null;
        //エラーのため終了
        if (!$ticket_ids) return false;

        if (serialize($tickets) != $tmp_member['ticket_list_serialized']) {
            //APIの値とDBの値との比較で所持チケットが異なる

            //DBからチケットIDに該当する会員レベルを取得する
            if (TMP_MEM_DEBUG) error_log(__CLASS__ . ":" . __FUNCTION__ . " [LINE:" . __LINE__ . "] INFO: DB:会員レベル取得 -> 差分チェック", 0);
            $memberships_level_id = $this->_get_membership_level_id($ticket_ids);
            if (!$memberships_level_id) {
                return false; //エラーのため終了
            }
            if ($memberships_level_id != $tmp_member['memberships_id']) {
                //APIの値から判定した会員レベルとDBの会員レベルが異なる
                if (TMP_MEM_DEBUG) error_log(__CLASS__ . ":" . __FUNCTION__ . " [LINE:" . __LINE__ . "] INFO: 会員Lv更新: 所持チケット変更、会員レベル変更", 0);

                //所持チケット、会員レベルの両方を更新
                $update_member['ticket_list_serialized'] = serialize($tickets);
                $update_member['memberships_id'] = $memberships_level_id;
            } else {
                //会員レベルは変わらない
                if (TMP_MEM_DEBUG) error_log(__CLASS__ . ":" . __FUNCTION__ . " [LINE:" . __LINE__ . "] INFO: 会員Lv更新: 所持チケット変更、会員レベル変更なし", 0);

                //所持チケットのみ更新
                $update_member['ticket_list_serialized'] = serialize($tickets);
            }
        } else {
            //APIの値とDBの値との比較で所持チケットが一致
            if (TMP_MEM_DEBUG) error_log(__CLASS__ . ":" . __FUNCTION__ . " [LINE:" . __LINE__ . "] INFO: 会員Lv更新: 所持チケット変更なし", 0);
        }

        global $wpdb;
        $upd_date = wp_date("Y-m-d H:i:s");
        $update_member["memberships_check_date"] = $upd_date;
        if ($is_last_login_update) $update_member["last_login"] = $upd_date;

        $ip = isset($_SERVER["REMOTE_ADDR"]) ? $_SERVER["REMOTE_ADDR"] : '127.0.0.1';
        if ($flg_update_token) {
            $token = TaketinMpUtils::get_token_strings(30);
            $update_member["last_login_ip"] = $ip;
            $update_member["login_token"] = $token;
        }

        $tmp_member_id = $tmp_member["id"];
        $tmp_member = array_merge($tmp_member, $update_member);
        $wpdb->update($wpdb->prefix . "tmp_members", $tmp_member, array('id' => $tmp_member_id));
        $wpdb->flush();

        $tmp_member["tickets"] = serialize($tInfo['tickets']);

        $tmp_member['af_code'] = $cms_user_data["af_code"];

        unset($tmp_member["last_login"]);
        return $tmp_member;
    }


    /**
     * ログイン認証API
     **/
    private function _api_login($mail, $password)
    {
        $json_result = TaketinMpUtils::get_api_user_login($mail, $password);

        if ($json_result) {
            if (!empty($json_result['response']['code']) && substr($json_result['response']['code'], 0, 1) == '5') {
                return $json_result['response'];
            }

            $result = json_decode($json_result['body'], true);

            if (isset($result["result"]) && $result["result"] == true) {
                //CMS側認証成功
                if (isset($result["hash"]) && !empty($result["hash"])) {
                    $unique_code = $result["hash"];
                    return $unique_code;
                }
            }
        }
        //認証失敗
        return false;
    }

    /**
     * APIを使いユーザー情報を取得する
     * 20200228 メールアドレスは参照しないように変更（第一引数は無効）
     **/
    private function _api_get_user_data($mail, $unique_code)
    {
        $result = array();
        //CMSからユーザー情報を取得する
        $cms_user_object = TaketinMpUtils::get_api_user($unique_code);
        $cms_user_data = json_decode($cms_user_object, true);
        if (isset($cms_user_data['hUser']) && !empty($cms_user_data['hUser'])) {
            $result['username'] = isset($cms_user_data['hUser']['User']['username']) ? $cms_user_data['hUser']['User']['username'] : "";
            $result['name_sei'] = isset($cms_user_data['hUser']['User']['name_sei']) ? $cms_user_data['hUser']['User']['name_sei'] : "";
            $result['name_mei'] = isset($cms_user_data['hUser']['User']['name_mei']) ? $cms_user_data['hUser']['User']['name_mei'] : "";
            $result['email'] = isset($cms_user_data['hUser']['User']['mail']) ? $cms_user_data['hUser']['User']['mail'] : "";
            $result['af_code'] = isset($cms_user_data['hUser']['AfPartner']['code']) ? $cms_user_data['hUser']['AfPartner']['code'] : "";
            //            $result['code'] = isset($cms_user_data['hUser']['User']['code']) ? $cms_user_data['hUser']['User']['code'] : "";
            $result['birth_month'] = isset($cms_user_data['hUser']['User']['birth_month']) ? $cms_user_data['hUser']['User']['birth_month'] : "";
            $result['birthday'] = isset($cms_user_data['hUser']['User']['birthday']) ? $cms_user_data['hUser']['User']['birthday'] : "";
            $result['age'] = isset($cms_user_data['hUser']['User']['age']) ? $cms_user_data['hUser']['User']['age'] : "";
        } else {
            //エラー
            $this->_error_msg = "ユーザー情報の取得に失敗しました。[API]";
            if (TMP_MEM_DEBUG) error_log(__CLASS__ . ":" . __FUNCTION__ . " [LINE:" . __LINE__ . "] ERR:" . $this->_error_msg, 0);
            if (TMP_MEM_DEBUG) error_log(__CLASS__ . ":" . __FUNCTION__ . " [LINE:" . __LINE__ . "] ERR:" . $mail, 0);
            if (TMP_MEM_DEBUG) error_log(__CLASS__ . ":" . __FUNCTION__ . " [LINE:" . __LINE__ . "] ERR:" . $unique_code, 0);
            return false;
        }
        return $result;
    }

    /**
     * APIを使いユーザーの所持するチケット情報を取得する
     **/
    private function _api_get_user_ticket_ids($unique_code)
    {
        $result = array();
        //CMSからユーザーの所持するチケット情報を取得する
        $cms_tickets_object = TaketinMpUtils::get_api_user_ticket($unique_code);
        $cms_tickets_data = json_decode($cms_tickets_object, true);
        if (isset($cms_tickets_data['hTickets']) && !empty($cms_tickets_data['hTickets'])) {
            if (isset($cms_tickets_data['hTickets'])) {
                foreach ($cms_tickets_data['hTickets'] as $val) {
                    $result[] = $val['ticket_id'];
                }
            }
        } else {
            //エラー
            $this->_error_msg = 'ログインする権限がありません';
            $error_msg = "ログインする資格のない利用者の可能性があります。所持するチケット情報の取得に失敗しました。[API]";
            if (TMP_MEM_DEBUG) error_log(__CLASS__ . ":" . __FUNCTION__ . " [LINE:" . __LINE__ . "] ERR:" . $error_msg, 0);
            return false;
        }
        return $result;
    }

    /**
     * APIを使いユーザーの所持するチケット情報の詳細を取得する
     **/
    private function _api_get_user_ticket_info($unique_code)
    {
        $result = array();
        //CMSからユーザーの所持するチケット情報を取得する
        $cms_tickets_object = TaketinMpUtils::get_api_user_ticket($unique_code);
        $cms_tickets_data = json_decode($cms_tickets_object, true);
        $ids = $tickets = array();
        if (isset($cms_tickets_data['hTickets']) && !empty($cms_tickets_data['hTickets'])) {
            if (isset($cms_tickets_data['hTickets'])) {
                foreach ($cms_tickets_data['hTickets'] as $val) {
                    $ids[] = $val['ticket_id'];
                    $tickets[$val['ticket_id']]['id'] =  $val['ticket_id'];
                    $tickets[$val['ticket_id']]['activated'] =  $val['activated'];
                }
            }
        } else {
            //エラー
            $this->_error_msg = 'ログインする権限がありません';
            $error_msg = "ログインする資格のない利用者の可能性があります。所持するチケット情報の取得に失敗しました。[API]";
            if (TMP_MEM_DEBUG) error_log(__CLASS__ . ":" . __FUNCTION__ . " [LINE:" . __LINE__ . "] ERR:" . $error_msg, 0);
            return false;
        }
        $result['ids'] = $ids;
        $result['tickets'] = $tickets;
        return $result;
    }

    /**
     * DBからチケットIDに該当する会員レベルを取得する
     **/
    private function _get_membership_level_id($ticket_ids)
    {
        $result = "";
        //チケットIDに該当する会員レベルを取得する
        $membership_level = TaketinMpUtils::get_membership_level($ticket_ids);

        if (isset($membership_level['id']) && !empty($membership_level['id'])) {
            $result = $membership_level['id'];
        } else {
            $this->_error_msg = "ログインできる権限を確認できませんでした。再度お試しください。";
            if (TMP_MEM_DEBUG) error_log(__CLASS__ . ":" . __FUNCTION__ . " [LINE:" . __LINE__ . "] ERR:" . $this->_error_msg, 0);
            return false;
        }
        return $result;
    }

    /**
     * メールアドレス、ユニークコードから会員情報をDBから取得
     * 2017/07/20 メールアドレスは参照しないように変更（第一引数は無効）
     **/
    function _get_member($mail = null, $unique_code)
    {
        global $wpdb;
        $sql = '';
        //$sql = "SELECT * FROM " . $wpdb->prefix . "tmp_members WHERE email = %s";
        $query = '';
        $tmp_member = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . $wpdb->prefix . "tmp_members WHERE unique_code = %s", $unique_code), ARRAY_A);

        // APIから取得したユニークコードとDBから取得したユニークコードが違っていた場合、DB側を更新する
        if ($tmp_member['unique_code'] != $unique_code) {
            $tmp_member['unique_code'] = $unique_code;
            // 投稿を更新
            $result = $wpdb->update(
                $wpdb->prefix . "tmp_members",
                array(
                    'unique_code' => $unique_code,
                ),
                array(
                    'id' => $tmp_member['id'] ?? null
                ),
                array('%s'),
                array('%d')
            );
        }
        if (isset($tmp_member['id'])) {
            // TMP会員情報を返す
            return $tmp_member;
        }
        //該当データなし
        return false;
    }

    /**
     * 会員IDから会員情報をDBから取得
     **/
    private function _get_member_by_id($id)
    {
        global $wpdb;

        $sql = '';
        $query = '';
        $tmp_member = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . $wpdb->prefix . "tmp_members WHERE id = %d", $id), ARRAY_A);

        if (isset($tmp_member['id'])) {
            // TMP会員情報を返す
            return $tmp_member;
        }
        //該当データなし
        return false;
    }

    /**
     * 閲覧制限判定
     */
    public function is_allow()
    {

        $obj = get_post_type_object(get_post_type());
        $is_allow = false;
        if (empty($this->settings)) {
            $this->settings = (array) get_option('tmp-settings');
        }
        if (TMP_MEM_DEBUG) error_log(__CLASS__ . ":" . __FUNCTION__ . " [LINE:" . __LINE__ . "] TYPE： " . get_post_type(), 0);

        // カスタム投稿のスラグ一覧を作成
        $hCustomPosts = get_post_types(array('public' => true, '_builtin' => false), 'names');
        $hOneDimiCustomPosts = [];
        foreach ($hCustomPosts as $key => $val) {
            $hOneDimiCustomPosts[] = $val;
        }

        // 会員ページのスラグ一覧を作成(リストにある場合はログインしていないと表示できない)
        $hMemberPosts = false;
        if (isset($this->settings['custom-posts-view']) && $this->settings['custom-posts-view']) {

            $hTmp = explode(',', $this->settings['custom-posts-view']);
            foreach ($hTmp as $key => $val) {
                if (trim($val) != '') {
                    $hMemberPosts[] = $val;
                }
            }
        }

        // functions.php等で個別判定関数の設定があった場合は現在のページの種類を取得し、会員用ページだった場合処理を行う
        global $tmp_functions_for_get_page_type;
        if (isset($tmp_functions_for_get_page_type) && $tmp_functions_for_get_page_type) {
            if (isset($tmp_functions_for_get_page_type['custom_page'])) {
                foreach ($tmp_functions_for_get_page_type['custom_page'] as $functionName => $conditions) {
                    // カスタム投稿のスラグを設定からコピー
                    $tmp_functions_for_get_page_type['custom_page'][$functionName][1] = array_values($hCustomPosts);
                }
            };
            if (isset($tmp_functions_for_get_page_type['member_page'])) {
                foreach ($tmp_functions_for_get_page_type['member_page'] as $functionName => $conditions) {
                    // NGのスラグを設定からコピー
                    $tmp_functions_for_get_page_type['member_page'][$functionName][1] = $hMemberPosts;
                }
            };

            // 個別判定関数を指定してページ種別を取得
            $pageType = $this->tmp_get_page_type($tmp_functions_for_get_page_type);

            if ($pageType == 'member_page') {
                //未ログインは許可しない
                if (!$this->isLoggedIn) {
                    return false;
                } else {
                    return true;
                }
            }
        }

        // トップページの場合、設定のないページを公開する設定の場合は無条件に表示する
        if (is_home() || is_front_page()) {
            if ($this->settings['show-contents-if-no-config']) {
                return true;
            } else {
                //未ログインは許可しない
                if (!$this->isLoggedIn) {
                    return false;
                } else {
                    return true;
                }
            }
        }

        //会員レベルIDを取得する
        $user_memberships_level_id = TaketinMpUtils::get_cookie_data("memberships_id");

        //記事ページ
        if (is_single()) {
            $post_id = get_the_ID();
            // カスタム投稿に関する判別を行う
            if (in_array(get_post_type(), $hCustomPosts) || (isset($pageType) && $pageType == 'custom_page')) {
                //明示的に会員専用とされている場合、ログインさえしていれば閲覧可能
                $hAllowCustomPosts = explode(',', $this->settings['custom-posts-view']);
                foreach ($hAllowCustomPosts as $key => $val) {
                    if (get_post_type() == trim($val) && !$this->isLoggedIn) {
                        return false;
                    }
                }
            }
            //明示的に会員専用とされていない場合、限定カテゴリかどうかを確認（カテゴリIDを取得）
            $hCates = [];
            $taxonomy_slugs = array_keys(get_the_taxonomies($post_id));
            foreach ($taxonomy_slugs as $taxonomy_slug) {
                // タグを除く
                if ($taxonomy_slug != 'post_tag') {
                    $hTerms = get_the_terms($post_id, $taxonomy_slug);
                    foreach ($hTerms as $hTerm) {
                        $hCates[] = $hTerm;
                    }
                }
            }
            //（１つでも許可されていれば閲覧可）
            $flgNoRestrictionInAllCategories = true; //全カテゴリに制限があるかどうか（True：ない）
            $is_allow = false;

            if (!isset($hCates->errors) && $hCates) {
                foreach ($hCates as $key => $hCate) {
                    global $wpdb;
                    // そのカテゴリの表示権限を持っているか確認
                    if ($is_allow = TaketinMpMembershipLevel::get_instance()->is_member_allow_category($user_memberships_level_id, $hCate->term_id)) {
                        $is_allow = true;
                        $flgNoRestrictionInAllCategories = false;
                        break;
                    }
                    // そのカテゴリの権限はなく、制限もかかっていない場合
                    if ($this->settings['show-contents-if-no-config']) {
                        $sql = '';
                        //制限もかかっていない場合
                        if (!$res = $wpdb->get_results(
                            $wpdb->prepare(
                                "select * from {$wpdb->prefix}tmp_memberships_categories
where category_id = %s", $hCate->term_id
                            )
                        )) {
                            if ($user_memberships_level_id) { //ログインしてれば見れる
                                $is_allow = true;
                                break;
                            }
                        } else {
                            // カテゴリに制限設定があった場合
                            $flgNoRestrictionInAllCategories = false;
                        }
                    }
                } // end foreach
            } else {
                $is_allow = true;
                if (TMP_MEM_DEBUG) error_log(__CLASS__ . ":" . __FUNCTION__ . " [LINE:" . __LINE__ . "] There is no term associated with the post ", 0);
            }
            // 全カテゴリをループし、制限の無いカテゴリだけだった場合は真とする
            if ($this->settings['show-contents-if-no-config'] && $flgNoRestrictionInAllCategories) {
                $is_allow = true;
            }

            $logMess =  ($this->isLoggedIn) ? 'Login：' : 'NoLogin：';
            $logMess .= ($is_allow) ? '見れます' : '見れません';
            if (TMP_MEM_DEBUG) error_log(__CLASS__ . ":" . __FUNCTION__ . " [LINE:" . __LINE__ . "] " . $logMess, 0);


            if ($is_allow) {
                return $is_allow;
            }

            //未ログインは許可しない
            if (!$this->isLoggedIn) {
                return false;
            }
        } else if (is_page()) {
            //固定ページ
            $is_allow = true;
        } else if (is_category()) {
            // カテゴリの表示権限があるか判定
            if (!$res = $this->_is_category_ok()) {
                return false;
            }
            return $res;
        } else if (is_search()) {
            $is_allow = ($this->settings['enable-view-search']) ? true : false;
        } else if (is_archive()) {

            if (empty($this->settings)) {
                $this->settings = (array) get_option('tmp-settings');
            }
            if (in_array(get_post_type(), $hOneDimiCustomPosts)) { //is_post_type_archive($hOneDimiCustomPosts)だろ上手く行かないケースがあった
                $keyName = sprintf('enable-view-archive-%s', get_post_type());
                if (TMP_MEM_DEBUG) error_log(__CLASS__ . ":" . __FUNCTION__ . " [LINE:" . __LINE__ . "] カスタム投稿アーカイブ ：" . $keyName, 0);
                $is_allow = ($this->settings[$keyName]) ? true : false;
                return $is_allow;
            }
            if (is_author()) {
                $is_allow = ($this->settings['enable-view-authorpage']) ? true : false;
                return $is_allow;
            }
            if (is_tag()) {
                return $is_allow = ($this->settings['enable-view-tagpage']) ? true : false;
            }
            if (is_date()) {
                return $is_allow = ($this->settings['enable-view-archive']) ? true : false;
            }
            //カテゴリIDを取得
            $catId = get_query_var('cat');
            //会員レベルIDとカテゴリIDを元に閲覧することができるカテゴリかを判定

            $is_allow = TaketinMpMembershipLevel::get_instance()->is_member_allow_category($user_memberships_level_id, $catId);

            //制限設定のないページは誰でも閲覧可ならば常にtrue
            if ($this->settings['show-contents-if-no-config']) {
                $is_allow = true;
            }
        } else {
            //404を想定（ページが存在しないので制限しない）
            $is_allow = true;
        }

        return $is_allow;
    }

    public function get_allow_category_id()
    {
        $result = "";
        //未ログインは許可しない
        if (!$this->isLoggedIn) return false;
        //会員レベルIDを取得する
        $user_memberships_level_id = TaketinMpUtils::get_cookie_data("memberships_id");
        //会員レベルIDを元に閲覧することができるカテゴリIDを取得する
        $cat_ids = TaketinMpMembershipLevel::get_instance()->get_member_allow_category_ids($user_memberships_level_id);
        if (!is_null($cat_ids) && !empty($cat_ids)) {
            //取得できたらカテゴリIDの表示を加工する
            $ar = array_values($cat_ids);
            if (count($ar) > 1) {
                //カンマ区切りに変換
                foreach ($ar as $v) {
                    if (isset($v[0])) {
                        $result .= $v[0] . ",";
                    }
                }
                $result = rtrim($result, ",");
            } else {
                if (isset($ar[0][0])) {
                    $result = $ar[0][0];
                }
            }
        }
        return $result;
    }

    //ログイン中かどうか
    public function is_logged_in()
    {
        return $this->isLoggedIn;
    }

    /**
     * ログイン
     **/
    public function login()
    {
        if ($this->isLoggedIn) {
            return;
        }
        $res = $this->authenticate();
        return $res;
    }

    /**
     * ログアウト
     **/
    public function logout()
    {
        if (!$this->isLoggedIn) {
            return;
        }
        TaketinMpUtils::clear_cookie();
        $this->isLoggedIn = false;
    }

    /**
     * [ログイン中の処理]
     * Cookieの会員IDから会員情報が存在するかチェック
     */
    public function login_in_is_exist_member()
    {
        //未ログインは存在していないものとして扱う
        if (!$this->isLoggedIn) return false;
        //会員IDを取得する
        $member_id = TaketinMpUtils::get_cookie_data("member_id");
        //取得失敗は存在していないものとして扱う
        if (!$member_id) return false;
        //会員IDから会員情報を取得
        $tmp_member = $this->_get_member_by_id($member_id);
        if (!$tmp_member) {
            //会員情報が存在しない
            return false;
        } else {
            //会員情報が存在する
            return true;
        }
    }

    /**
     * [ログイン中の処理]
     * Cookieの会員レベル更新日時をみて24時間経過しているか判定
     * @return boolean
     * true: 有効期限切れ | false: 有効期限内
     */
    public function login_in_is_past_memberships_check_date()
    {
        if (TMP_MEM_DEBUG) {
            error_log(__CLASS__ . ":" . __FUNCTION__ . " [LINE:" . __LINE__ . "] ログイン期限チェック",  0);
        }

        //未ログインは過ぎたものとして扱う
        if (!$this->isLoggedIn) return true;
        //会員レベル更新日時を取得する
        $memberships_check_date = TaketinMpUtils::get_cookie_data("memberships_check_date");
        //取得失敗は過ぎたものとして扱う
        if (!$memberships_check_date) return true;


        if (TMP_MEM_DEBUG) {
            error_log(__CLASS__ . ":" . __FUNCTION__ . " [LINE:" . __LINE__ . "] 最終確認時間：" . gmdate('Y-m-d H:i:s', $memberships_check_date + 9 * 3600));
            error_log(__CLASS__ . ":" . __FUNCTION__ . " [LINE:" . __LINE__ . "] 次回確認時間：" . gmdate('Y-m-d H:i:s', $memberships_check_date +  $this->settings['taketin_mp_check_memberlevel_update_minuites'] * 60 + 9 * 3600),  0);
            error_log(__CLASS__ . ":" . __FUNCTION__ . " [LINE:" . __LINE__ . "] 現在の時間：" . gmdate('Y-m-d H:i:s', time()+9*3600));
        }
        if (empty($this->settings)) {
            $this->settings = (array) get_option('tmp-settings');
        }
        if (!isset($this->settings['taketin_mp_check_memberlevel_update_minuites']) || !$this->settings['taketin_mp_check_memberlevel_update_minuites']) {
            $this->settings['taketin_mp_check_memberlevel_update_minuites'] = 5;
        }

        if (TMP_MEM_DEBUG) {
            error_log(__CLASS__ . ":" . __FUNCTION__ . " [LINE:" . __LINE__ . "] " . $this->settings['taketin_mp_check_memberlevel_update_minuites'],  0);
        }
        if (time() > $memberships_check_date +  $this->settings['taketin_mp_check_memberlevel_update_minuites'] * 60) {
            if (TMP_MEM_DEBUG) {
                error_log(__CLASS__ . ":" . __FUNCTION__ . " [LINE:" . __LINE__ . "] 期限切れ",  0);
            }
            return true;
        }
        return false;
    }

    /**
     * [ログイン中の処理]
     * 会員レベル再判定と会員情報更新
     */
    public  function login_in_update_tmp_menber()
    {
        //未ログインは終了
        if (!$this->isLoggedIn) return false;
        //会員IDを取得する
        $member_id = TaketinMpUtils::get_cookie_data("member_id");
        //取得失敗は終了
        if (!$member_id) return false;
        //会員IDから会員情報を取得
        $tmp_member = $this->_get_member_by_id($member_id);
        //会員レベル判定と情報の更新処理
        $refresh_tmp_member = $this->update_tmp_member($tmp_member, false, $flg_update_token = false);
        if (!$refresh_tmp_member) return false;
        //Cookieを更新
        if (!TaketinMpUtils::login_in_update_cookie_data($refresh_tmp_member)) {
            if (TMP_MEM_DEBUG) error_log(__CLASS__ . ":" . __FUNCTION__ . " [LINE:" . __LINE__ . "] INFO: Cookie値更新できませんでした", 0);
        } else {
            if (TMP_MEM_DEBUG) error_log(__CLASS__ . ":" . __FUNCTION__ . " [LINE:" . __LINE__ . "] INFO: Cookie値更新完了", 0);
        }
        return true;
    }

    /**
     * エラーメッセージを返す
     **/
    public function get_err_message()
    {
        return $this->_error_msg;
    }

    public function _is_category_ok()
    {
        $user_memberships_level_id = TaketinMpUtils::get_cookie_data("memberships_id");
        $categoryId = get_query_var('cat');
        // カテゴリの表示権限を持っているか確認
        if ($is_allow = TaketinMpMembershipLevel::get_instance()->is_member_allow_category($user_memberships_level_id, $categoryId)) {
            return true;
        }

        // 権限がない時に非公開の場合、false
        if (!$this->settings['show-contents-if-no-config']) {
            return false;
        }

        // 持っていない場合、このカテゴリに条件があるかを判定

        // カテゴリに制限設定が無い場合
        global $wpdb;
        $sql = '';
        if (!$res = $wpdb->get_results(

            $wpdb->prepare(
            "select * from {$wpdb->prefix}tmp_memberships_categories where category_id = %s",
            $categoryId
            )
        )) {
            return true;
        }

        // 上記以外は表示しない
        return false;
    }

    function tmp_get_page_type($tmp_functions_for_get_page_type = null)
    {
        /* 
           array('返すページ種別(custom_post, post, searchなど) 省略した場合、下記の判別関数の返値を返す')
           =>array('判別関数', array('判別関数への引数(配列にすると引数1, 2, 3...)', array('左記の関数の返値と比較してページ種別を返す値の配列(OR)')))
           例: array('custom_post'
           =>array('bbp_get_forum_post_type' => array(null, array('forum', 'testslug'))
        */
        if (!$tmp_functions_for_get_page_type) {
            return false;
        }

        // 汎用的なページ種別判別関数
        foreach ($tmp_functions_for_get_page_type as $ret => $mConditions) {
            // 条件が配列で無い場合、関数名と判別して実行
            if (!is_array($mConditions)) {
                if (!function_exists($mConditions)) {
                    continue;
                }
                if ($res = call_user_func($mConditions)) {
                    if ($ret) {
                        return $ret;
                    }
                    return $res;
                }
                continue;
            }

            // 種別ループを行う
            if (array_values($mConditions) == $mConditions) {
                // 関数名のみの配列が渡された場合、trueが返るかで判別
                foreach ($mConditions as $functionName) {
                    if (!function_exists($functionName)) {
                        continue;
                    }
                    if ($res = call_user_func($functionName)) {
                        if ($ret) {
                            return $ret;
                        }
                    }
                    return $res;
                }
                continue;
            }

            // 関数名とオプションの方で渡された場合、関数毎にループ
            foreach ($mConditions as $functionName => $mCond) {
                if (!function_exists($functionName)) {
                    continue;
                }

                if (!is_array($mCond[0])) {
                    $mCond[0] = array($mCond[0]);
                }

                // 結果セットがない場合、真偽でチェックする
                if (!isset($mCond[1])) {
                    if ($res = call_user_func_array($functionName, $mCond[0])) {
                        if ($ret) {
                            return $ret;
                        }
                        return $res;
                    }
                } else {
                    if (!is_array($mCond[1])) {
                        $mCond[1] = array($mCond[1]);
                    }
                    foreach ($mCond[1] as $val) {
                        if ($res = call_user_func_array($functionName, $mCond[0])) {
                            if ($res == $val) {
                                if ($ret) {
                                    return $ret;
                                } else {
                                    return $res;
                                }
                            }
                        }
                    }
                }
            }
        }

        return false;
    }
}
