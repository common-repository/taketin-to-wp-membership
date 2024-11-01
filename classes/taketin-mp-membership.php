<?php
class TaketinMpMembership
{

    private $_authenticate_error_message;

    public function __construct()
    {
        add_action('admin_menu', array(&$this, 'menu'));

        add_action('init', array(&$this, 'init_hook'));
        //init is too early for settings api.
        add_action('admin_init', array(&$this, 'admin_init_hook'));
        add_action('admin_notices', array(&$this, 'do_admin_notices'));
        add_action('wp_enqueue_scripts', array(&$this, 'do_wp_enqueue_scripts'));

        add_action('wp', array(&$this, 'tmp_authenticate'));                    //認証
        add_action('pre_get_posts', array(&$this, 'tmp_filter_list'));          //記事一覧出力制御

        add_shortcode('tmp_login_form', array(&$this, 'login'));
        add_shortcode('tmp_login_form_directly', array(&$this, 'login_form_directly'));
        add_shortcode('tmp_logout_form', array(&$this, 'logout'));
        add_shortcode('tmp_reset_form', array(&$this, 'reset'));

        add_shortcode('is_taketin_logged_in', array(&$this, 'is_logged_in'));
        add_shortcode('is_taketin_logged_out', array(&$this, 'is_logged_out'));
        add_shortcode('is_taketin_ticket_have', array(&$this, 'is_ticket_have'));
        add_shortcode('is_taketin_ticket_not_have', array(&$this, 'is_ticket_not_have'));

        add_shortcode('taketin_member_info', array(&$this, 'get_tmp_member_info'));
        add_shortcode('taketin_member_name', array(&$this, 'get_tmp_member_name'));
        add_shortcode('taketin_member_display_name', array(&$this, 'get_tmp_member_display_name'));
        add_shortcode('taketin_member_mail', array(&$this, 'get_tmp_member_mail'));
        add_shortcode('taketin_member_afcode', array(&$this, 'get_tmp_afcode'));
        add_shortcode('taketin_member_uniquecode', array(&$this, 'get_tmp_uniquecode'));
        add_shortcode('taketin_member_code', array(&$this, 'get_tmp_member_code'));

        //AJAX hooks
        add_action('wp_ajax_api_user', 'TaketinMpAjax::api_user');
        add_action('wp_ajax_api_user_ticket', 'TaketinMpAjax::api_user_ticket');
        add_action('wp_ajax_api_ticket_wizard', 'TaketinMpAjax::wizard_api_ticket');
        add_action('wp_ajax_save_configurator_wizard', 'TaketinMpAjax::wizard_save_configurator');
        add_action('wp_ajax_get_membership_level', 'TaketinMpAjax::get_membership_level_from_tickets');
        add_action('wp_ajax_end_configurator_wizard', 'TaketinMpAjax::wizard_end_configurator');

        add_action('wp_ajax_duplicate_login_check', 'TaketinMpAjax::duplicate_login_check');
        add_action('wp_ajax_nopriv_duplicate_login_check', 'TaketinMpAjax::duplicate_login_check');
    }

    public function menu()
    {

        add_menu_page(
            'TAKETIN MP', // page_title
            'TAKETIN MP', // menu_title
            TMP_MANAGEMENT_PERMISSION, // capability
            TMP_MEM_PREFIX, // menu_slug
            array(&$this, "admin_members_menu"), // function
            'dashicons-id', // icon_url
            81    // position
        );
        add_submenu_page(
            TMP_MEM_PREFIX, // parent_slug
            "会員", // page_title
            "会員", // menu_title
            TMP_MANAGEMENT_PERMISSION, // capability
            TMP_MEM_PREFIX, // menu_slug
            array(&$this, "admin_members_menu") // function
        );
        add_submenu_page(
            TMP_MEM_PREFIX,
            "会員レベル",
            "会員レベル",
            TMP_MANAGEMENT_PERMISSION,
            TMP_MEM_PREFIX . '_levels',
            array(&$this, "admin_membership_levels_menu")
        );
        add_submenu_page(
            TMP_MEM_PREFIX,
            "限定コンテンツ",
            "限定コンテンツ",
            TMP_MANAGEMENT_PERMISSION,
            TMP_MEM_PREFIX . '_categroy',
            array(&$this, "admin_membership_categroy_menu")
        );
        add_submenu_page(
            TMP_MEM_PREFIX,
            "設定",
            "設定",
            TMP_MANAGEMENT_PERMISSION,
            TMP_MEM_PREFIX . '_settings',
            array(&$this, "admin_settings_menu")
        );

        //do_action('tmp_after_main_admin_menu', $menu_parent_slug);

        //$this->meta_box();
    }

