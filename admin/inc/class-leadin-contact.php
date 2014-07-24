<?php
//=============================================
// LI_Contact Class
//=============================================
class LI_Contact {
	
	/**
	 * Variables
	 */
	var $hashkey;
	var $history;

	/**
	 * Class constructor
	 */
	function __construct () 
	{

	}

	/**
	 * Gets hashkey from lead id
	 *
	 * @param	int
	 * @return	string
	 */
	function set_hashkey_by_id ( $lead_id ) 
	{
		global $wpdb;

		$q = $wpdb->prepare("SELECT hashkey FROM li_leads WHERE lead_id = %d " . $wpdb->multisite_query, $lead_id);
		$this->hashkey = $wpdb->get_var($q);
		
		return $this->hashkey;
	}

	/**
	 * Gets contact history from the database (pageviews, form submissions, and lead details)
	 *
	 * @param	string
	 * @return	object 	$history (pageviews_by_session, submission, lead)
	 */
	function get_contact_history () 
	{
		global $wpdb;

		// Get the contact details
		$q = $wpdb->prepare("
			SELECT 
				DATE_FORMAT(lead_date, %s) AS lead_date,
				lead_id,
				lead_ip, 
				lead_email, 
				lead_status 
			FROM 
				li_leads 
			WHERE hashkey LIKE %s " . $wpdb->multisite_query, '%b %D %l:%i%p', $this->hashkey);

		$lead = $wpdb->get_row($q);

		// Get all page views for the contact
		$q = $wpdb->prepare("
			SELECT 
				pageview_id,
				pageview_date AS event_date,
				DATE_FORMAT(pageview_date, %s) AS pageview_day, 
				DATE_FORMAT(pageview_date, %s) AS pageview_date, 
				lead_hashkey, pageview_title, pageview_url, pageview_source, pageview_session_start 
			FROM 
				li_pageviews 
			WHERE 
				pageview_deleted = 0 AND
				lead_hashkey LIKE %s " . $wpdb->multisite_query . " ORDER BY event_date DESC", '%b %D', '%b %D %l:%i%p', $this->hashkey);

		$pageviews = $wpdb->get_results($q, ARRAY_A);

		// Get all submissions for the contact
		$q = $wpdb->prepare("
			SELECT 
				form_date AS event_date, 
				DATE_FORMAT(form_date, %s) AS form_date, 
				form_page_title, 
				form_page_url, 
				form_fields, 
				form_type 
			FROM 
				li_submissions 
			WHERE 
				form_deleted = 0 AND 
				lead_hashkey = %s " . $wpdb->multisite_query . " ORDER BY event_date DESC", '%b %D %l:%i%p', $this->hashkey);
		
		$submissions = $wpdb->get_results($q, ARRAY_A);

		// Merge the page views array and submissions array and reorder by date
		$events_array = array_merge($pageviews, $submissions); 
		usort($events_array, array('LI_Contact','sort_by_event_date'));
		
		$sessions = array();
		$cur_array = '0';
		$first_iteration = TRUE;
		$count = 0;
		$cur_event = 0;
		$prev_form_event = FALSE;
		$total_visits = 0;
		$total_pageviews = 0;
		$total_submissions = 0;
		$new_session = TRUE;

		foreach ( $events_array as $event_name => $event )
		{
			// Create a new session array if pageview started a new session
			if ( $new_session )
			{
				$cur_array = $count;

				$sessions['session_' . $cur_array] = array();
				$sessions['session_' . $cur_array]['session_date'] = $event['event_date']; 
				$sessions['session_' . $cur_array]['events'] = array();

				$cur_event = $count;
				$sessions['session_' . $cur_array]['events']['event_' . $cur_event] = array();
				$sessions['session_' . $cur_array]['events']['event_' . $cur_event]['event_type'] = 'pageview';
				$sessions['session_' . $cur_array]['events']['event_' . $cur_event]['event_date'] = $event['event_date'];
				
				// Set the first submission if it's not set and then leave it alone
				if ( ! isset($lead->last_visit) )
					$lead->last_visit = $event['event_date'];

				// Used for $lead->total_visits
				$total_visits++;
				$new_session = FALSE;
			}

			// Pageview activity
			if ( !isset($event['form_fields']) )
			{
				$cur_event = $count;
				$sessions['session_' . $cur_array]['events']['event_' . $cur_event] = array();
				$sessions['session_' . $cur_array]['events']['event_' . $cur_event]['event_type'] = 'pageview';
				$sessions['session_' . $cur_array]['events']['event_' . $cur_event]['event_date'] = $event['event_date'];
				$sessions['session_' . $cur_array]['events']['event_' . $cur_event]['activities'][] = $event;
				$total_pageviews++;

				// Always overwrite first_visit which will end as last pageview date
				$lead->first_visit = $event['event_date'];
				$lead->lead_source = $event['pageview_source'];
			}
			else
			{
				$cur_event = $count;
				$sessions['session_' . $cur_array]['events']['event_' . $cur_event] = array();
				$sessions['session_' . $cur_array]['events']['event_' . $cur_event]['event_type'] = 'form';
				$sessions['session_' . $cur_array]['events']['event_' . $cur_event]['event_date'] = $event['event_date'];
				$sessions['session_' . $cur_array]['events']['event_' . $cur_event]['activities'][] = $event;

				// Set the first submission if it's not set and then leave it alone
				if ( ! isset($lead->last_submission) )
					$lead->last_submission = $event['event_date'];

				// Always overwrite the last_submission date which will end as last submission date
				$lead->first_submission = $event['event_date'];
				$lead->last_submission_type = $event['form_type'];

				// Used for $lead->total_submissions
				$total_submissions++;
			}

			if ( (isset($event['pageview_session_start'])) && $event['pageview_session_start'] )
			{
				$new_session = TRUE;
			}

			$count++;
		}

		$lead->lead_status 			= $this->frontend_lead_status($lead->lead_status);
		$lead->total_visits 		= $total_visits;
		$lead->total_pageviews 		= $total_pageviews;
		$lead->total_submissions 	= $total_submissions;

		$this->history = (object)NULL;
		$this->history->submission = $submissions[0];
		$this->history->sessions = $sessions;
		$this->history->lead = $lead;

		return stripslashes_deep($this->history);
	}

	/**
	 * usort helper function to sort array by event date
	 *
	 * @param	string
	 * @return	array
	 */
	function sort_by_event_date ( $a, $b ) 
	{
		return $a['event_date'] < $b['event_date'];
	}

	/**
	 * Normalizes li_leads.lead_status for front end display
	 *
	 * @param	string
	 * @return	string
	 */
	function frontend_lead_status ( $lead_status = 'contact' ) 
	{
		if ( $lead_status == 'comment' )
			return 'Commenter';
		else if ( $lead_status == 'subscribe' )
			return 'Subscriber';
		else if ( $lead_status == 'lead' )
			return 'Lead';
		else if ( $lead_status == 'contacted' )
			return 'Contacted';
		else if ( $lead_status == 'customer' )
			return 'Customer';
		else
			return 'Contact';
	}
}
?>