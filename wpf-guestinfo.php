<?php
/*
Plugin Name: Mingle Forum Guest Info
Plugin URI: http://wpweaver.info/plugins/
Description: This is an add on to the Mingle Forum. It allows you allow geust posts without registration while requiring a name and e-mail to make posts easier to track.
Version: 1.0.1
Author: Bruce Wampler
Author URI: http://wpweaver.info
Text Domain: mingleforumguest
Copyright: 2009-2011, Bruce Wampler

GNU General Public License, Free Software Foundation <http://creativecommons.org/licenses/GPL/2.0/>
This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA

Version 1.0
*/

require_once(ABSPATH . WPINC . '/registration.php');

$wpw_guestID = 0;

// add the filters that will be called by Mingle Forum

add_filter('wpwf_guest_welcome_msg','wpf_guest_welcome_msg');
add_filter('wpwf_form_guestinfo','wpf_form_guestinfo');
add_filter('wpwf_quick_form_guestinfo','wpf_quick_form_guestinfo');
add_filter('wpwf_check_guestinfo','wpf_check_guestinfo');
add_filter('wpwf_change_userid','wpf_change_userid');
add_filter('wpwf_add_guest_sub', 'wpf_add_guest_sub');
add_filter('wpwf_new_posts','wpf_new_posts');

function wpf_guest_welcome_msg($value) {
    return "Welcome Guest. This forum allows guest posts without registration. However, please
        provide a name and a valid e-mail address (which will not be published). If you use the same
        e-mail address each time, you can subscribe to be notified when your post receives a reply.";
}

function wpf_change_userid($curID) {
    // override user ID if a guest has one now
    global $wpw_guestID;
    if ($wpw_guestID > 0) return $wpw_guestID;
    return $curID;
}

function wpf_new_posts($msg) {
    // to zap new posts line for guests
    //return $msg;
    return "";	// just hide for now
}


