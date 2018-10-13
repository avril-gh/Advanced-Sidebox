<?php
/*
 * Plugin Name: Advanced Sidebox for MyBB 1.8.x
 * Copyright 2014 WildcardSearch
 * http://www.rantcentralforums.com
 *
 * the forum-side routines start here
 */

// only add the necessary hooks and templates
asb_initialize();

/**
 * main implementation of many hooks depending upon THIS_SCRIPT constant and
 * $mybb->input/$_GET vars (see asb_initialize())
 *
 * @return void
 */
function asb_start()
{
	global $mybb, $theme;

	// don't waste execution if unnecessary
	if (!asb_do_checks()) {
		return;
	}

	$asb = AdvancedSideboxCache::getInstance()->getCache();
	$this_script = asb_get_this_script($asb, true);

	// no boxes, get out
	if (!is_array($this_script['sideboxes']) ||
	   empty($this_script['sideboxes']) ||
	   (empty($this_script['sideboxes'][0]) &&
	   empty($this_script['sideboxes'][1])) ||
	   ((strlen($this_script['find_top']) == 0 ||
	   strlen($this_script['find_bottom']) == 0) &&
	   (!$this_script['replace_all'] &&
	   !$this_script['eval']))) {
		return;
	}

	$width = $boxes = array(
		0 => '',
		1 => ''
	);

	// make sure this script's width is within range 120-800 (120 because the templates
	// aren't made to work any smaller and tbh 800 is kind of arbitrary :s
	foreach (array('left' => 0, 'right' => 1) as $key => $pos) {
		$width[$pos] = (int) max(120, min(800, $this_script["width_{$key}"]));
	}

	// does this column have boxes?
	if (!is_array($this_script['sideboxes']) ||
		empty($this_script['sideboxes'])) {
		return;
	}

	// functions for add-on modules
	require_once MYBB_ROOT . 'inc/plugins/asb/functions_addon.php';

	// loop through all the boxes for the script
	foreach ($this_script['sideboxes'] as $pos => $sideboxes) {
		// does this column have boxes?
		if (!is_array($sideboxes) ||
			empty($sideboxes)) {
			continue;
		}

		// loop through them
		foreach ($sideboxes as $id => $module_name) {
			// verify that the box ID exists
			if (!isset($asb['sideboxes'][$id])) {
				continue;
			}

			// then load the object
			$sidebox = new SideboxObject($asb['sideboxes'][$id]);

			// can the user view this side box?
			if (!asb_check_user_permissions($sidebox->get('groups'))) {
				continue;
			}

			// is this theme available for this side box?
			$good_themes = $sidebox->get('themes');
			if ($good_themes &&
				!in_array($theme['tid'], $good_themes)) {
				continue;
			}

			$result = false;

			// get the template variable
			$template_var = "{$module_name}_{$id}";

			// attempt to load the box as an add-on module
			$module = new SideboxExternalModule($module_name);

			// if it is valid, then the side box was created using an
			// add-on module, so we can proceed
			if ($module->isValid()) {
				// build the template. pass settings, template variable
				// name and column width
				$result = $module->buildTemplate($sidebox->get('settings'), $template_var, $width[$pos], get_current_location());
			// if it doesn't verify as an add-on, try it as a custom box
			} elseif (isset($asb['custom'][$module_name]) &&
				is_array($asb['custom'][$module_name])) {
				$custom = new CustomSidebox($asb['custom'][$module_name]);

				// if it validates, then build it, otherwise there was an error
				if ($custom->isValid()) {
					// build the custom box template
					$result = $custom->buildTemplate($template_var);
				}
			} else {
				continue;
			}

			/*
			 * all box types return true or false based upon whether they have
			 * content to show. in the case of custom boxes, false is returned
			 * when the custom content is empty; in reference to add-on modules
			 * many factors are involved, but basically, if the side box depends on
			 * an element (threads for example) and there are none, it will return
			 * false-- IF asb_show_empty_boxes is true then it will return a side
			 * box with a 'no content' message, if not, it will be skipped
			 */
			if ($result ||
				$mybb->settings['asb_show_empty_boxes']) {
				$boxes[$pos] .= asb_build_sidebox_content($sidebox->get('data'));
			}
		}
	}

	// make the edits
	asb_edit_template($boxes, $width, $this_script);
}

/**
 * edit the appropriate template
 *
 * @param  array side boxes
 * @param  array left and right width
 * @param  array current script definition
 * @return void
 */
