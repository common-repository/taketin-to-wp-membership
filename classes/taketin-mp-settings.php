<?php
class TaketinMpSettings
{

    private static $_this;
    private $settings;
    private $ticket_settings;
    public $current_tab;
    private $tabs;

    private function __construct()
    {
        $this->settings = (array) get_option('tmp-settings');
        $this->ticket_settings = (array) get_option('tmp-use-tickets');
    }

    public static function get_instance()
    {
        self::$_this = empty(self::$_this) ? new TaketinMpSettings() : self::$_this;
        return self::$_this;
    }

    public function init_config_hooks()
    {

        if (is_admin()) {

            //Read the value of tab query arg.
            $tab = filter_input(INPUT_GET, 'tab');
            $tab = empty($tab) ? filter_input(INPUT_POST, 'tab') : $tab;
            $this->current_tab = empty($tab) ? 1 : $tab;

            //Setup the available settings tabs array.
            $this->tabs = array(
                1 => '基本設定',
                2 => 'チケット設定',
                //3 => 'Addons Settings'
            );

            add_action('tmp-draw-settings-nav-tabs', array(&$this, 'draw_tabs'));

            //Register the various settings fields for the current tab.
            $method = 'tab_' . $this->current_tab;
            if (method_exists($this, $method)) {
                $this->$method();
            }
        }
    }

    public function get_value($key, $default = "")
    {
        if (isset($this->settings[$key])) {
            return $this->settings[$key];
        }
        return $default;
    }

    public function set_value($key, $value)
    {
        $this->settings[$key] = $value;
        return $this;
    }

    public function save()
    {
        update_option('tmp-settings', $this->settings);
    }

    public function handle_main_settings_admin_menu()
    {

?>
        <div class="wrap tmp-admin-menu-wrap"><!-- start wrap -->
            <h1>TAKETIN MP Membership::設定</h1><!-- page title -->

            <!-- start nav menu tabs -->
            <?php do_action("tmp-draw-settings-nav-tabs"); ?>
            <!-- end nav menu tabs -->
            <?php

            //Switch to handle the body of each of the various settings pages based on the currently selected tab
            $current_tab = $this->current_tab;

            switch ($current_tab) {
                case 1:
                    //General settings
                    include(TMP_MEM_PATH . 'views/admin_settings.php');
                    break;
                case 2:
                    //Redirect settings
                    include(TMP_MEM_PATH . 'views/ticket_settings.php');
                    break;
                default:
                    include(TMP_MEM_PATH . 'views/admin_settings.php');
                    break;
            }

            echo '</div>'; //<!-- end of wrap -->

        }

        public function tmp_general_post_submit_check_callback()
        {

            //Show settings updated message
            if (isset($_REQUEST['settings-updated'])) {
                echo '<div id="message" class="updated fade"><p>設定を変更しました。</p></div>';
            }
        }

