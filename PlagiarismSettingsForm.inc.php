<?php

import('lib.pkp.classes.form.Form');
import('plugins.generic.plagiarism.PlagiarismPlugin');

class PlagiarismSettingsForm extends Form {

	/**
	 * The context id
	 * 
	 * @var int
	 */
	var $_contextId;

	/**
	 * The PlagiarismPlugin instance
	 * 
	 * @var PlagiarismPlugin
	 */
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
                
		$this->addCheck(new FormValidator($this, 'ithenticateApiUrl', 'required', 'plugins.generic.plagiarism.manager.settings.apiUrlRequired'));
		$this->addCheck(new FormValidator($this, 'ithenticateApiKey', 'required', 'plugins.generic.plagiarism.manager.settings.apiKeyRequired'));

		$this->addCheck(new FormValidatorPost($this));
		$this->addCheck(new FormValidatorCSRF($this));
	}

	/**
	 * Initialize form data.
	 */
	public function initData() {
		$this->_data = [
            'ithenticateApiUrl' => $this->_plugin->getSetting($this->_contextId, 'ithenticateApiUrl'),
			'ithenticateApiKey' => $this->_plugin->getSetting($this->_contextId, 'ithenticateApiKey'),
			'ithenticateForced' => $this->_plugin->hasForcedCredentials(),
		];
	}

	/**
	 * Assign form data to user-submitted data.
	 */
	public function readInputData() {
		$this->readUserVars(['ithenticateApiUrl', 'ithenticateApiKey']);
	}

	/**
	 * @copydoc Form::fetch()
	 */
	public function fetch($request, $template = null, $display = false) {
		$templateMgr = TemplateManager::getManager($request);
		$templateMgr->assign('pluginName', $this->_plugin->getName());
		return parent::fetch($request, $template, $display);
	}

	/**
	 * @copydoc Form::execute()
	 */
	public function execute(...$functionArgs) {
        $this->_plugin->updateSetting($this->_contextId, 'ithenticateApiUrl', trim($this->getData('ithenticateApiUrl'), "\"\';"), 'string');
		$this->_plugin->updateSetting($this->_contextId, 'ithenticateApiKey', trim($this->getData('ithenticateApiKey'), "\"\';"), 'string');
		parent::execute(...$functionArgs);
	}
}
