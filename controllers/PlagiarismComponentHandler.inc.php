<?php

/**
 * @file controllers/PlagiarismComponentHandler.inc.php
 *
 * Copyright (c) 2024 Simon Fraser University
 * Copyright (c) 2024 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class PlagiarismComponentHandler
 * @ingroup plugins_generic_plagiarism
 *
 * @brief Base handler class for plagiarism plugin's ROUTE_COMPONENT classes
 */

import('lib.pkp.classes.handler.PKPHandler');
import('lib.pkp.classes.security.authorization.UserRequiredPolicy');
import('lib.pkp.classes.security.authorization.UserRolesRequiredPolicy');

class PlagiarismComponentHandler extends PKPHandler {

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
	 * @copydoc PKPHandler::authorize()
	 */
	public function authorize($request, &$args, $roleAssignments) {

		$this->addPolicy(new UserRequiredPolicy($request));
		$this->addPolicy(new UserRolesRequiredPolicy($request));
		
		return parent::authorize($request, $args, $roleAssignments);
	}
}
