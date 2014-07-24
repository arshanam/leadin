<?php

if ( !defined('LEADIN_PLUGIN_VERSION') ) 
{
    header('HTTP/1.0 403 Forbidden');
    die;
}

/**
 * Looks for a GET/POST value and echos if present. If nothing is set, echos blank
 *
 * @param   string
 * @return  null
 */
function print_submission_val ( $url_param ) 
{
    if ( isset($_GET[$url_param]) ) 
    {
        return $_GET[$url_param];
    }

    if ( isset($_POST[$url_param]) )
    {
        return $_POST[$url_param];
    }

    return '';
}

/**
 * Updates an option in the multi-dimensional option array
 *
 * @param   string   $option        option_name in wp_options
 * @param   string   $option_key    key for array
 * @param   string   $option        new value for array
 *
 * @return  bool            True if option value has changed, false if not or if update failed.
 */
function leadin_update_option ( $option, $option_key, $new_value ) 
{
    $options_array = get_option($option);

    if ( isset($options_array[$option_key]) )
    {
        if ( $options_array[$option_key] == $new_value )
            return false; // Don't update an option if it already is set to the value
    }

    if ( !is_array( $options_array ) ) {
        $options_array = array();
    }

    $options_array[$option_key] = $new_value;

    return update_option($option, $options_array);
}

/**
 * Prints a number with a singular or plural label depending on number
 *
 * @param   int
 * @param   string
 * @param   string
 * @return  string 
 */
function leadin_single_plural_label ( $number, $singular_label, $plural_label ) 
{
    //Set number = 0 when the variable is blank
    $number = ( !is_numeric($number) ? 0 : $number );

    return ( $number != 1 ? $number . " $plural_label" : $number . " $singular_label" );
}

/**
 * Get LeadIn user
 *
 * @return  array
 */
function leadin_get_current_user ()
{
    global $wp_version;
    global $current_user;

    get_currentuserinfo();
    $li_user_id = md5(get_bloginfo('wpurl'));

    $li_options = get_option('leadin_options');
    
    if ( isset($li_options['li_email']) ) {
        $li_user_email = $li_options['li_email'];
    } 
    else {
        $li_user_email = $current_user->user_email;
    }

    $leadin_user = array(
        'user_id' => $li_user_id,
        'email' => $li_user_email,
        'alias' => $current_user->display_name,
        'wp_url' => get_bloginfo('wpurl'),
        'li_version' => LEADIN_PLUGIN_VERSION,
        'wp_version' => $wp_version
    );

    return $leadin_user;
}

/**
 * Register LeadIn user
 *
 * @return  bool
 */
function leadin_register_user ()
{
    $leadin_user = leadin_get_current_user();
    $mp = new LI_Mixpanel(MIXPANEL_PROJECT_TOKEN);
    
    // @push mixpanel event for updated email
    $mp->identify($leadin_user['user_id']);
    $mp->createAlias( $leadin_user['user_id'],  $leadin_user['alias']);
    $mp->people->set( $leadin_user['user_id'], array(
        '$email'            => $leadin_user['email'],
        '$wp-url'           => $leadin_user['wp_url'],
        '$wp-version'       => $leadin_user['wp_version'],
        '$li-version'       => $leadin_user['li_version']
    ));

    // @push contact to HubSpot

    $hs_context = array(
        'pageName' => 'Plugin Settings'
    );

    $hs_context_json = json_encode($hs_context);
    
    //Need to populate these varilables with values from the form.
    $str_post = "email=" . urlencode($leadin_user['email'])
        . "&li_version=" . urlencode($leadin_user['li_version'])
        . "&leadin_stage=Activated"
        . "&li_user_id=" . urlencode($leadin_user['user_id'])
        . "&website=" . urlencode($leadin_user['wp_url'])
        . "&wp_version=" . urlencode($leadin_user['wp_version'])
        . "&hs_context=" . urlencode($hs_context_json);
    
    $endpoint = 'https://forms.hubspot.com/uploads/form/v2/324680/d93719d5-e892-4137-98b0-913efffae204';
    
    $ch = @curl_init();
    @curl_setopt($ch, CURLOPT_POST, true);
    @curl_setopt($ch, CURLOPT_POSTFIELDS, $str_post);
    @curl_setopt($ch, CURLOPT_URL, $endpoint);
    @curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/x-www-form-urlencoded'));
    @curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = @curl_exec($ch);  //Log the response from HubSpot as needed.
    @curl_close($ch);
    echo $response;

    return TRUE;
}

