<?php
/**
 * WebEngine CMS
 * https://webenginecms.org/
 * 
 * @version 1.0.9.8
 * @author Lautaro Angelico <http://lautaroangelico.com/>
 * @copyright (c) 2013-2017 Lautaro Angelico, All Rights Reserved
 * 
 * Licensed under the MIT license
 * http://opensource.org/licenses/MIT
 */

class Account extends common {
	
	private $_defaultAccountSerial = '1111111111111';
	
	public function registerAccount($username, $password, $cpassword, $email) {
		
		if(!check_value($username)) throw new Exception(lang('error_4',true));
		if(!check_value($password)) throw new Exception(lang('error_4',true));
		if(!check_value($cpassword)) throw new Exception(lang('error_4',true));
		if(!check_value($email)) throw new Exception(lang('error_4',true));

		// Filters
		if(!Validator::UsernameLength($username)) throw new Exception(lang('error_5',true));
		if(!Validator::AlphaNumeric($username)) throw new Exception(lang('error_6',true));
		if(!Validator::PasswordLength($password)) throw new Exception(lang('error_7',true));
		if($password != $cpassword) throw new Exception(lang('error_8',true));
		if(!Validator::Email($email)) throw new Exception(lang('error_9',true));
		
		# load registration configs
		$regCfg = loadConfigurations('register');
		
		# check if username / email exists
		if($this->userExists($username)) throw new Exception(lang('error_10',true));
		if($this->emailExists($email)) throw new Exception(lang('error_11',true));
		
		# WebEngine Email Verification System (EVS)
		if($regCfg['verify_email']) {
			# check if username / email exists
			if($this->checkUsernameEVS($username)) throw new Exception(lang('error_10',true));
			if($this->checkEmailEVS($email)) throw new Exception(lang('error_11',true));
			
			# generate verification key
			$verificationKey = $this->createRegistrationVerification($username,$password,$email);
			if(!check_value($verificationKey)) throw new Exception(lang('error_23',true));
			
			# send verification email
			$this->sendRegistrationVerificationEmail($username,$email,$verificationKey);
			message('success', lang('success_18',true));
			return;
		}
		
		# insert data
		$data = array(
			'username' => $username,
			'password' => $password,
			'name' => $username,
			'serial' => $this->_defaultAccountSerial,
			'email' => $email
		);
		
		# query
		if($this->_md5Enabled) {
			$query = "INSERT INTO "._TBL_MI_." ("._CLMN_USERNM_.", "._CLMN_PASSWD_.", "._CLMN_MEMBNAME_.", "._CLMN_SNONUMBER_.", "._CLMN_EMAIL_.", "._CLMN_BLOCCODE_.", "._CLMN_CTLCODE_.") VALUES (:username, [dbo].[fn_md5](:password, :username), :name, :serial, :email, 0, 0)";
		} else {
			$query = "INSERT INTO "._TBL_MI_." ("._CLMN_USERNM_.", "._CLMN_PASSWD_.", "._CLMN_MEMBNAME_.", "._CLMN_SNONUMBER_.", "._CLMN_EMAIL_.", "._CLMN_BLOCCODE_.", "._CLMN_CTLCODE_.") VALUES (:username, :password, :name, :serial, :email, 0, 0)";
		}
		# query for VI_CURR_INFO (Compatible with DarksTeam 97d+99i MuServer)
		$data2 = array(
			'username' => $username,
			'name' => $username,
			'serial' => $this->_defaultAccountSerial,
		);
		$query2 = "INSERT INTO VI_CURR_INFO (ends_days, chek_code, used_time, "._CLMN_USERNM_.", "._CLMN_MEMBNAME_.", "._CLMN_MEMBID_.", "._CLMN_SNONUMBER_.", Bill_Section, Bill_Value, Bill_Hour, Surplus_Point, Surplus_Minute, Increase_Days) VALUES (2005, 1, 1234, :username, :name, 1, :serial, 6, 3, 6, 6,'1905-06-26 00:00:00.000', 0)";
		
		# register account
		$result = $this->db->query($query, $data);
		if(!$result) throw new Exception(lang('error_22',true));
		$result2 = $this->db->query($query2, $data2);
		if(!$result2) throw new Exception('error_22',true);
		
		# send welcome email
		if($regCfg['send_welcome_email']) {
			$this->sendWelcomeEmail($username, $email);
		}
		
		# free vip days
		if($regCfg['freevip_enable']) {
			switch($this->_serverFiles) {
				case 'MUE':
					$vip = new Vip();
					$this->updateVipTimeStamp($this->db->lastInsertId(), $vip->CalculateTimestamp($regCfg['freevip_days']));
					break;
				default:
					break;
			}
		}
		
		# success message
		message('success', lang('success_1',true));
		
		# redirect to login (5 seconds)
		redirect(2,'login/',5);
	}
	
