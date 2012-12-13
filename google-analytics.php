<?php 

class GoogleAnalytics {

	protected $accountTableName;
	protected $currentAccount;

	/**
	 * Set up the menus, check if the database table exists, configure the variables and
	 * check for any new submissions.
	 */
	public function __construct() {
		global $wpdb;
		
		add_action( 'admin_menu', array( &$this, 'addSettingsSection' ) );
		add_action( 'admin_init', array( &$this, 'registerSettings' ) );

		$this->accountTableName = $wpdb->base_prefix . 'google_analytics';
		
		if ( ! $this->tableExists( $this->accountTableName ) )
			try {
				$this->buildTable( $this->accountTableName );
			} catch ( Exception $e ) {
				echo $e->getMessage();
			}

		$this->currentAccount = get_option( 'current_account_id' );

		$this->checkForNewAccount();
	}

	/**
	 * Register the settings so they can submitted in the backend
	 * @return void
	 */
	public function registerSettings() {
		register_setting( 'google-analytics-settings-group', 'current_account_id', 'intval' ); 
		register_setting( 'google-analytics-settings-group', 'add_portfolio', array( $this, 'validatePortfolio' ) ); 
		register_setting( 'google-analytics-settings-group', 'add_email', array( $this, 'validateEmail' ) ); 
		register_setting( 'google-analytics-settings-group', 'add_gaid', array( $this, 'ValidateGaId' ) ); 
	}

	/**
	 * Add a new section to the "General Settings" page
	 */
	public function addSettingsSection() {
		add_settings_section( 'google-analytics', 'Google Analytics', array( &$this, 'settingsSectionContent' ), 'general' );
	}