/**
 * Register LeadIn user
 *
 * @return  bool
 */
function leadin_set_beta_tester_property ( $beta_tester )
{
    $leadin_user = leadin_get_current_user();
    $mp = new LI_Mixpanel(MIXPANEL_PROJECT_TOKEN);
    $mp->people->set( $leadin_user['user_id'], array(
        '$beta_tester'  => $beta_tester
    ));
}

/**
 * Send Mixpanel event when plugin is activated/deactivated
 *
 * @param   bool
 *
 * @return  bool
 */
function leadin_track_plugin_registration_hook ( $activated )
{
    if ( $activated ) 
    {
        leadin_register_user();
        leadin_track_plugin_activity("Activated Plugin");
    }
    else
    {
        leadin_track_plugin_activity("Deactivated Plugin");
    }

    return TRUE;
}

/**
 * Track plugin activity in MixPanel
 *
 * @param   string
 *
 * @return  array
 */
function leadin_track_plugin_activity ( $activity_desc, $custom_properties = array() )
{   
    $leadin_user = leadin_get_current_user();

    global $wp_version;
    global $current_user;
    get_currentuserinfo();
    $user_id = md5(get_bloginfo('wpurl'));

    $default_properties = array(
        "distinct_id" => $user_id,
        '$wp-url' => get_bloginfo('wpurl'),
        '$wp-version' => $wp_version,
        '$li-version' => LEADIN_PLUGIN_VERSION
    );

    $properties = array_merge((array)$default_properties, (array)$custom_properties);

    $mp = new LI_Mixpanel(MIXPANEL_PROJECT_TOKEN);
    $mp->track($activity_desc, $properties);

    return true;
}

/**
 * Logs a debug statement to /wp-content/debug.log
 *
 * @param   string
 */
function leadin_log_debug ( $message )
{
    if ( WP_DEBUG === TRUE )
    {
        if ( is_array($message) || is_object($message) )
            error_log(print_r($message, TRUE));
        else 
            error_log($message);
    }
}

/**
 * Deletes an element or elements from an array
 *
 * @param   array
 * @param   wildcard
 * @return  array
 */
function leadin_array_delete ( $array, $element )
{
    if ( !is_array($element) )
        $element = array($element);

    return array_diff($array, $element);
}

/**
 * Deletes an element or elements from an array
 *
 * @param   array
 * @param   wildcard
 * @return  array
 */
function leadin_get_value_by_key ( $key_value, $array )
{
    foreach ( $array as $key => $value )
    {
        if ( is_array($value) && $value['label'] == $key_value )
            return $value['value'];
    }

    return null;
}

/** 
 * Data recovery algorithm for 0.7.2 upgrade
 *
 */
function leadin_recover_contact_data ()
{
    global $wpdb;

    $q = $wpdb->prepare("SELECT * FROM li_submissions AS s LEFT JOIN li_leads AS l ON s.lead_hashkey = l.hashkey WHERE l.hashkey IS NULL AND s.form_fields LIKE '%%%s%%' AND s.form_fields LIKE '%%%s%%' AND form_deleted = 0 " . $wpdb->multisite_query, '@', '.');
    $submissions = $wpdb->get_results($q);

    if ( count($submissions) )
    {
        foreach ( $submissions as $submission )
        {
            $json = json_decode(stripslashes($submission->form_fields), TRUE);

            if ( count($json) )
            {
                foreach ( $json as $object )
                {
                    if ( strstr($object['value'], '@') && strstr($object['value'], '@') && strlen($object['value']) <= 254 )
                    {
                        // check to see if the contact exists and if it does, skip the data recovery
                        $q = $wpdb->prepare("SELECT lead_email FROM li_leads WHERE lead_email = %s AND lead_deleted = 0 " . $wpdb->multisite_query, $object['value']);
                        $exists = $wpdb->get_var($q);

                        if ( $exists )
                            continue;

                        // get the original data
                        $q = $wpdb->prepare("SELECT pageview_date, pageview_source FROM li_pageviews WHERE lead_hashkey = %s AND pageview_deleted = 0 " . $wpdb->multisite_query . " ORDER BY pageview_date ASC LIMIT 1", $submission->lead_hashkey);
                        $first_pageview = $wpdb->get_row($q);

                        // recreate the contact
                        $q = $wpdb->prepare("INSERT INTO li_leads ( lead_date, hashkey, lead_source, lead_email, lead_status, blog_id ) VALUES ( %s, %s, %s, %s, %s, %d )",
                            ( $first_pageview->pageview_date ? $first_pageview->pageview_date : $submission->form_date), 
                            $submission->lead_hashkey,
                            ( $first_pageview->pageview_source ? $first_pageview->pageview_source : ''),
                            $object['value'], 
                            $submission->form_type,
                            $wpdb->blogid
                        );

                        $wpdb->query($q);
                    }
                }
            }
        }
    }

    leadin_update_option('leadin_options', 'data_recovered', 1);
}

