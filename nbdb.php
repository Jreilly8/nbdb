<?php

/**
* A class that contains the functions to build the http request to he NETBilling API, retrieve member reporting data,
* place it in an array, and sync member data changed since last sync.
*
* We have been told by NETbilling that requests to their api are limited to 50 every 24 hours, 
* so some of the timing settings may not make sense at such long intervals
*
*/

class Nbmemberdb {
	/*Database configuration*/
	var $db_host = "localhost";
	var $db_schema = "Add_Yours";
	var $db_user = "Add_Yours";
	var $db_password = "Add_Yours";                                         
	
	/*NETbilling configuration*/
	var $nb_account_id="Add_Yours";
	var $nb_authorization="Add_Yours";
	var $nb_url="https://secure.netbilling.com/gw/reports/member1.5";
	var $nb_timezone = "America/Los_Angeles"; //see __construct()

	/*log file location*/
	var $log_file = "full/path/to/Yours";
	
	/*Flat data file */
	var $email_notify_wait_file = "full/path/to/.nbdbnotifywait";
	
	/*Misc configuration*/
	var $script_enabled = 1;			//emergency on/off switch
	var $inc_days = 1;						//day range of each NETbilling call
	var $inc_sleep = 500;					//wait time between netbilling calls in milliseconds
	var $email_notify_on = TRUE;			//TRUE = email notification on
	var $email_notify_wait_time = 600;		//seconds to wait between emailing about errors
	var $email_notify_list = "your@email.com,their@email.com";
	
	/*Non-configurable properties*/	
	var $db;								//database handle
	var $nb_data;							//incoming data from netbilling
	var $date_first;						//first date of whole range
	var $date_last;							//last date of whole range
	var $inc_start;							//start date of current increment
	var $inc_end;							//end date of current increment
	var $fatal_error = FALSE;				//fatal error flag
	var $log_stack = array();				//log stack for emailing

	function __construct()
	{
		date_default_timezone_set($this->nb_timezone);
	}
	
	function log($msg)
	{
		$date = date('Y-m-d H:i:s');
		
		//add message to stack for emailing in case of an error
		$this->log_stack[] = $date." ".$msg;
		
		//log to file
		$fh = fopen($this->log_file, 'a');
		fwrite($fh,$date." ".$msg."\n");
		fclose($fh);
	}
	
	function stop_error()
	{		
		$this->log('A FATAL ERROR OCCURED');
		
		$nowtime = time();
		
		//we'll use a flat file as a time stamp to make sure we wait in between email notifications
		if (! $timelimit = file_get_contents($this->email_notify_wait_file) OR $nowtime > $timelimit)
		{
			if ($this->email_notify_on == TRUE)
			{
				//send out notification email
				$body = "<pre>".implode("\n",$this->log_stack)."</pre>";
				
				$extraheaders = 'From: it@...' . "\r\n" .
								'Reply-To: it@...' . "\r\n" .
								'Content-Type: text/html; charset=ISO-8859-1' . "\r\n" .
								'X-Mailer: PHP/' . phpversion();
				
				mail($this->email_notify_list, "NETbilling Member Database Synchronization Error", $body, $extraheaders);
							
				//record next email time to flat file
				file_put_contents($this->email_notify_wait_file, $nowtime + $this->email_notify_wait_time);
				
				$this->log("Sending out notification email");
			}
		}					
	}
			
	// Main function
	function sync_db()
	{
		if ($this->script_enabled)
		{
			$this->db_connect();
			$this->inc_run();
		}
	}
	
	//create database connection
	function db_connect()
	{
		$this->db = new mysqli($this->db_host,$this->db_user,$this->db_password, $this->db_schema);
		if($this->db->connect_errno)
		{
			$this->log('Failed to connect to MySQL: '. $this->db->connect_error);
			$this->fatal_error = TRUE;
		}
	}
	