function asb_edit_template($boxes, $width, $script)
{
	global $mybb, $lang, $templates, $headerinclude, $theme;

	if ($mybb->settings['asb_minify_js']) {
		$min = '.min';
	}

	$left_insert = $boxes[0];
	$right_insert = $boxes[1];
	$width_left = $width[0];
	$width_right = $width[1];
	$toggles = $show = array();
	$filename = THIS_SCRIPT;

	// if admin wants to show the toggle icons . . .
	if ($mybb->settings['asb_show_toggle_icons']) {
		// we will need this js
		$headerinclude .= <<<EOF
<script type="text/javascript" src="jscripts/asb/asb{$min}.js"></script>
EOF;

		$toggle_info['left'] = array(
			'close' => array(
				'img' => "{$theme['imgdir']}/asb/left_arrow.png",
				'alt' => '&lt;'
			),
			'open' => array(
				'img' => "{$theme['imgdir']}/asb/right_arrow.png",
				'alt' => '&gt;'
			)
		);
		$toggle_info['right']['close'] = $toggle_info['left']['open'];
		$toggle_info['right']['open'] = $toggle_info['left']['close'];

		foreach (array('left', 'right') as $key) {
			// check the cookie
			if ($mybb->cookies["asb_hide_{$key}"] == 1) {
				// hide left
				$show[$key] = $close_style = 'display: none; ';
				$open_style = '';
			} else {
				// show left
				$close_style = '';
				$open_style = 'display: none; ';
			}

			// produce the link
			$open_image = $toggle_info[$key]['open']['img'];
			$close_image = $toggle_info[$key]['close']['img'];
			$open_alt = $toggle_info[$key]['open']['alt'];
			$close_alt = $toggle_info[$key]['close']['alt'];
			$column_id = "asb_hide_column_{$key}";
			$closed_id = "asb_{$key}_close";
			$open_id = "asb_{$key}_open";

			eval("\$toggles[\$key] = \"" . $templates->get('asb_toggle_icon') . "\";");
		}
	}

	foreach (array('left', 'right') as $key) {
		// if there is content
		$var_name = "{$key}_insert";
		if ($$var_name) {
			$prop_name = "{$key}_content";
			$width_name = "width_{$key}";
			$width = $$width_name;
			$show_column = $show[$key];
			$column_id = "asb_{$key}_column_id";
			$insert_name = "{$key}_insert";
			$sideboxes = $$insert_name;

			eval("\$content_pad = \"" . $templates->get('asb_content_pad') . "\";");
			eval("\$content = \"" . $templates->get('asb_sidebox_column') . "\";");

			$toggle_left = $toggle_right = '';
			$toggle_name = "toggle_{$key}";
			$$toggle_name = $toggles[$key];

			// finally set $POSITION_content for ::make_edits()
			$$prop_name = <<<EOF

		<!-- start: ASB {$key} column -->{$toggle_left}
		{$content}
		<!-- end: ASB {$key} column -->{$toggle_right}
EOF;
		}
	}
	eval("\$insert_top = \"" . $templates->get('asb_begin') . "\";");
	eval("\$insert_bottom = \"" . $templates->get('asb_end') . "\";");

	if (is_array($script['extra_scripts']) &&
		!empty($script['extra_scripts'])) {
		$sep = '';
		$dateline = TIME_NOW;
		foreach ($script['extra_scripts'] as $id => $info) {
			// build the JS objects to pass to the custom object builder
			$extra_scripts .= <<<EOF
{$sep}{ addon: '{$info['module']}', id: {$id}, position: {$info['position']}, rate: {$info['rate']}, dateline: {$dateline} }
EOF;
			$sep = ', ';
		}

		$location = get_current_location();
		$headerinclude .= <<<EOF

<script type="text/javascript" src="jscripts/asb/asb_xmlhttp{$min}.js"></script>
<script type="text/javascript">
<!--
$(function() {
	ASB.ajax.buildUpdaters([ {$extra_scripts} ], { left: {$width_left}, right: {$width_right} }, '{$location}');
});
// -->
</script>
EOF;
	}

	if (is_array($script['js'])) {
		foreach ($script['js'] as $script_name) {
			$script_name .= $min;
			if (!file_exists(MYBB_ROOT . "jscripts/asb/{$script_name}.js")) {
				continue;
			}

			$headerinclude .= <<<EOF

<script type="text/javascript" src="jscripts/asb/{$script_name}.js"></script>
EOF;
		}
	}

	// replace everything on the page?
	if ($script['replace_all'] == true) {
		// if there is content
		if ($script['replacement']) {
			// replace the existing page entirely
			$templates->cache[$script['template_name']] = str_replace(array('{$asb_left}', '{$asb_right}'), array($insert_top, $insert_bottom), $script['replacement']);
		}
	// outputting to variables? (custom script/Page Manager)
	} elseif($script['eval']) {
		// globalize our columns
		global $asb_left, $asb_right;

		// globalize all the add-on template variables
		if (is_array($script['template_vars']) &&
			!empty($script['template_vars'])) {
			foreach ($script['template_vars'] as $var) {
				global $$var;
			}
		}

		// now eval() their content for the custom script
		eval("\$asb_left = \"" . str_replace("\\'", "'", addslashes($insert_top)) . "\";");
		eval("\$asb_right = \"" . str_replace("\\'", "'", addslashes($insert_bottom)) . "\";");
	// otherwise we are editing the template in the cache
	} else {
		// if there are columns stored
		if ($insert_top ||
			$insert_bottom) {
			// make the edits
			$script['find_top'] = str_replace("\r", '', $script['find_top']);
			$script['find_bottom'] = str_replace("\r", '', $script['find_bottom']);
			$find_top_pos = strpos($templates->cache[$script['template_name']], $script['find_top']);

			if ($find_top_pos !== false) {
				$find_bottom_pos = strpos($templates->cache[$script['template_name']], $script['find_bottom']);

				if ($find_bottom_pos !== false) {
					/*
					 * split the template in 3 parts and splice our columns in after 1 and before 3
					 * it is important that we function this way so we can work with the
					  * FIRST instance of the search text (find_top and find_bottom) rather
					  * than replacing multiple found instances
					 */
					$templates->cache[$script['template_name']] =
						substr($templates->cache[$script['template_name']], 0, $find_top_pos + strlen($script['find_top'])) .
						$insert_top .
						substr($templates->cache[$script['template_name']], $find_top_pos + strlen($script['find_top']), $find_bottom_pos - ($find_top_pos + strlen($script['find_top']))) .
						$insert_bottom .
						substr($templates->cache[$script['template_name']], $find_bottom_pos);
				}
			}
		}
	}
}