/** 
 * Algorithm to set deleted contacts flag for 0.8.3 upgrade
 *
 */
function leadin_delete_flag_fix ()
{
    global $wpdb;

    $q = $wpdb->prepare("SELECT lead_email, COUNT(hashkey) c FROM li_leads WHERE lead_email != '' AND lead_deleted = 0 " . $wpdb->multisite_query . " GROUP BY lead_email HAVING c > 1", '');
    $duplicates = $wpdb->get_results($q);

    if ( count($duplicates) )
    {
        foreach ( $duplicates as $duplicate )
        {
            $existing_contact_status = 'lead';

            $q = $wpdb->prepare("SELECT lead_email, hashkey, merged_hashkeys, lead_status FROM li_leads WHERE lead_email = %s AND lead_deleted = 0 " . $wpdb->multisite_query . " ORDER BY lead_date DESC", $duplicate->lead_email);
            $existing_contacts = $wpdb->get_results($q);

            $newest = $existing_contacts[0];
 
            // Setup the string for the existing hashkeys
            $existing_contact_hashkeys = $newest->merged_hashkeys;
            if ( $newest->merged_hashkeys && count($existing_contacts) )
                $existing_contact_hashkeys .= ',';

            // Do some merging if the email exists already in the contact table
            if ( count($existing_contacts) )
            {
                for ( $i = 0; $i < count($existing_contacts); $i++ )
                {
                    // Start with the existing contact's hashkeys and create a string containg comma-deliminated hashes
                    $existing_contact_hashkeys .= "'" . $existing_contacts[$i]->hashkey . "'";

                    // Add any of those existing contact row's merged hashkeys
                    if ( $existing_contacts[$i]->merged_hashkeys )
                        $existing_contact_hashkeys .= "," . $existing_contacts[$i]->merged_hashkeys;

                    // Add a comma delimiter 
                    if ( $i != count($existing_contacts)-1 )
                        $existing_contact_hashkeys .= ",";

                    // Check on each existing lead if the lead_status is comment. If it is, save the status to override the new lead's status
                    if ( $existing_contacts[$i]->lead_status == 'comment' && $existing_contact_status == 'lead' )
                        $existing_contact_status = 'comment';

                    // Check on each existing lead if the lead_status is subscribe. If it is, save the status to override the new lead's status
                    if ( $existing_contacts[$i]->lead_status == 'subscribe' && ($existing_contact_status == 'lead' || $existing_contact_status == 'comment') )
                        $existing_contact_status = 'subscribe';
                }
            }

            // Remove duplicates from the array and original hashkey just in case
            $existing_contact_hashkeys = leadin_array_delete(array_unique(explode(',', $existing_contact_hashkeys)), "'" . $newest->hashkey . "'");

            // Safety precaution - trim any trailing commas
            $existing_contact_hashkey_string = rtrim(implode(',', $existing_contact_hashkeys), ',');

            if ( $existing_contact_hashkey_string )
            {
                // Set the merged hashkeys with the fixed merged hashkey values
                $q = $wpdb->prepare("UPDATE li_leads SET merged_hashkeys = %s, lead_status = %s WHERE hashkey = %s " . $wpdb->multisite_query, $existing_contact_hashkey_string, $existing_contact_status, $newest->hashkey);
                $wpdb->query($q);

                // "Delete" all the old contacts
                $q = $wpdb->prepare("UPDATE li_leads SET merged_hashkeys = '', lead_deleted = 1 WHERE hashkey IN ( $existing_contact_hashkey_string ) " . $wpdb->multisite_query, '');
                $wpdb->query($q);

                // Set all the pageviews and submissions to the new hashkey just in case
                $q = $wpdb->prepare("UPDATE li_pageviews SET lead_hashkey = %s WHERE lead_hashkey IN ( $existing_contact_hashkey_string ) " . $wpdb->multisite_query, $newest->hashkey);
                $wpdb->query($q);

                // Update all the previous submissions to the new hashkey just in case
                $q = $wpdb->prepare("UPDATE li_submissions SET lead_hashkey = %s WHERE lead_hashkey IN ( $existing_contact_hashkey_string ) " . $wpdb->multisite_query, $newest->hashkey);
                $wpdb->query($q);
            }
        }
    }

    leadin_update_option('leadin_options', 'delete_flags_fixed', 1);
}

