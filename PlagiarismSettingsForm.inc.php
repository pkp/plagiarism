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
                
		$this->addCheck(new FormValidator($this, 'ithenticateUser', 'required', 'plugins.generic.plagiarism.manager.settings.usernameRequired'));
		$this->addCheck(new FormValidator($this, 'ithenticatePass', 'required', 'plugins.generic.plagiarism.manager.settings.passwordRequired'));

		$this->addCheck(new FormValidatorPost($this));
		$this->addCheck(new FormValidatorCSRF($this));
	}

	/**
	 * Initialize form data.
	 */
	function initData() {
		list($username, $password) = $this->_plugin->getForcedCredentials();
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
}