/**
 * add the appropriate hooks and caches any templates that will be used
 *
 * @return void
 */
function asb_initialize()
{
	global $mybb, $plugins;

	// hooks for the User CP routine.
	switch (THIS_SCRIPT) {
	case 'usercp.php':
		if ($mybb->settings['asb_allow_user_disable']) {
			$plugins->add_hook('usercp_options_end', 'asb_usercp_options_end');
			$plugins->add_hook('usercp_do_options_end', 'asb_usercp_options_end');
		}
		break;
	case 'xmlhttp.php':
		$plugins->add_hook('xmlhttp', 'asb_xmlhttp');
		break;
	}

	// get the cache
	$asb = AdvancedSideboxCache::getInstance()->getCache();
	$this_script = asb_get_this_script($asb, true);

	// anything to show for this script?
	if (!is_array($this_script['sideboxes']) ||
		empty($this_script['sideboxes'])) {
		return;
	}

	// then add the hook . . . one priority lower than Page Manager ;-) we need to run first
	$plugins->add_hook($this_script['hook'], 'asb_start', 9);

	// cache any script-specific templates (read: templates used by add-ons used in the script)
	$template_list = '';
	if (is_array($this_script['templates']) &&
		!empty($this_script['templates'])) {
		$template_list = ',' . implode(',', $this_script['templates']);
	}

	// add the extra templates (if any) to our base stack
	global $templatelist;
	$templatelist .= ',asb_begin,asb_end,asb_sidebox_column,asb_wrapped_sidebox,asb_toggle_icon,asb_content_pad,asb_expander' . $template_list;
}

/**
 * add a check box to the User CP under Other Options to toggle the side boxes
 *
 * @return void
 */
function asb_usercp_options_end()
{
	global $db, $mybb, $templates, $user, $lang;

	if (!$lang->asb) {
		$lang->load('asb');
	}

    // if the form is being submitted save the users choice.
	if ($mybb->request_method == 'post') {
		$db->update_query('users', array('show_sidebox' => (int) $mybb->input['showsidebox']), "uid='{$user['uid']}'");
    }

	// don't be silly and waste a query :p (thanks Destroy666)
	if ($mybb->user['show_sidebox'] > 0) {
		// checked
		$checked = 'checked="checked" ';
	}

	$usercp_option = <<<EOF
	<td valign="top" width="1">
		<input type="checkbox" class="checkbox" name="showsidebox" id="showsidebox" value="1" {$checked}/>
	</td>
	<td>
		<span class="smalltext"><label for="showsidebox">{$lang->asb_show_sidebox}</label></span>
	</td>
</tr>
<tr>
<td valign="top" width="1">
	<input type="checkbox" class="checkbox" name="showredirect"
EOF;

    // update the template cache
	$find = <<<EOF
<td valign="top" width="1"><input type="checkbox" class="checkbox" name="showredirect"
EOF;
    $templates->cache['usercp_options'] = str_replace($find, $usercp_option, $templates->cache['usercp_options']);
}

/**
 * handle the AJAX refresh for side box modules (replacing asb/xmlhttp.php)
 *
 * @return void
 */
function asb_xmlhttp()
{
	global $mybb;

	if ($mybb->input['action'] != 'asb') {
		return;
	}

	// get the ASB core stuff
	require_once MYBB_ROOT . 'inc/plugins/asb/functions_addon.php';

	// attempt to load the module and side box requested
	$module = new SideboxExternalModule($mybb->input['addon']);
	$sidebox = new SideboxObject($mybb->input['id']);

	// we need both objects to continue
	if ($module->isValid() &&
		$sidebox->isValid()) {
		// then call the module's AJAX method and echo its return value
		echo($module->doXmlhttp($mybb->input['dateline'], $sidebox->get('settings'), $mybb->input['width'], $mybb->input['script']));
	}
	exit;
}

?>