function wpf_check_guestinfo($unused) {
    /*
      * We will be sure they filled in a user name and e-mail address, with optional web address.
      * We will then see if the e-mail is already in the database, and use the info from there.
      * If the e-mail is a new one, we will create a fake new user account, and fill it in with
      * the information provided. This will provide an e-mail address to help track posters to the
      * forum.
     */
    global $wpw_guestID, $user_ID, $mingleforum;
    $gname = '';
    $gemail = '';
    $gurl = '';
    $err_msg = ''; $msg = '';
    $err = false;
    $wpw_guestID = 0;

    if (isset($_POST['add_guest_name'])) {
        $gname = sanitize_text_field($_POST['add_guest_name']);
        sanitize_user($gname);
	if (strlen($gname) < 3) {
            $err_msg = '** You must provide a name or handle 3 or more letters long in order to post. Please use your browser back button.<br />';
            $err = true;
        }
    } else {
        $err_msg = '** You must provide a name or handle in order to post. Please use your browser back button.<br />';
        $err = true;
    }

    if (isset($_POST['add_guest_email'])) {
        $gemail = sanitize_text_field($_POST['add_guest_email']);
        if (!is_email($gemail)) {
            $err_msg .= '** You must provide a valid E-mail address in order to post. Please use your browser back button.<br />';
            $err = true;
        }
    } else {
        $err_msg .= '** You must provide a valid Email address in order to post. Please use your browser back button.<br />';
        $err = true;
    }

    $smsg = wpf_validate_guest($gname,$gemail);		// check spammer lists
    if ($smsg != "") {
	$err_msg = $smsg;
	$err = true;
    }

    if (isset($_POST['add_guest_web'])) {		/* this copied from WP create user code */
	if ( empty ( $_POST['add_guest_web'] ) || $_POST['add_guest_web'] == 'http://' ) {
		    $gurl = '';
		} else {
		    $gurl = esc_url_raw( $_POST['add_guest_web'] );
		    $gurl = preg_match('/^(https?|ftps?|mailto|news|irc|gopher|nntp|feed|telnet):/is', $gurl) ? $gurl : 'http://'.$gurl;
		}
    }

    if ($err) {
        $msg = "<h2>".__("An error occured", "mingleforum")."</h2>";
        $msg .= ("<div id='error'><p>".$err_msg."</p></div>");
	return $msg;            // fail, let caller handle error
    }

    // so no errors, so do a simple check of the other form fields before creating the guest user
    if (isset($_POST['add_topic_submit'])) {
	$subject = $mingleforum->input_filter($_POST['add_topic_subject']);
	$content = $mingleforum->input_filter($_POST['message']);
	if ($subject == "") return "";
	if ($content == "") return "";
    } elseif (isset($_POST['add_post_submit'])){
	$subject = $mingleforum->input_filter($_POST['add_post_subject']);
	$content = $mingleforum->input_filter($_POST['message']);
	if ($subject == "") return "";
	if ($content == "") return "";
    } else { return "";} // shouldn't get here!

    $options = get_option("mingleforum_options");
    if ($options['forum_captcha'] == true && !$user_ID) {	// have to duplicate the captcha check
	include_once(WPFPATH."captcha/shared.php");
	$wpf_code = wpf_str_decrypt($_POST['wpf_security_check']);
   	if(($wpf_code == $_POST['wpf_security_code']) && (!empty($wpf_code))) {
	    //It passed
  	} else {
	    $emsg = __("Security code does not match!", "mingleforum");
	    $msg = "<h2>".__("An error occured", "mingleforum")."</h2>";
            $msg .= "<div id='error'><p>".$emsg."</p></div>";
	    wp_die($msg);
	    //return $msg;            // fail, let caller handle error
	}
    }

    $existingID = email_exists($gemail);	// see if they've been here before! Email is the controlling entry
    if ($existingID) {
	$wpw_guestID = $existingID;
	$user_info = get_userdata($wpw_guestID);
	if ($gname != $user_info->display_name)		// Update display name
	    wp_update_user( array('ID'=>$wpw_guestID, 'display_name' => $gname, 'nickname' => $gname));
        if (!empty($gurl) && $gurl != $user_info->user_url) 	// let them change their website
	    wp_update_user( array('ID'=>$wpw_guestID, 'user_url' => $gurl));
    } else {
	// Create new fake account
	$added = false;
	while (!$added) {
	    $pw = wp_generate_password();	// generate a password
	    $ip = $_SERVER['REMOTE_ADDR'];
	    if ($ip == '') $ip = '0.0.0.0';
	    $userlogin = sanitize_user('G_'.wp_generate_password(8,false).'_'.$ip);	// and a login name
	    $wpw_guestID = wp_insert_user(array('user_pass'=>$pw, 'user_login'=>$userlogin, 'user_url' => $gurl,
			'first_name' => $gname, 'last_name' => 'Guest', 'display_name' => $gname, 'nickname' => $gname,
			'user_email' => $gemail));
	    if (!$wpw_guestID) continue;
	    $added = true;
	}
    }

    return "";
}