        private function tab_1()
        {

            //Mess
            add_settings_section('tmp-general-post-submission-check', '', array(&$this, 'tmp_general_post_submit_check_callback'), 'taketin_mp_membership_settings');

            //Register settings sections and fileds for the general settings tab.
            register_setting('tmp-settings-tab-1', 'tmp-settings', array(&$this, 'sanitize_tab_1'));
            add_settings_section('general-settings', '基本設定', array(&$this, 'general_settings_callback'), 'taketin_mp_membership_settings');
            add_settings_field('enable-contents-block', 'コンテンツ制限機能', array(&$this, 'checkbox_callback'), 'taketin_mp_membership_settings', 'general-settings', array(
                'item' => 'enable-contents-block',
                'message' => '利用を開始する'
            ));

            add_settings_field('show-contents-if-no-config', '制限設定のないページ', array(&$this, 'checkbox_callback'), 'taketin_mp_membership_settings', 'general-settings', array(
                'item' => 'show-contents-if-no-config',
                'message' => '誰でも見られる'
            ));

            add_settings_section('api-settings', '連携設定', array(&$this, 'general_settings_callback'), 'taketin_mp_membership_settings');
            add_settings_field('taketin-system-url', 'API連携用URL', array(&$this, 'textfield_long_callback'), 'taketin_mp_membership_settings', 'api-settings', array(
                'item' => 'taketin-system-url',
                'message' => 'API連携用のURLを登録します。'
            ));
            add_settings_field('taketin-app-secret', 'API接続キー', array(&$this, 'textfield_long_callback'), 'taketin_mp_membership_settings', 'api-settings', array(
                'item' => 'taketin-app-secret',
                'message' => ''
            ));


            add_settings_field('taketin_mp_check_memberlevel_update_minuites', '会員権限の再チェック時間（分）', array(&$this, 'textfield_long_callback'), 'taketin_mp_membership_settings', 'api-settings', array(
                'item' => 'taketin_mp_check_memberlevel_update_minuites',
                'default' => '30',
                'message' => '会員ログイン時、権限があるかを再確認する時間を設定します。(単位: 分)'
            ));


            add_settings_section('page-settings', 'ページ設定', array(&$this, 'general_settings_callback'), 'taketin_mp_membership_settings');
            add_settings_field('notallow-page-url', '閲覧する権限のないページへのアクセス時', array(&$this, 'textfield_long_callback'), 'taketin_mp_membership_settings', 'page-settings', array('item' => 'notallow-page-url', 'message' => ''));

            add_settings_field('enable-duplicate-login-check', '重複ログイン禁止', array(&$this, 'checkbox_callback'), 'taketin_mp_membership_settings', 'page-settings', array('item' => 'enable-duplicate-login-check', 'message' => '禁止する'));
            add_settings_field('duplicate-login-page-url', '重複ログイン時の転送先', array(&$this, 'textfield_long_callback'), 'taketin_mp_membership_settings', 'page-settings', array('item' => 'duplicate-login-page-url', 'message' => ''));

            add_settings_field('custom-posts-view', 'ログインが必要な<br>カスタム投稿', array(&$this, 'textfield_long_callback'), 'taketin_mp_membership_settings', 'custompost-settings', array('item' => 'custom-posts-view', 'message' => ''));
            //add_settings_section('logout-button-settings', 'ログアウトボタン設定', array(&$this, 'general_settings_callback'), 'taketin_mp_membership_settings');
            /*add_settings_field('logout-button-target-style-element', 
            'ログアウトボタンの挿入箇所（css 要素指定）', 
            array(&$this, 'textfield_long_callback'), 
            'taketin_mp_membership_settings',
            'logout-button-settings',
            array('item' => 'logout-button-target-style-element','message' => '')
        );*/
            add_settings_section('logout-button-settings', 'ログアウト用のリンク', array(&$this, 'general_settings_callback'), 'taketin_mp_membership_settings');

            add_settings_section('option-settings', '各種一覧ページの表示', array(&$this, 'general_settings_callback'), 'taketin_mp_membership_settings');

            add_settings_field('enable-view-search', '検索結果ページの利用', array(&$this, 'checkbox_callback'), 'taketin_mp_membership_settings', 'option-settings', array('item' => 'enable-view-search', 'message' => '使用する'));
            add_settings_field('enable-view-archive', '月別アーカイブ一覧の利用', array(&$this, 'checkbox_callback'), 'taketin_mp_membership_settings', 'option-settings', array('item' => 'enable-view-archive', 'message' => '使用する'));
            add_settings_field('enable-view-tagpage', 'タグ検索ページの利用', array(&$this, 'checkbox_callback'), 'taketin_mp_membership_settings', 'option-settings', array('item' => 'enable-view-tagpage', 'message' => '使用する'));
            add_settings_field('enable-view-authorpage', '著者ページの利用', array(&$this, 'checkbox_callback'), 'taketin_mp_membership_settings', 'option-settings', array('item' => 'enable-view-authorpage', 'message' => '使用する'));

            //カスタム投稿のアーカイブページについての許可設定
            if ($hCustomPosts = get_post_types(array('public' => true, '_builtin' => false), 'names')) {
                foreach ($hCustomPosts as $key => $val) {
                    if ($val == 'lp_quiz') {
                        continue;
                    } //LMS側で将来利用可能性があるが消しておく
                    add_settings_field(sprintf('enable-view-archive-%s', $val), sprintf('カスタム投稿【%s】一覧', $val), array(&$this, 'checkbox_callback'), 'taketin_mp_membership_settings', 'option-settings', array('item' => sprintf('enable-view-archive-%s', $val), 'message' => '使用する'));
                }
            }
            add_settings_section('custompost-settings', '例外ページの登録', array(&$this, 'general_settings_callback'), 'taketin_mp_membership_settings');
        }

        public function sanitize_tab_1($input)
        {
            if (empty($this->settings)) {
                $this->settings = (array) get_option('tmp-settings');
            }
            $output = $this->settings;

            //general settings block
            $output['enable-contents-block'] = isset($input['enable-contents-block']) ? esc_attr($input['enable-contents-block']) : "";
            $output['show-contents-if-no-config'] = isset($input['show-contents-if-no-config']) ? esc_attr($input['show-contents-if-no-config']) : "";

            $output['taketin-system-url'] = isset($input['taketin-system-url']) ? esc_attr($input['taketin-system-url']) : "";
            $output['taketin-app-secret'] = isset($input['taketin-app-secret']) ? esc_attr($input['taketin-app-secret']) : "";
            $output['taketin_mp_check_memberlevel_update_minuites'] = isset($input['taketin_mp_check_memberlevel_update_minuites']) ? esc_attr($input['taketin_mp_check_memberlevel_update_minuites']) : "";
            $output['login-page-url'] = isset($input['login-page-url']) ? esc_attr($input['login-page-url']) : "";
            $output['notallow-page-url'] = isset($input['notallow-page-url']) ? esc_attr($input['notallow-page-url']) : "";
            $output['duplicate-login-page-url'] = isset($input['duplicate-login-page-url']) ? esc_attr($input['duplicate-login-page-url']) : "";
            $output['enable-duplicate-login-check'] = isset($input['enable-duplicate-login-check']) ? esc_attr($input['enable-duplicate-login-check']) : "";


            $output['logout-button-target-style-element'] = isset($input['logout-button-target-style-element']) ? esc_attr($input['logout-button-target-style-element']) : "";

            $output['enable-view-search'] = isset($input['enable-view-search']) ? esc_attr($input['enable-view-search']) : "";
            $output['enable-view-archive'] = isset($input['enable-view-archive']) ? esc_attr($input['enable-view-archive']) : "";
            $output['enable-view-tagpage'] = isset($input['enable-view-tagpage']) ? esc_attr($input['enable-view-tagpage']) : "";
            $output['enable-view-authorpage'] = isset($input['enable-view-authorpage']) ? esc_attr($input['enable-view-authorpage']) : "";
            $output['custom-posts-view'] = isset($input['custom-posts-view']) ? esc_attr($input['custom-posts-view']) : "";

            //カスタム投稿のアーカイブページについての許可設定
            if ($hCustomPosts = get_post_types(array('public' => true, '_builtin' => false), 'names')) {
                foreach ($hCustomPosts as $key => $val) {
                    $keyName = sprintf('enable-view-archive-%s', $val);
                    $output[$keyName] = isset($input[$keyName]) ? esc_attr($input[$keyName]) : "";
                }
            }

            return $output;
        }

