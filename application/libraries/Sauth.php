<?php if (!defined('BASEPATH')) exit('No direct script access allowed');

require_once('phpass-0.1/PasswordHash.php');

class Sauth
{
	public $error = array();
	
	function __construct()
	{
		$this->ci =& get_instance();
		
		$this->ci->load->library('session');
		$this->ci->load->database();
		$this->ci->load->model('auth/users');
		
		// try to autologin
		$this->autologin();
	}
	
	function login($username, $password)
	{
		if (!is_null($user = $this->ci->users->get_user_by_username($username)))
		{
			
			$hasher = new PasswordHash(8, FALSE);
			if ($hasher->CheckPassword($password, $user->password))
			{
				if ($user->banned == 1)
				{
					$this->error = array('banned' => $user->ban_reason);
				}
				else
				{
					$this->ci->session->set_userdata(array(
							'user_id'	=> $user->id,
							'username'	=> $user->username,
							'status'	=> ($user->activated == 1) ? 1 : 0,
							'threads_shown' => $user->threads_shown,
							'comments_shown' => $user->comments_shown
					));
					
					$this->create_autologin($user->id);
					
					$this->clear_login_attempts($username);
					
					$this->ci->users->update_login_info(
							$user->id,
							$this->ci->config->item('login_record_ip', 'auth'),
							$this->ci->config->item('login_record_time', 'auth'));
					
					return TRUE;
				}
				
			}
			else
			{
				$this->increase_login_attempt($username);
				$this->error = array('login' => 'auth_incorrect_login');
			}
		}
		
		return FALSE;
	}
	
	/**
	 * Logout user from the site
	 *
	 * @return	void
	 */
	function logout()
	{
		$this->delete_autologin();
		
		$this->ci->session->set_userdata(array('user_id' => '', 'username' => '', 'status' => ''));
		
		$this->ci->session->sess_destroy();
	}
	
	/**
	 * Insert a new yh invite record into the database
	 *
	 * @return	void
	 */
	function yh_invite($username, $invite_id)
	{
		if ($this->ci->users->is_yh_username_available($username))
		{
			$this->ci->users->create_yh_invite($username, $invite_id);
			
			return TRUE;
		}
		else
		{
			$this->error = array('invite' => 'An invitation has already been sent to that username.');
		}
	}
	
	/**
	 * Create new user on the site and return some data about it:
	 * user_id, username, password, email, new_email_key (if any).
	 *
	 * @param	string
	 * @param	string
	 * @param	string
	 * @param	bool
	 * @return	array
	 */
	function create_user($username, $email, $password, $key)
	{
		if ((strlen($username) > 0) AND !$this->ci->users->is_username_available($username)) {
			$this->error = array('username' => 'auth_username_in_use');

		} elseif (!$this->ci->users->is_email_available($email)) {
			$this->error = array('email' => 'auth_email_in_use');

		} else {
			
			if (!$this->ci->users->is_yh_invite_used($key))
			{
				// hash password using phpass
				$hasher = new PasswordHash(8, FALSE);
				
				$data = array(
					'username'	=> $username,
					'password'	=> $hasher->HashPassword($password),
					'email'		=> $email,
					'last_ip'	=> $this->ci->input->ip_address(),
					'yh_username' => $this->ci->users->get_yh_username_by_invite($key)
				);
				
				$user_id = $this->ci->users->create_user($data, $key);
				
				unset($data['last_ip']);
				
				$this->login($username, $password);
				
				redirect('');
			} else {
				
				$this->error = array('register' => 'That invite key is no longer valid.');
				
				return FALSE;
			}
		}
		
		return TRUE;
	}
	
	/**
	 * Check if user logged in. Also test if user is activated or not.
	 *
	 * @param	bool
	 * @return	bool
	 */
	function is_logged_in($activated = TRUE)
	{
		return $this->ci->session->userdata('status') === ($activated ? 1 : 0);
	}
	
	/**
	 * Get user_id
	 *
	 * @return	string
	 */
	function get_user_id()
	{
		return $this->ci->session->userdata('user_id');
	}
	
