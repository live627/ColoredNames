<?php

/**
 * Colored Names
 *
 * @author  emanuele
 * @license BSD http://opensource.org/licenses/BSD-3-Clause
 *
 * @version 0.0.3
 */

class ColoredNames
{
	protected $old_preload = null;
	protected $picker = null;

	public static function load_profile_fields(&$fields)
	{
		// Let's be nice to other addons
		if (isset($fields['real_name']['preload']))
			$this->old_preload = $fields['real_name']['preload'];

		$fields['real_name']['preload'] = function() use ($cur_profile)
		{
			if (trim($cur_profile['plain_real_name']) !== '')
				$cur_profile['real_name'] = $cur_profile['plain_real_name'];

			if ($this->old_preload !== null)
				return $this->old_preload();
			else
				return true;
		}

		$fields['plain_real_name'] = array(
			'type' => 'hidden',
			'is_dummy' => true,
		);

		$fields['colored_names_picker'] = array(
			'type' => 'callback',
			'callback_func' => 'colored_names_picker',
			'preload' => 'profileLoadColoredNames',
			'input_validate' => 'profileSaveColoredNames',
			'save_key' => 'colored_names',
		);
	}

	public static function account_profile_fields(&$fields)
	{
		$fields[] = 'plain_real_name';
	}

	public static function  themepick_profile_fields(&$fields)
	{
		$fields = elk_array_insert($fields, 'time_format', array('colored_names_picker', 'hr'), 'before', false);
		loadTemplate('ColoredNames');
	}

	public static function member_data(&$select_columns, &$select_tables, $set)
	{
		$select_columns .= ', mem.plain_real_name, mem.colored_names';
	}

	public static function profile_save(&$profile_vars, &$post_errors, $memID)
	{
		if (isset($profile_vars['real_name']))
		{
			self::setName($profile_vars, $memID);
		}
	}

	public static function saveColoredName($styles, $memID, $name)
	{
		$db = database();
		$style_pairs = array();

		foreach ($styles as $key => $val)
		{
			$style_pairs[] = $key . ':' . $val;
		}
		$style = implode(';', $style_pairs);
		$template = '<span style="' . $style . '">{name}</span>';

		$db->query('', '
			UPDATE {db_prefix}members
			SET
				real_name = {string:new_name},
				plain_real_name = {string:plain_name}
			WHERE id_member = {int:id_member}',
			array(
				'new_name' => str_replace('{name}', $name, $template),
				'plain_name' => $name,
				'id_member' => $memID,
			)
		);

		return $template;
	}

	public static function action_personalmessage_after()
	{
		global $context;

		if (!empty($context['to_value']))
		{
			$to_value = explode('&quot;, &quot;', substr($context['to_value'], 6, -6));
			foreach ($to_value as $key => $val)
				$to_value[$key] = self::cleanQuote(Util::htmlspecialchars($val));

			$context['to_value'] = '&quot;' . implode('&quot;, &quot;', $to_value) . '&quot;';
		}

		self::cleanGeneralGuesses();
	}

	public static function action_post_after()
	{
		global $context;

		if (!empty($context['current_action']) && $context['current_action'] == 'quotefast')
		{
			if (isset($context['message']['body']))
				$base = 'message';
			else
				$base = 'quote';

			foreach ($context[$base] as $key => $val)
				$context[$base][$key] = self::cleanQuote($context[$base][$key]);

			self::cleanGeneralGuesses();
		}
		else
		{
			self::cleanGeneralGuesses();
		}
	}

	private static function cleanGeneralGuesses()
	{
		global $context;

		if (!empty($context['message']))
			$context['message'] = self::cleanQuote($context['message']);

		if (!empty($context['controls']['richedit']))
		{
			foreach ($context['controls']['richedit'] as $key => $val)
			{
				$context['controls']['richedit'][$key]['value'] = self::cleanQuote($val['value']);
			}
		}
	}

	/**
	 * This function can be used by anyone to clean up a specific field.
	 */
	public static function cleanQuote($msg)
	{
		return preg_replace('~\[quote(.*?)author=&lt;span [^\]]*?&gt;([^\/]*?)&lt;/span&gt;~i', '[quote$1author=$2',  $msg);
	}

	private static function setName(&$profile_vars, $memID)
	{
		global $user_profile;

		if (!empty($user_profile[$memID]['colored_names']) && strpos($user_profile[$memID]['colored_names'], '{name}') !== false)
		{
			$profile_vars['plain_real_name'] = $profile_vars['real_name'];
			$profile_vars['real_name'] = str_replace('{name}', $profile_vars['real_name'], $user_profile[$memID]['colored_names']);
		}
	}
}

function profileLoadColoredNames()
{
	global $cur_profile, $context, $txt;

	loadLanguage('ColoredNames');
	$context['current_colored_name'] = ColoredNames::known_style_attributes();
	$current_vals = array();

	if (!empty($cur_profile['colored_names']))
	{
		$styles = explode(';', substr(substr($cur_profile['colored_names'], 13), 0, -15));
		foreach ($styles as $style)
		{
			$val = explode(':', $style);
			if (isset($context['current_colored_name'][$val[0]]))
			{
				$current_vals[trim($val[0])] = trim($val[1]);
				$context['current_colored_name'][$val[0]]['value'] = trim($val[1]);
			}
		}
	}

	return true;
}

function profileSaveColoredNames(&$value)
{
	global $cur_profile, $profile_vars;

	require_once(SUBSDIR . '/DataValidator.class.php');

	$styles = array();
	$validator = new Data_Validator();

	foreach (ColoredNames::known_style_attributes() as $name => $values)
	{
		$post = isset($_POST['colored_names_vals'][$name]) ? trim($_POST['colored_names_vals'][$name]) : '';
		if ($post !== '')
		{
			switch ($values['type'])
			{
				case 'select':
					if (isset($values['values'][$post]))
						$styles[$name] = $values['values'][$post];
					break;
				case 'color':
					if (empty($_POST['colored_names_vals']['default_' . $name]))
					{
						$validator->validation_rules(array($name => 'valid_color'));
						if ($validator->validate(array($name => $post)))
							$styles[$name] = $post;
					}
					break;
				case 'text':
				default:
					if (isset($values['validate']))
						$styles[$name] = $values['validate']($post);
					else
						$styles[$name] = Util::htmlspecialchars($post, ENT_QUOTES);
					break;
			}
		}
	}

	if (!empty($styles))
	{
		$memID = currentMemberID();
		$name = empty($cur_profile['plain_real_name']) ? $cur_profile['real_name'] : $cur_profile['plain_real_name'];

		$value = ColoredNames::saveColoredName($styles, $memID, $name);
		// Set the save variable.
		$profile_vars['colored_names'] = $value;

		// And update the user profile.
		$cur_profile['colored_names'] = $value;
	}
	return false;
}