    /* Render the members menu in admin dashboard */
    public function admin_members_menu()
    {
        if ($this->admin_configurator()) return;    //ウィザード表示判定
        include_once(TMP_MEM_PATH . 'classes/taketin-mp-members.php');
        $Members = new TaketinMpMembers();
        $Members->handle_main_members_admin_menu();
    }

    /* Render the membership levels menu in admin dashboard */
    public function admin_membership_levels_menu()
    {
        if ($this->admin_configurator()) return;    //ウィザード表示判定
        include_once(TMP_MEM_PATH . 'classes/taketin-mp-membership-levels.php');
        $Levels = new TaketinMpMembershipLevels();
        $Levels->handle_main_membership_level_admin_menu();
    }

    /* Render the membership levels menu in admin dashboard */
    public function admin_membership_categroy_menu()
    {
        if ($this->admin_configurator()) return;    //ウィザード表示判定
        include_once(TMP_MEM_PATH . 'classes/taketin-mp-membership-levels.php');
        $Levels = new TaketinMpMembershipLevels();
        $Levels->handle_main_membership_level_admin_menu('category_list');
    }

    /* Render the settings menu in admin dashboard */
    public function admin_settings_menu()
    {
        if ($this->admin_configurator()) return;    //ウィザード表示判定
        $TaketinMpSettings = TaketinMpSettings::get_instance();
        $TaketinMpSettings->handle_main_settings_admin_menu();
    }

    public function admin_init_hook()
    {
        $TaketinMpSettings = TaketinMpSettings::get_instance();
        //Initialize the settings menu hooks.
        $TaketinMpSettings->init_config_hooks();
    }

    public function admin_configurator()
    {
        wp_enqueue_style("options", TMP_MEM_DIR_URL . 'style/options.css');
        //初期設定確認
        $TaketinMpConfigurator = new TmpConfigurator();
        if (!$TaketinMpConfigurator->is_finished_setup()) {
            //setup未
            $TaketinMpConfigurator->wizard();
            return true; //ウィザード表示
        }
        //setup完了
        return false;
    }

    public function init_hook()
    {
        $init_tasks = new TaketinMpInitTimeTasks();
        $init_tasks->do_init_tasks();
    }

    /**
     * ログアウトボタン生成javascript出力 
     **/
    public function do_wp_enqueue_scripts()
    {
        $auth = TmpAuth::get_instance();
        //ログインしていなければ終了
        if (!$auth->is_logged_in()) return;

        //未設定であれば終了
        $settings = get_option('tmp-settings');
        if (!isset($settings['logout-button-target-style-element']) || empty($settings['logout-button-target-style-element'])) return;

        //フロント側にファイル読み込みを追加
        wp_enqueue_script('tmp-style01', TMP_MEM_DIR_URL . 'script/taketin-logout-button.js', array('jquery'), TMP_MEM_VERSION, true);
        wp_localize_script('tmp-style01', 'tmp01', array(
            'logout_url' => home_url() . TMP_URL_PATH_LOGOUT,
            'target_element' => $settings['logout-button-target-style-element']
        ));
    }