	//incrementally update the database
	//the incremental behavior should only trigger when first populating the database
	function inc_run()
	{
		//initialize step values
		if ( ! $this->fatal_error)
		{
			//grab get_last_update_date and put it into date_first
			$this->date_first = $this->get_last_update_date();
			
			//grab current timestamp and put into date_last
			$this->date_last = $this->timestamp(time());
			
			//initialize start and end increment values
			$this->inc_start = $this->date_first;
			$this->inc_end = $this->date_first;
		}
		
		//while the inc_end is less than date_last
		while( strtotime($this->inc_end) < strtotime($this->date_last) && ! $this->fatal_error )
		{
			//sleep between netbilling api calls
			usleep($this->inc_sleep * 1000);
			
			$this->inc_start = $this->get_last_update_date();
			
			//inc_end equals inc_start plus inc_days
			$this->inc_end = $this->timestamp( strtotime($this->inc_start) + ($this->inc_days * 60 * 60 * 24) );
			
			//if inc_end is later than date_last, then set inc_end equal to date_last
			if( strtotime($this->inc_end) >= strtotime($this->date_last) )
			{
				$this->inc_end = $this->date_last;
				//$this->log('Final Increment Reached');
			}
			
			$this->build_http_request();
			
			//grab the data from netbilling for this increment
			if (! $this->fatal_error)
				$this->send_request();
			
			//if succesful, update the database for this increment
			if (! $this->fatal_error)
				$this->update_data();
			
			//if and only if successful, update the last_update_date in the database
			if(! $this->fatal_error)
				$this->set_last_update_date($this->inc_end);
		}
		
		//if there is an error, run stop_error
		if($this->fatal_error) $this->stop_error();
	}
    
	// Build the http request to API
	function build_http_request()
	{
				
		$request= array();
		
		$request['account_id']= $this->nb_account_id;		
		$request['authorization']= $this->nb_authorization;
		
		$request['changed_after']= $this->inc_start;	
		$request['changed_before']= $this->inc_end;
		
		$this->http_request=http_build_query($request);
		
		//$this->log($this->http_request);
	}
	
	// Makes the http request to API
	function send_request()
	{
		//$this->log('Send Request Started');
		$ch = curl_init();

		//Create headers to satisfy request requirements
		$req_headers=array();
		$req_headers[] = 'Content-Type: application/x-www-form-urlencoded';
		$req_headers[] = 'Content-Length: '.strlen($this->http_request);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $req_headers);
		curl_setopt($ch, CURLOPT_USERAGENT, "TIMClient/Version:0.01");
		
