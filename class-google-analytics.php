<?php 

class Google_Analytics {

	protected $account_table_name;
	protected $current_account;

	/**
	 * Set up the menus, check if the database table exists, configure the variables and
	 * check for any new submissions.
	 */
	public function __construct() {
		global $wpdb;
		
		add_action( 'admin_menu', array( &$this, 'add_settings_section' ) );
		add_action( 'admin_init', array( &$this, 'register_settings' ) );

		$this->account_table_name = $wpdb->base_prefix . 'google_analytics';
		
		if ( ! $this->table_exists( $this->account_table_name ) )
			try {
				$this->build_table( $this->account_table_name );
			} catch ( Exception $e ) {
				echo $e->getMessage();
			}

		$this->current_account = get_option( 'current_account_id' );

		$this->check_for_new_account();
	}

	/**
	 * Register the settings so they can submitted in the backend
	 * @return void
	 */
	public function register_settings() {
		register_setting( 'google-analytics-settings-group', 'current_account_id', 'intval' ); 
		register_setting( 'google-analytics-settings-group', 'add_portfolio', array( $this, 'validate_portfolio' ) ); 
		register_setting( 'google-analytics-settings-group', 'add_email', array( $this, 'validate_email' ) ); 
		register_setting( 'google-analytics-settings-group', 'add_gaid', array( $this, 'validate_ga_id' ) ); 
	}

	/**
	 * Add a new section to the "General Settings" page
	 */
	public function add_settings_section() {
		add_settings_section( 'google-analytics', 'Google Analytics', array( &$this, 'settings_section_content' ), 'general' );
	}

	/**
	 * Make sure the portfolio text isn't empty when adding a new account
	 * @param  string $data The data to validate
	 * @return string|bool  If valid, return the input data. If invalid, return false
	 */
	public function validate_portfolio( $data ) {
		if ( ! strlen( $data ) ) {
			return false;
		}

		return $data;
	}

	/**
	 * Make sure the email isn't blank and is in the correct format when adding a new account
	 * @param string $data The data to validate
	 * @return string|bool If valid, return the input data. If invalid, return false
	 */
	public function validate_email( $data ) {
		if ( ! strlen( $data ) ) {
			return false;
		} elseif ( ! preg_match( "/^[a-z0-9]+([_\\.-][a-z0-9]+)*@([a-z0-9]+([\.-][a-z0-9]+)*)+\\.[a-z]{2,}$/i", $data ) ) {
			add_settings_error( 'add_email', 1, "Google Analytics: The email address you entered was invalid, valid email addresses should be in the format 'someone@somewhere.com'", 'error' );
			return false;
		}

		return $data;
	}

	/**
	 * Make sure the Google Analytics ID isn't blank and is in the correct format when adding a new account
	 * @param string $data The data to validate
	 * @return string|bool  If valid, return the input data. If invalid, return false
	 */
	public function validate_ga_id( $data ) {
		global $wpdb;

		if ( ! strlen( $data ) ) {
			return false;
		} elseif ( false === strpos( trim( $data ), 'UA-' ) ) {
			add_settings_error( 'add_gaid', 1, "Google Analytics: The analytics ID you entered was invalid, valid IDs should be in the format 'UA-XXXXXXXXXX'", 'error' );
			return false;
		} elseif ( $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $this->account_table_name WHERE ga_id = %s", $data ) ) ) {
			add_settings_error( 'add_gaid', 2, "Google Analytics: ID already exists", 'error' );
			return false;
		}

		return $data;
	}

	/**
	 * Generate and output the section content
	 * @return string The section HTML
	 * @todo 		  Clean it up!
	 */
	public function settings_section_content() {
		try {
			$accounts = $this->get_accounts();
		} catch ( Exception $e ) {
			if ( stristr( $e->getMessage(), 'Unknown error' ) ) {
				echo new WP_Error( 'google_analytics', 'An unknown error occurred with the Google Analytics script' );
			} else {
				echo '<div style="padding: 0 0 10px 0">' . $e->getMessage() . '</div>';
			}
		}
		settings_fields( 'google-analytics-settings-group' );
		echo "<table style='width:80%' cellspacing='0'><tr style='height:30px; background-color:#efefef; font-weight:bold;'><td></td><td>ID</td><td>Portfolio</td><td>Email</td><td>Analytics ID</td><td width='25'></td></tr>";
		if ( ! $this->current_account && $accounts ) {
			echo '<tr style="height:30px;"><td><input type="radio" name="current_account_id" checked="1" value="' . $accounts[0]["id"] . '" /><td> ' . $accounts[0]["id"] . '</td><td>' . $accounts[0]["portfolio"] . "</td><td>" . $accounts[0]["email"] . "</td><td>" . $accounts[0]["ga_id"] . "</td><td></td></tr>";
			$skip = 1;
		}
		if ( $accounts ) {
			foreach ( $accounts as $account ) {
				if ( $this->current_account == $account["id"] )
					$checked = 'checked="checked"';
				if ( ! $skip && $accounts )
					echo '<tr style="height:30px;"><td><input type="radio" name="current_account_id" value="' . $account["id"] . '" ' . $checked . ' /><td> ' . $account["id"] . '</td><td>' . $account["portfolio"] . "</td><td>" . $account["email"] . "</td><td>" . $account["ga_id"] . "</td><td></td></tr>";
				$checked = '';
				$skip = 0;
			}
		}
		echo '<tr><td></td><td></td><td><input style="width:90%" name="add_portfolio" id="add_portfolio" value="" tabindex="100"></input></td><td><input style="width:90%" name="add_email" id="add_email" value="" tabindex="101"></input></td><td><input style="width:90%" name="add_gaid" id="add_gaid" value="" tabindex="102"></input></td><td><input style="padding: 4px;border-radius: 12px;width: 25px;height: 25px;background-color: #EFEFEF;cursor: pointer;" type="submit" value="+" /></td></tr>';
		echo "</table>";
	}