	public function changePasswordProcess($userid, $username, $password, $new_password, $confirm_new_password) {
		if(!check_value($userid)) throw new Exception(lang('error_4',true));
		if(!check_value($username)) throw new Exception(lang('error_4',true));
		if(!check_value($password)) throw new Exception(lang('error_4',true));
		if(!check_value($new_password)) throw new Exception(lang('error_4',true));
		if(!check_value($confirm_new_password)) throw new Exception(lang('error_4',true));
		if(!Validator::PasswordLength($new_password)) throw new Exception(lang('error_7',true));
		if($new_password != $confirm_new_password) throw new Exception(lang('error_8',true));
		
		# check user credentials
		if(!$this->validateUser($username, $password)) throw new Exception(lang('error_13',true));
		
		# check online status
		if($this->accountOnline($username)) throw new Exception(lang('error_14',true));
		
		# change password
		if(!$this->changePassword($userid, $username, $new_password)) throw new Exception(lang('error_23',true));
		
		# send email with new password
		$accountData = $this->accountInformation($userid);
		try {
			$email = new Email();
			$email->setTemplate('CHANGE_PASSWORD');
			$email->addVariable('{USERNAME}', $username);
			$email->addVariable('{NEW_PASSWORD}', $new_password);
			$email->addAddress($accountData[_CLMN_EMAIL_]);
			$email->send();
		} catch (Exception $ex) {}
		
		# success message
		message('success', lang('success_2',true));
	}
	
	public function changePasswordProcess_verifyEmail($userid, $username, $password, $new_password, $confirm_new_password, $ip_address) {
		if(!check_value($userid)) throw new Exception(lang('error_4',true));
		if(!check_value($username)) throw new Exception(lang('error_4',true));
		if(!check_value($password)) throw new Exception(lang('error_4',true));
		if(!check_value($new_password)) throw new Exception(lang('error_4',true));
		if(!check_value($confirm_new_password)) throw new Exception(lang('error_4',true));
		if(!Validator::PasswordLength($new_password)) throw new Exception(lang('error_7',true));
		if($new_password != $confirm_new_password) throw new Exception(lang('error_8',true));
		
		# load changepw configs
		$mypassCfg = loadConfigurations('usercp.mypassword');
		
		# check user credentials
		if(!$this->validateUser($username, $password)) throw new Exception(lang('error_13',true));
		
		# check online status
		if($this->accountOnline($username)) throw new Exception(lang('error_14',true));
		
		# check if user has an active password change request
		if($this->hasActivePasswordChangeRequest($userid)) throw new Exception(lang('error_19',true));
		
		# load account data
		$accountData = $this->accountInformation($userid);
		if(!is_array($accountData)) throw new Exception(lang('error_21',true));
		
		# request data
		$auth_code = mt_rand(111111,999999);
		$link = $this->generatePasswordChangeVerificationURL($userid, $auth_code);
		
		# add request to database
		$addRequest = $this->addPasswordChangeRequest($userid, $new_password, $auth_code);
		if(!$addRequest) throw new Exception(lang('error_21',true));
		
		# send verification email
		try {
			$email = new Email();
			$email->setTemplate('CHANGE_PASSWORD_EMAIL_VERIFICATION');
			$email->addVariable('{USERNAME}', $username);
			$email->addVariable('{DATE}', date("m/d/Y @ h:i a"));
			$email->addVariable('{IP_ADDRESS}', $ip_address);
			$email->addVariable('{LINK}', $link);
			$email->addVariable('{EXPIRATION_TIME}', $mypassCfg['change_password_request_timeout']);
			$email->addAddress($accountData[_CLMN_EMAIL_]);
			$email->send();
			
			message('success', lang('success_3',true));
		} catch (Exception $ex) {
			message('error', lang('error_20',true));
		}
		
	}
	
