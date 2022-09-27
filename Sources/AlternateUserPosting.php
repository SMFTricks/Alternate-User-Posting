<?php

/**
 * @package Alternate User Posting
 * @version 2.0
 * @author Diego Andrés <diegoandres_cortes@outlook.com>
 * @copyright Copyright (c) 2020, SMF Tricks
 * @license https://www.mozilla.org/en-US/MPL/2.0/
 */

if (!defined('SMF'))
	die('No direct access...');

class AlternateUserPosting
{
	/**
	 * AlternateUserPosting::load_permissions()
	 *
	 * Add the permission for this mod
	 * 
	 */
	public static function load_permissions(&$permissionGroups, &$permissionList, &$leftPermissionGroups, &$hiddenPermissions, &$relabelPermissions)
	{
		loadLanguage(__CLASS__ . '/');
		$permissionList['board']['post_as_alternative_user'] = [false, 'topic'];
	}

	/**
	 * AlternateUserPosting::load_illegal_guest_permissions()
	 *
	 * Use it to set the permission of this mod as illegal for guests
	 * 
	 */
	public static function load_illegal_guest_permissions()
	{
		global $context;

		// Guests do not play nicely with this mod
		$context['non_guest_permissions'] = array_merge($context['non_guest_permissions'], ['post_as_alternative_user']);
	}

	/**
	 * AlternateUserPosting::before_create_topic()
	 *
	 * Called when starting a new topic
	 * 
	 */
	public static function before_create_topic(&$msgOptions, &$topicOptions, &$posterOptions, &$topic_columns, &$topic_parameters)
	{		
		// Did we get a member?
		if (isset($posterOptions['alternate_user']) && allowedTo('post_as_alternative_user'))
		{
			// Update very specific information
			$topic_parameters[1] = $posterOptions['alternate_user'];
			$topic_parameters[2] = $posterOptions['alternate_user'];
		}
	}

	/**
	 * AlternateUserPosting::create_post()
	 *
	 * Called when creating/posting a new message
	 * 
	 */
	public static function create_post(&$msgOptions, &$topicOptions, &$posterOptions, &$message_columns, &$message_parameters)
	{
		global $user_info;

		// Did we get a member?
		if (isset($_REQUEST['alternate_user']) && !empty($_REQUEST['alternate_user']) && allowedTo('post_as_alternative_user'))
		{
			// Search for this user
			$mem_data = self::search_member($_REQUEST['alternate_user']);

			// We got an user?
			if (!empty($mem_data) && $user_info['id'] != $mem_data['id_member'])
			{
				// Update very specific information
				$message_parameters[2] = $mem_data['id_member'];
				$message_parameters[5] = $mem_data['real_name'];
				$message_parameters[6] = $mem_data['email_address'];

				// Swap post increment
				$posterOptions['id'] = $mem_data['id_member'];

				// Add the alternate to use it later
				$posterOptions['alternate_user'] = $mem_data['id_member'];
			}
		}
	}

	/**
	 * AlternateUserPosting::modify_post()
	 *
	 * Called when modifying a post/topic
	 * 
	 */
	public static function modify_post(&$messages_columns, &$update_parameters, &$msgOptions, &$topicOptions, &$posterOptions, &$messageInts)
	{
		global $smcFunc;

		// Did we get a member?
		if (isset($_REQUEST['alternate_user']) && !empty($_REQUEST['alternate_user']) && allowedTo('post_as_alternative_user'))
		{
			// Search for this user
			$mem_data = self::search_member($_REQUEST['alternate_user']);

			// We got an user?
			if (!empty($mem_data))
			{
				// Update the id too
				$messages_columns['id_member'] = $mem_data['id_member'];

				// Update other columns
				$messages_columns['poster_name'] = $mem_data['real_name'];
				$messages_columns['poster_email'] = $mem_data['email_address'];

				// Swap post increment
				updateMemberData($mem_data['id_member'], array('posts' => '+'));

				// quickie
				$result = $smcFunc['db_query']('', '
					SELECT id_member
					FROM {db_prefix}messages
					WHERE id_msg = {int:msg}',
					[
						'msg' => $msgOptions['id'],
					]
				);
				$original_poster = $smcFunc['db_fetch_assoc']($result);
				$smcFunc['db_free_result']($result);

				// Decrease older poster
				updateMemberData($original_poster['id_member'], array('posts' => '-'));

				// Is it a topic???
				if (!empty($topicOptions['id']))
				{
					self::update_topic($msgOptions['id'], $mem_data['id_member']);

					// Event???? Woaaa
					self::update_event($topicOptions['id'], $mem_data['id_member']);
				}
			}
		}
	}