    /**
     * 記事一覧取得条件制御
     **/
    public function tmp_filter_list($query)
    {
        //管理画面はスキップ
        if (is_admin()) return;

        //ホームで記事一覧を出力する場合のクエリ
        //        if ( $query->is_home() && $query->is_main_query() ) {
        //            $auth = TmpAuth::get_instance();
        //            //ログインしている
        //            if ($auth->is_logged_in()) {
        //                //対象会員に許可されたカテゴリIDを取得
        //                $category_id = $auth->get_allow_category_id();
        //                if (!empty($category_id)) {
        //                    $query->set( 'cat', $category_id );
        //                }
        //            }
        //        }
        return;
    }

    /* If any message/notice was set during the execution then this function will output that message */
    public function notices()
    {
        $message = TaketinMpTransfer::get_instance()->get('status');
        $succeeded = false;
        if (empty($message)) {
            return false;
        }
        if ($message['succeeded']) {
            echo "<div id='message' class='updated'>";
            $succeeded = true;
        } else {
            echo "<div id='message' class='error'>";
        }
        echo esc_html($message['message']);
        $extra = isset($message['extra']) ? $message['extra'] : array();
        if (is_string($extra)) {
            echo esc_html($extra);
        } else if (is_array($extra)) {
            echo '<ul>';
            foreach ($extra as $key => $value) {
                echo '<li>' . esc_html($value) . '</li>';
            }
            echo '</ul>';
        }
        echo "</div>";
        return $succeeded;
    }
    /* 
     * This function is hooked to WordPress's admin_notices action hook 
     * It is used to show any plugin specific notices/warnings in the admin interface
     */
    public function do_admin_notices()
    {
        $this->notices(); //Show any execution specific notices in the admin interface.

        //Show any other general warnings/notices to the admin.
        if (is_admin()) {
            //we are in an admin page for SWPM plugin.

            $msg = '';

            if (!empty($msg)) { //Show warning messages if any.
                echo '<div id="message" class="error">';
                echo esc_html($msg);
                echo '</div>';
            }
        }
    }
    /*
     * Member info
    */
    public function get_tmp_member_info($atts)
    {
        extract(shortcode_atts(array(
            'item' => null,
        ), $atts));
        $auth = TmpAuth::get_instance();
        //ログイン中
        if (!$hash = TaketinMpUtils::get_cookie_data("unique_code")) {
            return false;
        }
        $hMember = $auth->_get_member('', $hash);
        return (isset($hMember[$atts['item']])) ? $hMember[$atts['item']] : 'ない';
    }
    public function get_tmp_uniquecode()
    {
        if (!$hash = TaketinMpUtils::get_cookie_data("unique_code")) {
            return false;
        }
        return $hash;
    }
    public function get_tmp_afcode()
    {
        if (!$afCode = TaketinMpUtils::get_cookie_data("af_code")) {
            return false;
        }
        return $afCode;
    }
    public function get_tmp_member_display_name()
    {
        $name = $this->get_tmp_member_info(array('item' => 'display_name'));
        return $name;
    }
    public function get_tmp_member_code()
    {
        $name = $this->get_tmp_member_info(array('item' => 'code'));
        return $name;
    }
    public function get_tmp_member_name()
    {
        $name = $this->get_tmp_member_info(array('item' => 'name'));
        return $name;
    }
    public function get_tmp_member_mail()
    {
        $name = $this->get_tmp_member_info(array('item' => 'email'));
        return $name;
    }
    /*
     * ショートコード用ログイン判別
     */
    function is_logged_out($atts, $content = null)
    {
        extract(shortcode_atts(array(
            'membership' => null,
            'boolean' => false,
        ), $atts));
        $auth = TmpAuth::get_instance();
        if (!$auth->is_logged_in()) {
            //ログインしていない
            $content = do_shortcode(shortcode_unautop($content)); //ショートコードの中にショート
            return ($boolean) ? true : $content;
        }
        //ログイン中
        $content = null;
        $content = do_shortcode(shortcode_unautop($content)); //ショートコードの中にショート
        return ($boolean) ? false : $content;
    }
    function is_logged_in($atts, $content = null)
    {
        extract(shortcode_atts(array(
            'tickets' => null,
            'membership' => null,
            'boolean' => false,
        ), $atts));
        $auth = TmpAuth::get_instance();
        if (!$auth->is_logged_in()) {
            $content = null;
            $content = do_shortcode(shortcode_unautop($content)); //ショートコードの中にショート
            return ($boolean) ? false : $content;
        }
        $content = do_shortcode(shortcode_unautop($content)); //ショートコードの中にショート
        //以下ログインしている場合
        if ($membership) {
            //特定の会員レベル指定がある場合
            $ids = explode(',', $membership);
            $much = false;
            $level_id = TaketinMpUtils::get_cookie_data("memberships_id");
            foreach ($ids as $id) {
                if ($id == $level_id) {
                    $much = true;
                    break;
                }
            }
        } else {
            //特定の会員レベル指定がなく、真偽フラグがあればログインしているのでTrueを返す
            return ($boolean) ? true : $content;
        }

        if (!$much) {
            $content = null;
            return ($boolean) ? false : $content;
        } else {
            return ($boolean) ? true : $content;
        }
    }
    function is_ticket_have($atts, $content = null)
    {
        extract(shortcode_atts(array(
            'ticket' => null,
            'return' => false,
        ), $atts));
        $auth = TmpAuth::get_instance();
        if (!$auth->is_logged_in()) {
            return null;
        }
        $much = false;
        if ($ticket) {
            //複数は想定しない
            if (!$hasTickets = TaketinMpUtils::get_cookie_data("tickets")) {
                //cookieデータが存在しない場合はDBに問い合わせ
                global $wpdb;
                $memberId = TaketinMpUtils::get_cookie_data("member_id");
                $query = '';
                $res = $wpdb->get_results($wpdb->prepare("SELECT ticket_list_serialized FROM " . $wpdb->prefix . "tmp_members WHERE id = %d", $memberId));
                $wpdb->flush();
                if ($res && isset($res[0]->ticket_list_serialized)) {
                    $hasTickets = $res[0]->ticket_list_serialized;
                } else {
                    return null;
                }
            }
            $hasTickets = unserialize(stripslashes($hasTickets));
            foreach ($hasTickets as $tId => $hTicket) {
                if ($tId == $ticket) {
                    $much = true;
                    break;
                }
            }
        }

        if (!$much) {
            return ($return) ? false : null;
        } else {
            if ($return == 'activated') {
                return gmdate('Y-m-d', strtotime($hTicket['activated']) + 9 * 3600);
            } elseif ($return == 'boolean') {
                return true;
            }
            return $content;
        }
    }
    function is_ticket_not_have($atts, $content = null)
    {
        extract(shortcode_atts(array(
            'ticket' => null,
        ), $atts));
        $auth = TmpAuth::get_instance();
        if (!$auth->is_logged_in()) {
            return null;
        }
        $much = false;
        if ($ticket) {
            //複数は想定しない
            if (!$hasTickets = TaketinMpUtils::get_cookie_data("tickets")) {
                //cookieデータが存在しない場合はDBに問い合わせ
                global $wpdb;
                $memberId = TaketinMpUtils::get_cookie_data("member_id");
                $query = '';
                $res = $wpdb->get_results($wpdb->prepare("SELECT ticket_list_serialized FROM " . $wpdb->prefix . "tmp_members WHERE id = %d", $memberId));
                $wpdb->flush();
                if ($res && isset($res[0]->ticket_list_serialized)) {
                    $hasTickets = $res[0]->ticket_list_serialized;
                } else {
                    return null;
                }
            }
            $hasTickets = unserialize(stripslashes($hasTickets));
            foreach ($hasTickets as $tId => $hTicket) {
                if ($tId == $ticket) {
                    $much = true;
                    break;
                }
            }
        }

        if ($much) {
            return null;
        } else {
            return $content;
        }
    }

