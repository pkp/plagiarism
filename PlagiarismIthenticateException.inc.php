<?php

/**
 * @file PlagiarismIthenticateException.inc.php
 *
 * Copyright (c) 2003-2021 Simon Fraser University
 * Copyright (c) 2003-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @brief Plagiarism plugin iThenticate exception
 */


/**
 * Define an exception class for iThenticate via this plugin
 * This Exception will include the failed iThenticate connection, when possible.
 */
class PlagiarismIthenticateException extends Exception
{
	private ?\bsobbe\ithenticate\Ithenticate $connection;

	/*
	 * Constructor
	 * @param $message string
	 * @param $code int
	 * @param $previous Exception
	 * @param $connection Ithenticate
	 */
	public function __construct($message, $code = 0, ?Throwable $previous = null, ?\bsobbe\ithenticate\Ithenticate $connection = null) {
		$this->connection = $connection;
		parent::__construct($message, $code, $previous);
	}

	/*
	 * Constructor
	 * @return Ithenticate|null
	 */
	public function getConnection() {
		return $this->connection;
	}
}

