<?php if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly ?>
<?php $wp_table->prepare_items(); ?>
<p>会員認証にはTAKETINの情報が利用されますので、この画面で会員を登録する必要はありません。<br>会員リストにはログインが行われると、自動的に追加されていきます。</p>
<form method="post">
	<?php $wp_table->search_box('検索','tmp-members');?>
	<?php $wp_table->display(); ?>
</form>

<?php //echo sprintf('<p><a href="admin.php?page=%s_levels&level_action=add" class="button-primary">追加</a></p>', TMP_MEM_PREFIX); ?>