    /*
     * TMP会員認証処理
     */
    public function tmp_authenticate()
    {
        //コンテンツ保護設定が無効な場合、終了
        if ($this->_check_enable_contents_block() != true) return;
        //認証処理スキップ
        if (!$this->_check_skip_page()) return;
        //除外ページ
        $this->_exclusion_page();
        if (TMP_MEM_DEBUG) error_log(__CLASS__ . ":" . __FUNCTION__ . " [LINE:" . __LINE__ . "] INFO: tmp_authenticate 開始", 0);
        if (TMP_MEM_DEBUG) error_log(__CLASS__ . ":" . __FUNCTION__ . " [LINE:" . __LINE__ . "] INFO: REQUEST_URI=" . $_SERVER['REQUEST_URI'], 0);
        $auth = TmpAuth::get_instance();
        if ($auth->is_logged_in()) {
            // --------------------------
            //@@ ログインしている
            // --------------------------
            if (TMP_MEM_DEBUG) error_log(__CLASS__ . ":" . __FUNCTION__ . " [LINE:" . __LINE__ . "] INFO: ログイン中", 0);

            //[ログイン中の会員情報チェック]会員存在チェック
            $this->_login_in_check_tmp_member($auth);
            //[ログイン中の会員情報チェック]所持チケットと会員ランクチェック
            $this->_login_in_check_tmp_membership_level($auth);

            //重複ログインチェック 
            if ($auth->settings['enable-duplicate-login-check']) {
                if (TMP_MEM_DEBUG) error_log(__CLASS__ . ":" . __FUNCTION__ . " [LINE:" . __LINE__ . "] INFO: 重複ログインチェック", 0);
                $loginToken = TaketinMpUtils::get_cookie_data("login_token");
                $unique_code = TaketinMpUtils::get_cookie_data("unique_code");
                $tmp_member_data = $auth->_get_member(null, $unique_code);
                if (isset($tmp_member_data['login_token']) && $tmp_member_data['login_token']) {
                    if ($loginToken != $tmp_member_data['login_token']) {
                        if (TMP_MEM_DEBUG) error_log(__CLASS__ . ":" . __FUNCTION__ . " [LINE:" . __LINE__ . "] 別の端末・場所からログインTokenが上書きされているのでNG ", 0);
                        //ログアウト処理をして転送
                        $auth->logout();
                        if (isset($auth->settings['duplicate-login-page-url']) && $auth->settings['duplicate-login-page-url']) {
                            $login_url = $auth->settings['duplicate-login-page-url'];
                        } else {
                            $login_url = home_url() . TMP_URL_PATH_LOGIN . "/";
                        }
                        wp_redirect($login_url);
                        die();
                    }
                }
            }

            //ログイン中のトップページ表示は許可
            if (is_home()) return;

            //その他のページは権限より判定する
            if (!$auth->is_allow()) {
                if (TMP_MEM_DEBUG) error_log(__CLASS__ . ":" . __FUNCTION__ . " [LINE:" . __LINE__ . "] INFO: アクセス制限ページ", 0);
                $this->_redirect_not_allow_page();
            }
            return;
        } elseif (
            !empty($_SESSION['taketin_user']) &&
            empty($_COOKIE[TMP_MEM_COOKIE_KEY . "_logout"]) //明示的なログアウトをしていない
        ) {
            // --------------------------
            //@@ 未ログインだが、TAKETINシステムとしてのログインセッションがある場合
            // --------------------------
            if (TMP_MEM_DEBUG) error_log(__CLASS__ . ":" . __FUNCTION__ . " [LINE:" . __LINE__ . "] INFO: TAKETINとしてログイン中", 0);
            $unique_code = $_SESSION['taketin_user']['hash'];
            $tmp_mail = $_SESSION['taketin_user']['mail'];
            //DBから会員レコードを探す
            $tmp_member_data = $auth->_get_member($tmp_mail, $unique_code);
            if (!$tmp_member_data) {
                //会員レコードがなければ作成
                $tmp_member_data = $auth->create_tmp_member($tmp_mail, $tmp_password = null, $unique_code);
            } else {
                //会員レコードがあればAPIから所持チケットを取得し会員レベルに変動がないかチェックし対象カラムの情報更新
                if (TMP_MEM_DEBUG) error_log(__CLASS__ . ":" . __FUNCTION__ . " [LINE:" . __LINE__ . "] INFO: 既存ユーザー", 0);
                $tmp_member_data = $auth->update_tmp_member($tmp_member_data);
            }
            if (!$tmp_member_data) return false;
            //ログイン状態
            $this->isLoggedIn = true;
            //ログインセッションを保存
            TaketinMpUtils::set_cookie_tmp_member($tmp_member_data, $auto_login);

            //現在のページを再読込
            $redirect_url = home_url();
            if (isset($_SERVER['REQUEST_URI']) && !empty($_SERVER['REQUEST_URI'])) {
                $redirect_url = $redirect_url . $_SERVER['REQUEST_URI'];
            }
            if (TMP_MEM_DEBUG) error_log(__CLASS__ . ":" . __FUNCTION__ . " [LINE:" . __LINE__ . "] INFO: リダイレクト=>" . $redirect_url, 0);
            wp_redirect($redirect_url);
            die();
        } else {
            // --------------------------
            //@@ 未ログイン
            // --------------------------
            $redirect_url = '';
            if (TMP_MEM_DEBUG) error_log(__CLASS__ . ":" . __FUNCTION__ . " [LINE:" . __LINE__ . "] INFO: ログインしていない", 0);
            $flgTryLogin = false;
            if (
                isset($_POST['tmp-login']) && $_POST['tmp-login'] &&
                isset($_POST['tmp_mail']) && $_POST['tmp_mail'] &&
                isset($_POST['tmp_password']) && $_POST['tmp_password']
            ) {
                $flgTryLogin = true;
                if (isset($_POST['_wp_http_referer']) && $_POST['_wp_http_referer']) {
                    $redirect_url = $_POST['_wp_http_referer'];
                }
            }
            if ($flgTryLogin || strpos($_SERVER['REQUEST_URI'], TMP_URL_PATH_LOGIN) !== false) {
                if (TMP_MEM_DEBUG) error_log(__CLASS__ . ":" . __FUNCTION__ . " [LINE:" . __LINE__ . "] INFO: ログイン処理", 0);
                // --------------------------
                //@@ ログインページへ遷移中なのでログイン処理
                // --------------------------
                $success = $auth->login();
                if ($success) {
                    //ログイン成功
                    if (!$redirect_url) {
                        $redirect_url = home_url();
                    }
                    if (isset($_SERVER['QUERY_STRING']) && !empty($_SERVER['QUERY_STRING'])) {
                        $query_strings = urldecode($_SERVER['QUERY_STRING']);
                        $hDummy = [];
                        parse_str($query_strings, $hDummy);
                        if (isset($hDummy['redirect_url'])) {
                            $redirect_url = $hDummy['redirect_url'];
                        }
                    }

                    if (TMP_MEM_DEBUG) error_log(__CLASS__ . ":" . __FUNCTION__ . " [LINE:" . __LINE__ . "] INFO: リダイレクト=>" . $redirect_url, 0);
                    wp_redirect($redirect_url);
                    die();
                } else {
                    //ログイン失敗
                    //エラー情報を取得しログインページを表示する
                    $this->_authenticate_error_message = $auth->get_err_message();
                    if ($redirect_url) {
                        $delimiter = '?';
                        if (strpos($redirect_url, '?') !== false) {
                            $delimiter = '&';
                        }
                        $redirect_url = sprintf('%s%smessage=%s', $redirect_url, $delimiter, urlencode($this->_authenticate_error_message));
                    } else {
                        $redirect_url = sprintf('%s/membership-login/?message=%s', home_url(), urlencode($this->_authenticate_error_message));
                    }

                    if (TMP_MEM_DEBUG) error_log(__CLASS__ . ":" . __FUNCTION__ . " [LINE:" . __LINE__ . "] INFO: リダイレクト=>" . $redirect_url, 0);
                    wp_redirect($redirect_url);
                    die();
                    //return;
                }
            } else {
                // --------------------------
                //@@ 未ログインでログインページ以外を表示
                // --------------------------
                //固定ページは保護しない（但トップページに設定されているものは保護する）
                if (is_page()) {
                    if (is_front_page() || is_home()) {
                        if (TMP_MEM_DEBUG) error_log(__CLASS__ . ":" . __FUNCTION__ . " [LINE:" . __LINE__ . "] INFO: 固定ページ（トップに設定された）なので保護対象", 0);
                    } else {
                        if (TMP_MEM_DEBUG) error_log(__CLASS__ . ":" . __FUNCTION__ . " [LINE:" . __LINE__ . "] INFO: 固定ページなので表示", 0);
                        return;
                    }
                }

                // ログインしていなくても許可されているかの判定ページを通るように変更
                if ($auth->is_allow()) {
                    if (TMP_MEM_DEBUG) error_log(__CLASS__ . ":" . __FUNCTION__ . " [LINE:" . __LINE__ . "] INFO: ログインしていなくてもOK", 0);
                    return;
                }

                if (TMP_MEM_DEBUG) error_log(__CLASS__ . ":" . __FUNCTION__ . " [LINE:" . __LINE__ . "] INFO: ログインページへリダイレクト", 0);

                $this_url = $_SERVER['REQUEST_URI'];
                $param = "?redirect_url=" . urlencode($this_url);
                $redirect_url = home_url() . TMP_URL_PATH_LOGIN . "/" . $param;  //引数に元のページを含める
                if (TMP_MEM_DEBUG) error_log(__CLASS__ . ":" . __FUNCTION__ . " [LINE:" . __LINE__ . "] INFO: " . $redirect_url, 0);
                wp_redirect($redirect_url);
                die();
            }
        }
    }