	/**
	 * Check if a new account has been submitted
	 * @return bool Whether a new account was submitted
	 */
	private function check_for_new_account() {
		$add_portfolio = get_option( 'add_portfolio' );
		$add_email = get_option( 'add_email' );
		$add_ga_id = get_option( 'add_gaid' );
		
		if ( strlen( $add_portfolio ) == 0 || strlen( $add_email ) == 0 || strlen( $add_ga_id ) == 0 )
			return false;

		$this->add_account( $add_portfolio, $add_email, $add_ga_id );
		
		update_option( 'add_portfolio', '' );
		update_option( 'add_email', '' );
		update_option( 'add_gaid', '' );

		return true;
	}

	/**
	 * Check if a table exists
	 * @param  string $table_name The table name to check for
	 * @return bool              If the table exists
	 */
	private function table_exists( $table_name ) {
		global $wpdb;
		$query = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $this->account_table_name ) );

		if( ! $query == $table_name )
			return false;

		return true;
	}

	/**
	 * Create a table in the database
	 * @param  string $table_name The name of the table to create
	 * @return bool              If the table was created
	 * @throws Exception         If there was an issue creating the table
	 */
	private function build_table( $table_name ) {
		global $wpdb;
		
		$query = $wpdb->query( "CREATE TABLE $this->account_table_name (`id` INT NOT NULL AUTO_INCREMENT , `portfolio` VARCHAR(45) NULL , `email` VARCHAR(45) NULL , `ga_id` VARCHAR(45) NULL , PRIMARY KEY (`id`) )" );
		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
		dbDelta( $query );

		if ( ! $this->table_exists( $table_name ) )
			throw new Exception( "Couldn't create the account table" );
		
		return true;
	}

	/**
	 * Add a new Google Analytics account to the database
	 * @param  string $portfolio The name of the portfolio
	 * @param  string $email     The account email address
	 * @param  string $ga_id     The Google Analytics ID	 
	 */
	private function add_account( $portfolio, $email, $ga_id ) {
		global $wpdb;

		$data = array(
			"portfolio" => $portfolio,
			"email" => $email,
			"ga_id" => $ga_id
			);

		if ( ! $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $this->account_table_name WHERE ga_id = %s", $ga_id ) ) );
			$insert = $wpdb->insert( $this->account_table_name, $data );

		if ( is_int( $insert ) )
			return true;
	}

	/**
	 * Get all of the accounts from the database
	 * @return array     The accounts and their details
	 * @throws Exception If there was an issue retrieving accounts from the database
	 */
	public function get_accounts() {
		global $wpdb;
		$query = $wpdb->get_results( "SELECT * FROM $this->account_table_name LIMIT 0, 1000" );
		if ( empty( $query ) )
			throw new Exception( "No accounts, please add one using the input boxes below" );

		$i = 0;
		$accounts = array();
		foreach ( $query as $entry ) {
			$accounts[ $i ]["id"] = $entry->id;
			$accounts[ $i ]["portfolio"] = $entry->portfolio;
			$accounts[ $i ]["email"] = $entry->email;
			$accounts[ $i ]["ga_id"] = $entry->ga_id;
			$i++;
		}

		if ( empty ( $accounts ) )
			throw new Exception( "Unknown error" );

		return $accounts;
	}

	/**
	 * Get the ID from the appropriate account record
	 * @return string The account GA ID
	 */
	private function get_current_account_id() {
		global $wpdb;
		$current_ga_id = $wpdb->get_var( "SELECT ga_id FROM $this->account_table_name WHERE id = $this->current_account" );
		
		if ( ! $current_ga_id )
			return 'Error';

		return $current_ga_id;
	}

	/**
	 * Get the user's email if they are logged in
	 * @return string The user's email or 'Anonymous' if they aren't logged in
	 */
	private function get_logged_in_username() {
	    global $current_user;
	    get_currentuserinfo();
	    
	    if ( $current_user->user_email )
	        return $current_user->user_email;
	 
    	return 'Anonymous';
	}

	/**
	 * Echo out the Google Analytics tracking script
	 * @return string The tracking script
	 */
	public function output_javascript() {
		if ( $this->current_account === '' )
			echo '<!-- No GA Account Specified -->';

		echo "<script type='text/javascript'>

			    var _gaq = _gaq || [];
			    _gaq.push(['_setAccount', '" . $this->get_current_account_id() . "']);
			    _gaq.push(['_trackPageview']);

			    _gaq.push(['_setCustomVar', 1, 'NTUserName', '" . $this->get_logged_in_username() . "', 1]);

			    (function() {
			        var ga = document.createElement('script'); ga.type = 'text/javascript'; ga.async = true;
			        ga.src = ('https:' == document.location.protocol ? 'https://ssl' : 'http://www') + '.google-analytics.com/ga.js';
			        var s = document.getElementsByTagName('script')[0]; s.parentNode.insertBefore(ga, s);
			    })();

			</script>";
	}

}

 ?>