	/**
	 * AlternateUserPosting::post_end()
	 *
	 * We use it to insert the posting fields
	 * 
	 */
	public static function post_end()
	{
		global $txt, $context;

		if (allowedTo('post_as_alternative_user'))
		{
			// Load language
			loadLanguage(__CLASS__ . '/');

			// Load js bits
			loadJavaScriptFile('suggest.js', array('defer' => false, 'minimize' => true), 'smf_suggest');

			// Alternate user
			$context['posting_fields']['alternate_user'] = [
				'label' => [
					'text' => $txt['post_alternate_user'],
				],
				'input' => [
					'type' => 'text',
					'attributes' => [
						'size' => 25,
						'placeholder' => $txt['post_alternate_user_descr'],
					],
					'after' => '
						<script>
							var oAddMemberSuggest = new smc_AutoSuggest({
								sSelf: \'oAddMemberSuggest\',
								sSessionId: smf_session_id,
								sSessionVar: smf_session_var,
								sControlId: \'alternate_user\',
								sSearchType: \'member\',
								bItemList: false
							});
						</script>',
				],
			];
		}
	}

	/**
	 * AlternateUserPosting::search_member()
	 *
	 * Helper methor to search/find a specific user ID
	 * 
	 * @param int $alternate_user The user ID
	 * 
	 */
	public static function search_member($alternate_user)
	{
		global $smcFunc;

		$member_query = array();
		$member_parameters = array();

		// Get the member name...
		$alternate_user = strtr($smcFunc['htmlspecialchars']($alternate_user, ENT_QUOTES), array('&quot;' => '"'));
		preg_match_all('~"([^"]+)"~', $alternate_user, $matches);
		$member_name = array_unique(array_merge($matches[1], explode(',', preg_replace('~"[^"]+"~', '', $alternate_user))));

		foreach ($member_name as $index => $name)
		{
			$member_name[$index] = trim($smcFunc['strtolower']($member_name[$index]));

			if (strlen($member_name[$index]) == 0)
				unset($member_name[$index]);
		}

		// Construct the query
		if (!empty($member_name))
		{
			$member_query[] = 'LOWER(member_name) IN ({array_string:member_name})';
			$member_query[] = 'LOWER(real_name) IN ({array_string:member_name})';
			$member_parameters['member_name'] = $member_name;
		}

		if (!empty($member_query))
		{
			$request = $smcFunc['db_query']('', '
				SELECT id_member, email_address, real_name
				FROM {db_prefix}members
				WHERE (' . implode(' OR ', $member_query) . ')
				LIMIT 1',
				$member_parameters
			);
			$mem_data = $smcFunc['db_fetch_assoc']($request);
			$smcFunc['db_free_result']($request);
		}

		return $mem_data;
	}

	/**
	 * AlternateUserPosting::update_topic()
	 *
	 * Called when updating a topic
	 * 
	 */
	public static function update_topic($id, $id_member)
	{
		global  $smcFunc;

		// Find that specific topic?
		$smcFunc['db_query']('', '
			UPDATE {db_prefix}topics
			SET
				id_member_started = {int:member},
				id_member_updated = {int:member}
			WHERE id_first_msg = {int:id}',
			[
				'id' => $id,
				'member' => $id_member,
			]
		);
	}
	
	/**
	 * AlternateUserPosting::update_event()
	 *
	 * Called when updating a calendar event
	 * 
	 */
	public static function update_event($id, $id_member)
	{
		global  $smcFunc;

		// Find that specific topic?
		$smcFunc['db_query']('', '
			UPDATE {db_prefix}calendar
			SET
				id_member = {int:member}
			WHERE id_topic = {int:id_event}',
			[
				'id_event' => $id,
				'member' => $id_member,
			]
		);
	}

	/**
	 * AlternateUserPosting::helpadmin()
	 *
	 * Loads the language file for the help popups in the permissions page
	 * 
	 */
	public static function helpadmin()
	{
		loadLanguage(__CLASS__ . '/');
	}
}