function wpf_form_guestinfo($unused) {
    global $user_ID;

    $name = '';
    $url = '';
    $email = '';

    $user_id = $user_ID;

    if ($user_id != 0) {
        $user_info = get_userdata($user_id);
        $name = $user_info->display_name;
        $email = $user_info->user_email;
        $url = esc_url($user_info->user_url);
    }
    $out = "";

    /* note - this is not properly styled for all mingle forum skins. Need to replace the styles with a class and entry in skin */

    $out .= "<tr style='border-right:1px solid #adadad;border-left: 1px solid #adadad;margin-bottom:-10px;'><td style='border:0px;padding-bottom:0px;'>". __("Name:", "mingleforum") .
	       " (required)</td><td style='border:0px;border-left: 1px solid #adadad;padding-bottom:0px;'><input size='40%' type='text' name='add_guest_name' value='$name'/></td></tr>
	    <tr style='border-right:1px solid #adadad;border-left: 1px solid #adadad;'><td style='border:0px;padding-bottom:0px;'>" . __("Email:", "mingleforum") .
	       " (required, not shown)</td><td style='border:0px;border-left: 1px solid #adadad;padding-bottom:0px;'><input size='40%' type='text' name='add_guest_email' value='$email'/></td></tr>
	    <tr style='border-right:1px solid #adadad;border-left: 1px solid #adadad;'><td style='border:0px;padding-bottom:0px;'>" . __("Website:", "mingleforum") .
	       "</td><td style='border:0px;border-left: 1px solid #adadad;padding-bottom:0px;'> <input size='40%' type='text' name='add_guest_web' value='$url'/></td></tr>
            <tr style='border-right:1px solid #adadad;border-left: 1px solid #adadad;border-bottom: 2px solid #adadad;'><td style='border:0px;padding-bottom:0px;'>&nbsp;</td>
	       <td style='border:0px;border-left: 1px solid #adadad;padding-bottom:0px;'><input type='checkbox' name='wpf_auto_sub' id='wpf_auto_sub');>&nbsp;".
                  __("Add this topic to your email notifications?", "mingleforum") . "</td></tr>";
    return $out;

}
function wpf_quick_form_guestinfo($unused) {
    global $user_ID;

    $name = '';
    $url = '';
    $email = '';

    $user_id = $user_ID;

    if ($user_id != 0) {
        $user_info = get_userdata($user_id);
        $name = $user_info->display_name;
        $email = $user_info->user_email;
        $url = esc_url($user_info->user_url);
    }
    $out = "";

    /* note - this is not properly styled for all mingle forum skins. Need to replace the styles with a class and entry in skin */

    $out .= "<tr><td ><input size='40%' type='text' name='add_guest_name' value='$name'/> ". __("Name:", "mingleforum") .
	       " (required) <br /> <input size='40%' type='text' name='add_guest_email' value='$email'/> "
	    . __("Email:", "mingleforum") . " (required, not published) <br />"
            . "<input size='40%' type='text' name='add_guest_web' value='$url'/> "
	    . __("Website:", "mingleforum") . " (optional)<br />" .
          "&nbsp; <input type='checkbox' name='wpf_auto_sub' id='wpf_auto_sub');>&nbsp;".
                  __("Add this topic to your email notifications?", "mingleforum") . "</td></tr>";
    return $out;

}

function wpf_add_guest_sub($thread) {
    global $mingleforum;
    global $wpw_guestID;

    if (!isset($_POST['wpf_auto_sub'])) return;    // not asking to subscribe
    if ($wpw_guestID < 1) return $thread;

    $id = $thread;
    $op = get_usermeta($wpw_guestID, "wpf_useroptions");
    $topics = $op['notify_topics'];
    if (!is_array($topics))
        $topics = array();
    // Add topic
    if ($mingleforum->array_search($id, $topics, TRUE)) {
        return;     // this topic is already being followed
    }
    $topics[] = $id;

    // Build array
    $op = array("allow_profile" => $op['allow_profile'],
		    "notify_topics" => (array)$topics);
    // Update meta
    update_usermeta($wpw_guestID, "wpf_useroptions", $op);
}

function wpf_validate_guest($name,$email) {
    // run some validation checks on guest name - return "" if valid, error message otherwise

    wpf_spam_name($name);		// first, hard coded spam check

    if (function_exists('banhammer')) {		// ban hammer installed, so use it!
	$errors = new WP_Error();	// need to call ban hammer
	banhammer($name, $email, $errors);
	if ( $errors->get_error_code() ) {	// was it bad?
	    return 'Your name or email has been rejected because it was found on a spam list.';
	}
    }
    // add more checks here
    return "";
}

function wpf_spam_name($name) {
    // to kill spammer
    if (strcasecmp($name, 'Vietp') == 0) {
	header ("Location: http://google.com");
	exit();
    }

}
?>