        private function tab_2()
        {

            //Mess
            add_settings_section('tmp-general-post-submission-check', '', array(&$this, 'tmp_general_post_submit_check_callback'), 'taketin_mp_membership_use_tickets');

            //Register settings sections and fileds for the general settings tab.
            register_setting('tmp-settings-tab-2', 'tmp-use-tickets', array(&$this, 'sanitize_tab_2'));
        }

        public function sanitize_tab_2($input)
        {

            $output = $input;

            //general settings block

            return $output;
        }

        public function checkbox_callback($args)
        {
            $item = $args['item'];
            $msg = isset($args['message']) ? $args['message'] : '';
            if ($is = esc_attr($this->get_value($item))) {
                echo "<input type='checkbox' id='" . esc_attr(TMP_MEM_PREFIX) . '_' . esc_attr($item) . "' " . esc_attr($is) . " name='tmp-settings[" . esc_attr($item) . "]'  value=\"1\" checked='checked'/>";
            } else {
                echo "<input type='checkbox' id='" . esc_attr(TMP_MEM_PREFIX) . '_' . esc_attr($item) . "' " . esc_attr($is) . " name='tmp-settings[" . esc_attr($item) . "]'  value=\"1\" />";
            }
            echo "<label for='" . esc_attr(TMP_MEM_PREFIX) . '_' . esc_attr($item) . "'>" . esc_html($msg) . "</label>";
        }

        public function textfield_long_callback($args)
        {

            $item = $args['item'];
            $msg = isset($args['message']) ? $args['message'] : '';
            $default = isset($args['default']) ? $args['default'] : null;
            if (!$this->get_value($item) && $default != null) {
                if (TMP_MEM_DEBUG) {
                    error_log(__CLASS__ . ":" . __FUNCTION__ . " [LINE:" . __LINE__ . "] " . $item . ':' . $default . ':' . $this->get_value($item), 0);
                }
                $this->set_value($item, $default);
            }
            $text = esc_attr($this->get_value($item));
            echo "<input type='text' name='tmp-settings[" . esc_attr($item) . "]'  size='100' value='" . esc_attr($text) . "' />";
            echo esc_html($msg);
        }


        /*
    public function set_value($key, $value) {
        $this->settings[$key] = $value;
        return $this;
    }

    public function save() {
        update_option('tmp-settings', $this->settings);
    }
*/
        public function draw_tabs()
        {
            $current = $this->current_tab;

            ?>
            <div class="nav-tab-wrapper">
                <?php foreach ($this->tabs as $id => $label) { ?>
                    <a class="nav-tab <?php echo ($current == $id) ? 'nav-tab-active' : ''; ?>" href="admin.php?page=taketin_mp_membership_settings&tab=<?php echo esc_attr($id) ?>"><?php echo esc_html($label) ?></a>
                <?php } ?>
            </div>
    <?php
        }

        public function general_settings_callback($args)
        {
            switch ($args['id']) {
                case 'custompost-settings':
                    echo 'カスタム投稿のスラッグを登録（カンマ区切りで複数可）すると、ログインユーザーだけが見れるコンテンツに変わります。';
                    break;
                case 'option-settings':
                    echo '各種一覧ページの結果は、閲覧権限のないものも含まれますが、表示したい場合はチェックを入れます。';
                    break;
                case 'general-settings':
                    echo 'コンテンツの保護設定を行います。';
                    break;
                case 'api-settings':
                    echo 'TAKETIN MPとの連携情報を登録します。';
                    break;
                case 'page-settings':
                    echo '転送先のページURLを設定します。通常は変更の必要はありません。';
                    break;
                case 'logout-button-settings':
                    echo 'こちらのURLにアクセスするとログアウトが行われます。会員サイトにログアウト用リンクを設置しましょう。<input type="text" value="' . esc_attr(home_url()) . '/membership-logout" size=100>';
                    break;
                default:
                    echo '設定を行います。';
                    break;
            }
        }
    }