	public function changePasswordVerificationProcess($user_id, $auth_code) {
		if(!check_value($user_id)) throw new Exception(lang('error_24',true));
		if(!check_value($auth_code)) throw new Exception(lang('error_24',true));
		
		$userid = Decode_id($user_id);
		$authcode = Decode_id($auth_code);
		
		if(!Validator::UnsignedNumber($userid)) throw new Exception(lang('error_25',true));
		if(!Validator::UnsignedNumber($authcode)) throw new Exception(lang('error_25',true));
		
		$result = $this->db->query_fetch_single("SELECT * FROM WEBENGINE_PASSCHANGE_REQUEST WHERE user_id = ?", array($userid));
		if(!is_array($result)) throw new Exception(lang('error_25',true));
		
		# load changepw configs
		$mypassCfg = loadConfigurations('usercp.mypassword');
		$request_timeout = $mypassCfg['change_password_request_timeout'] * 3600;
		$request_date = $result['request_date'] + $request_timeout;
		
		# check request data
		if($request_date < time()) throw new Exception(lang('error_26',true));
		if($result['auth_code'] != $authcode) throw new Exception(lang('error_27',true));
		
		# account data
		$accountData = $this->accountInformation($userid);
		$username = $accountData[_CLMN_USERNM_];
		$new_password = Decode($result['new_password']);
		
		# check online status
		if($this->accountOnline($username)) throw new Exception(lang('error_14',true));
		
		# update password
		if(!$this->changePassword($userid, $username, $new_password)) throw new Exception(lang('error_29',true));
		
		# send email
		try {
			$email = new Email();
			$email->setTemplate('CHANGE_PASSWORD');
			$email->addVariable('{USERNAME}', $username);
			$email->addVariable('{NEW_PASSWORD}', $new_password);
			$email->addAddress($accountData[_CLMN_EMAIL_]);
			$email->send();
		} catch (Exception $ex) {}
		
		# clear password change request
		$this->removePasswordChangeRequest($userid);
		
		# success message
		message('success', lang('success_5',true));
		
	}
	
	public function passwordRecoveryProcess($user_email, $ip_address) {
		if(!check_value($user_email)) throw new Exception(lang('error_30',true));
		if(!check_value($ip_address)) throw new Exception(lang('error_30',true));
		if(!Validator::Email($user_email)) throw new Exception(lang('error_30',true));
		if(!Validator::Ip($ip_address)) throw new Exception(lang('error_30',true));
		
		if(!$this->emailExists($user_email)) throw new Exception(lang('error_30',true));
		
		$user_id = $this->retrieveUserIDbyEmail($user_email);
		if(!check_value($user_id)) throw new Exception(lang('error_23',true));
		
		$accountData = $this->accountInformation($user_id);
		if(!is_array($accountData)) throw new Exception(lang('error_23',true));
		
		# Account Recovery Code
		$arc = $this->generateAccountRecoveryCode($accountData[_CLMN_MEMBID_], $accountData[_CLMN_USERNM_]);

		# Account Recovery URL
		$aru = $this->generateAccountRecoveryLink($accountData[_CLMN_MEMBID_], $accountData[_CLMN_EMAIL_], $arc);
		
		# send email
		try {
			$email = new Email();
			$email->setTemplate('PASSWORD_RECOVERY_REQUEST');
			$email->addVariable('{USERNAME}', $accountData[_CLMN_USERNM_]);
			$email->addVariable('{DATE}', date("Y-m-d @ h:i a"));
			$email->addVariable('{IP_ADDRESS}', $ip_address);
			$email->addVariable('{LINK}', $aru);
			$email->addAddress($accountData[_CLMN_EMAIL_]);
			$email->send();
			
			message('success', lang('success_6',true));
		} catch (Exception $ex) {
			throw new Exception(lang('error_23',true));
		}
	}
	