    /**
     * 認証処理スキップページの判定
     **/
    private function _check_skip_page()
    {
        if (is_admin() || is_feed() || is_trackback() || is_attachment()) return false;
        if (is_page(ltrim(TMP_URL_PATH_PASSRESET, "/"))) return false;
        if (strpos($_SERVER['REQUEST_URI'], TMP_URL_PATH_NOTALLOW) !== false) return false;

        if (is_page(ltrim(TMP_URL_PATH_LOGOUT, "/"))) {

            //ログアウトページ
            $auth = tmpAuth::get_instance();
            $auth->logout();
            return false;
        } else if (is_page(ltrim(TMP_URL_PATH_LOGIN, "/")) && $_SERVER["REQUEST_METHOD"] != "POST") {
            //ログインページ、通常アクセス（ログイン処理ではない）
            $auth = tmpAuth::get_instance();
            if ($auth->is_logged_in()) {
                //ログイン状態でログインページにアクセスした場合はログイン画面を表示しない
                wp_redirect(home_url());
                die();
            }
            return false;
        }
        return true;
    }

    /*
     * 除外ページ判定
     */
    private function _exclusion_page()
    {
        //TmpAuth:is_allow で判定することに変更 ←月別アーカイブは非表示
        if (is_date()) {
            //$this->_redirect_not_allow_page();
        }
    }

