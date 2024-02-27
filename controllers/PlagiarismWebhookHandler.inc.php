<?php

/**
 * @file plugins/generic/plagiarism/controllers/grid/PlagiarismWebhookHandler.inc.php
 *
 * Copyright (c) 2014-2024 Simon Fraser University
 * Copyright (c) 2000-2024 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class PlagiarismWebhookHandler
 * @ingroup plugins_generic_plagiarism
 *
 * @brief Handle the webhook calls for plagiarism check
 */

import('lib.pkp.classes.handler.PKPHandler');

class PlagiarismWebhookHandler extends PKPHandler {

	/** 
	 * The Plagiarism Plugin itself
	 * 
	 * @var PlagiarismPlugin 
	 */
	protected static $_plugin;

	/**
	 * Get the plugin
	 * 
	 * @return PlagiarismPlugin
	 */
	public static function getPlugin() {
		return static::$_plugin;
	}

	/**
	 * Set the Plugin
	 * 
	 * @param PlagiarismPlugin $plugin
	 */
	public static function setPlugin($plugin) {
		static::$_plugin = $plugin;
	}

	/**
	 * Authorize this request.
	 *
	 * @return boolean
	 */
	public function authorize($request, &$args, $roleAssignments) {
		return true;
	}

	/**
	 * Handle the incoming webhook request
	 *
	 * @return void
	 */
	public function handle() {
		error_log('PKP Plagiarism plugin handle webhook request');
	}
}