	/**
	 * Make sure the portfolio text isn't empty when adding a new account
	 * @param  string $data The data to validate
	 * @return string|bool  If valid, return the input data. If invalid, return false
	 */
	public function validatePortfolio( $data ) {
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
	public function ValidateEmail( $data ) {
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
	public function ValidateGaId( $data ) {
		global $wpdb;

		if ( ! strlen( $data ) ) {
			return false;
		} elseif ( ! preg_match( '/UA-[0-9]{5,}-[0-9]{1,}/', $data ) ) {
			add_settings_error( 'add_gaid', 1, "Google Analytics: The analytics ID you entered was invalid, valid IDs should be in the format 'UA-XXXXXXXXXX'", 'error' );
			return false;
		} elseif ( $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $this->accountTableName WHERE ga_id = %s", $data ) ) ) {
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
	public function settingsSectionContent() {
		try {
			$accounts = $this->getAccounts();
		} catch ( Exception $e ) {
			if ( stristr( $e->getMessage(), 'Unknown error' ) ) {
				echo new WP_Error( 'google_analytics', 'An unknown error occurred with the Google Analytics script' );
			} else {
				echo '<div style="padding: 0 0 10px 0">' . $e->getMessage() . '</div>';
			}
		}
		settings_fields( 'google-analytics-settings-group' );
		echo "<table style='width:80%' cellspacing='0'><tr style='height:30px; background-color:#efefef; font-weight:bold;'><td></td><td>ID</td><td>Portfolio</td><td>Email</td><td>Analytics ID</td><td width='25'></td></tr>";
		if ( ! $this->currentAccount && $accounts ) {
			echo '<tr style="height:30px;"><td><input type="radio" name="current_account_id" checked="1" value="' . $accounts[0]["id"] . '" /><td> ' . $accounts[0]["id"] . '</td><td>' . $accounts[0]["portfolio"] . "</td><td>" . $accounts[0]["email"] . "</td><td>" . $accounts[0]["ga_id"] . "</td><td></td></tr>";
			$skip = 1;
		}
		if ( $accounts ) {
			foreach ( $accounts as $account ) {
				if ( $this->currentAccount == $account["id"] )
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
	private function checkForNewAccount() {
		$addPortfolio = get_option( 'add_portfolio' );
		$addEmail = get_option( 'add_email' );
		$addGaId = get_option( 'add_gaid' );
		
		if ( strlen( $addPortfolio ) == 0 || strlen( $addEmail ) == 0 || strlen( $addGaId ) == 0 )
			return false;

		$this->addAccount( $addPortfolio, $addEmail, $addGaId );
		
		update_option( 'add_portfolio', '' );
		update_option( 'add_email', '' );
		update_option( 'add_gaid', '' );

		return true;
	}

	/**
	 * Check if a table exists
	 * @param  string $tableName The table name to check for
	 * @return bool              If the table exists
	 */
	private function tableExists( $tableName ) {
		global $wpdb;
		$query = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $this->accountTableName ) );

		if( ! $query == $tableName )
			return false;

		return true;
	}

	/**
	 * Create a table in the database
	 * @param  string $tableName The name of the table to create
	 * @return bool              If the table was created
	 * @throws Exception         If there was an issue creating the table
	 */
	private function buildTable( $tableName ) {
		global $wpdb;
		
		$query = $wpdb->query( "CREATE TABLE $this->accountTableName (`id` INT NOT NULL AUTO_INCREMENT , `portfolio` VARCHAR(45) NULL , `email` VARCHAR(45) NULL , `ga_id` VARCHAR(45) NULL , PRIMARY KEY (`id`) )" );
		require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
		dbDelta($query);

		if ( ! $this->tableExists( $tableName ) )
			throw new Exception( "Couldn't create the account table" );
		
		return true;
	}

	/**
	 * Add a new Google Analytics account to the database
	 * @param  string $portfolio The name of the portfolio
	 * @param  string $email     The account email address
	 * @param  string $ga_id     The Google Analytics ID	 
	 */
	private function addAccount( $portfolio, $email, $ga_id ) {
		global $wpdb;

		$data = array(
			"portfolio" => $portfolio,
			"email" => $email,
			"ga_id" => $ga_id
			);

		if ( ! $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $this->accountTableName WHERE ga_id = %s", $ga_id ) ) );
			$insert = $wpdb->insert($this->accountTableName, $data);

		if ( is_int( $insert ) )
			return true;
	}

	/**
	 * Get all of the accounts from the database
	 * @return array     The accounts and their details
	 * @throws Exception If there was an issue retrieving accounts from the database
	 */
	public function getAccounts() {
		global $wpdb;
		$query = $wpdb->get_results( "SELECT * FROM $this->accountTableName LIMIT 0, 1000" );
		if ( empty( $query ) )
			throw new Exception( "No accounts, please add one using the input boxes below" );

		$i = 0;
		$accounts = array();
		foreach ( $query as $entry ) {
			$accounts[$i]["id"] = $entry->id;
			$accounts[$i]["portfolio"] = $entry->portfolio;
			$accounts[$i]["email"] = $entry->email;
			$accounts[$i]["ga_id"] = $entry->ga_id;
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
	private function getCurrentAccountID() {
		global $wpdb;
		$currentGaId = $wpdb->get_var( "SELECT ga_id FROM $this->accountTableName WHERE id = $this->currentAccount" );
		
		if ( ! $currentGaId )
			return 'Error';

		return $currentGaId;
	}

	/**
	 * Get the user's email if they are logged in
	 * @return string The user's email or 'Anonymous' if they aren't logged in
	 */
	private function getLoggedInUsername() {
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
	public function outputJavascript() {
		if ( $this->currentAccount === '' )
			echo '<!-- No GA Account Specified -->';

		echo 'CurrentID: ' . $this->getCurrentAccountID();

		echo "<script type='text/javascript'>

			    var _gaq = _gaq || [];
			    _gaq.push(['_setAccount', '" . $this->getCurrentAccountID() . "']);
			    _gaq.push(['_trackPageview']);

			    _gaq.push(['_setCustomVar', 1, 'NTUserName', '" . $this->getLoggedInUsername() . "', 1]);

			    (function() {
			        var ga = document.createElement('script'); ga.type = 'text/javascript'; ga.async = true;
			        ga.src = ('https:' == document.location.protocol ? 'https://ssl' : 'http://www') + '.google-analytics.com/ga.js';
			        var s = document.getElementsByTagName('script')[0]; s.parentNode.insertBefore(ga, s);
			    })();

			</script>";
	}

}

 ?>