/**
 * Sorts the powerups into a predefined order in leadin.php line 416
 *
 * @param   array
 * @param   array
 * @return  array
 */
function leadin_sort_power_ups ( $power_ups, $ordered_power_ups ) 
{ 
    $ordered = array();
    $i = 0;
    foreach ( $ordered_power_ups as $key )
    {
        if ( in_array($key, $power_ups) )
        {
            array_push($ordered, $key);
            $i++;
        }
    }

    return $ordered;
}

/**
 * Encodes special HTML quote characters into utf-8 safe entities
 *
 * @param   string
 * @return  string
 */
function leadin_encode_quotes ( $string ) 
{ 
    $string = str_replace(array("’", "‘", '&#039;', '“', '”'), array("'", "'", "'", '"', '"'), $string);
    return $string;
}

/**
 * Strip url get parameters off a url and return the base url
 *
 * @param   string
 * @return  string
 */
function leadin_strip_params_from_url ( $url ) 
{ 
    /*$url_parts = parse_url($url);
    $base_url .= ( isset($url_parts['host']) ? : rtrim($url_parts['host'] . '/' . ltrim($url_parts['path'], '/'), '/'));
    $base_url = urldecode($base_url);*/

    $url_parts = parse_url($url);
    $base_url = ( isset($url_parts['host']) ? 'http://' . rtrim($url_parts['host'], '/') : '' ); 
    $base_url .= ( isset($url_parts['path']) ? '/' . ltrim($url_parts['path'], '/') : '' ); 

        ltrim($url_parts['path'], '/');
    $base_url = urldecode(ltrim($base_url, '/'));


    return $base_url;
}

/**
 * Search an object by for a value and return the associated index key
 *
 * @param   object 
 * @param   string
 * @param   string
 * @return  key for array index if present, false otherwise
 */
function leadin_search_object_by_value ( $haystack, $needle, $search_key )
{
   foreach ( $haystack as $key => $value )
   {
      if ( $value->$search_key === $needle )
         return $key;
   }

   return FALSE;
}

/**
 * Check if date is a weekend day
 *
 * @param   string
 * @return  bool
 */
function leadin_is_weekend ( $date )
{
    return (date('N', strtotime($date)) >= 6);
}

/**
 * Get the lead_status types from the leads table
 *
 * @return  array
 */
function leadin_get_contact_types ( $date )
{
    global $wpdb;

    $q = $wpdb->prepare("SELECT `COLUMN_TYPE` FROM `information_schema`.`COLUMNS`
    WHERE `TABLE_SCHEMA` = %s
    AND `TABLE_NAME`   = 'li_leads'
    AND `COLUMN_NAME`  = 'lead_status' " . $wpdb->multisite_query, DB_NAME);

    $row = $wpdb->get_row($q);
    $set = $row->COLUMN_TYPE;
    $set  = substr($set,5,strlen($set)-7);

    return preg_split("/','/",$set);
}


?>