	/**
	 * Get error message.
	 * Can be invoked after any failed operation such as login or register.
	 *
	 * @return	string
	 */
	function get_error_message()
	{
		return $this->error;
	}
	
	/**
	 * Save data for user's autologin
	 *
	 * @param	int
	 * @return	bool
	 */
	private function create_autologin($user_id)
	{
		$this->ci->load->helper('cookie');
		$key = substr(md5(uniqid(rand().get_cookie($this->ci->config->item('sess_cookie_name')))), 0, 16);
		
		$this->ci->load->model('auth/user_autologin');
		$this->ci->user_autologin->purge($user_id);
		
		if ($this->ci->user_autologin->set($user_id, md5($key))) {
			set_cookie(array(
					'name' 		=> 'autologin',
					'value'		=> serialize(array('user_id' => $user_id, 'key' => $key)),
					'expire'	=> 5356800,
			));
			return TRUE;
		}
		return FALSE;
	}
	
	/**
	 * Clear user's autologin data
	 *
	 * @return	void
	 */
	private function delete_autologin()
	{
		$this->ci->load->helper('cookie');
		if ($cookie = get_cookie('autologin', TRUE)) {

			$data = unserialize($cookie);

			$this->ci->load->model('auth/user_autologin');
			$this->ci->user_autologin->delete($data['user_id'], md5($data['key']));

			delete_cookie('autologin');
		}
	}
	
	/**
	 * Login user automatically if he/she provides correct autologin verification
	 *
	 * @return	void
	 */
	private function autologin()
	{
		if (!$this->is_logged_in() AND !$this->is_logged_in(FALSE)) {			// not logged in (as any user)
			$this->ci->load->helper('cookie');
			if ($cookie = get_cookie('autologin', TRUE)) {
			
				$data = unserialize($cookie);

				if (isset($data['key']) AND isset($data['user_id'])) {

					$this->ci->load->model('auth/user_autologin');
					if (!is_null($user = $this->ci->user_autologin->get($data['user_id'], md5($data['key'])))) {

						// Login user
						$this->ci->session->set_userdata(array(
								'user_id'	=> $user->id,
								'username'	=> $user->username,
								'status'	=> 1,
						));

						// Renew users cookie to prevent it from expiring
						set_cookie(array(
								'name' 		=> $this->ci->config->item('autologin_cookie_name', 'auth'),
								'value'		=> $cookie,
								'expire'	=> $this->ci->config->item('autologin_cookie_life', 'auth'),
						));

						$this->ci->users->update_login_info(
								$user->id,
								$this->ci->config->item('login_record_ip', 'auth'),
								$this->ci->config->item('login_record_time', 'auth'));
						
						return TRUE;
					}
				}
			}
		}
		return FALSE;
	}
	
	/**
	 * Check if login attempts exceeded max login attempts (specified in config)
	 *
	 * @param	string
	 * @return	bool
	 */
	function is_max_login_attempts_exceeded($login)
	{
		$this->ci->load->model('tank_auth/login_attempts');
		return $this->ci->login_attempts->get_attempts_num($this->ci->input->ip_address(), $login)
				>= $this->ci->config->item('login_max_attempts', 'auth');
	}
	
	/**
	 * Increase number of attempts for given IP-address and login
	 * (if attempts to login is being counted)
	 *
	 * @param	string
	 * @return	void
	 */
	private function increase_login_attempt($login)
	{
		if (!$this->is_max_login_attempts_exceeded($login)) {
			$this->ci->load->model('auth/login_attempts');
			$this->ci->login_attempts->increase_attempt($this->ci->input->ip_address(), $login);
		}
	}
	
	/**
	 * Clear all attempt records for given IP-address and login
	 * (if attempts to login is being counted)
	 *
	 * @param	string
	 * @return	void
	 */
	private function clear_login_attempts($login)
	{
		$this->ci->load->model('auth/login_attempts');
		$this->ci->login_attempts->clear_attempts(
				$this->ci->input->ip_address(),
				$login,
				$this->ci->config->item('login_attempt_expire', 'auth'));
	}	
}