	public function passwordRecoveryVerificationProcess($ui, $ue, $key) {
		if(!check_value($ui)) throw new Exception(lang('error_31',true));
		if(!check_value($ue)) throw new Exception(lang('error_31',true));
		if(!check_value($key)) throw new Exception(lang('error_31',true));
		
		$user_id = Decode($ui); // decoded user id
		if(!Validator::UnsignedNumber($user_id)) throw new Exception(lang('error_31',true));
		
		$user_email = Decode($ue); // decoded email address
		if(!$this->emailExists($user_email)) throw new Exception(lang('error_31',true));
		
		$accountData = $this->accountInformation($user_id);
		if(!is_array($accountData)) throw new Exception(lang('error_31',true));
		
		$username = $accountData[_CLMN_USERNM_];
		$gen_key = $this->generateAccountRecoveryCode($user_id, $username);
		
		# compare keys
		if($key != $gen_key) throw new Exception(lang('error_31',true));
		
		# update user password
		$new_password = rand(11111111,99999999);
		$update_pass = $this->changePassword($user_id, $username, $new_password);
		if(!$update_pass) throw new Exception(lang('error_23',true));

		try {
			$email = new Email();
			$email->setTemplate('PASSWORD_RECOVERY_COMPLETED');
			$email->addVariable('{USERNAME}', $username);
			$email->addVariable('{NEW_PASSWORD}', $new_password);
			$email->addAddress($accountData[_CLMN_EMAIL_]);
			$email->send();
			
			message('success', lang('success_7',true));
		} catch (Exception $ex) {
			throw new Exception(lang('error_23',true));
		}
	}
	
	public function masterKeyRecoveryProcess($user_id) {
		if(!check_value($user_id)) throw new Exception(lang('error_23',true));
		if(check_value($_COOKIE['webengine_masterkey'])) throw new Exception(lang('error_50',true));
		
		$accountData = $this->accountInformation($user_id);
		if(!check_value($accountData[_CLMN_MASTER_KEY_])) throw new Exception(lang('error_49',true));
		
		if($this->accountOnline($accountData[_CLMN_USERNM_])) throw new Exception(lang('error_14',true));
		
		try {
			$email = new Email();
			$email->setTemplate('MASTER_KEY_RECOVERY');
			$email->addVariable('{USERNAME}', $accountData[_CLMN_USERNM_]);
			$email->addVariable('{CURRENT_MASTERKEY}', $accountData[_CLMN_MASTER_KEY_]);
			$email->addAddress($accountData[_CLMN_EMAIL_]);
			$email->send();
			
			message('success', lang('success_16',true));
			setcookie("webengine_masterkey", $accountData[_CLMN_USERNM_], time()+3600);  /* expire in 1 hour */
		} catch (Exception $ex) {
			throw new Exception(lang('error_23',true));
		}
	}
	
	public function changeEmailAddress($accountId, $newEmail, $ipAddress) {
		if(!check_value($accountId)) throw new Exception(lang('error_21',true));
		if(!check_value($newEmail)) throw new Exception(lang('error_21',true));
		if(!check_value($ipAddress)) throw new Exception(lang('error_21',true));
		if(!Validator::Ip($ipAddress)) throw new Exception(lang('error_21',true));
		if(!Validator::Email($newEmail)) throw new Exception(lang('error_21',true));
		
		# check if email already in use
		if($this->emailExists($newEmail)) throw new Exception(lang('error_11',true));
		
		# account info
		$accountInfo = $this->accountInformation($accountId);
		if(!is_array($accountInfo)) throw new Exception(lang('error_21',true));
		
		$myemailCfg = loadConfigurations('usercp.myemail');
		if($myemailCfg['require_verification']) {
			# requires verification
			$userName = $accountInfo[_CLMN_USERNM_];
			$userEmail = $accountInfo[_CLMN_EMAIL_];
			$requestDate = strtotime(date("m/d/Y 23:59"));
			$key = md5(md5($userName).md5($userEmail).md5($requestDate).md5($newEmail));
			$verificationLink = __BASE_URL__.'verifyemail/?op='.Encode_id(3).'&uid='.Encode_id($accountId).'&email='.$newEmail.'&key='.$key;
			
			# send verification email
			$sendEmail = $this->changeEmailVerificationMail($userName, $userEmail, $newEmail, $verificationLink, $ipAddress);
			if(!$sendEmail) throw new Exception(lang('error_21',true));
		} else {
			# no verification required
			if(!$this->updateEmail($accountId, $newEmail)) throw new Exception(lang('error_21',true));
		}
	}
	
