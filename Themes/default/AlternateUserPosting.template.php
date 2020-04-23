<?php

/**
 * @package Alternate User Posting
 * @version 2.0
 * @author Diego Andrés <diegoandres_cortes@outlook.com>
 * @copyright Copyright (c) 2020, SMF Tricks
 * @license https://www.mozilla.org/en-US/MPL/2.0/
 */

function template_post_alternate_user_above() {}

function template_post_alternate_user_below()
{
	echo '
	<script>
		var oAddMemberSuggest = new smc_AutoSuggest({
			sSelf: \'oAddMemberSuggest\',
			sSessionId: smf_session_id,
			sSessionVar: smf_session_var,
			sControlId: \'alternate_user\',
			sSearchType: \'member\',
			bItemList: false
		});
	</script>';
}