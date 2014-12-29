<?php
/**
 * Author: Alin Marcu
 * Author URI: https://deconf.com
 * License: GPLv2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 */
if (! class_exists('GADASH_GAPI')) {

    final class GADASH_GAPI
    {

        public $client, $service;

        public $country_codes;

        public $timeshift;

        private $error_timeout;
        
        private $managequota;

        function __construct()
        {
            global $GADASH_Config;
            if (! function_exists('curl_version')) {
                update_option('gadash_lasterror', date('Y-m-d H:i:s') . ': CURL disabled. Please enable CURL!');
                return;
            }
            // If at least PHP 5.3.2 use the autoloader, if not try to edit the include_path
            if (version_compare(PHP_VERSION, '5.3.2') >= 0) {
                require 'vendor/autoload.php';
            } else {
                set_include_path($GADASH_Config->plugin_path . '/tools/src/' . PATH_SEPARATOR . get_include_path());
                // Include GAPI client
                if (! class_exists('Google_Client')) {
                    require_once 'Google/Client.php';
                }
                // Include GAPI Analytics Service
                if (! class_exists('Google_Service_Analytics')) {
                    require_once 'Google/Service/Analytics.php';
                }
            }
            
            $this->client = new Google_Client();
            $this->client->setScopes('https://www.googleapis.com/auth/analytics.readonly');
            $this->client->setAccessType('offline');
            $this->client->setApplicationName('Google Analytics Dashboard');
            $this->client->setRedirectUri('urn:ietf:wg:oauth:2.0:oob');
            
            $this->set_error_timeout();
            $this->managequota = 'u'.get_current_user_id().'s'.get_current_blog_id();
            
            if ($GADASH_Config->options['ga_dash_userapi']) {
                $this->client->setClientId($GADASH_Config->options['ga_dash_clientid']);
                $this->client->setClientSecret($GADASH_Config->options['ga_dash_clientsecret']);
                $this->client->setDeveloperKey($GADASH_Config->options['ga_dash_apikey']);
            } else {
                $this->client->setClientId('65556128781.apps.googleusercontent.com');
                $this->client->setClientSecret('Kc7888wgbc_JbeCpbFjnYpwE');
                $this->client->setDeveloperKey('AIzaSyBG7LlUoHc29ZeC_dsShVaBEX15SfRl_WY');
            }
            
            $this->service = new Google_Service_Analytics($this->client);
            
            if ($GADASH_Config->options['ga_dash_token']) {
                $token = $GADASH_Config->options['ga_dash_token'];
                $token = $this->ga_dash_refresh_token();
                if ($token) {
                    $this->client->setAccessToken($token);
                }
            }
        }

        private function set_error_timeout()
        {
            $midnight = strtotime("tomorrow 00:00:00"); // UTC midnight
            $midnight = $midnight + 8 * 3600; // UTC 8 AM
            $this->error_timeout = $midnight - time();
            return;
        }

        /**
         * Handles errors returned by GAPI
         *
         * @return boolean
         */
        function gapi_errors_handler()
        {
            $errors = (array) get_transient('ga_dash_gapi_errors');
            
            if (isset($errors[0]['reason'])) {
                
                if ($errors[0]['reason'] == 'dailyLimitExceeded') {
                    return TRUE;
                }
                
                if ($errors[0]['reason'] == 'insufficientPermissions') {
                    $this->ga_dash_reset_token(false);
                    return TRUE;
                }
                
                if ($errors[0]['reason'] == 'invalidCredentials' || $errors[0]['reason'] == 'authError') {
                    $this->ga_dash_reset_token(false);
                    return TRUE;
                }
                
                if ($errors[0]['reason'] == 'invalidParameter' or $errors[0]['reason'] == 'badRequest') {
                    return TRUE;
                }
            }
            
            return FALSE;
        }

        /**
         * Calculates proper timeouts for each GAPI query
         *
         * @param
         *            $daily
         * @return number
         */
        function get_timeouts($daily)
        {
            $local_time = time() + $this->timeshift;
            if ($daily) {
                $nextday = explode('-', date('n-j-Y', strtotime(' +1 day', $local_time)));
                $midnight = mktime(0, 0, 0, $nextday[0], $nextday[1], $nextday[2]);
                return $midnight - $local_time;
            } else {
                $nexthour = explode('-', date('H-n-j-Y', strtotime(' +1 hour', $local_time)));
                $newhour = mktime($nexthour[0], 0, 0, $nexthour[1], $nexthour[2], $nexthour[3]);
                return $newhour - $local_time;
            }
        }

        function token_request()
        {
            $authUrl = $this->client->createAuthUrl();
            
            ?>
<form name="input"
	action="<?php echo esc_url($_SERVER['REQUEST_URI']); ?>" method="post">

	<table class="options">
		<tr>
			<td colspan="2" class="info">
						<?php echo __( "Use this link to get your access code:", 'ga-dash' ) . ' <a href="' . $authUrl . '" id="gapi-access-code" target="_blank">' . __ ( "Get Access Code", 'ga-dash' ) . '</a>.'; ?>
					</td>
		</tr>
		<tr>
			<td class="title"><label for="ga_dash_code"
				title="<?php _e("Use the red link to get your access code!",'ga-dash')?>"><?php echo _e( "Access Code:", 'ga-dash' ); ?></label>
			</td>
			<td><input type="text" id="ga_dash_code" name="ga_dash_code" value=""
				size="61" required="required"
				title="<?php _e("Use the red link to get your access code!",'ga-dash')?>"></td>
		</tr>
		<tr>
			<td colspan="2"><hr></td>
		</tr>
		<tr>
			<td colspan="2"><input type="submit" class="button button-secondary"
				name="ga_dash_authorize"
				value="<?php _e( "Save Access Code", 'ga-dash' ); ?>" /></td>
		</tr>
	</table>
</form>
<?php
        }

        /**
         * Retrives all Google Analytics Views with details
         *
         * @return array|string
         */
        function refresh_profiles()
        {
            try {
                $profiles = $this->service->management_profiles->listManagementProfiles('~all', '~all');
                $items = $profiles->getItems();
                if (count($items) != 0) {
                    $ga_dash_profile_list = array();
                    foreach ($items as $profile) {
                        $timetz = new DateTimeZone($profile->getTimezone());
                        $localtime = new DateTime('now', $timetz);
                        $timeshift = strtotime($localtime->format('Y-m-d H:i:s')) - time();
                        $ga_dash_profile_list[] = array(
                            $profile->getName(),
                            $profile->getId(),
                            $profile->getwebPropertyId(),
                            $profile->getwebsiteUrl(),
                            $timeshift,
                            $profile->getTimezone()
                        );
                    }
                    update_option('gadash_lasterror', 'N/A');
                    return $ga_dash_profile_list;
                } else {
                    update_option('gadash_lasterror', date('Y-m-d H:i:s') . ': No properties were found in this account!');
                    return '';
                }
            } catch (Google_IO_Exception $e) {
                update_option('gadash_lasterror', date('Y-m-d H:i:s') . ': ' . esc_html($e));
                return '';
            } catch (Google_Service_Exception $e) {
                update_option('gadash_lasterror', date('Y-m-d H:i:s') . ': ' . esc_html("(" . $e->getCode() . ") " . $e->getMessage()));
                set_transient('ga_dash_gapi_errors', $e->getErrors(), $this->error_timeout);
            } catch (Exception $e) {
                update_option('gadash_lasterror', date('Y-m-d H:i:s') . ': ' . esc_html($e));
                return '';
            }
        }

        /**
         * Handles the token refresh process
         *
         * @return token|boolean
         */
        function ga_dash_refresh_token()
        {
            global $GADASH_Config;
            try {
                if (is_multisite() && $GADASH_Config->options['ga_dash_network']) {
                    $transient = get_site_transient("ga_dash_refresh_token");
                } else {
                    $transient = get_transient("ga_dash_refresh_token");
                }
                if (empty($transient)) {
                    
                    if (! $GADASH_Config->options['ga_dash_refresh_token']) {
                        $google_token = json_decode($GADASH_Config->options['ga_dash_token']);
                        $GADASH_Config->options['ga_dash_refresh_token'] = $google_token->refresh_token;
                        $this->client->refreshToken($google_token->refresh_token);
                    } else {
                        $this->client->refreshToken($GADASH_Config->options['ga_dash_refresh_token']);
                    }
                    
                    $token = $this->client->getAccessToken();
                    $google_token = json_decode($token);
                    $GADASH_Config->options['ga_dash_token'] = $token;
                    if (is_multisite() && $GADASH_Config->options['ga_dash_network']) {
                        set_site_transient("ga_dash_refresh_token", $token, $google_token->expires_in);
                        $GADASH_Config->set_plugin_options(true);
                    } else {
                        set_transient("ga_dash_refresh_token", $token, $google_token->expires_in);
                        $GADASH_Config->set_plugin_options();
                    }
                    return $token;
                } else {
                    return $transient;
                }
            } catch (Google_IO_Exception $e) {
                update_option('gadash_lasterror', date('Y-m-d H:i:s') . ': ' . esc_html($e));
                return false;
            } catch (Google_Service_Exception $e) {
                update_option('gadash_lasterror', date('Y-m-d H:i:s') . ': ' . esc_html("(" . $e->getCode() . ") " . $e->getMessage()));
                set_transient('ga_dash_gapi_errors', $e->getErrors(), $this->error_timeout);
                return $e->getCode();
            } catch (Exception $e) {
                update_option('gadash_lasterror', date('Y-m-d H:i:s') . ': ' . esc_html($e));
                return false;
            }
        }

        /**
         * Handles the token reset process
         *
         * @param
         *            $all
         */
        function ga_dash_reset_token($all = true)
        {
            global $GADASH_Config;
            if (is_multisite() && $GADASH_Config->options['ga_dash_network']) {
                delete_site_transient('ga_dash_refresh_token');
            } else {
                delete_transient('ga_dash_refresh_token');
            }
            $GADASH_Config->options['ga_dash_token'] = "";
            $GADASH_Config->options['ga_dash_refresh_token'] = "";
            
            if ($all) {
                $GADASH_Config->options['ga_dash_tableid'] = "";
                $GADASH_Config->options['ga_dash_tableid_jail'] = "";
                $GADASH_Config->options['ga_dash_profile_list'] = "";
                try {
                    $this->client->revokeToken();
                } catch (Exception $e) {
                    if (is_multisite() && $GADASH_Config->options['ga_dash_network']) {
                        $GADASH_Config->set_plugin_options(true);
                    } else {
                        $GADASH_Config->set_plugin_options();
                    }
                }
            }
            
            if (is_multisite() && $GADASH_Config->options['ga_dash_network']) {
                $GADASH_Config->set_plugin_options(true);
            } else {
                $GADASH_Config->set_plugin_options();
            }
        }

        /**
         * Analytics data for backend reports (top stats main report)
         *
         * @param
         *            $projectId
         * @param
         *            $period
         * @param
         *            $from
         * @param
         *            $to
         * @param
         *            $query
         * @return string|int
         */
        function ga_dash_main_charts($projectId, $period, $from, $to, $query)
        {
            global $GADASH_Config;
            
            $metrics = 'ga:' . $query;
            
            if ($period == "today") {
                $dimensions = 'ga:hour';
                $timeouts = 0;
            } else 
                if ($period == "yesterday") {
                    $dimensions = 'ga:hour';
                    $timeouts = 1;
                } else {
                    $dimensions = 'ga:date,ga:dayOfWeekName';
                    $timeouts = 1;
                }
            
            try {
                $serial = 'gadash_qr2' . str_replace(array(
                    'ga:',
                    ',',
                    '-'
                ), "", $projectId . $from . $metrics);
                $transient = get_transient($serial);
                if (empty($transient)) {
                    
                    if ($this->gapi_errors_handler()) {
                        return - 23;
                    }
                    
                    $data = $this->service->data_ga->get('ga:' . $projectId, $from, $to, $metrics, array(
                        'dimensions' => $dimensions,
                        'quotaUser' => $this->managequota.'p'.$projectId
                    ));
                    set_transient($serial, $data, $this->get_timeouts($timeouts));
                } else {
                    $data = $transient;
                }
            } catch (Google_Service_Exception $e) {
                update_option('gadash_lasterror', date('Y-m-d H:i:s') . ': ' . esc_html("(" . $e->getCode() . ") " . $e->getMessage()));
                set_transient('ga_dash_gapi_errors', $e->getErrors(), $this->error_timeout);
                return $e->getCode();
            } catch (Exception $e) {
                update_option('gadash_lasterror', date('Y-m-d H:i:s') . ': ' . esc_html($e));
                return $e->getCode();
            }
            
            if (! isset($data['rows'])) {
                return - 21;
            }
            
            $ga_dash_data = "";
            
            if ($period == "today" or $period == "yesterday") {
                for ($i = 0; $i < $data['totalResults']; $i ++) {
                    $ga_dash_data .= "['" . $data['rows'][$i][0] . ":00'," . round($data['rows'][$i][1], 2) . "],";
                }
            } else {
                for ($i = 0; $i < $data['totalResults']; $i ++) {
                    $ga_dash_data .= "['" . ucfirst(__($data['rows'][$i][1])) . ', ' . substr_replace(substr_replace($data['rows'][$i][0], '-', 4, 0), '-', 7, 0) . "'," . round($data['rows'][$i][2], 2) . "],";
                }
            }
            
            $ga_dash_data = wp_kses(rtrim($ga_dash_data, ','), $GADASH_Config->allowed_html);
            
            if ($ga_dash_data) {
                return $ga_dash_data;
            } else {
                return - 22;
            }
        }

        /**
         * Analytics data for backend reports (bottom stats main report)
         *
         * @param
         *            $projectId
         * @param
         *            $period
         * @param
         *            $from
         * @param
         *            $to
         * @return array|int
         */
        function ga_dash_bottom_stats($projectId, $period, $from, $to)
        {
            global $GADASH_Config;
            
            if ($period == "today") {
                $timeouts = 0;
            } else {
                $timeouts = 1;
            }
            
            $metrics = 'ga:sessions,ga:users,ga:pageviews,ga:BounceRate,ga:organicSearches,ga:pageviewsPerSession';
            $dimensions = 'ga:year';
            try {
                $serial = 'gadash_qr3' . $projectId . $from;
                $transient = get_transient($serial);
                if (empty($transient)) {
                    
                    if ($this->gapi_errors_handler()) {
                        return - 23;
                    }
                    
                    $data = $this->service->data_ga->get('ga:' . $projectId, $from, $to, $metrics, array(
                        'dimensions' => $dimensions,
                        'quotaUser' => $this->managequota.'p'.$projectId
                    ));
                    set_transient($serial, $data, $this->get_timeouts($timeouts));
                } else {
                    $data = $transient;
                }
            } catch (Google_Service_Exception $e) {
                update_option('gadash_lasterror', date('Y-m-d H:i:s') . ': ' . esc_html("(" . $e->getCode() . ") " . $e->getMessage()));
                set_transient('ga_dash_gapi_errors', $e->getErrors(), $this->error_timeout);
                return $e->getCode();
            } catch (Exception $e) {
                update_option('gadash_lasterror', date('Y-m-d H:i:s') . ': ' . esc_html($e));
                return $e->getCode();
            }
            
            if (! isset($data['rows'])) {
                return - 21;
            }
            
            if (isset($data['rows'][1][1])) {
                for ($i = 1; $i < 6; $i ++) {
                    $data['rows'][0][$i] += $data['rows'][1][$i];
                    if ($i == 4) {
                        $data['rows'][0][$i] = $data['rows'][0][$i] / 2;
                    }
                }
            }
            
            return $data;
        }

        /**
         * Analytics data for backend reports (top pages)
         *
         * @param
         *            $projectId
         * @param
         *            $from
         * @param
         *            $to
         * @return string|int
         */
        function ga_dash_top_pages($projectId, $from, $to)
        {
            global $GADASH_Config;
            
            $metrics = 'ga:pageviews';
            $dimensions = 'ga:pageTitle,ga:hostname,ga:pagePath';
            
            if ($from == "today") {
                $timeouts = 0;
            } else {
                $timeouts = 1;
            }
            
            try {
                $serial = 'gadash_qr4' . $projectId . $from;
                $transient = get_transient($serial);
                if (empty($transient)) {
                    
                    if ($this->gapi_errors_handler()) {
                        return - 23;
                    }
                    
                    $data = $this->service->data_ga->get('ga:' . $projectId, $from, $to, $metrics, array(
                        'dimensions' => $dimensions,
                        'sort' => '-ga:pageviews',
                        'max-results' => '24',
                        'quotaUser' => $this->managequota.'p'.$projectId
                    )); // 'filters' => 'ga:pagePath!=/'
                    set_transient($serial, $data, $this->get_timeouts($timeouts));
                } else {
                    $data = $transient;
                }
            } catch (Google_Service_Exception $e) {
                update_option('gadash_lasterror', date('Y-m-d H:i:s') . ': ' . esc_html("(" . $e->getCode() . ") " . $e->getMessage()));
                set_transient('ga_dash_gapi_errors', $e->getErrors(), $this->error_timeout);
                return $e->getCode();
            } catch (Exception $e) {
                update_option('gadash_lasterror', date('Y-m-d H:i:s') . ': ' . esc_html($e));
                return $e->getCode();
            }
            if (! isset($data['rows'])) {
                return - 21;
            }
            
            $ga_dash_data = "";
            $i = 0;
            
            while (isset($data['rows'][$i][0])) {
                $ga_dash_data .= "['<a href=\"http://" . addslashes($data['rows'][$i][1] . $data['rows'][$i][2]) . "\" target=\"_blank\">" . addslashes($data['rows'][$i][0]) . "</a>'," . $data['rows'][$i][3] . "],";
                $i ++;
            }
            
            $ga_dash_data = wp_kses(rtrim($ga_dash_data, ','), $GADASH_Config->allowed_html);
            
            if ($ga_dash_data) {
                return $ga_dash_data;
            } else {
                return - 22;
            }
        }

        /**
         * Analytics data for backend reports (top referrers)
         *
         * @param
         *            $projectId
         * @param
         *            $from
         * @param
         *            $to
         * @return string|int
         */
        function ga_dash_top_referrers($projectId, $from, $to)
        {
            global $GADASH_Config;
            
            $metrics = 'ga:sessions';
            $dimensions = 'ga:source,ga:fullReferrer,ga:medium';
            
            if ($from == "today") {
                $timeouts = 0;
            } else {
                $timeouts = 1;
            }
            
            try {
                $serial = 'gadash_qr5' . $projectId . $from;
                $transient = get_transient($serial);
                if (empty($transient)) {
                    
                    if ($this->gapi_errors_handler()) {
                        return - 23;
                    }
                    
                    $data = $this->service->data_ga->get('ga:' . $projectId, $from, $to, $metrics, array(
                        'dimensions' => $dimensions,
                        'sort' => '-ga:sessions',
                        'max-results' => '24',
                        'filters' => 'ga:medium==referral',
                        'quotaUser' => $this->managequota.'p'.$projectId
                    ));
                    set_transient($serial, $data, $this->get_timeouts($timeouts));
                } else {
                    $data = $transient;
                }
            } catch (Google_Service_Exception $e) {
                update_option('gadash_lasterror', date('Y-m-d H:i:s') . ': ' . esc_html("(" . $e->getCode() . ") " . $e->getMessage()));
                set_transient('ga_dash_gapi_errors', $e->getErrors(), $this->error_timeout);
                return $e->getCode();
            } catch (Exception $e) {
                update_option('gadash_lasterror', date('Y-m-d H:i:s') . ': ' . esc_html($e));
                return $e->getCode();
            }
            if (! isset($data['rows'])) {
                return - 21;
            }
            
            $ga_dash_data = "";
            $i = 0;
            while (isset($data['rows'][$i][0])) {
                $ga_dash_data .= "['<a href=\"http://" . stripslashes(esc_html($data['rows'][$i][1])) . "\" target=\"_blank\">" . addslashes($data['rows'][$i][0]) . "</a>'," . $data['rows'][$i][3] . "],";
                $i ++;
            }
            
            $ga_dash_data = wp_kses(rtrim($ga_dash_data, ','), $GADASH_Config->allowed_html);
            
            if ($ga_dash_data) {
                return $ga_dash_data;
            } else {
                return - 22;
            }
        }

        /**
         * Analytics data for backend reports (top searches)
         *
         * @param
         *            $projectId
         * @param
         *            $from
         * @param
         *            $to
         * @return string|int
         */
        function ga_dash_top_searches($projectId, $from, $to)
        {
            global $GADASH_Config;
            
            $metrics = 'ga:sessions';
            $dimensions = 'ga:keyword';
            
            if ($from == "today") {
                $timeouts = 0;
            } else {
                $timeouts = 1;
            }
            
            try {
                $serial = 'gadash_qr6' . $projectId . $from;
                $transient = get_transient($serial);
                if (empty($transient)) {
                    
                    if ($this->gapi_errors_handler()) {
                        return - 23;
                    }
                    
                    $data = $this->service->data_ga->get('ga:' . $projectId, $from, $to, $metrics, array(
                        'dimensions' => $dimensions,
                        'sort' => '-ga:sessions',
                        'max-results' => '24',
                        'quotaUser' => $this->managequota.'p'.$projectId
                    ));
                    set_transient($serial, $data, $this->get_timeouts($timeouts));
                } else {
                    $data = $transient;
                }
            } catch (Google_Service_Exception $e) {
                update_option('gadash_lasterror', date('Y-m-d H:i:s') . ': ' . esc_html("(" . $e->getCode() . ") " . $e->getMessage()));
                set_transient('ga_dash_gapi_errors', $e->getErrors(), $this->error_timeout);
                return $e->getCode();
            } catch (Exception $e) {
                update_option('gadash_lasterror', date('Y-m-d H:i:s') . ': ' . esc_html($e));
                return $e->getCode();
            }
            if (! isset($data['rows'])) {
                return - 21;
            }
            
            $ga_dash_data = "";
            $i = 0;
            while (isset($data['rows'][$i][0])) {
                if ($data['rows'][$i][0] != "(not set)") {
                    $ga_dash_data .= "['" . stripslashes(esc_html($data['rows'][$i][0])) . "'," . $data['rows'][$i][1] . "],";
                }
                $i ++;
            }
            
            $ga_dash_data = wp_kses(rtrim($ga_dash_data, ','), $GADASH_Config->allowed_html);
            
            if ($ga_dash_data) {
                return $ga_dash_data;
            } else {
                return - 22;
            }
        }

        /**
         * Analytics data for backend reports (location reports)
         *
         * @param
         *            $projectId
         * @param
         *            $from
         * @param
         *            $to
         * @return string|int
         */
        function ga_dash_sessions_country($projectId, $from, $to)
        {
            global $GADASH_Config;
            
            $metrics = 'ga:sessions';
            $options = "";
            
            if ($from == "today") {
                $timeouts = 0;
            } else {
                $timeouts = 1;
            }
            
            if ($GADASH_Config->options['ga_target_geomap']) {
                $dimensions = 'ga:city, ga:region';
                $this->getcountrycodes();
                if (isset($this->country_codes[$GADASH_Config->options['ga_target_geomap']])){
                    $filters = 'ga:country==' . ($this->country_codes[$GADASH_Config->options['ga_target_geomap']]);
                }else{
                    $filters = "";
                }    
            } else {
                $dimensions = 'ga:country';
                $filters = "";
            }
            try {
                if ($GADASH_Config->options['ga_target_geomap']) {
                    $serial = 'gadash_qr7' . $projectId . $from . $GADASH_Config->options['ga_target_geomap'] . $GADASH_Config->options['ga_target_number'];
                } else {
                    $serial = 'gadash_qr7' . $projectId . $from;
                }
                $transient = get_transient($serial);
                if (empty($transient)) {
                    
                    if ($this->gapi_errors_handler()) {
                        return - 23;
                    }
                    
                    if ($filters) {
                        
                        $data = $this->service->data_ga->get('ga:' . $projectId, $from, $to, $metrics, array(
                            'dimensions' => $dimensions,
                            'filters' => $filters,
                            'sort' => '-ga:sessions',
                            'max-results' => $GADASH_Config->options['ga_target_number'],
                            'quotaUser' => $this->managequota.'p'.$projectId
                        ));
                    } else {
                        $data = $this->service->data_ga->get('ga:' . $projectId, $from, $to, $metrics, array(
                            'dimensions' => $dimensions,
                            'quotaUser' => $this->managequota.'p'.$projectId
                        ));
                    }
                    set_transient($serial, $data, $this->get_timeouts($timeouts));
                } else {
                    $data = $transient;
                }
            } catch (Google_Service_Exception $e) {
                update_option('gadash_lasterror', date('Y-m-d H:i:s') . ': ' . esc_html("(" . $e->getCode() . ") " . $e->getMessage()));
                set_transient('ga_dash_gapi_errors', $e->getErrors(), $this->error_timeout);
                return $e->getCode();
            } catch (Exception $e) {
                update_option('gadash_lasterror', date('Y-m-d H:i:s') . ': ' . esc_html($e));
                return $e->getCode();
            }
            if (! isset($data['rows'])) {
                return - 21;
            }
            
            $ga_dash_data = "";
            $i = 0;
            while (isset($data['rows'][$i][1])) {
                if (isset($data['rows'][$i][2])) {
                    $ga_dash_data .= "['" . addslashes($data['rows'][$i][0]) . ", " . addslashes($data['rows'][$i][1]) . "'," . $data['rows'][$i][2] . "],";
                } else {
                    $ga_dash_data .= "['" . addslashes($data['rows'][$i][0]) . "'," . $data['rows'][$i][1] . "],";
                }
                $i ++;
            }
            
            $ga_dash_data = wp_kses(rtrim($ga_dash_data, ','), $GADASH_Config->allowed_html);
            
            if ($ga_dash_data) {
                return $ga_dash_data;
            } else {
                return - 22;
            }
        }

        /**
         * Analytics data for backend reports (traffic sources)
         *
         * @param
         *            $projectId
         * @param
         *            $from
         * @param
         *            $to
         * @return string|int
         */
        function ga_dash_traffic_sources($projectId, $from, $to)
        {
            global $GADASH_Config;
            
            $metrics = 'ga:sessions';
            $dimensions = 'ga:medium';
            
            if ($from == "today") {
                $timeouts = 0;
            } else {
                $timeouts = 1;
            }
            
            try {
                $serial = 'gadash_qr8' . $projectId . $from;
                $transient = get_transient($serial);
                if (empty($transient)) {
                    
                    if ($this->gapi_errors_handler()) {
                        return - 23;
                    }
                    
                    $data = $this->service->data_ga->get('ga:' . $projectId, $from, $to, $metrics, array(
                        'dimensions' => $dimensions,
                        'quotaUser' => $this->managequota.'p'.$projectId
                    ));
                    set_transient($serial, $data, $this->get_timeouts($timeouts));
                } else {
                    $data = $transient;
                }
            } catch (Google_Service_Exception $e) {
                update_option('gadash_lasterror', date('Y-m-d H:i:s') . ': ' . esc_html("(" . $e->getCode() . ") " . $e->getMessage()));
                set_transient('ga_dash_gapi_errors', $e->getErrors(), $this->error_timeout);
                return $e->getCode();
            } catch (Exception $e) {
                update_option('gadash_lasterror', date('Y-m-d H:i:s') . ': ' . esc_html($e));
                return $e->getCode();
            }
            if (! isset($data['rows'])) {
                return - 21;
            }
            
            $ga_dash_data = "";
            for ($i = 0; $i < $data['totalResults']; $i ++) {
                $ga_dash_data .= "['" . str_replace("(none)", "direct", $data['rows'][$i][0]) . "'," . $data['rows'][$i][1] . "],";
            }
            
            $ga_dash_data = wp_kses(rtrim($ga_dash_data, ','), $GADASH_Config->allowed_html);
            
            if ($ga_dash_data) {
                return $ga_dash_data;
            } else {
                return - 22;
            }
        }

        /**
         * Analytics data for backend reports (traffic type)
         *
         * @param
         *            $projectId
         * @param
         *            $from
         * @param
         *            $to
         * @return string|int
         */
        function ga_dash_new_return($projectId, $from, $to)
        {
            global $GADASH_Config;
            
            $metrics = 'ga:sessions';
            $dimensions = 'ga:visitorType';
            
            if ($from == "today") {
                $timeouts = 0;
            } else {
                $timeouts = 1;
            }
            
            try {
                $serial = 'gadash_qr9' . $projectId . $from;
                $transient = get_transient($serial);
                if (empty($transient)) {
                    
                    if ($this->gapi_errors_handler()) {
                        return - 23;
                    }
                    
                    $data = $this->service->data_ga->get('ga:' . $projectId, $from, $to, $metrics, array(
                        'dimensions' => $dimensions,
                        'quotaUser' => $this->managequota.'p'.$projectId
                    ));
                    set_transient($serial, $data, $this->get_timeouts($timeouts));
                } else {
                    $data = $transient;
                }
            } catch (Google_Service_Exception $e) {
                update_option('gadash_lasterror', date('Y-m-d H:i:s') . ': ' . esc_html("(" . $e->getCode() . ") " . $e->getMessage()));
                set_transient('ga_dash_gapi_errors', $e->getErrors(), $this->error_timeout);
                return $e->getCode();
            } catch (Exception $e) {
                update_option('gadash_lasterror', date('Y-m-d H:i:s') . ': ' . esc_html($e));
                return $e->getCode();
            }
            if (! isset($data['rows'])) {
                return - 21;
            }
            
            $ga_dash_data = "";
            for ($i = 0; $i < $data['totalResults']; $i ++) {
                $ga_dash_data .= "['" . addslashes($data['rows'][$i][0]) . "'," . $data['rows'][$i][1] . "],";
            }
            $ga_dash_data = wp_kses(rtrim($ga_dash_data, ','), $GADASH_Config->allowed_html);
            
            if ($ga_dash_data) {
                return $ga_dash_data;
            } else {
                return - 22;
            }
        }

        /**
         * Analytics data for frontend Widget (chart data and totals)
         *
         * @param
         *            $projectId
         * @param
         *            $period
         * @param
         *            $anonim
         * @return array|int
         */
        function frontend_widget_stats($projectId, $period, $anonim)
        {
            global $GADASH_Config;
            
            $content = '';
            $from = $period;
            $to = 'yesterday';
            $metrics = 'ga:sessions';
            $dimensions = 'ga:date,ga:dayOfWeekName';
            
            try {
                
                $serial = 'gadash_qr2' . str_replace(array(
                    'ga:',
                    ',',
                    '-'
                ), "", $projectId . $from . $metrics);
                
                $transient = get_transient($serial);
                if (empty($transient)) {
                    
                    if ($this->gapi_errors_handler()) {
                        return - 23;
                    }
                    
                    $data = $this->service->data_ga->get('ga:' . $projectId, $from, $to, $metrics, array(
                        'dimensions' => $dimensions,
                        'quotaUser' => $this->managequota.'p'.$projectId
                    ));
                    set_transient($serial, $data, $this->get_timeouts(1));
                } else {
                    $data = $transient;
                }
            } catch (Google_Service_Exception $e) {
                update_option('gadash_lasterror', date('Y-m-d H:i:s') . ': ' . esc_html("(" . $e->getCode() . ") " . $e->getMessage()));
                set_transient('ga_dash_gapi_errors', $e->getErrors(), $this->error_timeout);
                return $e->getCode();
            } catch (Exception $e) {
                update_option('gadash_lasterror', date('Y-m-d H:i:s') . ': ' . esc_html($e));
                return $e->getCode();
            }
            if (! isset($data['rows'])) {
                return - 21;
            }
            
            $ga_dash_data = "";
            
            $max_array = array();
            foreach ($data['rows'] as $item) {
                $max_array[] = $item[2];
            }
            
            $max = max($max_array) ? max($max_array) : 1;
            
            for ($i = 0; $i < $data['totalResults']; $i ++) {
                $ga_dash_data .= '["' . ucfirst(__($data["rows"][$i][1])) . ", " . substr_replace(substr_replace($data["rows"][$i][0], "-", 4, 0), "-", 7, 0) . '",' . ($anonim ? str_replace(",", ".", round($data["rows"][$i][2] * 100 / $max, 2)) : $data["rows"][$i][2]) . '],';
            }
            
            $ga_dash_data = '[["' . __("Date", 'ga-dash') . '", "' . __("Sessions", 'ga-dash') . ($anonim ? "' " . __("trend", 'ga-dash') : '') . '"],' . rtrim($ga_dash_data, ",") . "]";
            
            $ga_dash_data = wp_kses($ga_dash_data, $GADASH_Config->allowed_html);
            
            if ($ga_dash_data) {
                return array(
                    $ga_dash_data,
                    (int) $data['totalsForAllResults']['ga:sessions']
                );
            } else {
                return - 22;
            }
        }

        /**
         * Analytics data for frontend reports (pagviews and unique pageviews per page)
         *
         * @param
         *            $projectId
         * @param
         *            $period
         * @param
         *            $anonim
         * @return string|int
         */
        function frontend_afterpost_sessions($projectId, $page_url, $post_id)
        {
            global $GADASH_Config;
            
            $from = '30daysAgo';
            $to = 'yesterday';
            $metrics = 'ga:pageviews,ga:uniquePageviews';
            $dimensions = 'ga:date,ga:dayOfWeekName';
            
            try {
                $serial = 'gadash_qr21' . $post_id . 'stats';
                $transient = get_transient($serial);
                if (empty($transient)) {
                    
                    if ($this->gapi_errors_handler()) {
                        return - 23;
                    }
                    
                    $data = $this->service->data_ga->get('ga:' . $projectId, $from, $to, $metrics, array(
                        'dimensions' => $dimensions,
                        'filters' => 'ga:pagePath==' . $page_url,
                        'quotaUser' => $this->managequota.'p'.$projectId
                    ));
                    set_transient($serial, $data, $this->get_timeouts(1));
                } else {
                    $data = $transient;
                }
            } catch (Google_Service_Exception $e) {
                update_option('gadash_lasterror', date('Y-m-d H:i:s') . ': ' . esc_html("(" . $e->getCode() . ") " . $e->getMessage()));
                set_transient('ga_dash_gapi_errors', $e->getErrors(), $this->error_timeout);
                return $e->getCode();
            } catch (Exception $e) {
                update_option('gadash_lasterror', date('Y-m-d H:i:s') . ': ' . esc_html($e));
                return $e->getCode();
            }
            if (! isset($data['rows'])) {
                return - 21;
            }
            
            $ga_dash_data = "";
            for ($i = 0; $i < $data['totalResults']; $i ++) {
                $ga_dash_data .= '["' . ucfirst(__($data['rows'][$i][1])) . ", " . substr_replace(substr_replace($data['rows'][$i][0], "-", 4, 0), "-", 7, 0) . '",' . round($data['rows'][$i][2], 2) . ',' . round($data['rows'][$i][3], 2) . '],';
            }
            
            $ga_dash_data = wp_kses(rtrim($ga_dash_data, ','), $GADASH_Config->allowed_html);
            
            if ($ga_dash_data) {
                
                $ga_dash_data = '[["' . __('Date', "ga-dash") . '", "' . __('Views', "ga-dash") . '", "' . __('UniqueViews', "ga-dash") . '"],' . $ga_dash_data . ']';
                
                return $ga_dash_data;
            } else {
                return - 22;
            }
        }

        /**
         * Analytics data for frontend reports (searches per page)
         *
         * @param
         *            $projectId
         * @param
         *            $period
         * @param
         *            $anonim
         * @return string|int
         */
        function frontend_afterpost_searches($projectId, $page_url, $post_id)
        {
            global $GADASH_Config;
            
            $from = '30daysAgo';
            $to = 'yesterday';
            $metrics = 'ga:sessions';
            $dimensions = 'ga:keyword';
            try {
                $serial = 'gadash_qr22' . $post_id . 'search';
                $transient = get_transient($serial);
                if (empty($transient)) {
                    
                    if ($this->gapi_errors_handler()) {
                        return - 23;
                    }
                    
                    $data = $this->service->data_ga->get('ga:' . $projectId, $from, $to, $metrics, array(
                        'dimensions' => $dimensions,
                        'sort' => '-ga:sessions',
                        'max-results' => '24',
                        'filters' => 'ga:pagePath==' . $page_url,
                        'quotaUser' => $this->managequota.'p'.$projectId
                    ));
                    set_transient($serial, $data, $this->get_timeouts(1));
                } else {
                    $data = $transient;
                }
            } catch (Google_Service_Exception $e) {
                update_option('gadash_lasterror', date('Y-m-d H:i:s') . ': ' . esc_html("(" . $e->getCode() . ") " . $e->getMessage()));
                set_transient('ga_dash_gapi_errors', $e->getErrors(), $this->error_timeout);
                return $e->getCode();
            } catch (Exception $e) {
                update_option('gadash_lasterror', date('Y-m-d H:i:s') . ': ' . esc_html($e));
                return $e->getCode();
            }
            
            $ga_dash_data = "";
            if (! isset($data['rows'])) {
                return - 21;
            }
            
            $i = 0;
            while (isset($data['rows'][$i][0])) {
                if ($data['rows'][$i][0] != "(not set)") {
                    $ga_dash_data .= '["' . stripslashes(esc_html($data['rows'][$i][0])) . '",' . $data['rows'][$i][1] . '],';
                }
                $i ++;
            }
            
            $ga_dash_data = wp_kses(rtrim($ga_dash_data, ','), $GADASH_Config->allowed_html);
            
            if ($ga_dash_data) {
                
                $ga_dash_data = '[["' . __('Top Searches', "ga-dash") . '", "' . __('Sessions', "ga-dash") . '"],' . $ga_dash_data . ' ]';
                
                return $ga_dash_data;
            } else {
                
                return - 22;
            }
        }

        /**
         * Analytics data for backend reports (Real-Time)
         *
         * @param
         *            $projectId
         * @return string|int
         */
        function gadash_realtime_data($projectId)
        {
            global $GADASH_Config;
            $metrics = 'rt:activeUsers';
            $dimensions = 'rt:pagePath,rt:source,rt:keyword,rt:trafficType,rt:visitorType,rt:pageTitle';
            try {
                $serial = "gadash_realtimecache_" . $projectId;
                $transient = get_transient($serial);
                if (empty($transient)) {
                    
                    if ($this->gapi_errors_handler()) {
                        return - 23;
                    }
                    
                    $data = $this->service->data_realtime->get('ga:' . $projectId, $metrics, array(
                        'dimensions' => $dimensions,
                        'quotaUser' => $this->managequota.'p'.$projectId
                    ));
                    set_transient($serial, $data, 55);
                } else {
                    $data = $transient;
                }
            } catch (Google_Service_Exception $e) {
                update_option('gadash_lasterror', date('Y-m-d H:i:s') . ': ' . esc_html("(" . $e->getCode() . ") " . $e->getMessage()));
                set_transient('ga_dash_gapi_errors', $e->getErrors(), $this->error_timeout);
                return $e->getCode();
            } catch (Exception $e) {
                update_option('gadash_lasterror', date('Y-m-d H:i:s') . ': ' . esc_html($e));
                return $e->getCode();
            }
            
            if (! isset($data['rows'])) {
                return - 21;
            }
            
            $i = 0;
            while (isset($data->rows[$i])) {
                $data->rows[$i] = wp_kses(str_replace('"', "'", $data->rows[$i]), $GADASH_Config->allowed_html); // remove all double quotes before sending data
                $i ++;
            }
            
            return $data;
        }

        /**
         * Renders Real-Time reports in backend
         *
         * @return string
         */
        function ga_realtime()
        {
            global $GADASH_Config;
            
            $code = '

				<script type="text/javascript">

				var focusFlag = 1;

				jQuery(document).ready(function(){
					jQuery(window).bind("focus",function(event){
						focusFlag = 1;
					}).bind("blur", function(event){
						focusFlag = 0;
					});
				});

				jQuery(function() {
					jQuery( document ).tooltip();
				});

				function onlyUniqueValues(value, index, self) {
					return self.indexOf(value) === index;
				 }

				function countsessions(data, searchvalue) {
					var count = 0;
					for ( var i = 0; i < data["rows"].length; i = i + 1 ) {
						if (jQuery.inArray(searchvalue, data["rows"][ i ])>-1){
							count += parseInt(data["rows"][ i ][6]);
						}
		 			}
					return count;
				 }

				function gadash_generatetooltip(data) {
					var count = 0;
					var table = "";
					for ( var i = 0; i < data.length; i = i + 1 ) {
							count += parseInt(data[ i ].count);
							table += "<tr><td class=\'gadash-pgdetailsl\'>"+data[i].value+"</td><td class=\'gadash-pgdetailsr\'>"+data[ i ].count+"</td></tr>";
					};
					if (count){
						return("<table>"+table+"</table>");
					}else{
						return("");
					}
				}

				function gadash_pagedetails(data, searchvalue) {
					var newdata = [];
					for ( var i = 0; i < data["rows"].length; i = i + 1 ){
						var sant=1;
						for ( var j = 0; j < newdata.length; j = j + 1 ){
							if (data["rows"][i][0]+data["rows"][i][1]+data["rows"][i][2]+data["rows"][i][3]==newdata[j][0]+newdata[j][1]+newdata[j][2]+newdata[j][3]){
								newdata[j][6] = parseInt(newdata[j][6]) + parseInt(data["rows"][i][6]);
								sant = 0;
							}
						}
						if (sant){
							newdata.push(data["rows"][i].slice());
						}
					}

					var countrfr = 0;
					var countkwd = 0;
					var countdrt = 0;
					var countscl = 0;
					var tablerfr = "";
					var tablekwd = "";
					var tablescl = "";
					var tabledrt = "";
					for ( var i = 0; i < newdata.length; i = i + 1 ) {
						if (newdata[i][0] == searchvalue){
							var pagetitle = newdata[i][5];
							switch (newdata[i][3]){
								case "REFERRAL": 	countrfr += parseInt(newdata[ i ][6]);
													tablerfr +=	"<tr><td class=\'gadash-pgdetailsl\'>"+newdata[i][1]+"</td><td class=\'gadash-pgdetailsr\'>"+newdata[ i ][6]+"</td></tr>";
													break;
								case "ORGANIC": 	countkwd += parseInt(newdata[ i ][6]);
													tablekwd +=	"<tr><td class=\'gadash-pgdetailsl\'>"+newdata[i][2]+"</td><td class=\'gadash-pgdetailsr\'>"+newdata[ i ][6]+"</td></tr>";
													break;
								case "SOCIAL": 		countscl += parseInt(newdata[ i ][6]);
													tablescl +=	"<tr><td class=\'gadash-pgdetailsl\'>"+newdata[i][1]+"</td><td class=\'gadash-pgdetailsr\'>"+newdata[ i ][6]+"</td></tr>";
													break;
								case "DIRECT": 		countdrt += parseInt(newdata[ i ][6]);
													break;
							};
						};
		 			};
					if (countrfr){
						tablerfr = "<table><tr><td>' . __("REFERRALS", 'ga-dash') . ' ("+countrfr+")</td></tr>"+tablerfr+"</table><br />";
					}
					if (countkwd){
						tablekwd = "<table><tr><td>' . __("KEYWORDS", 'ga-dash') . ' ("+countkwd+")</td></tr>"+tablekwd+"</table><br />";
					}
					if (countscl){
						tablescl = "<table><tr><td>' . __("SOCIAL", 'ga-dash') . ' ("+countscl+")</td></tr>"+tablescl+"</table><br />";
					}
					if (countdrt){
						tabledrt = "<table><tr><td>' . __("DIRECT", 'ga-dash') . ' ("+countdrt+")</td></tr></table><br />";
					}
					return ("<p><center><strong>"+pagetitle+"</strong></center></p>"+tablerfr+tablekwd+tablescl+tabledrt);
				 }

				 function online_refresh(){
					if (focusFlag){

					jQuery.post(ajaxurl, {action: "gadash_get_online_data", gadash_security: "' . wp_create_nonce('gadash_get_online_data') . '"}, function(response){
						var data = jQuery.parseJSON(response);
                        if (jQuery.isNumeric(data) || typeof data === "undefined"){
                            data = [];
                            data["totalsForAllResults"] = []
                            data["totalsForAllResults"]["rt:activeUsers"] = "0";
                            data["rows"]= [];
                        }

						if (data["totalsForAllResults"]["rt:activeUsers"]!==document.getElementById("gadash-online").innerHTML){
							jQuery("#gadash-online").fadeOut("slow");
							jQuery("#gadash-online").fadeOut(500);
							jQuery("#gadash-online").fadeOut("slow", function() {
								if ((parseInt(data["totalsForAllResults"]["rt:activeUsers"]))<(parseInt(document.getElementById("gadash-online").innerHTML))){
									jQuery("#gadash-online").css({\'background-color\' : \'#FFE8E8\'});
								}else{
									jQuery("#gadash-online").css({\'background-color\' : \'#E0FFEC\'});
								}
								document.getElementById("gadash-online").innerHTML = data["totalsForAllResults"]["rt:activeUsers"];
							});
							jQuery("#gadash-online").fadeIn("slow");
							jQuery("#gadash-online").fadeIn(500);
							jQuery("#gadash-online").fadeIn("slow", function() {
								jQuery("#gadash-online").css({\'background-color\' : \'#FFFFFF\'});
							});
						};

						if (data["totalsForAllResults"]["rt:activeUsers"] == 0){
							data["rows"]= [];
						};

						var pagepath = [];
						var referrals = [];
						var keywords = [];
						var social = [];
						var visittype = [];
						for ( var i = 0; i < data["rows"].length; i = i + 1 ) {
							pagepath.push( data["rows"][ i ][0] );
							if (data["rows"][i][3]=="REFERRAL"){
								referrals.push( data["rows"][ i ][1] );
							}
							if (data["rows"][i][3]=="ORGANIC"){
								keywords.push( data["rows"][ i ][2] );
							}
							if (data["rows"][i][3]=="SOCIAL"){
								social.push( data["rows"][ i ][1] );
							}
							visittype.push( data["rows"][ i ][3] );
		 				}

						var upagepath = pagepath.filter(onlyUniqueValues);
						var upagepathstats = [];
						for ( var i = 0; i < upagepath.length; i = i + 1 ) {
							upagepathstats[i]={"pagepath":upagepath[i],"count":countsessions(data,upagepath[i])};
		 				}
						upagepathstats.sort( function(a,b){ return b.count - a.count } );

						var pgstatstable = "";
						for ( var i = 0; i < upagepathstats.length; i = i + 1 ) {
							if (i < ' . $GADASH_Config->options['ga_realtime_pages'] . '){
								pgstatstable += "<div class=\"gadash-pline\"><div class=\"gadash-pleft\"><a href=\"#\" title=\""+gadash_pagedetails(data, upagepathstats[i].pagepath)+"\">"+upagepathstats[i].pagepath.substring(0,70)+"</a></div><div class=\"gadash-pright\">"+upagepathstats[i].count+"</div></div>";
							}
		 				}
						document.getElementById("gadash-pages").innerHTML="<br /><div class=\"gadash-pg\">"+pgstatstable+"</div>";

						var ureferralsstats = [];
						var ureferrals = referrals.filter(onlyUniqueValues);
						for ( var i = 0; i < ureferrals.length; i = i + 1 ) {
							ureferralsstats[i]={"value":ureferrals[i],"count":countsessions(data,ureferrals[i])};
		 				}
						ureferralsstats.sort( function(a,b){ return b.count - a.count } );

						var ukeywordsstats = [];
						var ukeywords = keywords.filter(onlyUniqueValues);
						for ( var i = 0; i < ukeywords.length; i = i + 1 ) {
							ukeywordsstats[i]={"value":ukeywords[i],"count":countsessions(data,ukeywords[i])};
		 				}
						ukeywordsstats.sort( function(a,b){ return b.count - a.count } );

						var usocialstats = [];
						var usocial = social.filter(onlyUniqueValues);
						for ( var i = 0; i < usocial.length; i = i + 1 ) {
							usocialstats[i]={"value":usocial[i],"count":countsessions(data,usocial[i])};
		 				}
						usocialstats.sort( function(a,b){ return b.count - a.count } );

						var uvisittype = ["REFERRAL","ORGANIC","SOCIAL"];
						document.getElementById("gadash-tdo-right").innerHTML = "<span class=\"gadash-bigtext\"><a href=\"#\" title=\""+gadash_generatetooltip(ureferralsstats)+"\">"+\'' . __("REFERRAL", 'ga-dash') . '\'+"</a>: "+countsessions(data,uvisittype[0])+"</span><br /><br />";
						document.getElementById("gadash-tdo-right").innerHTML += "<span class=\"gadash-bigtext\"><a href=\"#\" title=\""+gadash_generatetooltip(ukeywordsstats)+"\">"+\'' . __("ORGANIC", 'ga-dash') . '\'+"</a>: "+countsessions(data,uvisittype[1])+"</span><br /><br />";
						document.getElementById("gadash-tdo-right").innerHTML += "<span class=\"gadash-bigtext\"><a href=\"#\" title=\""+gadash_generatetooltip(usocialstats)+"\">"+\'' . __("SOCIAL", 'ga-dash') . '\'+"</a>: "+countsessions(data,uvisittype[2])+"</span><br /><br />";

						var uvisitortype = ["DIRECT","NEW","RETURN"];
						document.getElementById("gadash-tdo-rights").innerHTML = "<span class=\"gadash-bigtext\">"+\'' . __("DIRECT", 'ga-dash') . '\'+": "+countsessions(data,uvisitortype[0])+"</span><br /><br />";
						document.getElementById("gadash-tdo-rights").innerHTML += "<span class=\"gadash-bigtext\">"+\'' . __("NEW", 'ga-dash') . '\'+": "+countsessions(data,uvisitortype[1])+"</span><br /><br />";
						document.getElementById("gadash-tdo-rights").innerHTML += "<span class=\"gadash-bigtext\">"+\'' . __("RETURN", 'ga-dash') . '\'+": "+countsessions(data,uvisitortype[2])+"</span><br /><br />";

					});
			   };
			   };
			   online_refresh();
			   setInterval(online_refresh, 60000);
			   </script>';
            return $code;
        }

        public function getcountrycodes()
        {
            include_once 'iso3166.php';
        }
    }
}
if (! isset($GLOBALS['GADASH_GAPI'])) {
    $GLOBALS['GADASH_GAPI'] = new GADASH_GAPI();
}