	public function changeEmailVerificationProcess($encodedId, $newEmail, $encryptedKey) {
		$userId = Decode_id($encodedId);
		if(!Validator::UnsignedNumber($userId)) throw new Exception(lang('error_21',true));
		if(!Validator::Email($newEmail)) throw new Exception(lang('error_21',true));
		
		# check if email already in use
		if($this->emailExists($newEmail)) throw new Exception(lang('error_11',true));
		
		# account info
		$accountInfo = $this->accountInformation($userId);
		if(!is_array($accountInfo)) throw new Exception(lang('error_21',true));
		
		# check key
		$requestDate = strtotime(date("m/d/Y 23:59"));
		$key = md5(md5($accountInfo[_CLMN_USERNM_]).md5($accountInfo[_CLMN_EMAIL_]).md5($requestDate).md5($newEmail));
		if($key != $encryptedKey) throw new Exception(lang('error_21',true));
		
		# change email
		if(!$this->updateEmail($userId, $newEmail)) throw new Exception(lang('error_21',true));
	}
	
	public function verifyRegistrationProcess($username, $key) {
		$verifyKey = $this->db->query_fetch_single("SELECT * FROM WEBENGINE_REGISTER_ACCOUNT WHERE registration_account = ? AND registration_key = ?", array($username,$key));
		if(!is_array($verifyKey)) throw new Exception(lang('error_25',true));
		
		# load registration configs
		$regCfg = loadConfigurations('register');
		
		# insert data
		$data = array(
			'username' => $verifyKey['registration_account'],
			'password' => Decode($verifyKey['registration_password']),
			'name' => $verifyKey['registration_account'],
			'serial' => $this->_defaultAccountSerial,
			'email' => $verifyKey['registration_email']
		);
		
		# query
		if($this->_md5Enabled) {
			$query = "INSERT INTO "._TBL_MI_." ("._CLMN_USERNM_.", "._CLMN_PASSWD_.", "._CLMN_MEMBNAME_.", "._CLMN_SNONUMBER_.", "._CLMN_EMAIL_.", "._CLMN_BLOCCODE_.", "._CLMN_CTLCODE_.") VALUES (:username, [dbo].[fn_md5](:password, :username), :name, :serial, :email, 0, 0)";
		} else {
			$query = "INSERT INTO "._TBL_MI_." ("._CLMN_USERNM_.", "._CLMN_PASSWD_.", "._CLMN_MEMBNAME_.", "._CLMN_SNONUMBER_.", "._CLMN_EMAIL_.", "._CLMN_BLOCCODE_.", "._CLMN_CTLCODE_.") VALUES (:username, :password, :name, :serial, :email, 0, 0)";
		}
		
		
		# create account
		$result = $this->db->query($query, $data);
		if(!$result) throw new Exception(lang('error_22',true));
		
		# delete verification request
		$this->deleteRegistrationVerification($username);
		
		# send welcome email
		if($regCfg['send_welcome_email']) {
			$this->sendWelcomeEmail($verifyKey['registration_account'],$verifyKey['registration_email']);
		}
		
		# free vip days
		if($regCfg['freevip_enable']) {
			switch($this->_serverFiles) {
				case 'MUE':
					$vip = new Vip();
					$this->updateVipTimeStamp($this->db->db->lastInsertId(), $vip->CalculateTimestamp($regCfg['freevip_days']));
					break;
				default:
					break;
			}
		}
		
		# success message
		message('success', lang('success_1',true));
		
		# redirect to login (5 seconds)
		redirect(2,'login/',5);
	}
	
