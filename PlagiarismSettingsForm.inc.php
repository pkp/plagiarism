<?php

import('lib.pkp.classes.form.Form');

class PlagiarismSettingsForm extends Form {

	/** @var int */
	var $_contextId;

	/** @var object */
	var $_plugin;

	/**
	 * Constructor
	 * @param $plugin PlagiarismPlugin
	 * @param $contextId int
	 */
	function __construct($plugin, $contextId) {
		$this->_contextId = $contextId;
		$this->_plugin = $plugin;

		parent::__construct($plugin->getTemplateResource('settingsForm.tpl'));
                
		$this->addCheck(new FormValidator($this, 'ithenticateUser', FORM_VALIDATOR_REQUIRED_VALUE, 'plugins.generic.plagiarism.manager.settings.usernameRequired'));
		$this->addCheck(new FormValidator($this, 'ithenticatePass', FORM_VALIDATOR_REQUIRED_VALUE, 'plugins.generic.plagiarism.manager.settings.passwordRequired'));
		$this->addCheck(new FormValidatorCustom($this, 'ithenticatePass', FORM_VALIDATOR_REQUIRED_VALUE, 'plugins.generic.plagiarism.manager.settings.loginFailed', array(&$this, '_checkConnection'), array(&$this), true));

		$this->addCheck(new FormValidatorPost($this));
		$this->addCheck(new FormValidatorCSRF($this));
	}

	/**
	 * Initialize form data.
	 */
	function initData() {
		list($username, $password) = $this->_plugin->getForcedCredentials($this->_contextId);
		$this->_data = array(
                        'ithenticateUser' => $this->_plugin->getSetting($this->_contextId, 'ithenticateUser'),
			'ithenticatePass' => $this->_plugin->getSetting($this->_contextId, 'ithenticatePass'),
			'ithenticateForced' => !empty($username) && !empty($password)
		);
	}

	/**
	 * Assign form data to user-submitted data.
	 */
	function readInputData() {
                $this->readUserVars(array('ithenticateUser', 'ithenticatePass'));
	}

	/**
	 * @copydoc Form::fetch()
	 */
	function fetch($request, $template = null, $display = false) {
		$templateMgr = TemplateManager::getManager($request);
		$templateMgr->assign('pluginName', $this->_plugin->getName());
		return parent::fetch($request, $template, $display);
	}

	/**
	 * @copydoc Form::execute()
	 */
	function execute(...$functionArgs) {
                $this->_plugin->updateSetting($this->_contextId, 'ithenticateUser', trim($this->getData('ithenticateUser'), "\"\';"), 'string');
		$this->_plugin->updateSetting($this->_contextId, 'ithenticatePass', trim($this->getData('ithenticatePass'), "\"\';"), 'string');
		parent::execute(...$functionArgs);
	}

	/**
	 * Check the username and password for the service
	 * @param $formPassword string the value of the field being checked
	 * @param $form object a reference to this form
	 * @return boolean Is there a problem with the form?
	 */
	function _checkConnection($formPassword, $form) {
		$username = $form->getData('ithenticateUser');
		// bypass testing if login is not present
		if (empty($username) || empty($formPassword)) {
			return false;
		}

		$contextDao = Application::getContextDAO();
		$context = $contextDao->getById($this->_contextId);
		// if credentials are forced, don't bother testing them.  The user can't do anything about a failure on this form.
		list($username, $password) = $this->_plugin->getForcedCredentials($this->_contextId); 
		if (!empty($username) && !empty($password)) {
			return false;
		}
		$contextName = $context->getLocalizedName($context->getPrimaryLocale());
		$username = $username = $form->getData('ithenticateUser');
		$password = $formPassword;

		$ithenticate = null;
		try {
			$ithenticate = $this->_plugin->ithenticateConnect($username, $password, $contextName);
		} catch (Exception $e) {
			error_log($e->getMessage());
			return true;
		}
		return false;
	}

}