    /*
     * リダイレクトし終了する
     */
    private function _redirect_not_allow_page()
    {
        $redirect_url = TMP_URL_PATH_NOTALLOW;
        wp_redirect($redirect_url);
        die();
    }

    /*
     * コンテンツ保護設定を判定
     */
    private function _check_enable_contents_block()
    {
        $settings = get_option('tmp-settings');
        if (isset($settings['enable-contents-block']) && !empty($settings['enable-contents-block'])) {
            //有効
            return true;
        } else {
            //無効
        }
        return false;
    }
    /**
     * [ログイン中の会員情報チェック]
     * Cookie内の会員IDを使い会員情報が存在するかチェック
     **/
    private function _login_in_check_tmp_member($auth_instance)
    {
        //会員情報の存在チェック
        $is_exist = $auth_instance->login_in_is_exist_member();
        if (!$is_exist) {
            //会員情報が存在しないのでログアウト処理をしてログイン画面を再表示
            $auth_instance->logout();
            $login_url = home_url() . TMP_URL_PATH_LOGIN . "/";
            if (TMP_MEM_DEBUG) error_log(__CLASS__ . ":" . __FUNCTION__ . " [LINE:" . __LINE__ . "] INFO: 会員情報の存在チェック= NG ログアウト処理をしてログイン画面を再表示", 0);
            wp_redirect($login_url);
            die();
        }
        if (TMP_MEM_DEBUG) error_log(__CLASS__ . ":" . __FUNCTION__ . " [LINE:" . __LINE__ . "] INFO: 会員情報の存在チェック= OK", 0);
    }