	private function sendRegistrationVerificationEmail($username, $account_email, $key) {
		$verificationLink = __BASE_URL__.'verifyemail/?op='.Encode_id(2).'&user='.Encode($username).'&key='.$key;
		try {
			$email = new Email();
			$email->setTemplate('WELCOME_EMAIL_VERIFICATION');
			$email->addVariable('{USERNAME}', $username);
			$email->addVariable('{LINK}', $verificationLink);
			$email->addAddress($account_email);
			$email->send();
		} catch (Exception $ex) {}
	}
	
	private function sendWelcomeEmail($username,$address) {
		try {
			$email = new Email();
			$email->setTemplate('WELCOME_EMAIL');
			$email->addVariable('{USERNAME}', $username);
			$email->addAddress($address);
			$email->send();
		} catch (Exception $ex) {
			// do nuthin u.u
		}
	}
	
	private function createRegistrationVerification($username,$password,$email) {
		if(!check_value($username)) return;
		if(!check_value($password)) return;
		if(!check_value($email)) return;
		
		$key = uniqid();
		$data = array(
			$username,
			Encode($password),
			$email,
			time(),
			$_SERVER['REMOTE_ADDR'],
			$key
		);
		
		$query = "INSERT INTO WEBENGINE_REGISTER_ACCOUNT (registration_account,registration_password,registration_email,registration_date,registration_ip,registration_key) VALUES (?,?,?,?,?,?)";
		
		$result = $this->db->query($query, $data);
		if(!$result) return;
		return $key;
	}
	
	private function deleteRegistrationVerification($username) {
		if(!check_value($username)) return;
		$delete = $this->db->query("DELETE FROM WEBENGINE_REGISTER_ACCOUNT WHERE registration_account = ?", array($username));
		if($delete) return true;
		return;
	}

	private function checkUsernameEVS($username) {
		if(!check_value($username)) return;
		$result = $this->db->query_fetch_single("SELECT * FROM WEBENGINE_REGISTER_ACCOUNT WHERE registration_account = ?", array($username));
		
		$configs = loadConfigurations('register');
		if(!is_array($configs)) return;
		
		$timelimit = $result['registration_date']+$configs['verification_timelimit']*60*60;
		if($timelimit > time()) return true;
		
		$this->deleteRegistrationVerification($username);
		return false;
	}

	private function checkEmailEVS($email) {
		if(!check_value($email)) return;
		$result = $this->db->query_fetch_single("SELECT * FROM WEBENGINE_REGISTER_ACCOUNT WHERE registration_email = ?", array($email));
		
		$configs = loadConfigurations('register');
		if(!is_array($configs)) return;
		
		$timelimit = $result['registration_date']+$configs['verification_timelimit']*60*60;
		if($timelimit > time()) return true;
		
		$this->deleteRegistrationVerification($result['registration_account']);
		return false;
	}
	
	private function changeEmailVerificationMail($userName, $emailAddress, $newEmail, $verificationLink, $ipAddress) {
		try {
			$email = new Email();
			$email->setTemplate('CHANGE_EMAIL_VERIFICATION');
			$email->addVariable('{USERNAME}', $userName);
			$email->addVariable('{IP_ADDRESS}', $ipAddress);
			$email->addVariable('{NEW_EMAIL}', $newEmail);
			$email->addVariable('{LINK}', $verificationLink);
			$email->addAddress($emailAddress);
			$email->send();
			
			return true;
		} catch (Exception $ex) {
			return;
		}
	}
	
	private function generateAccountRecoveryLink($userid,$email,$recovery_code) {
		if(!check_value($userid)) return;
		if(!check_value($recovery_code)) return;
		
		$build_url = __BASE_URL__;
		$build_url .= 'forgotpassword/';
		$build_url .= '?ui=';
		$build_url .= Encode($userid);
		$build_url .= '&ue=';
		$build_url .= Encode($email);
		$build_url .= '&key=';
		$build_url .= $recovery_code;
		return $build_url;
	}
	
}