<?php

/**
 * @file plugins/generic/plagiarism/controllers/PlagiarismComponentHandler.php
 *
 * Copyright (c) 2024 Simon Fraser University
 * Copyright (c) 2024 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class PlagiarismComponentHandler
 *
 * @brief Base handler class for plagiarism plugin's ROUTE_COMPONENT classes
 */

namespace APP\plugins\generic\plagiarism\controllers;

use APP\plugins\generic\plagiarism\PlagiarismPlugin;
use PKP\handler\PKPHandler;
use PKP\security\authorization\UserRolesRequiredPolicy;
use PKP\security\authorization\UserRequiredPolicy;

class PlagiarismComponentHandler extends PKPHandler
{
	/** 
	 * The Plagiarism Plugin itself
	 */
	protected PlagiarismPlugin $_plugin;

	/** 
	 * Constructor
	 */
	public function __construct(PlagiarismPlugin $plugin)
	{
		$this->_plugin = $plugin;
	}

	/**
	 * @copydoc PKPHandler::authorize()
	 */
	public function authorize($request, &$args, $roleAssignments)
	{
		$this->addPolicy(new UserRequiredPolicy($request));
		$this->addPolicy(new UserRolesRequiredPolicy($request));
		
		return parent::authorize($request, $args, $roleAssignments);
	}
}