    /**
     * [ログイン中の会員情報チェック]
     * Cookie内の会員ランク更新日時を使い、指定時間経過していた場合
     * APIで所持チケットを取得し会員レベルの更新を行う
     **/
    private function _login_in_check_tmp_membership_level($auth_instance)
    {
        //Cookieの情報より会員ランクの更新を行う
        $is_past = $auth_instance->login_in_is_past_memberships_check_date();
        if ($is_past) {
            if (TMP_MEM_DEBUG) error_log(__CLASS__ . ":" . __FUNCTION__ . " [LINE:" . __LINE__ . "] INFO: 会員ランク更新日時チェック= NG", 0);
            //APIを使い所持チケットを更新、会員レベルも更新する
            if (!$auth_instance->login_in_update_tmp_menber()) {
                $auth_instance->logout();
                wp_die("エラーが発生しました。再度TOPページからやり直してください。");
            }
        } else {
            //指定時間を経過していない
            if (TMP_MEM_DEBUG) error_log(__CLASS__ . ":" . __FUNCTION__ . " [LINE:" . __LINE__ . "] INFO: 会員ランク更新日時チェック= OK", 0);
        }
        //正常終了
    }

    public function login()
    {
        ob_start();
        // エラーがあればセット
        if (!empty($this->_authenticate_error_message)) {
            $message = array("result" => "error", "mess" => $this->_authenticate_error_message);
        }
        if (isset($_GET['message']) && $_GET['message']) {
            $message = array("result" => "error", "mess" => $_GET['message']);
        }
        $template_files = TMP_MEM_PATH . 'views/login.php';
        require($template_files);
        return ob_get_clean();
    }
    /* ショートコード等で即座にフォーム出力する必要があるときにつかう */
    public function login_form_directly()
    {
        // エラーがあればセット
        if (!empty($this->_authenticate_error_message)) {
            $message = array("result" => "error", "mess" => $this->_authenticate_error_message);
        }
        if (isset($_GET['message']) && $_GET['message']) {
            $message = array("result" => "error", "mess" => $_GET['message']);
        }

        $template_files = TMP_MEM_PATH . 'views/login.php';
        require($template_files);
        return;
    }

    public function logout()
    {
        ob_start();
        $template_files = TMP_MEM_PATH . 'views/logout.php';
        require($template_files);
        return ob_get_clean();
    }

    public function reset()
    {
        ob_start();
        //Load the forgot password template
        $template_files = TMP_MEM_PATH . 'views/forgot_password.php';
        require($template_files);
        return ob_get_clean();
    }
}
