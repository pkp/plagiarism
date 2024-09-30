<?php

/**
 * @file plugins/generic/plagiarism/classes/validation/ValidatorIthenticateAccess.php
 *
 * Copyright (c) 2024 Simon Fraser University
 * Copyright (c) 2024 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ValidatorIthenticateAccess
 * @see Validator
 *
 * @brief Validator to validate iThenticate service access
 */

namespace APP\plugins\generic\plagiarism\classes\validation;

use PKP\validation\Validator;
use APP\plugins\generic\plagiarism\IThenticate;
use APP\plugins\generic\plagiarism\TestIThenticate;

class ValidatorIthenticateAccess extends Validator {
    
	/**
	 * The iThenticate API communicating service class instance
	 */
	protected IThenticate|TestIThenticate $ithenticate;

	/**
	 * Constructor
	 */
	public function __construct(IThenticate|TestIThenticate $ithenticate)
	{
		$this->ithenticate = $ithenticate;
	}

	/**
	 * @copydoc Validator::isValid()
	 */
	public function isValid($value)
	{
		return $this->ithenticate->validateAccess();
	}
} 