		curl_setopt($ch, CURLOPT_URL, $this->nb_url);				//set URL
		curl_setopt($ch, CURLOPT_POST, 1);          			//specify to send as POST	
		curl_setopt($ch, CURLOPT_POSTFIELDS, $this->http_request);   	//adding POST data
		
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);        	//https cheat
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST,  2);       	//https cheat
		
		curl_setopt($ch, CURLOPT_HEADER, TRUE);                	//tells curl to include headers in response
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);        	//return response into a variable
		curl_setopt($ch, CURLOPT_TIMEOUT, 90);              	//times out after 90 secs
		curl_setopt($ch, CURLOPT_FORBID_REUSE, TRUE);       	//forces closure of connection when done
		
		//execute curl call
		$result = curl_exec($ch);
		
		//Parse out $header, $body
		list($header, $body) = explode("\r\n\r\n", $result, 2);
		
		//Parse out $http_code
		$v = curl_getinfo($ch);
		$http_code = $v['http_code'];
		
		//If http code is 200, then its all good
		if ($http_code=='200')
		{
			//$this->log('response ok');
			$this->nb_data=$body;
		}
		else
		{
			//bad http code response
			$this->log("Bad: $http_code");
			$this->log($header);
			$this->fatal_error = TRUE;
		}
			
	}
				
	// Update the Mysql database
	function update_data()
	{
		$data = $this->nb_data;
		
		//Parse rows into an array
		$data = explode("\n", $data);
		
		$v = array();
		//Break each row into colums
		foreach ($data as $row)
		{
			//only do if row isn't empty
			if ($row != '')
			{
				$row = explode('","', $row);
				
				//Trim first and last extra quotes
				$row[0] = trim($row[0],'"');    			
				$row[count($row)-1] = trim($row[count($row)-1],'"');
				
				//sanitize data
				$c = array();
				foreach ($row as $column)
				{
					$c[] = $this->db->real_escape_string($column);
				}
				$v[]=$c;
			}
		}
		$data=$v;
			
		// Lowercase column names
		$column_names = array_shift($data);
		$v = array();
		foreach ($column_names as $column_name)
		{
			$v[] = strtolower($column_name);
		}
		$column_names = $v;
		
		//Turn rows into associative arrays
		$v = array();
		foreach ($data as $row)
		{
			 $v[] = array_combine($column_names, $row);
		}
		$data = $v;

		//Build the query but only if netbilling returned data
		if(count($data) > 0)
		{
			//Build MySQL Query
			$query = "INSERT INTO `{$this->db_schema}`.`netbilling_members` (
				member_id,
				site_tag,
				member_status,
				email_address,
				previous_member_status,
				status_change_date,
				member_user_name,
				signup_date,
				expire_date,
				recurring_status,
				recurring_next_date,
				recurring_period,
				recurring_periods_left,
				recurring_amount,
				next_recurring_amount
				) VALUES ";
			$v = array();
	
			foreach ($data as $row)
			{
				$v[] = "(
				'{$row['member_id']}',
				'{$row['site_tag']}',
				'{$row['member_status']}',
				'{$row['email_address']}',
				'{$row['previous_member_status']}',
				'{$row['status_change_date']}',
				'{$row['member_user_name']}',
				'{$row['signup_date']}',
				'{$row['expire_date']}',
				'{$row['recurring_status']}',
				'{$row['recurring_next_date']}',
				'{$row['recurring_period']}',
				'{$row['recurring_periods_left']}',
				'{$row['recurring_amount']}',
				'{$row['next_recurring_amount']}'
				)";
			}
			$query .= implode(',', $v);
		
			$query .= " ON DUPLICATE KEY UPDATE site_tag=VALUES(site_tag),
				member_status=VALUES(member_status),
				email_address=VALUES(email_address),
				previous_member_status=VALUES(previous_member_status),
				status_change_date=VALUES(status_change_date),
				member_user_name=VALUES(member_user_name),
				signup_date=VALUES(signup_date),
				expire_date=VALUES(expire_date),
				recurring_status=VALUES(recurring_status),
				recurring_next_date=VALUES(recurring_next_date),
				recurring_period=VALUES(recurring_period),
				recurring_periods_left=VALUES(recurring_periods_left),
				recurring_amount=VALUES(recurring_amount),
				next_recurring_amount=VALUES(next_recurring_amount)";
			
			if($db_result = $this->db->query($query))
			{
				$this->log('query success, affected rows: '.$this->db->affected_rows);
			}
			else
			{
				$this->log('query failed, error: ' .$this->db->error);
				$this->fatal_error = TRUE;
			}
		}
		else
		{
			//$this->log("No data to enter into database");
		}
					
	}
		
	//Return the last update date from database
	function get_last_update_date()	
	{
		//Select all rows from the database where the column "param" is  "last_update"
		//We know that this should return only one row
		$query = "SELECT * FROM `{$this->db_schema}`.`netbilling_data` WHERE param='last_update'";
			
		//run the query, put the result object into $result
		$result = $this->db->query($query);
		
		if ( ! $result)
		{
			$this->log('query failed, error: ' .$this->db->error);
			$this->fatal_error = TRUE;
			return FALSE;
		}
				
		//grab an associative array of the first row and put it into row
		$row = $result->fetch_assoc();
			
		//return the value of column named "value"
		return $row['value'];
	}
	
	//Set the last update date in the database
	function set_last_update_date($date_string)
	{
		$date_string = $this->db->real_escape_string($date_string);
		$query = "UPDATE `{$this->db_schema}`.`netbilling_data` SET value='$date_string' WHERE param='last_update'";
		$result = $this->db->query($query);
		
		if ( ! $result)
		{
			$this->log('query failed, error: ' .$this->db->error);
			$this->fatal_error = TRUE;
		}
	}
	
	//given unix time, returns the properly formatted timestamp
	function timestamp($time)
	{
		return date('Y-m-d H:i:s',$time);
	}	
}



	
?>
